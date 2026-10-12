<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetCodeGenerator
{
    /** @var array<string, string> */
    public const ENTITY_CODES = [
        'MSI' => 'PT. MAHAKARYA SUKSES INDONESIA',
        'SPI' => 'PT. STEIN PERKASA INTERNASIONAL',
        'MEP' => 'PT. MITRA ELEKTRO PERKASA',
        'PII' => 'PT. PERKASA INJEKSI INDONESIA',
        'SDI' => 'PT. SUKA DESSERT INDONESIA',
    ];

    /** @var array<string, string> */
    private const CATEGORY_CODES = [
        'laptop' => 'LAP',
        'pc' => 'PC',
        'desktop' => 'PC',
        'server' => 'SRV',
        'printer' => 'PRN',
        'monitor' => 'MON',
        'network' => 'NET',
        'cctv' => 'CCTV',
        'ups' => 'UPS',
        'storage' => 'STO',
        'other' => 'OTH',
    ];

    public function next(Company $company, AssetCategory $category, int $purchaseYear): string
    {
        $entityCode = strtoupper(trim($company->code ?: ''));

        if ($entityCode === '') {
            throw ValidationException::withMessages([
                'company_id' => 'A company code is required to generate the asset code.',
            ]);
        }

        $categoryCode = $this->categoryCode($category->name);
        $sequence = [
            'entity_code' => $entityCode,
            'category_code' => $categoryCode,
            'purchase_year' => $purchaseYear,
        ];

        DB::table('asset_code_sequences')->insertOrIgnore([
            ...$sequence,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counter = DB::table('asset_code_sequences')
            ->where($sequence)
            ->lockForUpdate()
            ->first();

        $lastNumber = (int) $counter->last_number;

        if ($lastNumber === 0) {
            $lastNumber = $this->highestImportedNumber($entityCode, $categoryCode, $purchaseYear);
        }

        $nextNumber = $lastNumber + 1;

        DB::table('asset_code_sequences')
            ->where($sequence)
            ->update([
                'last_number' => $nextNumber,
                'updated_at' => now(),
            ]);

        return sprintf('%s-%s-%d-%04d', $entityCode, $categoryCode, $purchaseYear, $nextNumber);
    }

    private function categoryCode(string $categoryName): string
    {
        $normalized = mb_strtolower(trim($categoryName));

        if (isset(self::CATEGORY_CODES[$normalized])) {
            return self::CATEGORY_CODES[$normalized];
        }

        $words = preg_split('/[^a-z0-9]+/i', strtoupper($categoryName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $code = count($words) > 1
            ? implode('', array_map(fn (string $word): string => $word[0], array_slice($words, 0, 4)))
            : substr($words[0] ?? '', 0, 4);

        if ($code === '') {
            throw ValidationException::withMessages([
                'category_id' => 'This asset category cannot be used in an asset code.',
            ]);
        }

        return $code;
    }

    private function highestImportedNumber(string $entityCode, string $categoryCode, int $purchaseYear): int
    {
        $prefix = "{$entityCode}-{$categoryCode}-{$purchaseYear}-";

        return Asset::query()
            ->where('asset_id', 'like', $prefix.'%')
            ->pluck('asset_id')
            ->map(function (string $assetId) use ($prefix): int {
                $suffix = substr($assetId, strlen($prefix));

                return ctype_digit($suffix) ? (int) $suffix : 0;
            })
            ->max() ?? 0;
    }
}
