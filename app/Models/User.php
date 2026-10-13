<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'employee_id', 'email', 'password', 'entra_id', 'department', 'job_title', 'company', 'role', 'permission_overrides', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /** @var array<string, array<string, string>> */
    public const PERMISSION_GROUPS = [
        'Assets' => [
            'assets.view' => 'View assets',
            'assets.create' => 'Add assets',
            'assets.update' => 'Edit assets',
            'assets.archive' => 'Retire or dispose assets',
            'assets.audit' => 'Audit assets',
            'assets.transfer' => 'Transfer assets',
            'assets.repair' => 'Manage repairs',
            'assets.attachments' => 'Manage attachments',
            'assets.documents' => 'Generate documents',
            'assets.import' => 'Import Excel',
            'assets.export' => 'Export reports',
        ],
        'Administration' => [
            'master.manage' => 'Manage master data and templates',
            'activity.view' => 'View activity log',
            'users.manage' => 'Manage users',
            'agents.manage' => 'Manage audit agents',
            'services.manage' => 'View service monitor',
        ],
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'permission_overrides' => 'array',
        ];
    }

    public function permissionOverride(string $permission): ?bool
    {
        $value = ($this->permission_overrides ?? [])[$permission] ?? null;

        return is_bool($value) ? $value : null;
    }

    public function assignments()
    {
        return $this->hasMany(AssetAssignment::class, 'user_id');
    }

    public function audits()
    {
        return $this->hasMany(AssetAudit::class, 'auditor_id');
    }

    public function repairs()
    {
        return $this->hasMany(AssetRepair::class, 'created_by');
    }

    public function transfersSubmitted()
    {
        return $this->hasMany(AssetTransfer::class, 'submitted_by');
    }

    public function transfersApproved()
    {
        return $this->hasMany(AssetTransfer::class, 'approved_by');
    }

    public function transfersReceived()
    {
        return $this->hasMany(AssetTransfer::class, 'received_by');
    }

    public function documents()
    {
        return $this->hasMany(Document::class, 'generated_by');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class, 'uploaded_by');
    }

    public function customQrHistories(): HasMany
    {
        return $this->hasMany(CustomQrHistory::class);
    }

    public function activities()
    {
        return $this->hasMany(ActivityLog::class, 'actor_id');
    }

    public function currentAssets()
    {
        return $this->hasMany(Asset::class, 'current_user_id');
    }

    public function adminAssets()
    {
        return $this->hasMany(Asset::class, 'admin_it_pic');
    }

    public function assignmentHistory()
    {
        return $this->hasMany(AssetAssignment::class);
    }

    /**
     * Roles supported by the application (PRD §13).
     */
    public const ROLES = ['admin', 'support', 'technician', 'viewer'];

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function isSupport(): bool
    {
        return $this->hasRole('support');
    }

    public function isTechnician(): bool
    {
        return $this->hasRole('technician');
    }

    public function isViewer(): bool
    {
        return $this->hasRole('viewer');
    }

    /**
     * PRD §13 — Viewers are read-only, everyone else may view assets.
     */
    public function canWriteAssets(): bool
    {
        return $this->hasRole('admin', 'support');
    }
}
