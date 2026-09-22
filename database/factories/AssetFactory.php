<?php

namespace Database\Factories;

use App\Models\Asset;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'asset_id' => 'LAPTOP-'.fake()->unique()->numerify('####'),
            'company_id' => 1,
            'category_id' => 1,
            'brand' => fake()->company(),
            'model' => fake()->word(),
            'serial_number' => fake()->bothify('SN-####-####'),
            'hostname' => fake()->unique()->word(),
            'processor' => fake()->word(),
            'storage' => fake()->randomElement(['256GB NVMe', '512GB NVMe', '1TB HDD', '2TB HDD']),
            'ram' => fake()->randomElement(['4GB', '8GB', '16GB', '32GB']),
            'os_version' => 'Microsoft Windows 11 Pro',
            'location_id' => 1,
            'purchase_year' => fake()->numberBetween(2020, 2025),
            'vendor' => fake()->company(),
            'purchase_price' => fake()->randomElement([5000000, 7500000, 10000000, 15000000]),
            'useful_life_years' => 4,
            'asset_status_id' => 1,
            'warranty_until' => fake()->dateTimeBetween('now', '+2 years'),
            'deploy_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'mac_address' => fake()->macAddress(),
            'static_ip' => fake()->ipv4(),
            'is_active' => true,
        ];
    }
}
