<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\AssetImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * A template that uses a different header spelling fails silently: analyze()
     * maps only known headers and lists the rest as unmapped, so the operator
     * uploads a full sheet and imports almost nothing without a clear reason.
     */
    public function template()
    {
        $headers = [
            'Asset ID', 'KATEGORI', 'Merk / Model', 'Serial Number', 'Hostname',
            'Spec', 'OS', 'Lokasi', 'User Saat Ini', 'Status Asset', 'Catatan',
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Overall');
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->getStyle('A1:K1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC2626']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->freezePane('A2');

        foreach (range('A', 'K') as $column) {
            $sheet->getColumnDimension($column)->setWidth(24);
        }

        $guide = $spreadsheet->createSheet();
        $guide->setTitle('Petunjuk');
        $guide->fromArray([
            ['Kolom', 'Artinya'],
            ['Asset ID', 'Kode aset unik. Kosongkan jika ingin dibuat otomatis.'],
            ['KATEGORI', 'Misalnya Laptop / PC / Monitor.'],
            ['Merk / Model', 'Ditulis sebagai "Merk / Model", misalnya "LENOVO / ThinkPad T14".'],
            ['Serial Number', 'Nomor seri perangkat.'],
            ['Hostname', 'Wajib diisi.'],
            ['Spec', 'Prosesor / spesifikasi utama.'],
            ['OS', 'Sistem operasi yang terpasang.'],
            ['Lokasi', 'Lokasi aset, misalnya HO atau cabang.'],
            ['User Saat Ini', 'Nama pemegang aset saat ini.'],
            ['Status Asset', 'Misalnya Active / Available / Assigned.'],
            ['Catatan', 'Catatan tambahan (disimpan sebagai catatan perbaikan).'],
            ['', ''],
            ['Catatan', 'Jangan mengubah nama kolom di sheet Overall. Baris pertama adalah header, sisanya satu aset per baris.'],
        ], null, 'A1');
        $guide->getColumnDimension('A')->setWidth(24);
        $guide->getColumnDimension('B')->setWidth(90);

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
