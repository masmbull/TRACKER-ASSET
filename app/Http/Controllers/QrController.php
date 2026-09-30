<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Services\AssetQrCode;
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

        return view('assets.qr-sheet', [
            'assets' => $assets,
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
