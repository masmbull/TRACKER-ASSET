<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\User;
use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Directory import: the office sends a workbook with a full name and an
 * office email. Email is the identity - names change, addresses do not - so
 * every row is matched case-insensitively on a normalised email address.
 *
 * An optional Asset ID column turns the same workbook into an assignment
 * sheet: the matched user becomes the current holder of that asset, with a
 * real AssetAssignment row so the handover appears in the history.
 */
class UserImportService
{
    public const VALID = 'valid';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public const DUPLICATE = 'duplicate';

    public function analyze(string $path): array
    {
        [$sheetTitle, $headers, $rows] = $this->readSheet($path);

        $mapped = [];
        $unmapped = [];
        foreach ($headers as $index => $header) {
            $key = $this->normalizeHeader((string) $header);
            $field = config('users-import.column_map.'.$key);

            if ($field) {
                $mapped[$index] = $field;
            } elseif ($key !== '') {
                $unmapped[] = (string) $header;
            }
        }

        if (! in_array('name', $mapped, true) || ! in_array('email', $mapped, true)) {
            $unmapped[] = 'Missing required column: Full Name and Office Email are both required.';
        }

        $summary = [self::VALID => 0, self::WARNING => 0, self::ERROR => 0, self::DUPLICATE => 0];
        $preview = [];
        $seenEmails = [];

        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $values = $this->extract($row, $mapped);
            $email = $this->normalizeEmail($values['email'] ?? null);
            $name = NameNormalizer::normalize($values['name'] ?? null);
            $assetId = NameNormalizer::normalize($values['asset_id'] ?? null);

            if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $status = self::ERROR;
                $note = $email === null ? 'Office Email is empty.' : 'Office Email is not a valid address.';
            } elseif ($name === null) {
                $status = self::ERROR;
                $note = 'Full Name is empty.';
            } elseif (isset($seenEmails[$email])) {
                $status = self::DUPLICATE;
                $note = 'Email repeats a row above; only the first row is imported.';
            } else {
                $seenEmails[$email] = true;
                $existing = $this->findUser($email);
                $asset = $assetId !== null ? $this->findAsset($assetId) : null;

                if ($existing && $asset === null && $assetId !== null) {
                    $status = self::WARNING;
                    $note = 'Asset ID '.$assetId.' was not found; the user is still imported.';
                } elseif ($existing) {
                    $status = self::WARNING;
                    $note = 'User already exists; the name is updated if it changed.';
                } elseif ($assetId !== null && $asset === null) {
                    $status = self::WARNING;
                    $note = 'Asset ID '.$assetId.' was not found; the user is still created.';
                } else {
                    $status = self::VALID;
                    $note = 'New user'.($asset ? ' and asset holder' : '').'.';
                }
            }

            $summary[$status]++;
            $preview[] = [
                'row' => $index + 2, // +1 for zero-based, +1 for the header.
                'name' => $name,
                'email' => $email,
                'asset_id' => $assetId,
                'status' => $status,
                'note' => $note,
            ];
        }

        return [
            'sheet' => $sheetTitle,
            'mapped_columns' => array_values(array_unique($mapped)),
            'unmapped_columns' => array_values(array_unique($unmapped)),
            'summary' => $summary,
            'rows' => array_slice($preview, 0, (int) config('users-import.preview_limit', 50)),
        ];
    }

    /**
     * @return array{created: int, updated: int, assignments: int, skipped: int, errors: array<int, string>}
     */
    public function import(string $path, ?int $actorId = null): array
    {
        [, $headers, $rows] = $this->readSheet($path);

        $mapped = [];
        foreach ($headers as $index => $header) {
            $field = config('users-import.column_map.'.$this->normalizeHeader((string) $header));
            if ($field) {
                $mapped[$index] = $field;
            }
        }

        $result = ['created' => 0, 'updated' => 0, 'assignments' => 0, 'skipped' => 0, 'errors' => []];
        $seen = [];

        foreach ($rows as $index => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $values = $this->extract($row, $mapped);
            $email = $this->normalizeEmail($values['email'] ?? null);
            $name = NameNormalizer::normalize($values['name'] ?? null);
            $assetId = NameNormalizer::normalize($values['asset_id'] ?? null);
            $line = $index + 2;

            if ($email === null || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $name === null) {
                $result['skipped']++;
                $result['errors'][] = 'Row '.$line.': a valid Full Name and Office Email are required.';

                continue;
            }

            if (isset($seen[$email])) {
                $result['skipped']++;
                $result['errors'][] = 'Row '.$line.': duplicate email '.$email.'.';

                continue;
            }
            $seen[$email] = true;

            try {
                DB::transaction(function () use ($email, $name, $assetId, $actorId, &$result) {
                    $user = $this->findUser($email);

                    if ($user === null) {
                        $user = User::create([
                            'name' => $name,
                            'email' => $email,
                            'role' => config('users-import.default_role', 'viewer'),
                            'is_active' => (bool) config('users-import.default_is_active', true),
                        ]);
                        $result['created']++;
                    } elseif ($user->name !== $name) {
                        $user->update(['name' => $name]);
                        $result['updated']++;
                    }

                    if ($assetId === null) {
                        return;
                    }

                    $asset = $this->findAsset($assetId);
                    if ($asset === null) {
                        return;
                    }

                    AssetAssignment::where('asset_id', $asset->id)
                        ->whereNull('returned_date')
                        ->update(['returned_date' => now()]);

                    AssetAssignment::create([
                        'asset_id' => $asset->id,
                        'user_id' => $user->id,
                        'assigned_date' => now()->toDateString(),
                        'from_location_id' => $asset->location_id,
                        'to_location_id' => $asset->location_id,
                        'notes' => 'Assigned from the people directory import.',
                        'created_by' => $actorId,
                    ]);

                    $asset->update(['current_user_id' => $user->id]);
                    $result['assignments']++;
                });
            } catch (\Throwable $e) {
                $result['skipped']++;
                $result['errors'][] = 'Row '.$line.': '.$e->getMessage();
            }
        }

        $result['errors'] = array_slice($result['errors'], 0, 20);

        return $result;
    }

    public function normalizeEmail(mixed $value): ?string
    {
        $clean = NameNormalizer::normalize($value);

        return $clean !== null ? mb_strtolower($clean) : null;
    }

    private function findUser(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    private function findAsset(string $assetId): ?Asset
    {
        return Asset::query()->whereRaw('LOWER(asset_id) = ?', [mb_strtolower($assetId)])->first();
    }

    private function normalizeHeader(string $label): string
    {
        return (string) Str::of($label)->lower()->replaceMatches('/[^a-z0-9]+/u', '');
    }

    /** @return array<string, mixed> */
    private function extract(array $row, array $mapped): array
    {
        $values = [];
        foreach ($row as $index => $value) {
            if (isset($mapped[$index])) {
                $values[$mapped[$index]] = $value;
            }
        }

        return $values;
    }

    /** @return array{0: string, 1: array, 2: array} */
    private function readSheet(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $sheet = null;
        foreach ((array) config('users-import.sheet_names', ['Users', 'Sheet1']) as $name) {
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

        return [$title, $header, array_slice($rows, 0, (int) config('users-import.max_rows', 20000))];
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (NameNormalizer::normalize($value) !== null) {
                return false;
            }
        }

        return true;
    }
}
