<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

/**
 * Authorization rules for asset records (PRD §13, §50).
 *
 * Roles:
 *  - admin      : full access
 *  - support    : view, create, edit, audit, transfer, generate documents (cannot destroy history)
 *  - technician : view, audit, repair
 *  - viewer     : read-only
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('admin', 'support', 'technician', 'viewer');
    }

    public function view(User $user, Asset $asset): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('admin', 'support');
    }

    public function update(User $user, Asset $asset): bool
    {
        return $user->hasRole('admin', 'support');
    }

    /**
     * Archive (soft) — assets are never hard deleted, history must survive.
     */
    public function archive(User $user, Asset $asset): bool
    {
        return $user->hasRole('admin', 'support');
    }

    /**
     * Permanent deletion is reserved for IT Admin only.
     */
    public function forceDelete(User $user, Asset $asset): bool
    {
        return $user->isAdmin();
    }

    public function audit(User $user, Asset $asset): bool
    {
        return $user->hasRole('admin', 'support', 'technician');
    }

    public function transfer(User $user, Asset $asset): bool
    {
        return $user->hasRole('admin', 'support');
    }

    public function manageRepair(User $user, Asset $asset): bool
    {
        return $user->hasRole('admin', 'support', 'technician');
    }

    public function generateDocument(User $user, Asset $asset): bool
    {
        return $user->hasRole('admin', 'support');
    }

    /**
     * Upload/delete attachments (PRD §37).
     */
    public function manageAttachments(User $user, Asset $asset): bool
    {
        return $user->hasRole('admin', 'support');
    }
}
