<?php

namespace App\Models;

use App\Models\Concerns\NormalizesName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasFactory, NormalizesName;

    protected $fillable = ['name', 'code', 'address', 'status'];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class);
    }
}
