<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'employee_id', 'email', 'password', 'entra_id', 'department', 'job_title', 'company', 'role', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
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
