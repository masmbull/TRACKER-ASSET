<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
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

        $asset = $this->find($code);

        if (! $asset) {
            return back()->withInput()->withErrors([
                'code' => "No asset found for code \"{$code}\".",
            ]);
        }

        return redirect()
            ->route('assets.show', $asset)
            ->with('success', "Asset found by {$this->matchedBy($asset, $code)}.");
    }

    /**
     * Resolve whatever a scanner typed to an asset.
     *
     * The obvious lookup is an exact match against the stored identifiers, but
     * that is not enough on its own: the QR labels this app prints encode a URL
     * (see AssetQrCode), and a scanner types the whole thing. A code of
     * "https://assets.mito.co.id/assets/42" therefore has to be unwrapped before
     * the identifier lookup, or every printed label scans to "not found".
     *
     * The host is deliberately not checked. A sticker keeps whatever host it was
     * printed with, so labels printed before a host change, or during local
     * development, still resolve.
     */
    private function find(string $code): ?Asset
    {
        $code = trim($code);

        $asset = $this->query($code)->first();

        if ($asset) {
            return $asset;
        }

        $id = $this->idFromLabel($code);

        return $id === null ? null : $this->findById($id);
    }

    /**
     * The asset id a label URL carries, or null when the code is not one.
     *
     * Only /assets/{number} is unwrapped. Accepting anything looser would turn
     * this into a way to fetch an arbitrary record by typing a path.
     */
    private function idFromLabel(string $code): ?int
    {
        if (preg_match('#/assets/(\d+)/?$#', $code, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function query(string $code): Builder
    {
        return Asset::query()
            ->where('barcode', $code)
            ->orWhere('qr_code', $code)
            ->orWhere('asset_id', $code)
            ->orWhere('serial_number', $code)
            ->orWhere('hostname', $code);
    }

    /**
     * Look up by primary key, kept out of the shared query on purpose.
     *
     * "id" is a bigint, and a scanner's input is arbitrary text: PostgreSQL
     * raises "invalid input syntax for type bigint" on 'BC-100200300' rather
     * than returning no rows. SQLite quietly casts and would let this pass, so
     * the cast is made explicit here and only ever applied to something already
     * known to be an integer.
     */
    private function findById(int $id): ?Asset
    {
        return Asset::query()->find($id);
    }

    /**
     * How it was found, for the message the user sees.
     */
    private function matchedBy(Asset $asset, string $code): string
    {
        $field = collect([
            'barcode' => $asset->barcode,
            'qr_code' => $asset->qr_code,
            'asset_id' => $asset->asset_id,
            'serial_number' => $asset->serial_number,
            'hostname' => $asset->hostname,
        ])->search(fn ($value) => $value !== null && $value === $code);

        if ($field !== false) {
            return $field;
        }

        return $this->idFromLabel($code) === null ? 'identifier' : 'QR label';
    }
}
