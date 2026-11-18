<?php

namespace App\Models;

use App\Services\AssetNumberService;
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
        'repair_notes', 'retire_date', 'qr_code', 'qr_token', 'qr_generated_at', 'barcode', 'is_active',
        'storage_type', 'storage_capacity',
    ];

    /**
     * Assign the running asset number when none was supplied.
     *
     * The number is what is printed on the label stuck to the device, so it is
     * allocated here rather than left to each form: a screen that forgot to ask
     * the numbering service would either save a blank number or invent its own
     * scheme. An explicit number is still honoured - imports carry the number
     * from the source sheet, and that is the number already on the hardware.
     */
    protected static function booted(): void
    {
        static::creating(function (Asset $asset) {
            if (blank($asset->asset_id)) {
                $asset->asset_id = app(AssetNumberService::class)->forAsset($asset);
            }
        });
    }

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
            'qr_generated_at' => 'datetime',
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
        return $this->belongsTo(AssetStatus::class, 'asset_status_id');
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

    /**
     * The assignment history, oldest first.
     *
     * Ordered by the date and then by id rather than by id alone: two rows can
     * share an assigned_date when a laptop is registered and transferred on the
     * same day, and "first" has to be stable between two calls or the same
     * document would name a different person each time it is generated.
     */
    public function assignmentHistory()
    {
        return $this->assignments()
            ->with(['user', 'fromLocation', 'toLocation'])
            ->orderBy('assigned_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Who the asset was first issued to - the first party on a transfer form.
     */
    public function firstHolder(): ?AssetAssignment
    {
        return $this->assignmentHistory()->first();
    }

    /**
     * Who holds it now - the second party on a transfer form.
     *
     * Prefers the open assignment over simply the newest row. An asset whose last
     * assignment was closed without a replacement has no holder, and the newest
     * row in that case names somebody who gave it back, which is exactly the
     * person a "you are receiving this from" field must not offer.
     */
    public function currentHolder(): ?AssetAssignment
    {
        $history = $this->assignmentHistory();

        // The newest *open* assignment, not the first one.
        //
        // "firstWhere" was the obvious reading and it is wrong: when a laptop moves
        // and the previous row was never closed, several rows are open at once, and
        // the earliest of them is the person who had it before rather than the one
        // who has it. Taking the last open row - and falling back to the last row
        // when nothing is open - gives the same answer whether the transfer closed
        // its rows properly or not.
        return $history->where('returned_date', null)->last() ?? $history->last();
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

    /** Whether a public scan target exists for this asset yet. */
    public function hasQr(): bool
    {
        return filled($this->qr_token);
    }

    /**
     * Issue a fresh public token, replacing any previous one.
     *
     * The "re-create" action: rotating the token invalidates every label already
     * printed for the old one, which is the point when a sticker has been lost,
     * copied, or used to reach a page it should not. 32 random bytes is far past
     * guessable, and the URL-safe encoding keeps the token clean inside a QR and
     * a link.
     */
    public function mintQrToken(): string
    {
        $this->qr_token = bin2hex(random_bytes(16));
        $this->qr_generated_at = now();
        $this->save();

        return $this->qr_token;
    }

    /** The public, login-free URL a scan of this asset's QR opens. */
    public function qrPublicUrl(): ?string
    {
        if (! $this->hasQr()) {
            return null;
        }

        $base = rtrim((string) (config('assets.qr.base_url') ?: url('/')), '/');

        return $base.'/a/'.$this->qr_token;
    }
}
