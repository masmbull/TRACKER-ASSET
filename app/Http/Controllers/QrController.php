<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

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

        $svg = QrCode::format('svg')
            ->size(300)
            ->margin(2)
            ->errorCorrection('M')
            ->generate(route('assets.show', $asset));

        return response($svg)->header('Content-Type', 'image/svg+xml');
    }
}
