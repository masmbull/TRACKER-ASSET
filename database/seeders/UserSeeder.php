<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate([
            'email' => 'shohibul.anwar@mito.co.id',
        ], [
            'name' => 'Shohibul Anwar',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        User::firstOrCreate([
            'email' => 'it-support@mito.co.id',
        ], [
            'name' => 'IT Support',
            'password' => Hash::make('password'),
            'role' => 'support',
            'is_active' => true,
        ]);

        User::firstOrCreate([
            'email' => 'it-technician@mito.co.id',
        ], [
            'name' => 'IT Technician',
            'password' => Hash::make('password'),
            'role' => 'technician',
            'is_active' => true,
        ]);

        User::firstOrCreate([
            'email' => 'viewer@mito.co.id',
        ], [
            'name' => 'Viewer',
            'password' => Hash::make('password'),
            'role' => 'viewer',
            'is_active' => true,
        ]);
    }
}
