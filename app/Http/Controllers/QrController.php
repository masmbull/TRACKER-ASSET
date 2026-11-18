<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\CustomQrHistory;
use App\Models\Location;
use App\Services\AssetQrCode;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-asset QR codes (PRD §41).
 *
 * The QR encodes the asset detail URL: scan → authenticated asset detail.
 * Rendered on demand — nothing is stored in the `qr_code` column.
 */
class QrController extends Controller
{
    /**
     * Rendered as SVG rather than PNG.
     *
     * The raster back end of the QR library is Imagick, and Imagick is a PHP
     * extension that has to be compiled in or installed separately. Without it
     * every request to this route threw and the QR button on the asset page was
     * a 500 for every role - a PRD §41 feature that looked present and was not.
     * SVG is produced by the library itself with no extension at all, and for a
     * code that is scanned off a screen it is the better format anyway: it stays
     * sharp at any size and is a fraction of the bytes.
     */
    public function show(Request $request, Asset $asset)
    {
        $this->authorize('view', $asset);

        $qr = AssetQrCode::fromConfig();

        // "plain" is the compact label's code: the same payload, without the
        // white logo plate the square label needs. It is an option on the
        // existing route rather than a second route, so a sticker's address
        // stays recognisable and the old label keeps its mark.
        return $this->svgResponse(
            $request->boolean('plain') ? $qr->code($asset) : $qr->svg($asset),
            $asset->id.'-qr.svg'
        );
    }

    /**
     * The compact label on its own page, ready to print.
     *
     * Deliberately outside the application layout: a label printed from inside
     * the dashboard would carry the sidebar and the page header onto the sheet,
     * which is exactly what a sticker sheet must not have.
     */
    public function label(Asset $asset)
    {
        $this->authorize('view', $asset);

        $asset->load(['company', 'category']);

        return view('assets.label', ['asset' => $asset]);
    }

    /** The same label as a downloadable PDF, sized for the configured paper. */
    public function labelPdf(Asset $asset): Response
    {
        $this->authorize('view', $asset);

        $asset->load(['company', 'category']);

        $pdf = Pdf::loadView('assets.label-pdf', [
            'labels' => [['asset' => $asset, 'qrSrc' => $this->qrDataUri($asset)]],
            'columns' => 1,
        ])->setPaper($this->paper());

        return $pdf->download('label-'.$asset->asset_id.'.pdf');
    }

    /**
     * A print-only sheet for a set of assets.
     *
     * "Print selected" posts the checkbox ids here; with none selected the
     * current filters are used instead, so the button is useful even when the
     * operator has not ticked anything.
     */
    public function printSheet(Request $request)
    {
        $this->authorize('viewAny', Asset::class);

        return view('assets.label-print', [
            'assets' => $this->selectedAssets($request),
        ]);
    }

    /** The same selection as a PDF, laid out for the configured paper. */
    public function sheetPdf(Request $request): Response
    {
        $this->authorize('viewAny', Asset::class);

        $qr = AssetQrCode::fromConfig();

        $labels = $this->selectedAssets($request)
            ->map(fn (Asset $asset) => [
                'asset' => $asset,
                'qrSrc' => 'data:image/svg+xml;base64,'.base64_encode($qr->code($asset)),
            ])
            ->all();

        $pdf = Pdf::loadView('assets.label-pdf', [
            'labels' => $labels,
            'columns' => $this->labelColumns(),
        ])->setPaper($this->paper());

        return $pdf->download('asset-labels-'.now()->format('Ymd-His').'.pdf');
    }

