<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\Company;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporting (PRD §43 "Reports"): management summary over the asset register.
 */
class ReportController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('export-assets');

        $company = $this->filterValue($request, 'company', Company::class);
        $location = $this->filterValue($request, 'location', Location::class);

        $base = Asset::query()
            ->when($company, fn ($q) => $q->where('company_id', $company))
            ->when($location, fn ($q) => $q->where('location_id', $location));

        $totalAssets = (clone $base)->count();
        $totalValue = (clone $base)->sum('purchase_price');

        $byCategory = $this->breakdown($base, 'asset_categories', 'category_id', 'category_id');
        $byStatus = $this->breakdown($base, 'asset_statuses', 'asset_status_id', 'asset_status_id');
        $byLocation = $this->breakdown($base, 'locations', 'location_id', 'location_id');
        $byCompany = $this->breakdown($base, 'companies', 'company_id', 'company_id');

        $retirementDue = (clone $base)
            ->whereNotNull('purchase_year')
            ->whereRaw('(purchase_year + coalesce(useful_life_years, 0)) <= ?', [now()->year])
            ->count();

        $neverAudited = (clone $base)->doesntHave('audits')->count();
        $auditedLast12Months = (clone $base)
            ->whereHas('audits', fn ($q) => $q->where('audit_date', '>=', now()->subYear()))
            ->count();
        $openMismatches = AssetAudit::where('result', '!=', 'MATCH')
            ->when($company || $location, fn ($q) => $q->whereIn('asset_id', (clone $base)->select('id')))
            ->distinct()
            ->count('asset_id');

        $repairCostThisYear = (clone $base)
            ->join('asset_repairs', 'asset_repairs.asset_id', '=', 'assets.id')
            ->whereYear('asset_repairs.date', now()->year)
            ->sum('asset_repairs.cost');

        $companies = Company::orderBy('name')->get();
        $locations = Location::orderBy('name')->get();

        return view('reports.index', compact(
            'totalAssets', 'totalValue', 'byCategory', 'byStatus', 'byLocation', 'byCompany',
            'retirementDue', 'neverAudited', 'auditedLast12Months', 'openMismatches',
            'repairCostThisYear', 'companies', 'locations'
        ));
    }

    /**
     * CSV export of the summary breakdowns.
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('export-assets');

        $company = $this->filterValue($request, 'company', Company::class);
        $location = $this->filterValue($request, 'location', Location::class);

        $base = Asset::query()
            ->when($company, fn ($q) => $q->where('company_id', $company))
            ->when($location, fn ($q) => $q->where('location_id', $location));

        $sections = [
            'BY CATEGORY' => $this->breakdown($base, 'asset_categories', 'category_id', 'category_id'),
            'BY STATUS' => $this->breakdown($base, 'asset_statuses', 'asset_status_id', 'asset_status_id'),
            'BY LOCATION' => $this->breakdown($base, 'locations', 'location_id', 'location_id'),
            'BY COMPANY' => $this->breakdown($base, 'companies', 'company_id', 'company_id'),
        ];

        $fileName = 'asset_report_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($sections, $base) {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['SECTION', 'NAME', 'ASSETS', 'TOTAL VALUE (IDR)']);

            foreach ($sections as $title => $rows) {
                fputcsv($out, [$title, '', '', '']);

                foreach ($rows as $row) {
                    fputcsv($out, ['', $row->name, $row->total, $row->value]);
                }
            }

            fputcsv($out, ['TOTAL', '', $base->count(), $base->sum('purchase_price')]);

            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv']);
    }

    private function breakdown($base, string $lookupTable, string $foreignKey, string $selectColumn)
    {
        return (clone $base)
            ->join($lookupTable, "{$lookupTable}.id", '=', "assets.{$foreignKey}")
            ->select(
                "{$lookupTable}.name as name",
                DB::raw('count(*) as total'),
                DB::raw('coalesce(sum(assets.purchase_price), 0) as value'),
            )
            ->groupBy("{$lookupTable}.name")
            ->orderByDesc('total')
            ->get();
    }

    private function filterValue(Request $request, string $key, string $model): ?int
    {
        $value = $request->input($key);

        if (! $value || ! $model::where('id', $value)->exists()) {
            return null;
        }

        return (int) $value;
    }
}
