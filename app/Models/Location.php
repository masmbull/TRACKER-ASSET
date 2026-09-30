<?php

namespace App\Models;

use App\Models\Concerns\NormalizesName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory, NormalizesName;

    protected $fillable = ['name', 'branch', 'type', 'address', 'status'];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function assignmentsFrom(): HasMany
    {
        return $this->hasMany(AssetAssignment::class, 'from_location_id');
    }

    public function assignmentsTo(): HasMany
    {
        return $this->hasMany(AssetAssignment::class, 'to_location_id');
    }

    public function transfersFrom(): HasMany
    {
        return $this->hasMany(AssetTransfer::class, 'from_location_id');
    }

    public function transfersTo(): HasMany
    {
        return $this->hasMany(AssetTransfer::class, 'to_location_id');
    }
}
