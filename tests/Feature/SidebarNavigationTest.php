<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DocumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sidebar must offer a page once, to the roles allowed to reach it.
 *
 * "Templates" used to appear twice for an admin: once in the DOCUMENTS group,
 * and again as a hand-written <li> further down added when the document pages
 * were made findable (PRD §43). Both linked to templates.index, so the menu
 * offered the same page twice with no way to tell them apart.
 *
 * Counting links to a route is the assertion that catches this. Asserting the
 * label is not enough - "Templates" appearing in prose, a breadcrumb or a
 * heading would satisfy it while the duplication came straight back.
 */
class SidebarNavigationTest extends TestCase
{
    use RefreshDatabase;

    /** How many times the sidebar links to this route. */
    private function linksTo(string $path, User $user): int
    {
        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();

        return substr_count($response->getContent(), 'href="'.url($path).'"');
    }

    public function test_templates_is_linked_once_for_an_admin(): void
    {
        $this->seed(DocumentTemplateSeeder::class);

        $this->assertSame(
            1,
            $this->linksTo(route('templates.index'), User::factory()->create(['role' => 'admin'])),
            'the sidebar must not link the same page twice'
        );
    }

    /**
     * The gate has to survive deduplication.
     *
     * The surviving entry lives in the DOCUMENTS group behind
     * manage-master-data. If that gate were dropped while the duplicate was
     * removed, a viewer would be offered a link that answers 403 - and a menu
     * that sends people to a door they cannot open is worse than one missing
     * the door entirely.
     */
    public function test_a_viewer_is_not_offered_templates(): void
    {
        $this->seed(DocumentTemplateSeeder::class);

        $this->assertSame(
            0,
            $this->linksTo(route('templates.index'), User::factory()->create(['role' => 'viewer']))
        );
    }

    public function test_sidebar_is_permanent_and_has_no_toggle_control(): void
    {
        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('dashboard'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('aria-label="Main navigation"', $html);
        $this->assertStringContainsString('All Documents', $html);
        $this->assertStringNotContainsString('data-sidebar-toggle', $html);
    }

    /**
     * The page the menu is on should be the one marked active.
     *
     * These entries compared the current route against a literal - `$route ===
     * 'templates.*'` - which is a string comparison, not a pattern, so it was
     * false on every page including the templates page itself. Nothing in the
     * suite covered it because the menu rendered, just never highlighted.
     */
    public function test_the_current_section_is_marked_active(): void
    {
        $this->seed(DocumentTemplateSeeder::class);

        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([
            route('templates.index') => 'templates',
            route('services.index') => 'services',
            route('users.index') => 'users',
        ] as $path => $routeName) {
            $html = $this->actingAs($admin)->get($path)->assertOk()->getContent();

            // The active class has to be on the link to *this* page, not merely
            // present somewhere in the markup - several entries share it.
            $this->assertMatchesRegularExpression(
                '/href="'.preg_quote(url($path), '/').'"[^>]*menu-item-active/',
                $html,
                'the sidebar entry for the current page should be marked active'
            );
        }
    }

    /**
     * Master data entries live in their own loop, not in $navGroups, and were
     * matching the same dead pattern.
     */
    public function test_the_current_master_data_type_is_marked_active(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)
            ->get(route('master.index', 'locations'))
            ->assertOk()
            ->getContent();

        // Matched with a pattern, not a contiguous string: the anchor carries an
        // @click attribute between its href and its class, so the two never sit
        // side by side in the markup.
        $this->assertMatchesRegularExpression(
            '/href="'.preg_quote(route('master.index', 'locations'), '/').'"[^>]*menu-item-active/',
            $html,
            'the sidebar should mark the master data type currently open'
        );
    }
}
