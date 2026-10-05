<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\DocumentGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who a document names, and where that answer comes from (PRD Ãƒâ€šÃ‚Â§44).
 *
 * The complaint behind these: every transfer form was typed by hand. The register
 * already knew which laptop moved from which person to which person, and none of
 * it reached the document - so somebody retyped the same eleven fields every
 * time a laptop changed hands, and every retyping was a chance to get it wrong.
 *
 * The sender used to default to whoever was logged in, which on a transfer meant
 * the IT administrator was named as the person handing the laptop back.
 */
class DocumentPartySourceTest extends TestCase
{
    use RefreshDatabase;

    private Asset $asset;

    private User $first;

    private User $second;

    private User $itPic;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Company::create(['name' => 'Mito Group', 'code' => 'MITO', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->first = User::factory()->create([
            'role' => 'support',
            'name' => 'First Party',
            'department' => 'Finance',
            'email' => 'first@mito.test',
        ]);

        $this->second = User::factory()->create([
            'role' => 'support',
            'name' => 'Second Party',
            'department' => 'Operations',
            'email' => 'second@mito.test',
        ]);

        $this->itPic = User::factory()->create([
            'role' => 'admin',
            'name' => 'IT Person',
            'department' => 'IT',
            'email' => 'it@mito.test',
        ]);

        $this->asset = Asset::factory()->create([
            'current_user_id' => $this->second->id,
            'admin_it_pic' => $this->itPic->id,
        ]);
    }

    /**
     * Record that the laptop was issued, then handed to somebody else.
     */
    private function recordHistory(): void
    {
        AssetAssignment::create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->first->id,
            'assigned_date' => now()->subYear(),
        ]);

        AssetAssignment::create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->second->id,
            'assigned_date' => now()->subMonth(),
        ]);
    }

    private function parties(string $type): array
    {
        $payload = app(DocumentGenerationService::class)->buildPayload(
            $this->asset->fresh(),
            $type,
            [],
            null
        );

        return $payload['parties'];
    }

    /**
     * The first party is the person who had it first, not the current holder.
     */
    public function test_the_history_exposes_who_had_it_first_and_who_has_it_now(): void
    {
        $this->recordHistory();

        $this->assertSame('First Party', $this->asset->firstHolder()->user->name);
        $this->assertSame('Second Party', $this->asset->currentHolder()->user->name);
    }

    /**
     * The ordering has to be stable, or the same document names a different
     * person each time it is opened.
     */
    public function test_two_assignments_on_the_same_day_still_order_consistently(): void
    {
        $sameDay = now()->subMonths(2);

        AssetAssignment::create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->first->id,
            'assigned_date' => $sameDay,
        ]);

        AssetAssignment::create([
            'asset_id' => $this->asset->id,
            'user_id' => $this->second->id,
            'assigned_date' => $sameDay,
        ]);

        // Read it twice: a "first" that depends on insertion order by accident
        // is a fact about the database, not about the asset.
        $this->assertSame('First Party', $this->asset->firstHolder()->user->name);
        $this->assertSame('First Party', $this->asset->fresh()->firstHolder()->user->name);
    }

    /**
     * A laptop nobody has taken yet has no holder, and offering the previous
     * holder as "who you are receiving this from" would be wrong.
     */
    public function test_an_asset_with_no_history_has_no_holder(): void
    {
        $this->assertNull($this->asset->firstHolder());
        $this->assertNull($this->asset->currentHolder());
    }

    /**
     * A fresh issue names IT as the sender, because nobody is handing a brand new
     * laptop back and naming the employee as "penyerah" is the wrong shape of
     * document.
     */
    public function test_a_fresh_issue_names_the_it_pic_as_the_sender(): void
    {
        $parties = $this->parties('FAT');

        $this->assertSame('IT Person', $parties['sender']['name']);
        $this->assertSame('IT', $parties['sender']['division']);
        $this->assertSame('Second Party', $parties['receiver']['name']);
    }

    /**
     * On a return it is the employee handing it back.
     *
     * This is the case that was actively wrong: the sender defaulted to the
     * logged-in administrator, so a BAST said IT gave the laptop back.
     */
    public function test_a_return_names_the_employee_as_the_sender(): void
    {
        $this->recordHistory();

        $parties = $this->parties('BAST');

        $this->assertSame('Second Party', $parties['sender']['name']);
        $this->assertSame('Operations', $parties['sender']['division']);
    }

    /**
     * An explicit choice on the form always wins over the derived value.
     *
     * The derivation is a default, not a lock: the document is signed by people,
     * and a proxy signing on someone's behalf has to be able to say so.
     */
    public function test_an_explicit_choice_on_the_form_still_wins(): void
    {
        $this->recordHistory();

        $payload = app(DocumentGenerationService::class)->buildPayload(
            $this->asset->fresh(),
            'BAST',
            [
                'signatory_menyerahkan' => 'Someone Else Entirely',
                'signatory_menerima' => 'Receiver Typed By Hand',
            ],
            null
        );

        $this->assertSame('Someone Else Entirely', $payload['parties']['sender']['name']);
        $this->assertSame('Receiver Typed By Hand', $payload['parties']['receiver']['name']);
    }

    /**
     * The form has to arrive filled in, or the derivation saves nothing.
     *
     * The point of the change is that nobody retypes eleven fields. If the payload
     * is right but the form still opens blank, the copy-paste remains.
     */
    public function test_the_generate_form_arrives_prefilled_with_both_parties(): void
    {
        $this->recordHistory();

        $admin = User::factory()->create(['role' => 'admin', 'name' => 'The Administrator']);

        $response = $this->actingAs($admin)
            ->get(route('documents.generate', ['asset' => $this->asset, 'document_type' => 'BAST']));

        $response->assertOk();

        // Second Party is handing it back; the form is for a BAST.
        $response->assertSee('name="signatory_menyerahkan" value="Second Party"', false);

        // And critically: not the administrator who is logged in and running it.
        $response->assertDontSee('name="signatory_menyerahkan" value="The Administrator"', false);
    }

    public function test_the_form_still_renders_for_an_asset_with_no_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('documents.generate', ['asset' => $this->asset]))
            ->assertOk();
    }
}
