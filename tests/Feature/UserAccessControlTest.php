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

        // 14 users at 12 per page is two pages, so the numbered pager must render.
        // It is matched on the "of N results" line, which only the pager emits;
        // the footer's "Showing x to y of z users" and the per-page selector are
        // always present, so neither proves pagination is happening.
        $response = $this->actingAs($admin)
            ->get(route('users.access'))
            ->assertOk()
            ->assertSee('Access Control')
            ->assertSee('Showing')
            ->assertSee('results');

        $this->actingAs(User::factory()->create(['role' => 'support']))
            ->get(route('users.access'))
            ->assertForbidden();
    }

    /** The per-page selector changes how many users are listed, within a known set. */
    public function test_access_control_honours_the_per_page_selection(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(20)->create(['role' => 'viewer']);

        // 21 users at 25 per page is one page, so the pager is not rendered.
        $this->actingAs($admin)
            ->get(route('users.access', ['per_page' => 25]))
            ->assertOk()
            ->assertDontSee('results');

        // An arbitrary value is ignored and falls back to the default of 12, so
        // ?per_page=10000 cannot be used to dump the directory in one query.
        $this->actingAs($admin)
            ->get(route('users.access', ['per_page' => 10000]))
            ->assertOk()
            ->assertSee('results');
    }

    public function test_panel_endpoint_returns_the_selected_users_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create([
            'role' => 'viewer',
            'permission_overrides' => ['assets.create' => true],
        ]);

        $this->actingAs($admin)
            ->get(route('users.access.panel', $viewer))
            ->assertOk()
            ->assertSee($viewer->name)
            ->assertSee($viewer->email)
            ->assertSee('View assets')
            ->assertSee('name="permissions[assets.create]" value="allow" checked', false)
            ->assertDontSee('Choose a user to configure their access.');
    }

    public function test_panel_endpoint_is_admin_only(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)
            ->get(route('users.access.panel', $viewer))
            ->assertForbidden();
    }

    public function test_access_page_renders_the_initial_panel_and_client_side_controls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($admin)
            ->get(route('users.access', ['user' => $viewer->id]))
            ->assertOk()
            ->assertSee('View assets')
            ->assertSee('data-user-search', false)
            ->assertSee('data-user-row', false)
            ->assertSee('accessControl(', false)
            ->assertSee(route('users.access.panel', ['user' => '__USER__']), false);
    }
}
