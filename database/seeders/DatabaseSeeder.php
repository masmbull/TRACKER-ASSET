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
            ExcelImportSeeder::class,
        ]);
    }
}
