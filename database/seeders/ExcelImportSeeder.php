<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Support\NameNormalizer;
use Illuminate\Database\Seeder;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ExcelImportSeeder extends Seeder
{
    public function run(): void
    {
        $this->ensureLookupTables();

        $excelPath = base_path('IT Assets.xlsx');

        if (! file_exists($excelPath)) {
            $this->command->warn("Excel file not found: {$excelPath}");

            return;
        }

        try {
            $spreadsheet = IOFactory::load($excelPath);
            $sheet = $spreadsheet->getSheetByName('Details');

            if (! $sheet) {
                $sheets = $spreadsheet->getAllSheets();
                if (count($sheets) > 1) {
                    $sheet = $sheets[1];
                } else {
                    $this->command->warn('Details sheet not found');

                    return;
                }
            }

            $rows = $sheet->toArray();
            $header = array_shift($rows);

            $validRows = [];
            foreach ($rows as $row) {
                $row = array_combine($header, $row);
                $serial = $row['Serial Number'] ?? null;
                $hostname = $row['Hostname'] ?? null;

                if (empty($serial) && empty($hostname)) {
                    continue;
                }

                $validRows[] = $row;
            }

            $created = 0;
            $imported = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($validRows as $row) {
                $serial = $row['Serial Number'] ?? null;
                $hostname = $row['Hostname'] ?? null;

                try {
                    // Normalised before the lookup: this seeder read the sheet
                    // straight into where('name', ...), so a BOM-prefixed cell
                    // missed the seeded company and created a second one.
                    $companyName = NameNormalizer::normalize($row['Company Name'] ?? null)
                        ?? 'PT. Mahakarya Sukses Indonesia';
                    $company = Company::query()->whereNameIs($companyName)->first();
                    if (! $company) {
                        $company = Company::create([
                            'name' => $companyName,
                            'code' => $this->nextCompanyCode(),
                            'address' => '',
                        ]);
                    }

                    $categoryName = $row['Kategori'] ?? 'Laptop';
                    $category = AssetCategory::firstOrCreate(['name' => $categoryName]);

                    $statusName = $row['Status Aset'] ?? 'Available';
                    $status = AssetStatus::firstOrCreate(['name' => $statusName]);

                    $locationName = $row['LOKASI'] ?? 'HO';
                    $location = Location::firstOrCreate(['name' => $locationName], [
                        'branch' => 'Main', 'type' => 'office', 'address' => '', 'status' => 'active',
                    ]);

                    $assetId = $row['ASSET ID'] ?? 'AST-'.str_replace([' ', '-', '(', ')', '/'], '', $hostname).'-'.($imported + 1);

                    if (Asset::where('asset_id', $assetId)->exists()) {
                        $skipped++;
                        $imported++;

                        continue;
                    }

                    Asset::create([
                        'asset_id' => $assetId,
                        'company_id' => $company->id,
                        'category_id' => $category->id,
                        'brand' => $row['Merk'] ?? 'N/A',
                        'model' => $row['Model'] ?? 'N/A',
                        'serial_number' => $serial ?: 'N/A',
                        'hostname' => $hostname,
                        'processor' => $row['Processor'] ?? null,
                        'storage' => $row['Storage'] ?? null,
                        'ram' => $row['RAM'] ?? null,
                        'os_version' => $row['OS / Versi'] ?? null,
                        'location_id' => $location->id,
                        'purchase_year' => $row['Tahun Pembelian'] ?? null,
                        'vendor' => $row['Vendor'] ?? null,
                        'purchase_price' => $row['Harga (Rp)'] ?? null,
                        'useful_life_years' => $row['Masa Pakai (thn)'] ?? null,
                        'asset_status_id' => $status->id,
                        'warranty_until' => $row['Garansi s/d'] ?? null,
                        'deploy_date' => $row['Tanggal Deploy'] ?? null,
                        'software_license' => $row['Lisensi SW/Office'] ?? null,
                        'mac_address' => $row['MAC Address'] ?? null,
                        'static_ip' => $row['IP Address (Statis)'] ?? null,
                    ]);

                    $created++;
                    $imported++;
                } catch (\Exception $e) {
                    $errors++;
                    if ($errors <= 3) {
                        $this->command->error('Row error: '.$e->getMessage());
                    }
                }
            }

            $this->command->info("Imported: {$created} assets, Skipped: {$skipped}, Errors: {$errors}");
        } catch (\Exception $e) {
            $this->command->error('Import failed: '.$e->getMessage());
        }
    }

    private function ensureLookupTables(): void
    {
        $categories = ['Laptop', 'PC', 'Server', 'Printer', 'Monitor', 'Network', 'CCTV', 'UPS', 'Storage', 'Other'];
        foreach ($categories as $cat) {
            AssetCategory::firstOrCreate(['name' => $cat]);
        }

        $statuses = ['Active', 'Available', 'Assigned', 'Repair', 'Spare', 'Lost', 'Retired', 'Disposed'];
        foreach ($statuses as $status) {
            AssetStatus::firstOrCreate(['name' => $status]);
        }

        Location::firstOrCreate(['name' => 'HO', 'branch' => 'Main', 'type' => 'office', 'address' => '', 'status' => 'active']);
        Location::firstOrCreate(['name' => 'Jakarta HQ', 'branch' => 'Main', 'type' => 'office', 'address' => '', 'status' => 'active']);
    }

    /**
     * The next unused CMPnnn code.
     *
     * Count()+1 collides once any company is deleted, because the count drops
     * while the codes do not. Highest-number-in-use cannot be overtaken that way.
     */
    private function nextCompanyCode(): string
    {
        $highest = 0;

        foreach (Company::pluck('code') as $code) {
            if (preg_match('/^CMP0*(\d+)$/i', (string) $code, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'CMP'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }
}
