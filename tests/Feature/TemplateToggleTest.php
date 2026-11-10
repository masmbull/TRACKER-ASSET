<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enabling and disabling a document template (templates.toggle).
 *
 * Templates are picked by document generation, so deactivating one is how an
 * outdated form is retired without losing the documents already issued from
 * it. The toggle has to work in both directions, or a mistakenly disabled
 * template could only be fixed in the database.
 */
class TemplateToggleTest extends TestCase
{
    use RefreshDatabase;

    private function template(User $user, string $name = 'FAT Pakem', bool $active = true): DocumentTemplate
    {
        return DocumentTemplate::create([
            'name' => $name,
            'document_type' => 'FAT',
            'template_content' => ['asset_id' => ['label' => 'Asset ID', 'type' => 'text']],
            'is_active' => $active,
            'created_by' => $user->id,
        ]);
    }

    public function test_support_can_toggle_a_template_off_and_back_on(): void
    {
        $support = User::factory()->create(['role' => 'support']);
        $template = $this->template($support);
        $untouched = $this->template($support, 'BAST Pakem', true);

        $this->actingAs($support)
            ->put(route('templates.toggle', $template))
            ->assertRedirect(route('templates.index'))
            ->assertSessionHas('success', 'Template updated.');

        $this->assertFalse($template->fresh()->is_active);
        $this->assertTrue($untouched->fresh()->is_active);

        $this->actingAs($support)
            ->put(route('templates.toggle', $template))
            ->assertRedirect(route('templates.index'));

        $this->assertTrue($template->fresh()->is_active);
    }

    public function test_an_admin_can_toggle_a_template(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $template = $this->template($admin);

        $this->actingAs($admin)
            ->put(route('templates.toggle', $template))
            ->assertRedirect(route('templates.index'));

        $this->assertFalse($template->fresh()->is_active);
    }

    public function test_roles_outside_master_data_management_cannot_toggle_a_template(): void
    {
        foreach (['technician', 'viewer'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $template = $this->template($user, "FAT {$role}");

            $this->actingAs($user)
                ->put(route('templates.toggle', $template))
                ->assertForbidden();

            $this->assertTrue($template->fresh()->is_active);
        }
    }

    public function test_a_guest_cannot_toggle_a_template(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $template = $this->template($owner);

        $this->put(route('templates.toggle', $template))->assertRedirect(route('login'));
        $this->assertTrue($template->fresh()->is_active);
    }
}
