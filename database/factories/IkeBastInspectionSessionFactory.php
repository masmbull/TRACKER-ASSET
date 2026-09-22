<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\IkeBastInspectionSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class IkeBastInspectionSessionFactory extends Factory
{
    protected $model = IkeBastInspectionSession::class;

    public function definition(): array
    {
        static $sequence = 0;
        $sequence++;

        return [
            'asset_id' => Asset::factory(),
            'transfer_id' => null,
            'created_by' => User::factory(),
            'session_number' => 'IKE/'.now()->format('Ymd').'/'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
            'inspection_date' => now()->toDateString(),
            'kind' => 'IKE_BAST',
            'status' => 'submitted',
            'notes' => null,
        ];
    }
}
