<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\IkeBastInspectionLineItem;
use App\Models\IkeBastInspectionSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IkeBastInspectionController extends Controller
{
    public function create(Asset $asset)
    {
        $asset->loadMissing(['company', 'category', 'status', 'currentUser', 'location']);

        return view('ike-bast.inspection.create', [
            'asset' => $asset,
            'defaultKind' => 'IKE_BAST',
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'asset_id' => 'required|exists:assets,id',
            'transfer_id' => 'nullable|exists:asset_transfers,id',
            'inspection_date' => 'nullable|date',
            'kind' => 'required|in:IKE_BAST,BAST,FAT,CUSTOM',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.seq' => 'nullable|integer',
            'lines.*.item_desc' => 'nullable|string|max:255',
            'lines.*.item_code' => 'nullable|string|max:255',
            'lines.*.barcode' => 'nullable|string|max:255',
            'lines.*.serial_number' => 'nullable|string|max:255',
            'lines.*.unit' => 'nullable|string|max:255',
            'lines.*.qty_planned' => 'nullable|numeric',
            'lines.*.qty_received' => 'nullable|numeric',
            'lines.*.physical_condition' => 'nullable|string',
            'lines.*.test_result' => 'nullable|in:pass,fail,na',
            'lines.*.is_defective' => 'nullable|boolean',
            'lines.*.remarks' => 'nullable|string',
        ]);

        $asset = Asset::findOrFail($validated['asset_id']);
        $this->authorize('manageAttachments', $asset);

        $sessionNumber = 'IKE/'.now()->format('Ymd').'/'.str_pad(
            (string) (IkeBastInspectionSession::max('id') + 1),
            3,
            '0',
            STR_PAD_LEFT
        );

        $session = IkeBastInspectionSession::create([
            'asset_id' => $asset->id,
            'transfer_id' => $validated['transfer_id'] ?? null,
            'created_by' => Auth::id(),
            'session_number' => $sessionNumber,
            'inspection_date' => $validated['inspection_date'] ?? now()->toDateString(),
            'kind' => $validated['kind'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'submitted',
        ]);

        foreach ($validated['lines'] as $index => $line) {
            IkeBastInspectionLineItem::create([
                'session_id' => $session->id,
                'seq' => (int) ($line['seq'] ?? $index + 1),
                'item_desc' => $line['item_desc'] ?? null,
                'item_code' => $line['item_code'] ?? null,
                'barcode' => $line['barcode'] ?? null,
                'serial_number' => $line['serial_number'] ?? null,
                'unit' => $line['unit'] ?? null,
                'qty_planned' => $line['qty_planned'] ?? null,
                'qty_received' => $line['qty_received'] ?? null,
                'physical_condition' => $line['physical_condition'] ?? null,
                'test_result' => $line['test_result'] ?? 'na',
                'is_defective' => (bool) ($line['is_defective'] ?? false),
                'remarks' => $line['remarks'] ?? null,
            ]);
        }

        return redirect()->route('ike-bast.inspection.show', $session)
            ->with('success', "Session {$sessionNumber} saved.");
    }

    public function show(IkeBastInspectionSession $session)
    {
        $this->authorize('manageAttachments', $session->asset);

        $session->loadMissing(['asset', 'transfer', 'creator', 'lines']);

        return view('ike-bast.inspection.show', compact('session'));
    }

    public function update(Request $request, IkeBastInspectionSession $session)
    {
        $this->authorize('manageAttachments', $session->asset);

        $validated = $request->validate([
            'inspection_date' => 'nullable|date',
            'kind' => 'required|in:IKE_BAST,BAST,FAT,CUSTOM',
            'status' => 'nullable|in:draft,submitted,approved,rejected',
            'notes' => 'nullable|string',
            'lines' => 'required|array|min:1',
            'lines.*.seq' => 'nullable|integer',
            'lines.*.item_desc' => 'nullable|string|max:255',
            'lines.*.item_code' => 'nullable|string|max:255',
            'lines.*.barcode' => 'nullable|string|max:255',
            'lines.*.serial_number' => 'nullable|string|max:255',
            'lines.*.unit' => 'nullable|string|max:255',
            'lines.*.qty_planned' => 'nullable|numeric',
            'lines.*.qty_received' => 'nullable|numeric',
            'lines.*.physical_condition' => 'nullable|string',
            'lines.*.test_result' => 'nullable|in:pass,fail,na',
            'lines.*.is_defective' => 'nullable|boolean',
            'lines.*.remarks' => 'nullable|string',
        ]);

        $session->update([
            'inspection_date' => $validated['inspection_date'] ?? $session->inspection_date,
            'kind' => $validated['kind'],
            'status' => $validated['status'] ?? $session->status,
            'notes' => $validated['notes'] ?? $session->notes,
        ]);

        $session->lines()->delete();

        foreach ($validated['lines'] as $index => $line) {
            IkeBastInspectionLineItem::create([
                'session_id' => $session->id,
                'seq' => (int) ($line['seq'] ?? $index + 1),
                'item_desc' => $line['item_desc'] ?? null,
                'item_code' => $line['item_code'] ?? null,
                'barcode' => $line['barcode'] ?? null,
                'serial_number' => $line['serial_number'] ?? null,
                'unit' => $line['unit'] ?? null,
                'qty_planned' => $line['qty_planned'] ?? null,
                'qty_received' => $line['qty_received'] ?? null,
                'physical_condition' => $line['physical_condition'] ?? null,
                'test_result' => $line['test_result'] ?? 'na',
                'is_defective' => (bool) ($line['is_defective'] ?? false),
                'remarks' => $line['remarks'] ?? null,
            ]);
        }

        return redirect()->route('ike-bast.inspection.show', $session)
            ->with('success', 'Inspection updated.');
    }

    public function approve(Request $request, IkeBastInspectionSession $session)
    {
        $this->authorize('manageAttachments', $session->asset);

        $request->validate([
            'status' => 'required|in:approved,rejected',
            'notes' => 'nullable|string',
        ]);

        $session->update([
            'status' => $request->input('status'),
            'notes' => $request->input('notes') ?? $session->notes,
        ]);

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => strtoupper($request->input('status')),
            'entity_type' => 'ike_bast_inspection_sessions',
            'entity_id' => $session->id,
            'entity_identifier' => $session->session_number,
            'source' => 'IKE_BAST Inspection',
        ]);

        return redirect()->route('ike-bast.inspection.show', $session)
            ->with('success', 'Session '.$request->input('status').'.');
    }

    public function print(IkeBastInspectionSession $session)
    {
        $this->authorize('manageAttachments', $session->asset);

        $session->loadMissing(['asset', 'transfer', 'creator', 'lines']);

        return view('ike-bast.inspection.print', compact('session'));
    }

    public function index(Request $request)
    {
        $sessions = IkeBastInspectionSession::query()
            ->with(['asset', 'creator'])
            ->withCount('lines')
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = trim((string) $request->input('search'));
                $q->where('session_number', 'like', "%{$search}%")
                    ->orWhereHas('asset', fn ($sub) => $sub
                        ->where('asset_id', 'like', "%{$search}%")
                        ->orWhere('hostname', 'like', "%{$search}%")
                        ->orWhere('serial_number', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->input('kind')))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('ike-bast.inspection.index', compact('sessions'));
    }
}
