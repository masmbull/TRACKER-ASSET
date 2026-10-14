<?php

namespace App\Http\Controllers;

use App\Models\Asset;
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
    public function show(Asset $asset)
    {
        $this->authorize('view', $asset);

        return $this->svgResponse(AssetQrCode::fromConfig()->svg($asset), $asset->id.'-qr.svg');
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
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('asset_id', 'like', $like)
                ->orWhere('serial_number', 'like', $like)
                ->orWhere('hostname', 'like', $like)))
            ->when($request->filled('company'), fn ($q) => $q->where('company_id', $request->input('company')))
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
