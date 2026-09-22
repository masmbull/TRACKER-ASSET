<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        Company::firstOrCreate(['name' => 'MITO Group'], ['code' => 'MITO', 'address' => '']);
        Company::firstOrCreate(['name' => 'MITO Technology'], ['code' => 'MITO-TECH', 'address' => '']);
        Company::firstOrCreate(['name' => 'PT. Mahakarya Sukses Indonesia'], ['code' => 'MITO-CORP', 'address' => '']);
    }
}
