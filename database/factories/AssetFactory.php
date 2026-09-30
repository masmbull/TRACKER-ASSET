<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'asset_id' => 'LAPTOP-'.fake()->unique()->numerify('####'),
            'company_id' => self::lookup(Company::class, 'Test Co', 'TC'),
            'category_id' => self::lookup(AssetCategory::class, 'Laptop'),
            'brand' => fake()->company(),
            'model' => fake()->word(),
            'serial_number' => fake()->bothify('SN-####-####'),
            'hostname' => fake()->unique()->word(),
            'processor' => fake()->word(),
            'storage' => fake()->randomElement(['256GB NVMe', '512GB NVMe', '1TB HDD', '2TB HDD']),
            'ram' => fake()->randomElement(['4GB', '8GB', '16GB', '32GB']),
            'os_version' => 'Microsoft Windows 11 Pro',
            'location_id' => self::lookup(Location::class, 'Jakarta'),
            'purchase_year' => fake()->numberBetween(2020, 2025),
            'vendor' => fake()->company(),
            'purchase_price' => fake()->randomElement([5000000, 7500000, 10000000, 15000000]),
            'useful_life_years' => 4,
            'asset_status_id' => self::lookup(AssetStatus::class, 'Active'),
            'warranty_until' => fake()->dateTimeBetween('now', '+2 years'),
            'deploy_date' => fake()->dateTimeBetween('-1 year', 'now'),
            'mac_address' => fake()->macAddress(),
            'static_ip' => fake()->ipv4(),
            'is_active' => true,
        ];
    }

    /**
     * Resolve a lookup row, creating a default one if the table is empty.
     *
     * These four columns used to be a literal 1, which was right for the first test
     * that ran and wrong for every test after it. SQLite hands out rowid 1 again
     * once a rolled-back transaction empties a table; a PostgreSQL sequence never
     * rewinds, so from the second test onward "id 1" named a row that no longer
     * existed. SQLite does not enforce foreign keys by default either, so the
     * factory was quietly relying on one engine's rollback semantics, and the
     * whole suite only ever ran on that engine.
     *
     * The oldest existing row is reused rather than matched by name, so a test
     * that deliberately builds "Bandung" first still gets a valid company.
     *
     * @param  class-string<Model>  $model
     * @param  array<string, mixed>  $attributes
     */
    private static function lookup(string $model, string $name, ...$attributes): int
    {
        $existing = $model::query()->orderBy('id')->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) $model::query()->create(['name' => $name, ...$attributes])->id;
    }
}
