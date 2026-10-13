<?php

namespace App\Http\Controllers;

use App\Exports\AssetsExport;
use App\Models\ActivityLog;
use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetAudit;
use App\Models\AssetCategory;
use App\Models\AssetRepair;
use App\Models\AssetStatus;
use App\Models\AssetTransfer;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\AuditComparisonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class AssetController extends Controller
{
    /**
     * Columns the asset list may be sorted by (PRD §22).
     */
    private const SORTABLE = [
        'asset_id', 'serial_number', 'hostname', 'brand', 'model',
        'purchase_year', 'created_at', 'updated_at',
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Asset::class);

        $sort = in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'updated_at';
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';

        $assets = $this->filteredQuery($request)
            ->orderBy($sort, $direction)
            ->paginate(20)
            ->withQueryString();

        $categories = AssetCategory::orderBy('name')->get();
        $statuses = AssetStatus::orderBy('name')->get();
        $companies = Company::orderBy('name')->get();
        $locations = Location::orderBy('name')->get();

        return view('assets.index', compact('assets', 'categories', 'statuses', 'companies', 'locations'));
    }

    /**
     * Shared search/filter query used by the list and the Excel export (PRD §7, §22).
     *
     * Search covers: Asset ID, Serial, Hostname, User, Email, Department,
     * Location, Company, Category, Status, MAC, IP.
     */
    private function filteredQuery(Request $request)
    {
        $query = Asset::query()->with(['category', 'status', 'location', 'currentUser', 'company', 'adminItPic']);

        if ($request->input('archived') !== 'include') {
            $query->where('assets.is_active', true);
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $like = "%{$search}%";

            $query->where(function ($q) use ($like) {
                $q->where('asset_id', 'like', $like)
                    ->orWhere('serial_number', 'like', $like)
                    ->orWhere('hostname', 'like', $like)
                    ->orWhere('brand', 'like', $like)
                    ->orWhere('model', 'like', $like)
                    ->orWhere('mac_address', 'like', $like)
                    ->orWhere('static_ip', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('qr_code', 'like', $like)
                    ->orWhereHas('category', fn ($sub) => $sub->where('name', 'like', $like))
                    ->orWhereHas('status', fn ($sub) => $sub->where('name', 'like', $like))
                    ->orWhereHas('company', fn ($sub) => $sub
                        ->where('name', 'like', $like)
                        ->orWhere('code', 'like', $like))
                    ->orWhereHas('location', fn ($sub) => $sub
                        ->where('name', 'like', $like)
                        ->orWhere('branch', 'like', $like))
                    ->orWhereHas('currentUser', fn ($sub) => $sub
                        ->where('name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('department', 'like', $like));
            });
        }

        if ($request->filled('status')) {
            $query->where('asset_status_id', $request->input('status'));
        }

        if ($request->filled('category')) {
            $query->where('category_id', $request->input('category'));
        }

        if ($request->filled('company')) {
            $query->where('company_id', $request->input('company'));
        }

        if ($request->filled('location')) {
            $query->where('location_id', $request->input('location'));
        }

        return $query;
    }

    public function create()
    {
        $this->authorize('create', Asset::class);

        $companies = Company::all();
        $categories = AssetCategory::all();
        $locations = Location::all();
        $statuses = AssetStatus::all();
        $users = User::all();

        return view('assets.create', compact('companies', 'categories', 'locations', 'statuses', 'users'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Asset::class);

        $validated = $request->validate([
            'asset_id' => 'required|unique:assets',
            'company_id' => 'required|exists:companies,id',
            'category_id' => 'required|exists:asset_categories,id',
            'brand' => 'required|string',
            'model' => 'required|string',
            'serial_number' => 'required|string',
            'hostname' => 'required|string',
            'location_id' => 'required|exists:locations,id',
            'purchase_year' => 'nullable|integer',
            'vendor' => 'nullable|string',
            'purchase_price' => 'nullable|numeric',
            'useful_life_years' => 'nullable|integer',
            'asset_status_id' => 'required|exists:asset_statuses,id',
            'warranty_until' => 'nullable|date',
            'deploy_date' => 'nullable|date',
            'software_license' => 'nullable|string',
            'mac_address' => 'nullable|string',
            'static_ip' => 'nullable|string',
            'current_user_id' => 'nullable|exists:users,id',
            'admin_it_pic' => 'nullable|exists:users,id',
            'repair_notes' => 'nullable|string',
            'retire_date' => 'nullable|date',
            'qr_code' => 'nullable|string',
            'barcode' => 'nullable|string',
        ]);

        $asset = Asset::create($validated);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'CREATE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'source' => 'Web UI',
            'old_value' => null,
            'new_value' => json_encode($validated),
        ]);

        return redirect()->route('assets.index')->with('success', 'Asset created successfully.');
    }

    public function show(Asset $asset)
    {
        $this->authorize('view', $asset);

        $asset->load(['category', 'status', 'location', 'currentUser', 'company', 'assignments', 'audits', 'repairs', 'transfers', 'documents', 'attachments.uploader', 'activityLogs', 'inspectionSessions.lines']);

        return view('assets.show', compact('asset'));
    }

    public function edit(Asset $asset)
    {
        $this->authorize('update', $asset);

        $companies = Company::all();
        $categories = AssetCategory::all();
        $locations = Location::all();
        $statuses = AssetStatus::all();
        $users = User::all();

        $asset->load(['category', 'status', 'location', 'currentUser', 'company']);

        return view('assets.edit', compact('asset', 'companies', 'categories', 'locations', 'statuses', 'users'));
    }

    public function update(Request $request, Asset $asset)
    {
        $this->authorize('update', $asset);

        $validated = $request->validate([
            'asset_id' => 'required|unique:assets,asset_id,'.$asset->id,
            'company_id' => 'required|exists:companies,id',
            'category_id' => 'required|exists:asset_categories,id',
            'brand' => 'required|string',
            'model' => 'required|string',
            'serial_number' => 'required|string',
            'hostname' => 'required|string',
            'location_id' => 'required|exists:locations,id',
            'purchase_year' => 'nullable|integer',
            'vendor' => 'nullable|string',
            'purchase_price' => 'nullable|numeric',
            'useful_life_years' => 'nullable|integer',
            'asset_status_id' => 'required|exists:asset_statuses,id',
            'warranty_until' => 'nullable|date',
            'deploy_date' => 'nullable|date',
            'software_license' => 'nullable|string',
            'mac_address' => 'nullable|string',
            'static_ip' => 'nullable|string',
            'current_user_id' => 'nullable|exists:users,id',
            'admin_it_pic' => 'nullable|exists:users,id',
            'repair_notes' => 'nullable|string',
            'retire_date' => 'nullable|date',
            'qr_code' => 'nullable|string',
            'barcode' => 'nullable|string',
        ]);

        $oldValues = $asset->toArray();
        $asset->update($validated);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'UPDATE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'field_changed' => json_encode(array_keys($validated)),
            'old_value' => json_encode($oldValues),
            'new_value' => json_encode($validated),
            'source' => 'Web UI',
        ]);

        return redirect()->route('assets.show', $asset)->with('success', 'Asset updated successfully.');
    }

    public function destroy(Asset $asset)
    {
        $this->authorize('archive', $asset);

        $asset->update(['is_active' => false]);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'ARCHIVE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'source' => 'Web UI',
        ]);

        return redirect()->route('assets.index')->with('success', 'Asset archived.');
    }

    /**
     * Retire an asset (PRD §G3): sets the Retired status, stamps retire_date
     * when the record has none, closes the open assignment and releases the
     * holder — all inside one transaction so history survives intact.
     */
    /**
     * Retire an asset (PRD §G3): sets the Retired status, stamps retire_date
     * when the record has none, closes the open assignment and releases the
     * holder — all inside one transaction so history survives intact.
     */
    public function retire(Request $request, Asset $asset)
    {
        $this->authorize('archive', $asset);

        $validated = $request->validate([
            'retire_date' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|string|max:2000',
        ]);

        $status = AssetStatus::where('name', 'Retired')->firstOrFail();
        $retireDate = $validated['retire_date'] ?? now()->toDateString();

        DB::transaction(function () use ($asset, $status, $retireDate) {
            $openAssignment = $asset->assignments()->whereNull('returned_date')->latest('assigned_date')->first();
            if ($openAssignment) {
                $openAssignment->close(now()->toDateString());
            }

            $asset->update([
                'asset_status_id' => $status->id,
                'retire_date' => $retireDate,
                'current_user_id' => null,
            ]);
        });

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'RETIRE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'old_value' => json_encode(['status' => $asset->getOriginal('asset_status_id')]),
            'new_value' => json_encode(['status' => $status->id, 'retire_date' => $retireDate]),
            'metadata' => ['notes' => $validated['notes'] ?? null],
            'source' => 'Web UI',
        ]);

        return redirect()->route('assets.show', $asset)->with('success', 'Asset retired.');
    }

    /**
     * Dispose an asset (PRD §G3): same guarantees as retire(), with the
     * Disposed status. Retired/disposed assets can be relisted via edit.
     */
    public function dispose(Request $request, Asset $asset)
    {
        $this->authorize('archive', $asset);

        $validated = $request->validate([
            'retire_date' => 'nullable|date|before_or_equal:today',
            'notes' => 'nullable|string|max:2000',
        ]);

        $status = AssetStatus::where('name', 'Disposed')->firstOrFail();
        $retireDate = $validated['retire_date'] ?? now()->toDateString();

        DB::transaction(function () use ($asset, $status, $retireDate) {
            $openAssignment = $asset->assignments()->whereNull('returned_date')->latest('assigned_date')->first();
            if ($openAssignment) {
                $openAssignment->close(now()->toDateString());
            }

            $asset->update([
                'asset_status_id' => $status->id,
                'retire_date' => $retireDate,
                'current_user_id' => null,
            ]);
        });

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'DISPOSE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'old_value' => json_encode(['status' => $asset->getOriginal('asset_status_id')]),
            'new_value' => json_encode(['status' => $status->id, 'retire_date' => $retireDate]),
            'metadata' => ['notes' => $validated['notes'] ?? null],
            'source' => 'Web UI',
        ]);

        return redirect()->route('assets.show', $asset)->with('success', 'Asset disposed.');
    }

    public function agentScan(Request $request, Asset $asset): JsonResponse|RedirectResponse
    {
        $this->authorize('audit', $asset);

        if (empty($asset->serial_number)) {
            return $this->agentScanResponse($request, 'This asset has no serial number. Agent scan requires a serial number to match the device.', false);
        }

        // Address the device through the token whose telemetry snapshot is
        // linked to this asset (re-linked on every scan the agent submits).
        $token = AgentApiToken::query()
            ->whereHas('telemetry', fn ($q) => $q->where('asset_id', $asset->id))
            ->orderByDesc('last_used_at')
            ->first();

        if (! $token) {
            return $this->agentScanResponse($request, 'No audit agent is enrolled for this asset yet. Download and run the 1-click agent on the device — it registers itself automatically.', false);
        }

        if (! $token->isUsable()) {
            return $this->agentScanResponse($request, 'The audit agent for this asset is deactivated or its token has expired. Re-activate it on the Agents page or reinstall the agent on the device.', false);
        }

        AgentCommand::create([
            'agent_token_id' => $token->id,
            'command' => 'scan',
            'payload' => ['source' => 'asset-audit', 'asset_id' => $asset->id],
            'status' => 'pending',
        ]);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'AGENT_SCAN_REQUEST',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'new_value' => json_encode(['serial_number' => $asset->serial_number]),
            'metadata' => ['via' => 'agent-scan', 'agent_token_id' => $token->id],
            'source' => 'Web UI',
        ]);

        return $this->agentScanResponse($request, 'Scan command queued. The audit agent on this device will pick it up within a few seconds and send the scan result.');
    }

    private function agentScanResponse(Request $request, string $message, bool $success = true): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $success,
                'message' => $message,
            ], $success ? 200 : 422);
        }

        return back()->with($success ? 'success' : 'error', $message);
    }

    public function audit(Asset $asset)
    {
        $this->authorize('audit', $asset);

        $asset->load(['category', 'status', 'location', 'currentUser', 'company', 'audits']);

        return view('assets.audit', compact('asset'));
    }

    /**
     * Browser-based device scan (PRD §10 web replacement of the legacy EXE
     * "Scan" button). Open this page ON the device being audited.
     */
    public function auditDevice(Asset $asset)
    {
        $this->authorize('audit', $asset);

        $asset->load(['category', 'status', 'location', 'currentUser', 'company', 'audits']);

        return view('assets.audit-device', compact('asset'));
    }

    /**
     * Submit a browser-collected audit (PRD §10/§11 web replacement of the
     * legacy EXE "Scan" button). The browser reports only what it can truthfully
     * read from its sandbox; server-side comparison logic is unchanged.
     */
    public function submitAuditDevice(Request $request, Asset $asset)
    {
        $this->authorize('audit', $asset);

        $validated = $request->validate([
            'browser_os' => 'nullable|string|max:255',
            'browser_ram' => 'nullable|string|max:255',
            'browser_processor' => 'nullable|string|max:255',
            'browser_storage' => 'nullable|string|max:255',
            'browser_gpu' => 'nullable|string|max:255',
            'manual_serial' => 'nullable|string|max:255',
            'manual_hostname' => 'nullable|string|max:255',
            'manual_mac' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        // Build the note without string concatenation (style-safe, easy to read).
        $parts = [];
        if (! empty($validated['notes'])) {
            $parts[] = $validated['notes'];
        }
        $parts[] = sprintf('Browser scan - GPU: %s', $validated['browser_gpu'] ?? 'n/a');
        $parts[] = sprintf('UA: %s', substr((string) $request->userAgent(), 0, 300));
        $notes = implode("\n", $parts);

        $actual = [
            // Identity is typed from the device label when available; the browser
            // sandbox cannot read serial/MAC/hostname (skipped when empty).
            'device_serial' => $validated['manual_serial'] ?? null,
            'device_hostname' => $validated['manual_hostname'] ?? null,
            'device_mac' => $validated['manual_mac'] ?? null,
            'device_os' => $validated['browser_os'] ?? null,
            'device_ram' => $validated['browser_ram'] ?? null,
            'device_processor' => $validated['browser_processor'] ?? null,
            'device_storage' => $validated['browser_storage'] ?? null,
            'device_ip' => $request->ip() ?: null,
        ];

        // Only the IP is guaranteed from the browser scan — identity may be absent.
        $result = app(AuditComparisonService::class)->compare($asset, $actual, ['device_ip']);

        $audit = AssetAudit::create([
            'asset_id' => $asset->id,
            'audit_date' => now(),
            'auditor_id' => Auth::id(),
            ...$actual,
            'result' => $result['result'],
            'mismatches' => $result['mismatches'],
            'status' => 'submitted',
            'notes' => $notes,
        ]);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'AUDIT',
            'entity_type' => 'asset_audits',
            'entity_id' => $audit->id,
            'entity_identifier' => $asset->asset_id,
            'old_value' => json_encode($asset->only([
                'hostname', 'serial_number', 'brand', 'model',
                'processor', 'ram', 'storage', 'os_version', 'mac_address', 'static_ip',
            ])),
            'new_value' => json_encode($actual),
            'metadata' => [
                'result' => $result['result'],
                'mismatches' => $result['mismatches'],
                'via' => 'browser-scan',
            ],
            'source' => 'Asset Audit',
        ]);

        $detail = $result['mismatches'] === [] ? '' : ' — '.implode('; ', array_map(
            fn (string $field, array $values) => "{$field}: registered \"{$values['registered']}\" vs actual \"{$values['actual']}\"",
            array_keys($result['mismatches']),
            $result['mismatches'],
        ));

        return redirect()
            ->route('assets.audit', $asset)
            ->with(
                $result['result'] === AuditComparisonService::MATCH ? 'success' : 'warning',
                "Device scan submitted: {$result['result']}{$detail}"
            );
    }

    public function submitAudit(Request $request, Asset $asset)
    {
        $this->authorize('audit', $asset);

        $validated = $request->validate([
            'device_hostname' => 'required|string',
            'device_serial' => 'required|string',
            'device_brand' => 'nullable|string',
            'device_model' => 'nullable|string',
            'device_processor' => 'nullable|string',
            'device_ram' => 'nullable|string',
            'device_storage' => 'nullable|string',
            'device_os' => 'nullable|string',
            'device_mac' => 'nullable|string',
            'device_ip' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        // The result is computed by the system — never chosen by the auditor (PRD §28/§29).
        $comparison = app(AuditComparisonService::class)->compare($asset, $validated);

        $audit = AssetAudit::create([
            'asset_id' => $asset->id,
            'audit_date' => now(),
            'auditor_id' => Auth::id(),
            ...$validated,
            'result' => $comparison['result'],
            'mismatches' => $comparison['mismatches'],
            'status' => 'submitted',
        ]);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'AUDIT',
            'entity_type' => 'asset_audits',
            'entity_id' => $audit->id,
            'entity_identifier' => $asset->asset_id,
            'old_value' => json_encode($asset->only(['hostname', 'serial_number', 'brand', 'model', 'mac_address', 'processor', 'ram', 'storage', 'os_version', 'static_ip'])),
            'new_value' => json_encode($validated),
            'metadata' => ['result' => $comparison['result'], 'mismatches' => $comparison['mismatches']],
            'source' => 'Asset Audit',
        ]);

        $detail = $comparison['mismatches'] === [] ? '' : ' — '.implode('; ', array_map(
            fn (string $field, array $values) => "{$field}: registered \"{$values['registered']}\" vs actual \"{$values['actual']}\"",
            array_keys($comparison['mismatches']),
            $comparison['mismatches'],
        ));

        return redirect()
            ->route('assets.audit', $asset)
            ->with(
                $comparison['result'] === AuditComparisonService::MATCH ? 'success' : 'warning',
                "Audit submitted: {$comparison['result']}{$detail}"
            );
    }

    public function transfer(Asset $asset)
    {
        $this->authorize('transfer', $asset);

        $asset->load(['currentUser', 'location', 'category', 'status', 'company']);
        $users = User::orderBy('name')->get();
        $locations = Location::orderBy('name')->get();

        return view('assets.transfer', compact('asset', 'users', 'locations'));
    }

    public function submitTransfer(Request $request, Asset $asset)
    {
        $this->authorize('transfer', $asset);

        $validated = $request->validate([
            'to_user_id' => 'required|exists:users,id',
            'to_location_id' => 'required|exists:locations,id',
            'from_location_id' => 'required|exists:locations,id',
            'transfer_date' => 'required|date',
            'from_department' => 'nullable|string',
            'to_department' => 'nullable|string',
            'condition' => 'nullable|string',
            'accessories' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $transfer = DB::transaction(function () use ($asset, $validated) {
            $oldAssignment = $asset->assignments()
                ->whereNull('returned_date')
                ->orderByDesc('assigned_date')
                ->first();

            if ($oldAssignment) {
                $oldAssignment->close($validated['transfer_date']);
            }

            $transfer = AssetTransfer::create([
                'transfer_number' => $this->nextTransferNumber(),
                'transfer_date' => $validated['transfer_date'],
                'company_id' => $asset->company_id,
                'asset_id' => $asset->id,
                'from_user_id' => $oldAssignment?->user_id ?? $asset->current_user_id,
                'from_department' => $validated['from_department']
                    ?? $oldAssignment?->user?->department,
                'from_location_id' => $validated['from_location_id'],
                'to_user_id' => $validated['to_user_id'],
                'to_department' => $validated['to_department']
                    ?? User::find($validated['to_user_id'])?->department,
                'to_location_id' => $validated['to_location_id'],
                'condition' => $validated['condition'] ?? null,
                'accessories' => $validated['accessories'] ?? null,
                'notes' => $validated['notes'] ?? null,
                'submitted_by' => Auth::id(),
                'status' => 'approved',
            ]);

            AssetAssignment::create([
                'asset_id' => $asset->id,
                'user_id' => $validated['to_user_id'],
                'assigned_date' => $validated['transfer_date'],
                'from_location_id' => $validated['from_location_id'],
                'to_location_id' => $validated['to_location_id'],
                'transfer_id' => $transfer->id,
                'notes' => $validated['notes'] ?? null,
                'created_by' => Auth::id(),
            ]);

            $asset->update([
                'current_user_id' => $validated['to_user_id'],
                'location_id' => $validated['to_location_id'],
            ]);

            return $transfer;
        });

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'TRANSFER',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'old_value' => json_encode([
                'user_id' => $transfer->from_user_id,
                'location_id' => $transfer->from_location_id,
            ]),
            'new_value' => json_encode([
                'user_id' => $transfer->to_user_id,
                'location_id' => $transfer->to_location_id,
            ]),
            'metadata' => ['transfer_number' => $transfer->transfer_number],
            'source' => 'Transfer',
        ]);

        return redirect()->route('assets.show', $asset)
            ->with('success', "Transfer {$transfer->transfer_number} completed.");
    }

    /**
     * Sequential transfer number per year (PRD §33) — duplicate safe because the
     * sequence is derived from the last stored number of the same year.
     */
    private function nextTransferNumber(): string
    {
        $prefix = 'AST/TRF/'.now()->format('Y').'/';

        $last = AssetTransfer::query()
            ->where('transfer_number', 'like', $prefix.'%')
            ->orderByDesc('transfer_number')
            ->value('transfer_number');

        $sequence = $last ? ((int) substr($last, -5)) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    public function export(Request $request)
    {
        $this->authorize('export-assets');

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'EXPORT',
            'entity_type' => 'assets',
            'entity_identifier' => 'assets_export',
            'metadata' => $request->only(['search', 'status', 'category', 'company', 'location', 'archived']),
            'source' => 'Export',
        ]);

        return Excel::download(
            new AssetsExport($this->filteredQuery($request)->orderBy('asset_id')),
            'assets_'.now()->format('Y-m-d_His').'.xlsx'
        );
    }

    /**
     * Retired / disposed assets list (PRD §43 "Assets → Retired").
     */
    public function retired(Request $request)
    {
        $this->authorize('viewAny', Asset::class);

        $retiredStatuses = AssetStatus::whereIn('name', ['Retired', 'Disposed'])->pluck('id');

        $query = Asset::query()
            ->with(['category', 'status', 'location', 'currentUser', 'company'])
            ->whereIn('asset_status_id', $retiredStatuses);

        if ($request->filled('search')) {
            $search = '%'.trim((string) $request->input('search')).'%';
            $query->where(function ($q) use ($search) {
                $q->where('asset_id', 'like', $search)
                    ->orWhere('serial_number', 'like', $search)
                    ->orWhere('hostname', 'like', $search)
                    ->orWhereHas('currentUser', fn ($sub) => $sub->where('name', 'like', $search));
            });
        }

        $assets = $query->orderByDesc('retire_date')
            ->paginate(20)
            ->withQueryString();

        return view('assets.retired', compact('assets'));
    }

    public function createRepair(Asset $asset)
    {
        $this->authorize('manageRepair', $asset);

        $asset->load(['category', 'status', 'location', 'currentUser', 'company']);

        return view('assets.repair', compact('asset'));
    }

    public function storeRepair(Request $request, Asset $asset)
    {
        $this->authorize('manageRepair', $asset);

        $validated = $request->validate([
            'date' => 'required|date',
            'problem' => 'required|string',
            'action' => 'nullable|string',
            'vendor' => 'nullable|string',
            'cost' => 'nullable|numeric',
            'status' => 'nullable|in:open,in_progress,completed,cancelled',
            'notes' => 'nullable|string',
        ]);

        $repair = AssetRepair::create([
            'asset_id' => $asset->id,
            ...$validated,
            'created_by' => Auth::id(),
        ]);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'REPAIR',
            'entity_type' => 'asset_repairs',
            'entity_id' => $repair->id,
            'entity_identifier' => $asset->asset_id,
            'source' => 'Repair',
        ]);

        return redirect()->route('assets.show', $asset)->with('success', 'Repair record added.');
    }

    public function destroyRepair(Asset $asset, AssetRepair $repair)
    {
        $this->authorize('manageRepair', $asset);

        if ($repair->asset_id !== $asset->id) {
            abort(404);
        }

        $repair->delete();

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'DELETE_REPAIR',
            'entity_type' => 'asset_repairs',
            'entity_id' => $repair->id,
            'entity_identifier' => $asset->asset_id,
            'source' => 'Repair',
        ]);

        return redirect()->route('assets.show', $asset)->with('success', 'Repair record deleted.');
    }
}
