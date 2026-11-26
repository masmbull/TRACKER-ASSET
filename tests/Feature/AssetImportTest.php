<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\AssetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Excel import pipeline (PRD §25, §26, §46): analyse -> preview -> confirm.
 */
class AssetImportTest extends TestCase
{
    use RefreshDatabase;

    private array $header = [
        'Company Name', 'ASSET ID', 'Kategori', 'Merk', 'Model', 'Serial Number', 'Hostname',
        'Processor', 'Storage', 'RAM', 'OS / Versi', 'LOKASI', 'Tahun Pembelian', 'Vendor',
        'Harga (Rp)', 'Masa Pakai (thn)', 'Status Aset', 'Garansi s/d', 'Tanggal Deploy',
        'Lisensi SW/Office', 'MAC Address', 'IP Address (Statis)', 'User Pertama', 'Tgl Mulai (U1)',
        'Tgl Serah (U1→U2)', 'User Kedua', 'Tgl Mulai (U2)', 'Tgl Serah (U2→U3)', 'User Ketiga',
        'Tgl Mulai (U3)', 'Tgl Serah (U3→U4)', 'USER SEKARANG', 'Tgl Mulai (Current)',
        'Tgl Serah Terakhir', 'Admin/IT PIC', 'Riwayat Perbaikan / Catatan', 'Tgl Retire/Disposal',
        'QR', 'BARCODE',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Company::create(['name' => 'PT. Mahakarya Sukses Indonesia', 'code' => 'MSI', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        AssetCategory::create(['name' => 'Other']);
        Location::create(['name' => 'HO', 'status' => 'active']);
        AssetStatus::create(['name' => 'Available']);
    }

    private function row(array $values): array
    {
        return array_map(fn (string $column) => $values[$column] ?? null, $this->header);
    }

    private function workbook(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Details');
        $sheet->fromArray([$this->header, ...$rows], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $path,
            'assets.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function fullRow(array $overrides = []): array
    {
        return $this->row(array_merge([
            'Company Name' => 'PT. Mahakarya Sukses Indonesia',
            'Kategori' => 'Laptop',
            'Merk' => 'Lenovo',
            'Model' => 'X1',
            'LOKASI' => 'HO',
            'Status Aset' => 'Active',
        ], $overrides));
    }

    public function test_analyze_classifies_rows_and_detects_duplicates(): void
    {
        Asset::factory()->create([
            'asset_id' => 'LAP-EXISTING',
            'serial_number' => 'SN-DUP-1',
            'hostname' => 'HOST-DUP-1',
        ]);

        $rows = [
            // Importable, but the current user is unknown -> warning.
            $this->fullRow([
                'ASSET ID' => 'LAP-100',
                'Serial Number' => 'SN-100',
                'Hostname' => 'HOST-100',
                'Tahun Pembelian' => 2024,
                'Harga (Rp)' => 'Rp 12.500.000',
                'Masa Pakai (thn)' => 4,
                'USER SEKARANG' => 'Budi Santoso',
                'Tgl Mulai (Current)' => '2024-01-15',
            ]),
            // Duplicate against the register (serial number exists).
            $this->fullRow(['Serial Number' => 'SN-DUP-1', 'Hostname' => 'HOST-OTHER']),
            // Duplicate inside the file (asset_id of the first row).
            $this->fullRow(['ASSET ID' => 'LAP-100', 'Serial Number' => 'SN-300', 'Hostname' => 'HOST-300']),
            // Error: no identity columns at all.
            $this->row(['Merk' => 'Dell']),
        ];

        $support = User::factory()->create(['role' => 'support']);

        $response = $this->actingAs($support)->post(route('assets.import.analyze'), [
            'file' => $this->workbook($rows),
        ]);

        $response->assertOk();
        $response->assertViewHas('analysis', function (array $analysis): bool {
            return $analysis['summary']['total'] === 4
                && $analysis['summary']['warning'] === 1
                && $analysis['summary']['duplicate'] === 2
                && $analysis['summary']['error'] === 1
                && in_array('serial_number', $analysis['mapped_columns'], true)
                && in_array('useful_life_years', $analysis['mapped_columns'], true);
        });
    }

    public function test_confirm_import_creates_assets_assignments_and_activity_log(): void
    {
        $rows = [
            $this->fullRow([
                'ASSET ID' => 'LAP-100',
                'Serial Number' => 'SN-100',
                'Hostname' => 'HOST-100',
                'Tahun Pembelian' => 2024,
                'Harga (Rp)' => 'Rp 12.500.000',
                'Masa Pakai (thn)' => 4,
                'User Pertama' => 'Agus',
                'Tgl Mulai (U1)' => '2022-03-01',
                'Tgl Serah (U1→U2)' => '2023-12-31',
                'USER SEKARANG' => 'Budi Santoso',
                'Tgl Mulai (Current)' => '2024-01-15',
                'Riwayat Perbaikan / Catatan' => 'Ganti keyboard 2023',
            ]),
            $this->fullRow([
                'Serial Number' => 'SN-200',
                'Hostname' => 'HOST-200',
                'Kategori' => 'PC',
                'Merk' => 'HP',
                'Model' => 'ProDesk',
                'Status Aset' => 'Assigned',
            ]),
        ];

        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('assets.import.analyze'), ['file' => $this->workbook($rows)])->assertOk();
        $response = $this->actingAs($support)->post(route('assets.import.confirm'));

        $response->assertRedirect(route('assets.import'));
        $this->assertDatabaseCount('assets', 2);

        $asset = Asset::where('asset_id', 'LAP-100')->firstOrFail();
        $this->assertSame('Lenovo', $asset->brand);
        $this->assertSame(2024, $asset->purchase_year);
        $this->assertSame(12500000.0, (float) $asset->purchase_price);
        $this->assertSame(4, $asset->useful_life_years);
        $this->assertSame('Ganti keyboard 2023', $asset->repair_notes);

        // Assignment history rebuilt from the legacy "User 1..3 + current" columns.
        $this->assertDatabaseCount('asset_assignments', 2);

        $budi = User::where('name', 'Budi Santoso')->firstOrFail();
        $this->assertFalse((bool) $budi->is_active);
        $this->assertStringContainsString('imported.local', $budi->email);
        $this->assertSame($budi->id, $asset->fresh()->current_user_id);

        // Lookup values were created on the fly.
        $this->assertDatabaseHas('asset_statuses', ['name' => 'Active']);
        $this->assertDatabaseHas('asset_categories', ['name' => 'PC']);

        // Per-asset import logs + summary log.
        $this->assertGreaterThanOrEqual(3, ActivityLog::where('action', 'IMPORT')->count());
    }

    public function test_reimport_skips_duplicates_and_never_overwrites(): void
    {
        $rows = [
            $this->fullRow([
                'ASSET ID' => 'LAP-100',
                'Serial Number' => 'SN-100',
                'Hostname' => 'HOST-100',
            ]),
        ];

        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('assets.import.analyze'), ['file' => $this->workbook($rows)])->assertOk();
        $this->actingAs($support)->post(route('assets.import.confirm'))->assertRedirect(route('assets.import'));

        $this->assertDatabaseCount('assets', 1);

        // Same identity but "changed" brand — must be skipped, not overwritten.
        $rows = [
            $this->fullRow([
                'ASSET ID' => 'LAP-100',
                'Serial Number' => 'SN-100',
                'Hostname' => 'HOST-100',
                'Merk' => 'Dell',
            ]),
        ];

        $this->actingAs($support)->post(route('assets.import.analyze'), ['file' => $this->workbook($rows)])->assertOk();
        $response = $this->actingAs($support)->post(route('assets.import.confirm'));

        $response->assertSessionHas('import_result.created', 0);
        $this->assertDatabaseCount('assets', 1);
        $this->assertSame('Lenovo', Asset::where('asset_id', 'LAP-100')->value('brand'));
    }

    /**
     * The register already held this company, so the sheet's byte order mark must
     * not file it a second time.
     *
     * A sheet exported from Excel prefixes its cells with a UTF-8 BOM, and the
     * importer used to look companies up with a raw equality match. The BOM made
     * the lookup miss, a new company was created, and the register ended up with
     * two rows that render identically - one of them holding real assets.
     */
    public function test_a_bom_prefixed_company_name_reuses_the_existing_company(): void
    {
        $rows = [
            $this->fullRow([
                'ASSET ID' => 'LAP-BOM',
                'Serial Number' => 'SN-BOM',
                'Hostname' => 'HOST-BOM',
                'Company Name' => "\u{FEFF}PT. Mahakarya Sukses Indonesia",
            ]),
        ];

        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('assets.import.analyze'), ['file' => $this->workbook($rows)])->assertOk();
        $this->actingAs($support)->post(route('assets.import.confirm'))->assertRedirect(route('assets.import'));

        $this->assertSame(1, Company::count());
        $this->assertSame(1, Company::where('name', 'PT. Mahakarya Sukses Indonesia')->count());

        $asset = Asset::where('asset_id', 'LAP-BOM')->firstOrFail();
        $this->assertSame('PT. Mahakarya Sukses Indonesia', $asset->company->name);
    }

    /**
     * Count()+1 stops being a free code the moment a company is deleted.
     *
     * Deleting one drops the count while the codes stay put, so the next import
     * asks for a code that is already taken and dies on the unique index. That
     * is a 500 in the middle of a spreadsheet import, with no clue in the report
     * about which row caused it.
     */
    public function test_a_deleted_company_does_not_make_the_next_import_collide(): void
    {
        Company::create(['name' => 'Alpha', 'code' => 'CMP004', 'address' => '']);
        Company::create(['name' => 'Beta', 'code' => 'CMP005', 'address' => '']);
        Company::create(['name' => 'Gamma', 'code' => 'CMP006', 'address' => '']);

        // Leaves three companies holding codes 004, 005 and 006. count() + 1
        // would now answer CMP004, which is taken.
        Company::where('code', 'CMP005')->delete();

        $rows = [
            $this->fullRow([
                'ASSET ID' => 'LAP-NEWCO',
                'Serial Number' => 'SN-NEWCO',
                'Hostname' => 'HOST-NEWCO',
                'Company Name' => 'Delta',
            ]),
        ];

        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($support)->post(route('assets.import.analyze'), ['file' => $this->workbook($rows)])->assertOk();
        $this->actingAs($support)->post(route('assets.import.confirm'))->assertRedirect(route('assets.import'));

        $this->assertDatabaseCount('assets', 1);
        $this->assertSame('CMP007', Company::where('name', 'Delta')->value('code'));
    }

    public function test_viewer_cannot_access_import(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('assets.import'))->assertForbidden();
        $this->actingAs($viewer)->post(route('assets.import.analyze'), [
            'file' => $this->workbook([$this->row([])]),
        ])->assertForbidden();
    }

    public function test_template_uses_the_it_assets_columns(): void
    {
        $support = User::factory()->create(['role' => 'support']);

        $response = $this->actingAs($support)->get(route('assets.import.template'));
        $response->assertOk()->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->getContent());

        $sheet = IOFactory::load($path)->getActiveSheet();
        $headers = array_values($sheet->toArray(null, true, true, false)[0]);

        $this->assertSame([
            'Asset ID', 'KATEGORI', 'Merk / Model', 'Serial Number', 'Hostname',
            'Spec', 'OS', 'Lokasi', 'User Saat Ini', 'Status Asset', 'Catatan',
        ], $headers);
    }

    public function test_it_assets_headers_all_map_to_import_fields(): void
    {
        $analysis = app(AssetImportService::class)->analyze(base_path('IT Assets.xlsx'));

        $this->assertSame('Overall', $analysis['sheet']);
        $this->assertSame([], $analysis['unmapped_columns']);
        $this->assertContains('status_name', $analysis['mapped_columns']);
    }
}
