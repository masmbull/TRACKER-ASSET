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
    public function show(Asset $asset)
    {
        $this->authorize('view', $asset);

        $png = QrCode::format('png')
            ->size(300)
            ->margin(2)
            ->errorCorrection('M')
            ->generate(route('assets.show', $asset));

        return response($png)->header('Content-Type', 'image/png');
    }
}
