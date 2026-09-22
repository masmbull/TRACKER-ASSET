<?php

namespace Tests\Unit;

use App\Models\Asset;
use App\Models\User;
use App\Policies\AssetPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Role helpers + policy matrix (PRD §13, §46).
 */
class UserTest extends TestCase
{
    public function test_role_helpers(): void
    {
        $admin = new User(['role' => 'admin']);
        $support = new User(['role' => 'support']);
        $technician = new User(['role' => 'technician']);
        $viewer = new User(['role' => 'viewer']);

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->canWriteAssets());

        $this->assertTrue($support->isSupport());
        $this->assertTrue($support->canWriteAssets());

        $this->assertTrue($technician->isTechnician());
        $this->assertFalse($technician->canWriteAssets());

        $this->assertTrue($viewer->isViewer());
        $this->assertFalse($viewer->canWriteAssets());
    }

    public function test_asset_policy_matrix(): void
    {
        $policy = new AssetPolicy;
        $asset = new Asset;

        $admin = new User(['role' => 'admin']);
        $support = new User(['role' => 'support']);
        $technician = new User(['role' => 'technician']);
        $viewer = new User(['role' => 'viewer']);

        // Everyone may read.
        foreach ([$admin, $support, $technician, $viewer] as $user) {
            $this->assertTrue($policy->viewAny($user));
            $this->assertTrue($policy->view($user, $asset));
        }

        // Write access: admin + support only.
        $this->assertTrue($policy->create($support));
        $this->assertTrue($policy->update($support, $asset));
        $this->assertTrue($policy->archive($support, $asset));

        $this->assertFalse($policy->create($viewer));
        $this->assertFalse($policy->update($viewer, $asset));
        $this->assertFalse($policy->archive($viewer, $asset));
        $this->assertFalse($policy->archive($technician, $asset));

        // Audit: admin, support, technician.
        $this->assertTrue($policy->audit($technician, $asset));
        $this->assertFalse($policy->audit($viewer, $asset));

        // Transfer: admin, support.
        $this->assertTrue($policy->transfer($support, $asset));
        $this->assertFalse($policy->transfer($technician, $asset));

        // Repairs: admin, support, technician.
        $this->assertTrue($policy->manageRepair($technician, $asset));
        $this->assertFalse($policy->manageRepair($viewer, $asset));

        // Documents: admin, support.
        $this->assertTrue($policy->generateDocument($support, $asset));
        $this->assertFalse($policy->generateDocument($viewer, $asset));

        // Permanent deletion: admin only.
        $this->assertTrue($policy->forceDelete($admin, $asset));
        $this->assertFalse($policy->forceDelete($support, $asset));
    }
}
