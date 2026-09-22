<?php

namespace Database\Seeders;

use App\Models\AssetCategory;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = ['Laptop', 'PC', 'Server', 'Printer', 'Monitor', 'Network', 'CCTV', 'UPS', 'Storage', 'Other'];

        foreach ($categories as $category) {
            AssetCategory::firstOrCreate(['name' => $category]);
        }
    }
}
