<?php

namespace Database\Seeders;

use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanySeeder extends Seeder
{
    public function run(): void
    {
        $entities = [
            ['name' => 'PT. MAHAKARYA SUKSES INDONESIA', 'code' => 'MSI', 'address' => ''],
            ['name' => 'PT. STEIN PERKASA INTERNASIONAL', 'code' => 'SPI', 'address' => ''],
            ['name' => 'PT. MITRA ELEKTRO PERKASA', 'code' => 'MEP', 'address' => ''],
            ['name' => 'PT. PERKASA INJEKSI INDONESIA', 'code' => 'PII', 'address' => ''],
            ['name' => 'PT. SUKA DESSERT INDONESIA', 'code' => 'SDI', 'address' => ''],
        ];

        foreach ($entities as $entity) {
            Company::firstOrCreate(['code' => $entity['code']], ['name' => $entity['name'], 'address' => $entity['address']]);
        }
    }
}
