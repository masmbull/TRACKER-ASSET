<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        Location::firstOrCreate(['name' => 'Jakarta HQ', 'branch' => 'Main', 'type' => 'office', 'address' => '', 'status' => 'active']);
        Location::firstOrCreate(['name' => 'Bandung Office', 'branch' => 'West Java', 'type' => 'office', 'address' => '', 'status' => 'active']);
        Location::firstOrCreate(['name' => 'Surabaya Office', 'branch' => 'East Java', 'type' => 'office', 'address' => '', 'status' => 'active']);
        Location::firstOrCreate(['name' => 'Warehouse Jakarta', 'branch' => 'Main', 'type' => 'warehouse', 'address' => '', 'status' => 'active']);
        Location::firstOrCreate(['name' => 'Warehouse Bandung', 'branch' => 'West Java', 'type' => 'warehouse', 'address' => '', 'status' => 'active']);
    }
}
