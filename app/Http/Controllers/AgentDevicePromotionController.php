<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AgentApiToken;
use App\Models\Asset;
use App\Models\AssetAudit;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\AssetCodeGenerator;
use App\Services\AuditComparisonService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Promote an unknown device from the fleet into the register (PRD §11).
 *
 * Until this existed, a laptop whose serial was not on file became an asset the
 * moment it scanned - with company, category and location guessed from whichever
 * row happened to be first, and nothing on screen marking it as a guess. This is
 * the deliberate replacement: the scan waits here, and the fields a machine
 * cannot know are filled in by a person.
 *
 * The form arrives prefilled from the scan. Only the fields no sensor can report
 * are asked for: who uses it, what it is, where it lives, which company owns it.
 * Overwriting those with "first row in the table" is what made the register
 * quietly wrong.
 */
class AgentDevicePromotionController extends Controller
{
    /**
     * The form, prefilled from what the device reported.
     *
     * Split out from the POST so a validation failure can come back with the
     * operator's own answers intact rather than re-deriving them from telemetry
     * and silently discarding what they typed.
     */
    public function create(AgentApiToken $token)
    {
        $this->authorize('create', Asset::class);

        return view('agent.promote', [
            'token' => $token,
            'scan' => $this->latestScan($token),
            'telemetry' => $token->telemetry,
            'fingerprint' => $token->fingerprint,
            'companies' => Company::orderBy('name')->get(),
            'categories' => AssetCategory::orderBy('name')->get(),
            'locations' => Location::orderBy('name')->get(),
            'statuses' => AssetStatus::orderBy('name')->get(),
            'users' => User::orderBy('name')->get(),
        ]);
    }

    /**
     * Create the asset, then hand the waiting scan over to it.
     *
     * In one transaction on purpose: an asset with no scan behind it would claim
     * a device the system has no reading of, and a scan attached to an asset id
     * that was rolled back would leave the fleet page showing a device that does
     * not exist.
     */
    public function store(Request $request, AgentApiToken $token)
    {
        $this->authorize('create', Asset::class);

        $validated = $request->validate([
            'company_id' => 'required|exists:companies,id',
            'category_id' => 'required|exists:asset_categories,id',
            'location_id' => 'required|exists:locations,id',
            'asset_status_id' => 'required|exists:asset_statuses,id',
            'current_user_id' => 'nullable|exists:users,id',
            'admin_it_pic' => 'nullable|exists:users,id',
            'purchase_year' => 'required|integer|min:1900|max:'.(date('Y') + 2),
            'vendor' => 'nullable|string|max:255',
            'purchase_price' => 'nullable|numeric|min:0',
            'useful_life_years' => 'nullable|integer|min:0|max:50',
            'warranty_until' => 'nullable|date',
            'deploy_date' => 'nullable|date',
        ]);

        $company = Company::findOrFail($validated['company_id']);
        $category = AssetCategory::findOrFail($validated['category_id']);
        $validated['asset_id'] = app(AssetCodeGenerator::class)
            ->next($company, $category, (int) $validated['purchase_year']);

        $asset = DB::transaction(function () use ($validated, $token) {
            $asset = Asset::create(array_merge($this->hardwareDefaults($token), $validated));

            // Attach the scan that was waiting, so the device's first appearance
            // in the register is the one the hardware actually reported rather
            // than an audit trail that starts today.
            $this->latestScan($token)?->update(['asset_id' => $asset->id]);

            // Same for telemetry: it was filed with no asset because there was
            // none, and the fleet page reads that column to tell a registered
            // device from one still waiting.
            $token->telemetry?->update(['asset_id' => $asset->id]);

            if ($asset->current_user_id) {
                $this->recordInitialAssignment($asset);
            }

            ActivityLog::create([
                'actor_id' => Auth::id(),
                'action' => 'CREATE',
                'entity_type' => 'assets',
                'entity_id' => $asset->id,
                'entity_identifier' => $asset->asset_id,
                'source' => 'Web UI',
                'old_value' => null,
                'new_value' => json_encode($validated),
                'metadata' => ['promoted_from' => $token->device_id, 'via' => 'agent-fleet'],
            ]);

            return $asset;
        });

        return redirect()
            ->route('assets.show', $asset)
            ->with('success', "{$asset->asset_id} added to the register from {$token->name}.");
    }

