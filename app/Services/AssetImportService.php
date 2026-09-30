<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Support\NameNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Legacy Excel import pipeline (PRD §8, §25, §26).
 *
 * analyze() : reads the sheet, maps legacy columns, validates and detects duplicates.
 * import()  : writes only safe rows — existing records are never overwritten or deleted.
 */
class AssetImportService
{
    public const VALID = 'valid';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public const DUPLICATE = 'duplicate';

    /** Legacy placeholder values that must be treated as "no value". */
    private const EMPTY_VALUES = ['', '-', '--', 'n/a', 'na', 'none', 'null', 'tidak ada', 'unknown'];

    private const DATE_FIELDS = [
        'warranty_until', 'deploy_date', 'retire_date',
        'assignment_1_start', 'assignment_1_end',
        'assignment_2_start', 'assignment_2_end',
        'assignment_3_start', 'assignment_3_end',
        'current_assignment_start', 'current_assignment_end',
    ];

    private const USER_FIELDS = [
        'admin_it_pic', 'current_user',
        'assignment_1_user', 'assignment_2_user', 'assignment_3_user',
    ];

    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->config = (array) config('asset-import');
    }

    /**
     * Analyse a spreadsheet and classify every row.
     *
     * @return array{sheet:string, mapped_columns:array, unmapped_columns:array, summary:array, rows:array}
     */
    public function analyze(string $absolutePath): array
    {
        [$sheetName, $header, $rows] = $this->readSheet($absolutePath);

        $columnMap = (array) ($this->config['column_map'] ?? []);
        $mapping = [];
        $unmapped = [];

        foreach ($header as $index => $label) {
            $normalized = $this->normalizeHeader((string) $label);

            if ($normalized !== '' && isset($columnMap[$normalized])) {
                $mapping[$index] = $columnMap[$normalized];
            } elseif (trim((string) $label) !== '') {
                $unmapped[] = trim((string) $label);
            }
        }

        $seen = ['asset_id' => [], 'serial_number' => [], 'hostname' => []];
        $results = [];
        $summary = ['total' => 0, 'valid' => 0, 'warning' => 0, 'error' => 0, 'duplicate' => 0];

        foreach ($rows as $offset => $row) {
            $excelRow = $offset + 2; // Row 1 holds the header.
            $raw = $this->extractRow((array) $row, $mapping);

            if ($this->isEmptyRow($raw)) {
                continue;
            }

            $summary['total']++;

            $result = $this->classify($raw, $seen, $excelRow);
            $summary[$result['status']]++;
            $results[] = $result;

            if ($result['status'] !== self::ERROR) {
                // Only rows that can be imported reserve their identity keys.
                foreach (['asset_id', 'serial_number', 'hostname'] as $key) {
                    if ($result['data'][$key] !== null) {
                        $seen[$key][mb_strtolower((string) $result['data'][$key])] = $excelRow;
                    }
                }
            }
        }

        return [
            'sheet' => $sheetName,
            'mapped_columns' => array_values($mapping),
            'unmapped_columns' => $unmapped,
            'summary' => $summary,
            'rows' => $results,
        ];
    }

    /**
     * Validate one row and decide its import status.
     */
    private function classify(array $raw, array $seen, int $excelRow): array
    {
        $values = $this->normalizeValues($raw);

        $errors = [];
        $warnings = [];

        // --- identity ------------------------------------------------------
        if ($values['hostname'] === null) {
            $errors[] = 'Missing Hostname (required by the asset register).';
        }

        if ($values['asset_id'] === null && $values['serial_number'] === null) {
            $errors[] = 'Row has neither Asset ID nor Serial Number.';
        }

        // --- duplicate detection (file + register) -------------------------
        $duplicates = $this->duplicateReasons($values, $seen);

        // --- data completeness warnings ------------------------------------
        if ($values['brand'] === null) {
            $warnings[] = 'Missing Brand — stored as "N/A".';
        }

        if ($values['model'] === null) {
            $warnings[] = 'Missing Model — stored as "N/A".';
        }

        if ($values['serial_number'] === null) {
            $warnings[] = 'Missing Serial Number.';
        }

        if ($values['company_name'] === null) {
            $warnings[] = 'Missing Company — default company will be used.';
        }

        if ($values['category_name'] === null) {
            $warnings[] = 'Missing Category — "Other" will be used.';
        }

        if ($values['status_name'] === null) {
            $warnings[] = 'Missing Status — "Available" will be used.';
        }

        if ($values['location_name'] === null) {
            $warnings[] = 'Missing Location — default location will be used.';
        }

        foreach ($values['_issues'] ?? [] as $issue) {
            $warnings[] = $issue;
        }

        foreach (self::USER_FIELDS as $field) {
            if ($values[$field] !== null && ! $this->userExists((string) $values[$field])) {
                $warnings[] = "User \"{$values[$field]}\" not found in the directory — a placeholder account will be created.";
            }
        }

        $status = match (true) {
            $errors !== [] => self::ERROR,
            $duplicates !== [] => self::DUPLICATE,
            $warnings !== [] => self::WARNING,
            default => self::VALID,
        };

        unset($values['_issues']);

        return [
            'row' => $excelRow,
            'status' => $status,
            'issues' => array_merge($errors, $duplicates, $warnings),
            'data' => $values,
        ];
    }

    /**
     * Detect duplicates inside the file and against the existing register.
     *
     * Duplicates are never overwritten (PRD §15, §25).
     */
    private function duplicateReasons(array $values, array $seen): array
    {
        $reasons = [];
        $labels = [
            'asset_id' => 'Asset ID',
            'serial_number' => 'Serial Number',
            'hostname' => 'Hostname',
        ];

        foreach ($labels as $key => $label) {
            $value = $values[$key] ?? null;

            if ($value === null) {
                continue;
            }

            $lookup = mb_strtolower((string) $value);

            if (isset($seen[$key][$lookup])) {
                $reasons[] = "Duplicate {$label} \"{$value}\" — already seen on row {$seen[$key][$lookup]} of this file.";

                continue;
            }

            if ($this->existsInRegister($key, (string) $value)) {
                $reasons[] = "Duplicate {$label} \"{$value}\" — already exists in the asset register (skipped).";
            }
        }

        return $reasons;
    }

    private function existsInRegister(string $column, string $value): bool
    {
        if (! in_array($column, ['asset_id', 'serial_number', 'hostname'], true)) {
            return false;
        }

        return Asset::query()->where($column, $value)->exists();
    }

    /**
     * Normalise raw legacy cell values into database ready values.
     */
    private function normalizeValues(array $raw): array
    {
        $values = [];
        $issues = [];

        foreach ($raw as $field => $value) {
            $values[$field] = $this->cleanString($value);
        }

        foreach (['purchase_year', 'useful_life_years'] as $field) {
            $values[$field] = $this->parseInt($values[$field] ?? null);
        }

        $values['purchase_price'] = $this->parseDecimal($raw['purchase_price'] ?? null);

        foreach (self::DATE_FIELDS as $field) {
            $parsed = $this->parseDate($raw[$field] ?? null);

            if ($parsed === false) {
                $issues[] = "Unreadable date in column \"{$field}\" — left empty.";
                $parsed = null;
            }

            $values[$field] = $parsed;
        }

        $values['_issues'] = $issues;

        return $values;
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : null;
        }

        if (is_float($value) && floor($value) === $value) {
            $value = number_format($value, 0, '', '');
        }

        if (! is_scalar($value)) {
            return null;
        }

        $clean = NameNormalizer::normalize($value);

        if ($clean === null || in_array(mb_strtolower($clean), self::EMPTY_VALUES, true)) {
            return null;
        }

        return $clean;
    }

    private function parseInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        // Legacy years occasionally carry text such as "Tahun 2022".
        if (is_string($value) && preg_match('/(18|19|20)\d{2}/', $value, $matches) === 1) {
            return (int) $matches[0];
        }

        $digits = preg_replace('/[^0-9]/', '', (string) $value);

        return ($digits === null || $digits === '') ? null : (int) $digits;
    }

    private function parseDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $digits = preg_replace('/[^0-9.,]/', '', (string) $value);

        if ($digits === null || $digits === '') {
            return null;
        }

        // "12.500.000" / "12,500,000" -> 12500000
        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $digits) === 1) {
            $digits = str_replace(['.', ','], '', $digits);
        } else {
            $digits = str_replace(',', '.', str_replace('.', '', $digits));
        }

        return (float) $digits;
    }

    /**
     * Parse an Excel serial number, a readable string or a DateTime instance.
     *
     * @return Carbon|null|false false when the raw value could not be understood.
     */
    private function parseDate(mixed $value): Carbon|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfDay();
        }

        if (is_numeric($value)) {
            $serial = (float) $value;

            if ($serial > 0 && $serial < 60000) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($serial))->startOfDay();
            }

            return false;
        }

        $clean = $this->cleanString($value);

        if ($clean === null) {
            return null;
        }

        try {
            return Carbon::parse($clean)->startOfDay();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Import every safe row (valid + warning). Duplicates and errors are skipped,
     * existing records are never modified or deleted (PRD §25, §54).
     *
     * @return array{created:int, skipped:int, assignment_rows:int, placeholder_users:int, errors:array}
     */
    public function import(array $analysis, int $batchSize = 200): array
    {
        $importable = array_values(array_filter(
            $analysis['rows'],
            fn (array $row) => in_array($row['status'], [self::VALID, self::WARNING], true)
        ));

        $created = 0;
        $skipped = count($analysis['rows']) - count($importable);
        $assignmentRows = 0;
        $placeholderUsers = 0;
        $errors = [];

        foreach (array_chunk($importable, $batchSize) as $chunk) {
            foreach ($chunk as $row) {
                try {
                    $result = DB::transaction(fn () => $this->importRow($row['data'], $row));
                } catch (\Throwable $e) {
                    $skipped++;
                    $errors[] = "Row {$row['row']}: {$e->getMessage()}";

                    continue;
                }

                if ($result === null) {
                    // Identity appeared in the register after the analyse step.
                    $skipped++;

                    continue;
                }

                $created++;
                $assignmentRows += $result['assignments'];
                $placeholderUsers += $result['placeholders'];
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'assignment_rows' => $assignmentRows,
            'placeholder_users' => $placeholderUsers,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    /**
     * Insert one analysed row together with its assignment history.
     *
     * @return array{asset:Asset, assignments:int, placeholders:int}|null
     */
    private function importRow(array $values, array $row): ?array
    {
        foreach (['asset_id', 'serial_number', 'hostname'] as $key) {
            if (($values[$key] ?? null) !== null && $this->existsInRegister($key, (string) $values[$key])) {
                return null;
            }
        }

        $asset = Asset::create([
            'asset_id' => $values['asset_id'] ?? $this->generateAssetId($values['hostname'] ?? $values['serial_number']),
            'company_id' => $this->resolveCompany($values['company_name'] ?? null)->id,
            'category_id' => $this->resolveLookup(
                AssetCategory::class,
                $values['category_name'] ?? null,
                'Other'
            )->id,
            'brand' => $values['brand'] ?? 'N/A',
            'model' => $values['model'] ?? 'N/A',
            'serial_number' => $values['serial_number'] ?? 'N/A',
            'hostname' => $values['hostname'] ?? 'N/A',
            'processor' => $values['processor'] ?? null,
            'storage' => $values['storage'] ?? null,
            'ram' => $values['ram'] ?? null,
            'os_version' => $values['os_version'] ?? null,
            'location_id' => $this->resolveLocation($values['location_name'] ?? null)->id,
            'purchase_year' => $values['purchase_year'] ?? null,
            'vendor' => $values['vendor'] ?? null,
            'purchase_price' => $values['purchase_price'] ?? null,
            'useful_life_years' => $values['useful_life_years'] ?? null,
            'asset_status_id' => $this->resolveLookup(
                AssetStatus::class,
                $values['status_name'] ?? null,
                'Available'
            )->id,
            'warranty_until' => $values['warranty_until'] ?? null,
            'deploy_date' => $values['deploy_date'] ?? null,
            'software_license' => $values['software_license'] ?? null,
            'mac_address' => $values['mac_address'] ?? null,
            'static_ip' => $values['static_ip'] ?? null,
            'repair_notes' => $values['repair_notes'] ?? null,
            'retire_date' => $values['retire_date'] ?? null,
            'qr_code' => $values['qr_code'] ?? null,
            'barcode' => $values['barcode'] ?? null,
            'is_active' => true,
        ]);

        $placeholders = 0;
        $assignments = $this->createAssignments($asset, $values, $placeholders);

        if (($values['admin_it_pic'] ?? null) !== null) {
            $placeholder = false;
            $pic = $this->resolveUser((string) $values['admin_it_pic'], $placeholder);

            if ($pic) {
                $asset->update(['admin_it_pic' => $pic->id]);
                $placeholders += $placeholder ? 1 : 0;
            }
        }

        ActivityLog::create([
            'actor_id' => Auth::id(),
            'action' => 'IMPORT',
            'entity_type' => 'assets',
            'entity_id' => $asset->id,
            'entity_identifier' => $asset->asset_id,
            'new_value' => json_encode($asset->fresh()?->toArray()),
            'metadata' => [
                'source_row' => $row['row'] ?? null,
                'status' => $row['status'] ?? self::VALID,
            ],
            'source' => 'Import',
        ]);

        return [
            'asset' => $asset,
            'assignments' => $assignments,
            'placeholders' => $placeholders,
        ];
    }

    /**
     * Rebuild the assignment history from the legacy "User 1..3 + current user"
     * columns (PRD §16, §26). History is preserved, never flattened into columns.
     */
    private function createAssignments(Asset $asset, array $values, int &$placeholders): int
    {
        $definitions = (array) ($this->config['assignments'] ?? []);
        $created = 0;
        $lastUserId = null;
        $currentUserId = null;
        $lastKey = array_key_last($definitions);

        foreach ($definitions as $index => $definition) {
            $name = $values[$definition['user']] ?? null;

            if ($name === null) {
                continue;
            }

            $placeholder = false;
            $user = $this->resolveUser((string) $name, $placeholder);

            if (! $user) {
                continue;
            }

            $placeholders += $placeholder ? 1 : 0;

            $start = $values[$definition['start']] ?? null;
            $end = isset($definition['end']) ? ($values[$definition['end']] ?? null) : null;

            AssetAssignment::create([
                'asset_id' => $asset->id,
                'user_id' => $user->id,
                'assigned_date' => $start ?? $end ?? now()->toDateString(),
                'returned_date' => $end,
                'from_location_id' => $asset->location_id,
                'to_location_id' => $asset->location_id,
                'notes' => 'Imported from legacy Excel column: '.$definition['user'].'.',
                'created_by' => Auth::id(),
            ]);

            $created++;
            $lastUserId = $user->id;

            if ($index === $lastKey) {
                $currentUserId = $user->id;
            }
        }

        $current = $currentUserId ?? $lastUserId;

        if ($current !== null) {
            $asset->update(['current_user_id' => $current]);
        }

        return $created;
    }

    /**
     * Find a company by name, creating it when the sheet names a new one.
     *
     * The lookup goes through whereNameIs() rather than an equality match on
     * the raw cell. A sheet exported from Excel prefixes its first cell with a
     * BOM, so the value arriving here can differ from the stored name by a
     * character nobody can see; an exact match misses it and the import files
     * the same organisation a second time.
     */
    private function resolveCompany(?string $name): Company
    {
        $name = NameNormalizer::normalize($name)
            ?? NameNormalizer::normalize($this->config['default_company'] ?? null)
            ?? 'Unknown';

        $company = Company::query()->whereNameIs($name)->first();

        if ($company) {
            return $company;
        }

        return Company::create([
            'name' => $name,
            'code' => $this->nextCompanyCode(),
            'address' => '',
            'status' => 'active',
        ]);
    }

    /**
     * The next unused CMPnnn code.
     *
     * Deriving this from Company::count() + 1 collides as soon as any company
     * is deleted: the count drops but the codes do not, so the next import asks
     * for a code that is already taken and dies on the unique index. The highest
     * number actually in use is the only figure that stays ahead of deletions.
     */
    private function nextCompanyCode(): string
    {
        $highest = 0;

        foreach (Company::pluck('code') as $code) {
            if (preg_match('/^CMP0*(\d+)$/i', (string) $code, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'CMP'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Find or create a lookup row by name.
     *
     * The four lookup tables are keyed by name (PRD §17-§20), so this is the
     * single point where an imported label becomes a master data row. Matching
     * case-insensitively as well as exactly mirrors how a MySQL unique index
     * compares, which keeps the lookup from turning a name that differs only in
     * capitalisation into an integrity error instead of a reuse.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    private function resolveLookup(string $model, ?string $name, string $default, array $attributes = []): Model
    {
        $name = NameNormalizer::normalize($name) ?? $default;

        return $model::query()->whereNameIs($name)->first()
            ?? $model::query()->create(['name' => $name, ...$attributes]);
    }

    private function resolveLocation(?string $name): Location
    {
        /** @var Location $location */
        $location = $this->resolveLookup(Location::class, $name, 'HO', [
            'branch' => 'Main',
            'type' => 'office',
            'address' => '',
            'status' => 'active',
        ]);

        return $location;
    }

    /**
     * Resolve a legacy name/email to a directory user.
     *
     * Legacy sheets only contain display names. Unknown people get a placeholder
     * account so the assignment history survives; that account is inactive and can
     * therefore never log in (PRD §16, §39).
     */
    private function resolveUser(?string $nameOrEmail, bool &$createdPlaceholder): ?User
    {
        $createdPlaceholder = false;

        if ($nameOrEmail === null) {
            return null;
        }

        $user = User::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nameOrEmail)])
            ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($nameOrEmail)])
            ->first();

        if ($user) {
            return $user;
        }

        if (! ($this->config['create_missing_users'] ?? true)) {
            return null;
        }

        $createdPlaceholder = true;

        return User::create([
            'name' => $nameOrEmail,
            'email' => $this->placeholderEmail($nameOrEmail),
            'role' => 'viewer',
            'is_active' => false,
        ]);
    }

    private function placeholderEmail(string $name): string
    {
        $slug = Str::slug($name) ?: 'user';
        $domain = (string) ($this->config['placeholder_email_domain'] ?? 'imported.local');
        $email = "{$slug}@{$domain}";
        $suffix = 1;

        while (User::where('email', $email)->exists()) {
            $email = "{$slug}-".(++$suffix)."@{$domain}";
        }

        return $email;
    }

    private function userExists(string $nameOrEmail): bool
    {
        return User::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($nameOrEmail)])
            ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($nameOrEmail)])
            ->exists();
    }

    private function generateAssetId(?string $seed): string
    {
        $base = 'AST-'.(Str::upper(Str::slug((string) $seed, '-')) ?: 'ROW');
        $candidate = $base;
        $suffix = 1;

        while (Asset::where('asset_id', $candidate)->exists()) {
            $candidate = $base.'-'.(++$suffix);
        }

        return $candidate;
    }

    /**
     * Read the asset sheet as a raw value matrix.
     *
     * @return array{0:string, 1:array, 2:array}
     */
    private function readSheet(string $absolutePath): array
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($absolutePath);

        $sheet = null;

        foreach ((array) ($this->config['sheet_names'] ?? []) as $name) {
            $candidate = $spreadsheet->getSheetByName($name);

            if ($candidate) {
                $sheet = $candidate;
                break;
            }
        }

        $sheet ??= $spreadsheet->getSheet(0);
        $title = $sheet->getTitle();

        $rows = $sheet->toArray(null, true, false, false);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $reader, $sheet);

        $header = array_shift($rows) ?? [];
        $rows = array_slice($rows, 0, (int) ($this->config['max_rows'] ?? 20000));

        return [$title, $header, $rows];
    }

    /**
     * "Serial Number" -> "serialnumber", "Harga (Rp)" -> "hargarp".
     */
    private function normalizeHeader(string $label): string
    {
        return (string) Str::of($label)->lower()->replaceMatches('/[^a-z0-9]+/u', '');
    }

    private function extractRow(array $row, array $mapping): array
    {
        $values = [];

        foreach ($mapping as $index => $target) {
            $values[$target] = $row[$index] ?? null;
        }

        return $values;
    }

    private function isEmptyRow(array $values): bool
    {
        foreach ($values as $value) {
            if ($this->cleanString($value) !== null) {
                return false;
            }
        }

        return true;
    }
}
