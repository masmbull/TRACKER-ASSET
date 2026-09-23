<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'asset_id', 'company_id', 'category_id', 'brand', 'model', 'serial_number',
        'hostname', 'processor', 'storage', 'ram', 'os_version', 'location_id',
        'purchase_year', 'vendor', 'purchase_price', 'useful_life_years',
        'asset_status_id', 'warranty_until', 'deploy_date', 'software_license',
        'mac_address', 'static_ip', 'current_user_id', 'admin_it_pic',
        'repair_notes', 'retire_date', 'qr_code', 'barcode', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'warranty_until' => 'date',
            'deploy_date' => 'date',
            'retire_date' => 'date',
            'purchase_year' => 'integer',
            'useful_life_years' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(AssetStatus::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function currentUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_user_id');
    }

    public function adminItPic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_it_pic');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(AssetAudit::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(AssetRepair::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AssetTransfer::class);
    }

    /**
     * Retire or dispose an asset (PRD §G3): sets the terminal status, records
     * the retirement date, closes the open assignment and releases the holder.
     * History is preserved — the asset is never deleted.
     */
    public function retire(string $action): static
    {
        $status = AssetStatus::where('name', $action)->firstOrFail();

        $openAssignment = $this->assignments()->whereNull('returned_date')->latest('assigned_date')->first();

        if ($openAssignment) {
            $openAssignment->close(now()->toDateString());
        }

        $this->asset_status_id = $status->id;
        $this->retire_date = $this->retire_date ?? now()->toDateString();
        $this->current_user_id = null;
        $this->save();

        return $this;
    }

    public function inspectionSessions(): HasMany
    {
        return $this->hasMany(IkeBastInspectionSession::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'entity_id')->where('entity_type', 'assets');
    }
}
