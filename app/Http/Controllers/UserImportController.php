<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\UserImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * People directory import. A workbook with Full Name + Office Email creates
 * or updates users (matched by email); an optional Asset ID also assigns the
 * user as that asset's current holder.
 */
class UserImportController extends Controller
{
    public function __construct(private readonly UserImportService $importer) {}

    public function show()
    {
        return view('users.import', [
            'analysis' => null,
            'stagedName' => Session::get('user_import.name'),
            'result' => Session::pull('import_result'),
        ]);
    }

    /**
     * Blank workbook that matches the columns the importer understands.
     */
    public function template()
    {
        $headers = ['Full Name', 'Office Email', 'Asset ID'];
        $guide = [
            ['Kolom', 'Wajib?', 'Artinya'],
            ['Full Name', 'Wajib', 'Nama lengkap karyawan. Jangan kosong.'],
            ['Office Email', 'Wajib', 'Email kantor. Dipakai sebagai kunci pencocokan (huruf besar/kecil diabaikan).'],
            ['Asset ID', 'Opsional', 'Kode aset di TRACKER-ASSET. Jika diisi, user ini langsung jadi pemegang aset tersebut.'],
            ['', '', ''],
            ['Catatan', '', 'Baris pertama adalah header. Satu user per baris. Kosongkan Asset ID jika hanya ingin membuat/update user.'],
        ];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Users');
        $sheet->fromArray([$headers], null, 'A1');
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B91C1C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(26);
        $sheet->freezePane('A2');
        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(40);
        $sheet->getColumnDimension('C')->setWidth(24);

        $info = $spreadsheet->createSheet();
        $info->setTitle('Petunjuk');
        $info->fromArray($guide, null, 'A1');
        $info->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B91C1C']],
        ]);
        $info->getColumnDimension('A')->setWidth(20);
        $info->getColumnDimension('B')->setWidth(12);
        $info->getColumnDimension('C')->setWidth(100);

        $spreadsheet->setActiveSheetIndex(0);

        ob_start();
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save('php://output');
        $content = ob_get_clean();

        return response($content, 200)
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="tracker-users-import-template.xlsx"')
            ->header('Cache-Control', 'no-store');
    }

    public function analyze(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:20480',
        ]);

        $uploaded = $request->file('file');
        $directory = (string) config('users-import.staging_directory', 'imports');
        $storeName = Str::slug(pathinfo($uploaded->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'users';
        $storeName .= '-'.now()->format('YmdHis').'.'.strtolower($uploaded->getClientOriginalExtension() ?: 'xlsx');
        $path = $uploaded->storeAs($directory, $storeName);

        if ($previous = Session::get('user_import.file')) {
            Storage::delete($previous);
        }

        Session::put('user_import.file', $path);
        Session::put('user_import.name', $uploaded->getClientOriginalName());

        $analysis = $this->importer->analyze(Storage::path($path));

        return view('users.import', [
            'analysis' => $analysis,
            'stagedName' => Session::get('user_import.name'),
            'result' => null,
        ]);
    }

    public function confirm()
    {
        $path = (string) Session::get('user_import.file', '');

        if ($path === '' || ! Storage::exists($path)) {
            abort(419, 'The uploaded file has expired. Please upload it again.');
        }

        $result = $this->importer->import(Storage::path($path), auth()->id());

        ActivityLog::create([
            'actor_id' => auth()->id(),
            'action' => 'IMPORT',
            'entity_type' => 'users',
            'entity_identifier' => Session::get('user_import.name'),
            'field_changed' => null,
            'old_value' => null,
            'new_value' => json_encode($result),
            'source' => 'Import',
        ]);

        Storage::delete($path);
        Session::forget(['user_import.file', 'user_import.name']);

        return redirect()->route('users.index')->with('import_result', $result);
    }
}
