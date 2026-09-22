<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Http\Request;

/**
 * Barcode / QR scanner entry point (PRD §42).
 *
 * A scanner behaves like a keyboard: it types the barcode and submits.
 * This page resolves the code to an asset and hands off to the right
 * workflow (detail, audit, transfer). Check-in / check-out happen through
 * the existing transfer and status flows so history stays intact.
 */
class BarcodeController extends Controller
{
    public function form()
    {
        $this->authorize('viewAny', Asset::class);

        return view('barcode.index');
    }

    public function resolve(Request $request)
    {
        $this->authorize('viewAny', Asset::class);

        $code = trim((string) $request->validate(['code' => 'required|string|max:255'])['code']);

        $asset = Asset::query()
            ->where('barcode', $code)
            ->orWhere('qr_code', $code)
            ->orWhere('asset_id', $code)
            ->orWhere('serial_number', $code)
            ->orWhere('hostname', $code)
            ->first();

        if (! $asset) {
            return back()->withInput()->withErrors([
                'code' => "No asset found for code \"{$code}\".",
            ]);
        }

        $matchedBy = collect([
            'barcode' => $asset->barcode,
            'qr_code' => $asset->qr_code,
            'asset_id' => $asset->asset_id,
            'serial_number' => $asset->serial_number,
            'hostname' => $asset->hostname,
        ])->search(fn ($value) => $value !== null && $value === $code) ?: 'identifier';

        return redirect()
            ->route('assets.show', $asset)
            ->with('success', "Asset found by {$matchedBy}.");
    }
}
