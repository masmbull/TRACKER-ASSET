<?php

namespace App\Services;

use App\Support\NameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs lookup tables that hold the same entity twice.
 *
 * PRD §17-§20 make the name the identity of a company, location, category and
 * status. Two rows whose names are identical once invisible characters are
 * removed are one entity stored twice, and nothing above this level can tell
 * them apart: they render identically in every dropdown, they sign identically
 * on a generated document, and picking the wrong one in a form looks exactly
 * like picking the right one.
 *
 * The register was already carrying such a pair. Companies 3 and 7 held the same
 * name, one prefixed with the UTF-8 byte order mark an Excel export leaves at
 * the head of a sheet, with five assets pointing at the copy nobody could see.
 *
 * Two things this deliberately does not do:
 *
 *  - It does not merge names that merely look alike. "PT. Mito" and "PT Mito"
 *    are different strings after normalisation and are left alone; concluding
 *    they are one organisation is a judgement about the business rather than a
 *    data repair, and guessing it would silently erase a distinction somebody
 *    made on purpose.
 *
 *  - It does not rewrite generated documents. A stored payload and its PDF are
 *    a record of what was signed on a particular day. Editing them to match
 *    today's master data would make the archive disagree with the paperwork it
 *    exists to preserve.
 */
class MasterDataNormalizer
{
    /**
     * The lookup tables, in the order they are repaired.
     *
     * @var list<string>
     */
    public const TABLES = ['companies', 'locations', 'asset_categories', 'asset_statuses'];

    /**
     * Normalise every stored name, merging rows that collapse onto one name.
     *
     * @return array<string, array{renamed: int, merged: array<string, string>}>
     *                                                                           per table: rows rewritten in place, and the ids that were folded
     *                                                                           away and into what.
     */
    public function repair(): array
    {
        $report = [];

        foreach (self::TABLES as $table) {
            $report[$table] = $this->repairTable($table);
        }

        return $report;
    }

    /**
     * @return array{renamed: int, merged: array<string, string>}
     */
    private function repairTable(string $table): array
    {
        $merged = [];
        $renamed = 0;

        foreach ($this->groupByNormalizedName($table) as $rows) {
            // Ordered by id, so the row that survives is the one every existing
            // reference already points at. After normalising, either candidate
            // would carry the same name, so which one lives changes nothing a
            // user can see - only which id their assets keep.
            $keep = $rows[0];

            foreach (array_slice($rows, 1) as $duplicate) {
                $this->repoint($table, $duplicate->id, $keep->id);
                $merged[(string) $duplicate->id] = (string) $keep->id;

                DB::table($table)->where('id', $duplicate->id)->delete();
            }

            if ((string) $keep->name !== $keep->normalized) {
                DB::table($table)->where('id', $keep->id)->update(['name' => $keep->normalized]);
                $renamed++;
            }
        }

        return ['renamed' => $renamed, 'merged' => $merged];
    }

    /**
     * Bucket every row of a table by the name it normalises to.
     *
     * A name that normalises away entirely is given a key of its own, so a row
     * whose name is nothing but a byte order mark gets reported but is never
     * folded into a neighbour it merely resembles.
     *
     * @return list<list<object{id:int, name:string, normalized:string}>>
     */
    private function groupByNormalizedName(string $table): array
    {
        $buckets = [];

        foreach (DB::table($table)->orderBy('id')->get(['id', 'name']) as $row) {
            $normalized = NameNormalizer::normalize($row->name);

            $buckets[$normalized ?? "\0unprintable\0".$row->id][] = (object) [
                'id' => $row->id,
                'name' => (string) $row->name,
                'normalized' => $normalized ?? '',
            ];
        }

        return array_values($buckets);
    }

    /**
     * Move every reference from a duplicate row onto the row that survives.
     *
     * The referencing columns are read from the schema rather than listed here,
     * so a table that starts pointing at one of these four later is covered
     * without anyone having to remember to come back and edit this method.
     */
    private function repoint(string $table, int $from, int $to): void
    {
        foreach ($this->referencesTo($table) as [$referencingTable, $column]) {
            $query = DB::table($referencingTable)->where($column, $from);

            // A self-referencing table can end up pointing a row at itself once
            // the row it used to hang under is the one being folded away. That
            // is not a hierarchy, it is a loop.
            if ($referencingTable === $table) {
                $query->where('id', '!=', $to);
            }

            $query->update([$column => $to]);
        }
    }

    /**
     * Every (table, column) pair holding a foreign key into $table.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function referencesTo(string $table): array
    {
        $references = [];

        foreach (Schema::getTables() as $candidate) {
            $name = is_array($candidate) ? $candidate['name'] : $candidate->name;

            foreach (Schema::getForeignKeys($name) as $foreign) {
                if (($foreign['foreign_table'] ?? null) !== $table) {
                    continue;
                }

                foreach ($foreign['columns'] as $position => $column) {
                    // Only the plain "points at the id" shape is safe to move. A
                    // composite key could be part of a wider identity, and
                    // rewriting one column of it would invent a combination that
                    // never existed.
                    if (($foreign['foreign_columns'][$position] ?? null) === 'id') {
                        $references[] = [$name, $column];
                    }
                }
            }
        }

        return $references;
    }

    /**
     * Names that are distinct here but would collide under a case-folding index.
     *
     * MySQL compares text case-insensitively by default, so a table holding both
     * "PABRIK BITUNG" and "Pabrik Bitung" is unique on SQLite and not on MySQL.
     * An index created on one and not the other leaves two databases disagreeing
     * about the same data, so the caller has to be told which tables are in that
     * state and which are safe.
     *
     * These are reported rather than merged. Case is a deliberate choice about
     * how a site is written down, and unlike an invisible character it is
     * visible: somebody typed "PABRIK BITUNG" and can see that they did.
     *
     * @return list<string>
     */
    public function capitalisationCollisions(string $table): array
    {
        $seen = [];
        $collisions = [];

        foreach (DB::table($table)->pluck('name') as $name) {
            $key = mb_strtolower(NameNormalizer::normalize($name) ?? "\0unprintable\0");

            if (isset($seen[$key])) {
                $collisions[] = (string) $name;
            }

            $seen[$key] = true;
        }

        return array_values(array_unique($collisions));
    }
}
