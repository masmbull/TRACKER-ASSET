<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\AssetImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
