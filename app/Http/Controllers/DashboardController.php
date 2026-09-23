<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\AssetRepair;
use App\Models\AssetTransfer;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $statusCounts = Asset::query()
            ->join('asset_statuses', 'asset_statuses.id', '=', 'assets.asset_status_id')
            ->select('asset_statuses.name as status_name', DB::raw('count(*) as total'))
            ->groupBy('asset_statuses.name')
            ->pluck('total', 'status_name');

        $totalAssets = Asset::count();
        $activeAssets = (int) ($statusCounts['Active'] ?? 0);
        $availableAssets = (int) ($statusCounts['Available'] ?? 0);
        $assignedAssets = (int) ($statusCounts['Assigned'] ?? 0);
        $repairAssets = (int) ($statusCounts['Repair'] ?? 0);
        $retiredAssets = (int) ($statusCounts['Retired'] ?? 0);
        $disposedAssets = (int) ($statusCounts['Disposed'] ?? 0);

        $openRepairs = AssetRepair::whereIn('status', ['open', 'in_progress'])->count();
        $totalRepairs = AssetRepair::count();

        $recentTransfers = AssetTransfer::with(['fromUser', 'toUser'])
            ->orderByDesc('transfer_date')
            ->limit(5)
            ->get();

        $assetsByCategory = $this->groupCount('asset_categories', 'category_id');
        $assetsByLocation = $this->groupCount('locations', 'location_id');
        $assetsByCompany = $this->groupCount('companies', 'company_id');

        $warnings = $this->warnings();

        $fleet = $this->fleetStatus();

        $recentActivity = ActivityLog::with('actor')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return view('dashboard.index', compact(
            'totalAssets', 'activeAssets', 'availableAssets', 'assignedAssets', 'repairAssets',
            'retiredAssets', 'disposedAssets',
            'openRepairs', 'totalRepairs', 'recentTransfers',
            'assetsByCategory', 'assetsByLocation', 'assetsByCompany', 'statusCounts',
            'warnings', 'fleet', 'recentActivity'
        ));
    }

    /**
     * Agent fleet health (PRD §11): how many resident agents are reporting.
     *
     * @return array{online:int, stale:int, offline:int, pending_commands:int, recent_audits:int}
     */
    private function fleetStatus(): array
    {
        $tokens = AgentApiToken::where('is_active', true)->get(['id', 'last_used_at']);

        $online = 0;
        $stale = 0;

        foreach ($tokens as $token) {
            if ($token->last_used_at === null) {
                continue;
            }

            if ($token->last_used_at->gt(now()->subMinutes(5))) {
                $online++;
            } else {
                $stale++;
            }
        }

        return [
            'online' => $online,
            'stale' => $stale,
            'offline' => $tokens->count() - $online - $stale,
            'pending_commands' => AgentCommand::where('status', 'pending')->count(),
            'recent_audits' => ActivityLog::where('source', 'Audit Agent')
                ->where('created_at', '>=', now()->subDay())
                ->count(),
        ];
    }

    /**
     * Count assets grouped by a related lookup table (PRD §21 charts).
     */
    private function groupCount(string $lookupTable, string $foreignKey)
    {
        return Asset::query()
            ->join($lookupTable, "{$lookupTable}.id", '=', "assets.{$foreignKey}")
            ->select("{$lookupTable}.name as name", DB::raw('count(*) as total'))
            ->groupBy("{$lookupTable}.name")
            ->orderByDesc('total')
            ->get();
    }

    /**
     * Dashboard warnings (PRD §21).
     */
    private function warnings(): array
    {
        $duplicateSerials = Asset::query()
            ->select('serial_number', DB::raw('count(*) as total'))
            ->whereNotNull('serial_number')
            ->where('serial_number', '!=', '')
            ->where('serial_number', '!=', 'N/A')
            ->groupBy('serial_number')
            ->havingRaw('count(*) > 1')
            ->get();

        return [
            'warranty_expiring' => Asset::whereNotNull('warranty_until')
                ->whereBetween('warranty_until', [now()->startOfDay(), now()->addDays(60)])
                ->count(),
            'not_audited' => Asset::doesntHave('audits')->count(),
            'audit_mismatch' => AssetAudit::where('result', '!=', 'MATCH')
                ->distinct()
                ->count('asset_id'),
            'missing_serial' => Asset::where(fn ($q) => $q->whereNull('serial_number')->orWhere('serial_number', ''))->count(),
            'missing_asset_id' => Asset::where(fn ($q) => $q->whereNull('asset_id')->orWhere('asset_id', ''))->count(),
            'duplicate_serial' => $duplicateSerials->count(),
            'retirement_due' => Asset::whereNotNull('purchase_year')
                ->whereRaw('(purchase_year + coalesce(useful_life_years, 0)) <= ?', [now()->year])
                ->count(),
            'audit_stale' => Asset::has('audits')
                ->whereDoesntHave('audits', fn ($q) => $q->where('audit_date', '>=', now()->subMonths(6)))
                ->count(),
        ];
    }
}
