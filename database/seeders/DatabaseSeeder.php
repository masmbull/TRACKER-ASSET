<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CompanySeeder::class,
            LocationSeeder::class,
            CategorySeeder::class,
            StatusSeeder::class,
            UserSeeder::class,
            DocumentTemplateSeeder::class,
        ]);

        // ponytail: ExcelImportSeeder deliberately unwired — it re-imports the demo
        // sheet (IT Assets.xlsx), so a fresh install was never actually empty.
        // Run `php artisan db:seed --class=ExcelImportSeeder` when demo assets are wanted.
    }
}
