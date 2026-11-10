<?php

namespace App\Services;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Creates the next asset number in a running sequence.
 *
 * Numbers look like MITO-LAP-2026-0007: company, category, year and a
 * four-digit counter that never repeats and never goes backwards.
 *
 * Two rules make that hold under concurrency:
 *
 * 1. The counter is derived from the highest existing number in the same
 *    company/category/year group, not from a row count. A count skips numbers
 *    the moment anything is archived or deleted, which is exactly the "bolong"
 *    sequence that prompted this.
 * 2. The read and the insert happen inside a transaction holding a Postgres
 *    advisory lock keyed on that group. Two operators registering a laptop at
 *    the same second would otherwise both compute 0007 and one would win with a
 *    duplicate key error.
 *
 * The year is part of the number, so the sequence restarts at 0001 each January
 * - a 2026 number never collides with 2025, and the year in the label tells IT
 * when the device was registered.
 */
class AssetNumberService
{
    /** Advisory lock namespace, so this never collides with another lock. */
    private const LOCK_NAMESPACE = 4021;

    /**
     * The next asset number for a group.
     *
     * @param  string  $companyCode  e.g. MITO, MSI
     * @param  string  $categoryCode  e.g. LAP for laptop, PC, PRN
     * @param  int|null  $year  defaults to the current year
     */
    public function next(string $companyCode, string $categoryCode, ?int $year = null): string
    {
        $companyCode = $this->normalise($companyCode);
        $categoryCode = $this->normalise($categoryCode);
        $year ??= (int) now()->format('Y');

        $prefix = "{$companyCode}-{$categoryCode}-{$year}-";

        // One lock per group: registration for MITO-LAP-2026 cannot block a
        // MIS-PRN-2026 registration, but two MITO laptops serialise.
        $lockKey = $this->lockKey($prefix);

        $number = DB::transaction(function () use ($lockKey, $prefix) {
            // Postgres is the production driver; SQLite is only used by the test
            // suite. The lock is a Postgres feature, but the identifier query is
            // portable so sequence selection behaves the same on both engines.
            $isPostgres = DB::connection()->getDriverName() === 'pgsql';

            if ($isPostgres) {
                DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::LOCK_NAMESPACE, $lockKey]);
            }

            $query = Asset::query()->where('asset_id', 'like', $prefix . '%');
            $last = $query
                ->orderByRaw('CAST(SUBSTR(asset_id, ?) AS INTEGER) DESC', [strlen($prefix) + 1])
                ->value('asset_id');

            $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

            return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        }, 3);

        return $number;
    }

    /**
     * The asset number for a date/time, using the company's own convention.
     */
    public function forAsset(Asset $asset): string
    {
        return $this->next(
            $this->companyCode($asset),
            $this->categoryCode($asset),
            (int) ($asset->created_at ?? now())->format('Y'),
        );
    }

    /** Company code from the asset's company, or a stable fallback. */
    public function companyCode(Asset $asset): string
    {
        $name = (string) optional($asset->company)->name;

        if ($name === '') {
            return 'MITO';
        }

        // "PT. Mahakarya Sukses Indonesia" -> MITO is a business fact, not
        // something derivable from the name, so known entities are mapped and
        // anything else falls back to its initialism.
        $known = [
            'mahakarya' => 'MITO',
            'stein' => 'MSI',
            'mitra elektro' => 'MEP',
            'perkasa' => 'PII',
        ];

        foreach ($known as $needle => $code) {
            if (str_contains(mb_strtolower($name), $needle)) {
                return $code;
            }
        }

        return $this->normalise($name);
    }

    /** Category code: LAP for laptops, PC for desktops, PRN for printers. */
    public function categoryCode(Asset $asset): string
    {
        $category = mb_strtolower((string) optional($asset->category)->name);
        $model = mb_strtolower((string) $asset->brand . ' ' . $asset->model);

        if (str_contains($category, 'laptop') || str_contains($category, 'notebook') ||
            str_contains($category, 'laptop') || str_contains($model, 'latitude') ||
            str_contains($model, 'thinkpad') || str_contains($model, 'elitebook')) {
            return 'LAP';
        }

        if (str_contains($category, 'printer') || str_contains($category, 'scanner')) {
            return 'PRN';
        }

        if (str_contains($category, 'monitor')) {
            return 'MON';
        }

        if (str_contains($category, 'pc') || str_contains($category, 'desktop')) {
            return 'PC';
        }

        return 'AST';
    }

    /** Uppercase A-Z0-9 only, so a number is always URL and label safe. */
    private function normalise(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]+/', '', $value) ?? '';

        return strtoupper($clean === '' ? 'AST' : $clean);
    }

    /** A stable 32-bit lock key for a prefix. */
    private function lockKey(string $prefix): int
    {
        // crc32 returns an unsigned value on 64-bit PHP; pg advisory locks take
        // a signed int, so fold it into range.
        return (int) (crc32($prefix) % 2147483647);
    }
}
