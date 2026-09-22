<?php

namespace App\Console\Commands;

use App\Services\AssetImportService;
use Illuminate\Console\Command;

class CheckExcelCommand extends Command
{
    protected $signature = 'excel:check {file? : Path to the legacy spreadsheet}';

    protected $description = 'Analyse a legacy asset spreadsheet (mapping, validation, duplicates) without importing';

    public function handle(AssetImportService $importer): int
    {
        $path = (string) ($this->argument('file') ?: base_path('IT Assets.xlsx'));

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $analysis = $importer->analyze($path);
        $summary = $analysis['summary'];

        $this->info('Sheet: '.$analysis['sheet']);
        $this->table(
            ['Rows detected', 'Valid', 'Warning', 'Error', 'Duplicate'],
            [[$summary['total'], $summary['valid'], $summary['warning'], $summary['error'], $summary['duplicate']]]
        );

        $this->line('Mapped columns ('.count($analysis['mapped_columns']).'): '.implode(', ', $analysis['mapped_columns']));

        if ($analysis['unmapped_columns'] !== []) {
            $this->warn('Ignored columns: '.implode(', ', $analysis['unmapped_columns']));
        }

        foreach (array_slice($analysis['rows'], 0, 5) as $row) {
            $issues = $row['issues'] === [] ? 'OK' : implode(' | ', $row['issues']);
            $this->line("Row {$row['row']} [{$row['status']}] {$issues}");
        }

        return self::SUCCESS;
    }
}
