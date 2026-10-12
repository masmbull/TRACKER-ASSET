<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccessControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_individual_user_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($admin)
            ->put(route('users.access.update', $viewer), [
                'permissions' => [
                    'assets.create' => 'allow',
                    'assets.export' => 'deny',
                    'assets.view' => 'inherit',
                ],
            ])
            ->assertRedirect(route('users.access', ['user' => $viewer->id]));

        $this->assertSame([
            'assets.create' => true,
            'assets.export' => false,
        ], $viewer->fresh()->permission_overrides);

        $this->actingAs($viewer->fresh())->get(route('assets.create'))->assertOk();
        $this->actingAs($viewer->fresh())->get(route('reports.index'))->assertForbidden();
    }

    public function test_access_control_is_paginated_and_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(13)->create(['role' => 'viewer']);

        $this->actingAs($admin)
            ->get(route('users.access'))
            ->assertOk()
            ->assertSee('Access Control')
            ->assertSee('Next');

        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('users.access'))
            ->assertForbidden();
    }
}
