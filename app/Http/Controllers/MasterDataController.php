<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Support\NameNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Single controller for all configurable lookup tables
 * (PRD §17, §18, §19, §20): companies, locations, categories, statuses.
 *
 * Records that are still referenced by assets/transfers/assignments
 * cannot be deleted — history is never orphaned.
 */
class MasterDataController extends Controller
{
    private const TYPES = [
        'companies' => [
            'label' => 'Companies',
            'singular' => 'Company',
            'model' => Company::class,
            'table' => 'companies',
            'columns' => ['name', 'code', 'address', 'status'],
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'Name', 'required' => true],
                'code' => ['type' => 'text', 'label' => 'Code', 'required' => true],
                'address' => ['type' => 'textarea', 'label' => 'Address'],
                'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
            ],
            'unique' => ['name', 'code'],
            'usage' => [['assets', 'company_id'], ['asset_transfers', 'company_id']],
        ],
        'locations' => [
            'label' => 'Locations',
            'singular' => 'Location',
            'model' => Location::class,
            'table' => 'locations',
            'columns' => ['name', 'branch', 'type', 'status'],
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'Name', 'required' => true],
                'branch' => ['type' => 'text', 'label' => 'Branch'],
                'type' => ['type' => 'select', 'label' => 'Type', 'options' => ['office' => 'Office', 'warehouse' => 'Warehouse', 'branch' => 'Branch', 'store' => 'Store', 'other' => 'Other']],
                'address' => ['type' => 'textarea', 'label' => 'Address'],
                'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
            ],
            'unique' => ['name'],
            'usage' => [
                ['assets', 'location_id'],
                ['asset_assignments', 'from_location_id'],
                ['asset_assignments', 'to_location_id'],
                ['asset_transfers', 'from_location_id'],
                ['asset_transfers', 'to_location_id'],
            ],
        ],
        'categories' => [
            'label' => 'Categories',
            'singular' => 'Category',
            'model' => AssetCategory::class,
            'table' => 'asset_categories',
            'columns' => ['name', 'status'],
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'Name', 'required' => true],
                'status' => ['type' => 'select', 'label' => 'Status', 'options' => ['active' => 'Active', 'inactive' => 'Inactive']],
            ],
            'unique' => ['name'],
            'usage' => [['assets', 'category_id']],
        ],
        'statuses' => [
            'label' => 'Statuses',
            'singular' => 'Status',
            'model' => AssetStatus::class,
            'table' => 'asset_statuses',
            'columns' => ['name', 'color'],
            'fields' => [
                'name' => ['type' => 'text', 'label' => 'Name', 'required' => true],
                'color' => ['type' => 'text', 'label' => 'Color'],
            ],
            'unique' => ['name'],
            'usage' => [['assets', 'asset_status_id']],
        ],
    ];

    private function config(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }

    public function index(Request $request, string $type)
    {
        $config = $this->config($type);
        $model = $config['model'];

        $records = $model::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.trim((string) $request->input('search')).'%'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $records->getCollection()->each(function ($record) use ($config) {
            $record->setAttribute('usage_total', $this->usageCount($config, $record->id));
        });

        return view('master.index', [
            'type' => $type,
            'config' => $config,
            'records' => $records,
        ]);
    }

    public function create(string $type)
    {
        $config = $this->config($type);

        return view('master.form', [
            'type' => $type,
            'config' => $config,
            'record' => null,
        ]);
    }

    public function store(Request $request, string $type)
    {
        $config = $this->config($type);
        $this->normalizeName($request);

        $record = $config['model']::create($request->validate($this->rules($config)));

        $this->log('CREATE', $config, $record);

        return redirect()
            ->route('master.index', $type)
            ->with('success', "{$config['singular']} created.");
    }

    public function edit(string $type, int $id)
    {
        $config = $this->config($type);
        $record = $config['model']::findOrFail($id);

        return view('master.form', [
            'type' => $type,
            'config' => $config,
            'record' => $record,
        ]);
    }

    public function update(Request $request, string $type, int $id)
    {
        $config = $this->config($type);
        $record = $config['model']::findOrFail($id);
        $this->normalizeName($request);

        $record->update($request->validate($this->rules($config, $record->id)));

        $this->log('UPDATE', $config, $record);

        return redirect()
            ->route('master.index', $type)
            ->with('success', "{$config['singular']} updated.");
    }

    /**
     * Normalise the submitted name before validation runs.
     *
     * The models clean `name` on the way in, but the unique rule reads the raw
     * request. Without this step a name pasted out of Excel — a byte order mark
     * in front, say — passes the uniqueness check against the stored row, is
     * then normalised on its way to the database and collides with the very row
     * the form was supposed to reject. That turns a clear field error into an
     * unhandled integrity violation, so the form would look broken rather than
     * telling the user what is wrong.
     */
    private function normalizeName(Request $request): void
    {
        if ($request->has('name')) {
            $request->merge(['name' => NameNormalizer::normalize($request->input('name')) ?? '']);
        }
    }

    public function destroy(string $type, int $id)
    {
        $config = $this->config($type);
        $record = $config['model']::findOrFail($id);

        $usage = $this->usageCount($config, $record->id);

        if ($usage > 0) {
            return back()->with('error', "Cannot delete: still referenced by {$usage} record(s). Deactivate it instead.");
        }

        $record->delete();

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'DELETE',
            'entity_type' => $config['table'],
            'entity_id' => $record->id,
            'entity_identifier' => $record->name ?? (string) $record->id,
            'source' => 'Master Data',
        ]);

        return redirect()
            ->route('master.index', $type)
            ->with('success', "{$config['singular']} deleted.");
    }

    private function rules(array $config, ?int $ignoreId = null): array
    {
        $rules = [];

        foreach ($config['fields'] as $field => $definition) {
            $rule = ($definition['required'] ?? false) ? 'required' : 'nullable';

            if (in_array($field, $config['unique'], true)) {
                $rule .= "|unique:{$config['table']},{$field}".($ignoreId ? ",{$ignoreId}" : '');
            }

            if (($definition['type'] ?? 'text') === 'select') {
                $rule .= '|in:'.implode(',', array_keys($definition['options'] ?? []));
            } else {
                $rule .= '|string|max:255';
            }

            $rules[$field] = $rule;
        }

        return $rules;
    }

    private function usageCount(array $config, int $id): int
    {
        $total = 0;

        foreach ($config['usage'] as [$table, $column]) {
            $total += DB::table($table)->where($column, $id)->count();
        }

        return $total;
    }

    private function log(string $action, array $config, mixed $record): void
    {
        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'entity_type' => $config['table'],
            'entity_id' => $record->id,
            'entity_identifier' => $record->name ?? (string) $record->id,
            'source' => 'Master Data',
        ]);
    }
}