    /**
     * The QR for a label, inlined so a PDF does not depend on a network fetch.
     *
     * DomPDF does not request URLs, so an <img src="https://..."> in a PDF is a
     * blank box. A data URI is the same SVG the browser would fetch, carried in
     * the document itself.
     */
    private function qrDataUri(Asset $asset): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(AssetQrCode::fromConfig()->code($asset));
    }

    /**
     * The assets a bulk action applies to: the ticked ids when there are any,
     * otherwise the current filters.
     */
    private function selectedAssets(Request $request)
    {
        $ids = array_values(array_filter((array) $request->input('ids', []), 'is_numeric'));

        if ($ids !== []) {
            return Asset::query()
                ->with(['company', 'category'])
                ->whereIn('id', $ids)
                ->orderBy('asset_id')
                ->get();
        }

        return $this->filtered($request)->orderBy('asset_id')->get();
    }

    /** How many labels fit across the configured paper, gaps included. */
    private function labelColumns(): int
    {
        $width = (float) config('assets.label.width_mm', 90);
        $gap = (float) config('assets.label.gap_mm', 4);
        $margin = (float) config('assets.label.page_margin_mm', 10);
        $page = strtolower((string) config('assets.label.page_size', 'A4')) === 'letter' ? 215.9 : 210.0;

        return max(1, (int) floor(($page - ($margin * 2) + $gap) / ($width + $gap)));
    }

    /** DomPDF wants a lowercase paper name. */
    private function paper(): string
    {
        return strtolower((string) config('assets.label.page_size', 'A4'));
    }

    /**
     * Generate a QR symbol from text or a URL supplied by the user.
     */
    public function custom(Request $request): Response
    {
        $this->authorize('viewAny', Asset::class);

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:500'],
        ]);

        $svg = AssetQrCode::fromConfig()->customSvg($validated['content']);

        $history = $request->user()->customQrHistories()->create(['content' => $validated['content']]);

        $response = $this->svgResponse($svg, 'mito-custom-qr.svg');

        $response->headers->set('X-QR-History-Id', (string) $history->id);
        $response->headers->set('X-QR-History-Created-At', $history->created_at->toIso8601String());
        $response->headers->set('X-QR-History-Download', route('assets.qr.history.download', $history));

        return $response;
    }

    public function downloadHistory(Request $request, CustomQrHistory $customQrHistory): Response
    {
        abort_unless($customQrHistory->user_id === (int) $request->user()->id, 404);

        return response(AssetQrCode::fromConfig()->customSvg($customQrHistory->content), 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="mito-custom-qr-'.$customQrHistory->id.'.svg"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Issue (or rotate) the scan target for one asset, then show its label.
     *
     * "Create QR" and "Re-create QR" are the same action with one difference:
     * whether an existing token is allowed to survive. Creating when one already
     * exists is a no-op that shows the existing label, so a double-click does not
     * silently invalidate a sticker someone already printed; re-creating mints a
     * new token on purpose.
     */
    public function generate(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        if (! $asset->hasQr() || $request->boolean('regenerate')) {
            $asset->mintQrToken();
        }

        return redirect()
            ->route('assets.show', ['asset' => $asset, 'tab' => 'documents'])
            ->with('success', $request->boolean('regenerate')
                ? "QR for {$asset->asset_id} re-created. Previously printed labels no longer work."
                : "QR for {$asset->asset_id} created.");
    }

    /**
     * The public page a scan opens: a read-only asset document, no login (PRD §41).
     *
     * Resolved by the unguessable qr_token, never the numeric id, so scanning one
     * label cannot be turned into browsing the whole register. Deliberately a
     * document, not the app: the person scanning is standing at the machine with a
     * phone, and what they need is what this asset is and who it belongs to - not
     * a sidebar and an edit button.
     */
    public function publicAsset(string $token)
    {
        $asset = Asset::with(['company', 'category', 'status', 'location', 'currentUser', 'adminItPic'])
            ->where('qr_token', $token)
            ->firstOrFail();

        return view('assets.public', [
            'asset' => $asset,
            'issuedAt' => $asset->qr_generated_at,
        ]);
    }

    /** The same public document as a downloadable PDF. */
    public function publicPdf(string $token): Response
    {
        $asset = Asset::with(['company', 'category', 'status', 'location', 'currentUser', 'adminItPic'])
            ->where('qr_token', $token)
            ->firstOrFail();

        $pdf = Pdf::loadView('assets.public-pdf', [
            'asset' => $asset,
            'issuedAt' => $asset->qr_generated_at,
        ])->setPaper('a4');

        return $pdf->download('asset-'.($asset->asset_id ?: $asset->id).'.pdf');
    }

    /**
     * A printable sheet of labels for assets that already exist (PRD §41).
     *
     * Generating one code at a time means visiting 261 assets and printing 261
     * browser dialogs, which is how labels end up never being applied at all.
     * This answers the question an operator actually has - "give me labels for
     * the laptops in this branch" - and prints them in one pass.
     */
    public function sheet(Request $request)
    {
        $this->authorize('viewAny', Asset::class);

        $perPage = (int) config('assets.qr.sheet_size', 60);

        $assets = $this->filtered($request)
            ->orderBy('asset_id')
            ->paginate($perPage)
            ->withQueryString();

        // Printing a label IS the act of publishing it, so every code on this
        // sheet gets a public token now if it lacks one. Without this the sheet
        // fell back to the authenticated /assets/{id} URL - which a phone scan
        // cannot open, and which is how a batch of labels ended up encoding
        // http://localhost from a dev checkout and were dead the moment they
        // were stuck on a machine. Only the current page is touched (<= sheet_size
        // rows), not the whole register, per the note on the token migration.
        foreach ($assets as $asset) {
            if (! $asset->hasQr()) {
                $asset->mintQrToken();
            }
        }

        $qrHistory = $request->user()
            ->customQrHistories()
            ->latest()
            ->paginate(10, ['*'], 'history_page')
            ->withQueryString();

        return view('assets.qr-sheet', [
            'assets' => $assets,
            'qrHistory' => $qrHistory,
            'qr' => AssetQrCode::fromConfig(),
            'companies' => Company::orderBy('name')->get(),
            'categories' => AssetCategory::orderBy('name')->get(),
            'locations' => Location::orderBy('name')->get(),
            'statuses' => AssetStatus::orderBy('name')->get(),
        ]);
    }

    /**
     * The same filters the asset list uses, so a label run can be scoped the same
     * way a search is (PRD §22, §41).
     */
    private function filtered(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $like = '%'.$search.'%';

        return Asset::query()
            ->with(['company', 'category'])
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('asset_id', 'like', $like)
                ->orWhere('serial_number', 'like', $like)
                ->orWhere('hostname', 'like', $like)
                ->orWhere('model', 'like', $like)))
            ->when($request->filled('company'), fn ($q) => $q->where('company_id', $request->input('company')))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->input('category')))
            ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->input('location')))
            ->when($request->filled('status'), fn ($q) => $q->where('asset_status_id', $request->input('status')))
            ->where('is_active', true);
    }

    private function svgResponse(string $svg, string $filename): Response
    {
        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            // Only ever rendered on screen or dropped straight into a document;
            // an attachment is right for the single-code download and wrong for
            // anything embedding it in an <img>.
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
