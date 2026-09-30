<?php

use App\Services\MasterDataNormalizer;
use Illuminate\Console\OutputStyle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lookup tables whose name the app already treats as unique.
     *
     * MasterDataController validates `unique:companies,name` and
     * `unique:locations,name` on every create and edit, so the application has
     * always promised these are one row per name. Only the database disagreed:
     * asset_categories and asset_statuses carry the index, companies and
     * locations do not. That gap is why a company whose name differed from the
     * original by one invisible character could be created and then appear
     * twice in every picker, with no error anywhere to explain it.
     *
     * @var list<string>
     */
    private const TABLES_WITHOUT_A_NAME_INDEX = ['companies', 'locations'];

    public function up(): void
    {
        $repair = new MasterDataNormalizer;
        $report = $repair->repair();

        Log::info('Lookup name repair', $report);

        foreach ($report as $table => $result) {
            foreach ($result['merged'] as $duplicate => $survivor) {
                $this->report("{$table}: merged #{$duplicate} into #{$survivor}");
            }

            if ($result['renamed'] > 0) {
                $this->report("{$table}: rewrote {$result['renamed']} name(s)");
            }
        }

        // After the merge, so the index has nothing left to reject.
        foreach (self::TABLES_WITHOUT_A_NAME_INDEX as $table) {
            $collisions = $repair->capitalisationCollisions($table);

            if ($collisions !== []) {
                // Skipped, not failed. This table holds names differing only by
                // capitalisation, so a unique index would be accepted by the
                // SQLite used in development and rejected by the MySQL used in
                // production. Aborting the whole migration over it would leave
                // the repair unrecorded and the duplicates unfixed, which is a
                // much worse outcome than one table without the extra index: the
                // model mutator and the form's unique rule both still apply, and
                // on MySQL the rule already behaves case-insensitively.
                //
                // The names are named so this stays a decision somebody can make
                // rather than a gap discovered in production.
                $this->report(sprintf(
                    'SKIPPED unique name on [%s] - these differ only by capitalisation, so SQLite '
                    .'and MySQL would disagree: %s',
                    $table,
                    implode(', ', $collisions)
                ));

                Log::warning('Unique name index skipped', ['table' => $table, 'names' => $collisions]);

                continue;
            }

            // Re-runnable on purpose. The repair and the index are not one atomic
            // step - SQLite commits the index it manages to create before a later
            // table fails - so an aborted run leaves the index already in place
            // and a second attempt has to skip it rather than trip over itself.
            if (Schema::hasIndex($table, ['name'])) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unique('name');
            });
        }
    }

    /**
     * Say what the repair did, where there is a console to say it to.
     *
     * Migrations run from the test suite too, where no OutputStyle is bound;
     * the report still reaches the log, so nothing is lost by staying quiet.
     */
    private function report(string $line): void
    {
        if (app()->bound(OutputStyle::class)) {
            app(OutputStyle::class)->writeln($line);
        }
    }

    public function down(): void
    {
        // Only the index is reversible, and only where it was actually created -
        // a table skipped above for holding case variants never got one.
        //
        // Un-merging is not: the duplicate row is gone, along with its id and its
        // code, and no amount of un-repointing brings either back. A rollback that
        // claims otherwise would be worse than one that says so.
        foreach (self::TABLES_WITHOUT_A_NAME_INDEX as $table) {
            if (! Schema::hasIndex($table, ['name'])) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropUnique(['name']);
            });
        }
    }
};
