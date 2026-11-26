<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\AssetImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Excel import UI (PRD §25): upload -> map -> validate -> preview -> duplicates -> confirm.
 */
class AssetImportController extends Controller
{
    public function __construct(private readonly AssetImportService $importer) {}

    public function show()
    {
        return view('imports.assets', [
            'analysis' => null,
            'stagedName' => Session::get('asset_import.name'),
            'result' => Session::pull('import_result'),
        ]);
    }

    /**
     * Blank workbook with exactly the columns of the office's IT Assets sheet.
     *
     * The real register lives in the "Details" sheet of IT Assets.xlsx and has
     * 39 legacy columns. A template that uses a different header spelling fails
     * silently: analyze() maps only known headers and lists the rest as
     * unmapped, so the operator uploads a full sheet and imports almost nothing
     * without a clear reason. This template is generated from the same header
     * list the importer understands, so every column maps cleanly.
     */
    public function template()
    {
        // Keep in sync with IT Assets.xlsx, sheet "Details" (39 columns).
        $headers = [
            'Company Name', 'ASSET ID', 'Kategori', 'Merk', 'Model', 'Serial Number',
            'Hostname', 'Processor', 'Storage', 'RAM', 'OS / Versi', 'LOKASI',
            'Tahun Pembelian', 'Vendor', 'Harga (Rp)', 'Masa Pakai (thn)', 'Status Aset',
            'Garansi s/d', 'Tanggal Deploy', 'Lisensi SW/Office', 'MAC Address',
            'IP Address (Statis)', 'User Pertama', 'Tgl Mulai (U1)', 'Tgl Serah (U1→U2)',
            'User Kedua', 'Tgl Mulai (U2)', 'Tgl Serah (U2→U3)', 'User Ketiga',
            'Tgl Mulai (U3)', 'Tgl Serah (U3→U4)', 'USER SEKARANG', 'Tgl Mulai (Current)',
            'Tgl Serah Terakhir', 'Admin/IT PIC', 'Riwayat Perbaikan / Catatan',
            'Tgl Retire/Disposal', 'QR', 'BARCODE',
        ];

        $guide = [
            ['Kolom', 'Artinya'],
            ['Company Name', 'Nama perusahaan. Boleh kosong — memakai perusahaan bawaan.'],
            ['ASSET ID', 'Kode aset unik. Kosongkan jika ingin dibuat otomatis.'],
            ['Kategori', 'Misalnya Laptop / PC / Monitor.'],
            ['Merk', 'Merek perangkat, misalnya LENOVO.'],
            ['Model', 'Model perangkat, misalnya ThinkPad T14.'],
            ['Serial Number', 'Nomor seri perangkat.'],
            ['Hostname', 'Nama host perangkat.'],
            ['Processor', 'Spesifikasi prosesor.'],
            ['Storage', 'Kapasitas penyimpanan.'],
            ['RAM', 'Kapasitas memori.'],
            ['OS / Versi', 'Sistem operasi yang terpasang.'],
            ['LOKASI', 'Lokasi aset, misalnya HO atau cabang.'],
            ['Tahun Pembelian', 'Tahun pembelian (angka, misalnya 2025).'],
            ['Vendor', 'Nama vendor / supplier.'],
            ['Harga (Rp)', 'Harga dalam rupiah.'],
            ['Masa Pakai (thn)', 'Masa pakai dalam tahun.'],
            ['Status Aset', 'Misalnya Active / Available / Assigned.'],
            ['Garansi s/d', 'Tanggal berakhirnya garansi.'],
            ['Tanggal Deploy', 'Tanggal perangkat mulai dipakai.'],
            ['Lisensi SW/Office', 'Lisensi perangkat lunak / Office.'],
            ['MAC Address', 'Alamat MAC perangkat.'],
            ['IP Address (Statis)', 'Alamat IP statis perangkat.'],
            ['User Pertama', 'Nama pemegang aset pertama.'],
            ['Tgl Mulai (U1)', 'Tanggal mulai pemegang pertama.'],
            ['Tgl Serah (U1→U2)', 'Tanggal serah dari pemegang 1 ke 2.'],
            ['User Kedua', 'Nama pemegang aset kedua.'],
            ['Tgl Mulai (U2)', 'Tanggal mulai pemegang kedua.'],
            ['Tgl Serah (U2→U3)', 'Tanggal serah dari pemegang 2 ke 3.'],
            ['User Ketiga', 'Nama pemegang aset ketiga.'],
            ['Tgl Mulai (U3)', 'Tanggal mulai pemegang ketiga.'],
            ['Tgl Serah (U3→U4)', 'Tanggal serah dari pemegang 3 ke 4.'],
            ['USER SEKARANG', 'Nama pemegang aset saat ini.'],
            ['Tgl Mulai (Current)', 'Tanggal mulai pemegang saat ini.'],
            ['Tgl Serah Terakhir', 'Tanggal serah terakhir.'],
            ['Admin/IT PIC', 'Nama admin / IT PIC.'],
            ['Riwayat Perbaikan / Catatan', 'Catatan perbaikan atau catatan tambahan.'],
            ['Tgl Retire/Disposal', 'Tanggal aset di-retire / dihapus.'],
            ['QR', 'Kode QR yang sudah ada (biasanya kosong).'],
            ['BARCODE', 'Barcode yang sudah ada (biasanya kosong).'],
            ['', ''],
            ['Catatan', 'Jangan mengubah nama kolom di sheet Details. Baris pertama adalah header, sisanya satu aset per baris.'],
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Details');
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->freezePane('A2');

        foreach ($headers as $index => $header) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))
                ->setWidth(max(16, min(32, strlen($header) + 4)));
        }

        $info = $spreadsheet->createSheet();
        $info->setTitle('Petunjuk');
        $info->fromArray($guide, null, 'A1');
        $info->getStyle('A1:B1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
        ]);
        $info->getColumnDimension('A')->setWidth(36);
        $info->getColumnDimension('B')->setWidth(90);

        // Open the workbook on the data sheet, not the guide sheet.
        $spreadsheet->setActiveSheetIndex(0);

        ob_start();
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        $content = ob_get_clean();

        return response($content, 200)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="tracker-asset-import-template.xlsx"')
            ->header('Cache-Control', 'no-store');
    }

    public function analyze(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480',
        ]);

        $uploaded = $request->file('file');
        $directory = (string) config('asset-import.staging_directory', 'imports');
        $storeName = Str::slug(pathinfo($uploaded->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'import';
        $storeName .= '-'.now()->format('YmdHis').'.'.strtolower($uploaded->getClientOriginalExtension() ?: 'xlsx');

        $path = $uploaded->storeAs($directory, $storeName);

        if ($previous = Session::get('asset_import.file')) {
            Storage::delete($previous);
        }

        Session::put('asset_import.file', $path);
        Session::put('asset_import.name', $uploaded->getClientOriginalName());

        $analysis = $this->importer->analyze(Storage::path($path));

        return view('imports.assets', [
            'analysis' => $analysis,
            'stagedName' => $uploaded->getClientOriginalName(),
            'result' => null,
        ]);
    }

    public function confirm()
    {
        $path = (string) Session::get('asset_import.file', '');

        abort_unless($path !== '' && Storage::exists($path), 419, 'Upload session expired. Please upload the file again.');

        $name = (string) Session::get('asset_import.name', basename($path));

        // Re-analyse right before writing so duplicates that appeared meanwhile are skipped.
        $analysis = $this->importer->analyze(Storage::path($path));
        $result = $this->importer->import($analysis);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'IMPORT',
            'entity_type' => 'assets',
            'entity_identifier' => Str::limit($name, 250, ''),
            'metadata' => [
                'summary' => $analysis['summary'],
                'result' => $result,
            ],
            'source' => 'Import',
        ]);

        Storage::delete($path);
        Session::forget(['asset_import.file', 'asset_import.name']);

        return redirect()
            ->route('assets.import')
            ->with('import_result', $result + ['file' => $name])
            ->with('success', "Import finished — {$result['created']} assets created, {$result['skipped']} skipped.");
    }
}
