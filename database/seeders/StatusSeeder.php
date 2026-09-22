<?php

namespace Database\Seeders;

use App\Models\AssetStatus;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['name' => 'Active', 'color' => 'green'],
            ['name' => 'Available', 'color' => 'blue'],
            ['name' => 'Assigned', 'color' => 'yellow'],
            ['name' => 'Repair', 'color' => 'orange'],
            ['name' => 'Spare', 'color' => 'gray'],
            ['name' => 'Lost', 'color' => 'red'],
            ['name' => 'Retired', 'color' => 'dark-gray'],
            ['name' => 'Disposed', 'color' => 'black'],
        ];

        foreach ($statuses as $status) {
            AssetStatus::firstOrCreate($status);
        }
    }
}