    /**
     * Hardware the device reported, used only where the form left a gap.
     *
     * Blank rather than "N/A": the old auto-created rows filled every unknown
     * column with N/A, so a register full of them looked complete and could not
     * be told apart from real values. Null says "not reported".
     */
    private function hardwareDefaults(AgentApiToken $token): array
    {
        $telemetry = $token->telemetry;
        $scan = $this->latestScan($token);

        return [
            'brand' => $scan?->device_brand,
            'model' => $scan?->device_model ?? $token->fingerprint?->device_model,
            'serial_number' => $scan?->device_serial,
            'hostname' => $scan?->device_hostname ?? $telemetry?->hostname,
            'processor' => $scan?->device_processor,
            'storage' => $scan?->device_storage,
            'ram' => $scan?->device_ram ?? $telemetry?->ram_config,
            'os_version' => $scan?->device_os ?? $telemetry?->os,
            'mac_address' => $scan?->device_mac,
            'static_ip' => $telemetry?->ip_address,
        ];
    }

    /**
     * Accept a scan's readings onto the asset, from the fleet page.
     *
     * The agent never writes to the register - it reports, and a mismatch stops
     * at a report. This is the second half: an operator has read the difference
     * and agrees the machine is right.
     *
     * Gated on update-asset rather than manage-agents, because what it writes is
     * the asset row, not the fleet. Someone who can edit assets but does not
     * run the fleet can still correct a register they are responsible for.
     */
    public function applyScan(Request $request, AssetAudit $audit)
    {
        $this->authorize('update', $audit->asset);

        if ($audit->isUnmatched()) {
            return back()->with('error', 'That scan has no asset yet — add the device to the register first.');
        }

        $asset = $audit->asset;

        $actual = [
            'device_hostname' => $audit->device_hostname,
            'device_serial' => $audit->device_serial,
            'device_brand' => $audit->device_brand,
            'device_model' => $audit->device_model,
            'device_processor' => $audit->device_processor,
            'device_ram' => $audit->device_ram,
            'device_storage' => $audit->device_storage,
            'device_os' => $audit->device_os,
            'device_mac' => $audit->device_mac,
            'device_ip' => $audit->device_ip,
        ];

        $changed = app(AuditComparisonService::class)->applyToAsset($asset, $actual);

        // The audit has now been acted on, so its stale difference must not keep
        // prompting. Leaving `mismatches` in place meant the warning panel - and
        // the "Update ... from this scan" button - reappeared on the very next
        // page load, which reads as if the click did nothing. Clearing it makes
        // the panel say "in sync" until the device reports a genuinely new
        // difference.
        $audit->forceFill(['mismatches' => null, 'result' => 'MATCH'])->save();

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'UPDATE',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'source' => 'Web UI',
            'old_value' => json_encode(array_intersect_key($asset->getOriginal(), array_flip($changed))),
            'new_value' => json_encode(array_intersect_key($asset->getAttributes(), array_flip($changed))),
            'metadata' => ['from_scan' => $audit->id, 'via' => 'agent-fleet'],
        ]);

        if ($changed === []) {
            return back()->with('success', 'Nothing to change — the asset already matches that scan.');
        }

        $labels = collect($changed)
            ->map(fn (string $field) => str_replace('_', ' ', $field))
            ->implode(', ');

        return back()->with('success', "Updated {$asset->asset_id} from the scan: {$labels}.");
    }

    /**
     * The newest scan this device sent that has no asset yet.
     *
     * Scoped through the token rather than the serial number: the token is what
     * identified the device when the scan arrived, and a serial can be blank on
     * machines whose WMI call failed.
     */
    private function latestScan(AgentApiToken $token): ?AssetAudit
    {
        return AssetAudit::query()
            ->where('agent_token_id', $token->id)
            ->whereNull('asset_id')
            ->orderByDesc('audit_date')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Open the assignment history at the person it was issued to.
     *
     * The transfer documents all read "from" this row (PRD §44), so an asset
     * registered with a holder but no assignment history would generate a FAT
     * with no first party - the exact copy-paste problem the document work exists
     * to remove.
     */
    private function recordInitialAssignment(Asset $asset): void
    {
        if ($asset->assignments()->exists()) {
            return;
        }

        $asset->assignments()->create([
            'user_id' => $asset->current_user_id,
            'assigned_date' => now(),
            'created_by' => Auth::id(),
            'notes' => 'Issued to this user when the asset was registered from the device fleet.',
        ]);
    }
}
