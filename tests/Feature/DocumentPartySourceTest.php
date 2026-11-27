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
use Illuminate\Support\Facades\Schema;
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
            'employee_id' => 'EMP-IT',
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
     * A RETURN names the employee as the sender and IT as the receiver.
     *
     * The case that was actively wrong: the sender defaulted to the
     * logged-in administrator, so a return said IT gave the laptop back.
     *
     * RETURN, not BAST. BAST is "Berita Acara Serah Terima" for a *new* asset -
     * an issue, like FAT. Reading it as a return is what the first version of
     * this code did, and it wrote that assumption straight into a passing test,
     * which is how a wrong rule manages to look verified.
     */
    public function test_a_return_names_the_employee_as_the_sender(): void
    {
        $this->recordHistory();

        $parties = $this->parties('RETURN');

        $this->assertSame('Second Party', $parties['sender']['name'], 'The holder is handing it back.');
        $this->assertSame('Operations', $parties['sender']['division']);
        $this->assertSame('IT Person', $parties['receiver']['name'], 'IT takes it back.');
    }

    /**
     * A BAST is an issue, not a return - the same as FAT.
     *
     * Pinned because getting this backwards is the mistake that motivated the
     * test above: on a brand new asset BAST has IT handing it to the employee.
     */
    public function test_a_bast_is_an_issue_not_a_return(): void
    {
        $this->recordHistory();

        $parties = $this->parties('BAST');

        $this->assertSame('IT Person', $parties['sender']['name']);
        $this->assertSame('Second Party', $parties['receiver']['name']);
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

        // A single FAT now covers handover, transfer and return. The person
        // holding the asset is the receiver on that one form.
        $response = $this->actingAs($admin)
            ->get(route('documents.generate', ['asset' => $this->asset, 'document_type' => 'FAT']));

        $response->assertOk();

        $response->assertSee('name="signatory_menerima" value="Second Party"', false);

        // And critically: not the administrator who is logged in and running it.
        $response->assertDontSee('name="signatory_menerima" value="The Administrator"', false);
    }

    /**
     * The employee number follows the person, so nobody retypes it off a payroll sheet.
     *
     * The form asked for "NIK / ID Karyawan" on every document for as long as it has
     * existed and could never fill itself in: it defaulted from
     * Auth::user()?->employee_id, and there has never been an employee_id column.
     * So this box was empty every single time, which is the whole reason the document
     * forms were being filled by hand.
     */
    public function test_the_employee_number_comes_from_the_register(): void
    {
        $this->first->update(['employee_id' => 'EMP-001']);
        $this->second->update(['employee_id' => 'EMP-002']);

        $this->recordHistory();

        // A RETURN: the holder hands the laptop back, IT receives it.
        $parties = $this->parties('RETURN');

        $this->assertSame('EMP-002', $parties['sender']['employee_id']);
        $this->assertSame('EMP-IT', $parties['receiver']['employee_id']);

        // A fresh issue: IT hands it over, and the number has to follow IT.
        $fresh = $this->parties('FAT');
        $this->assertSame('EMP-IT', $fresh['sender']['employee_id']);
    }

    /**
     * A typed value still wins, and a person with no number leaves it blank.
     *
     * Blank rather than borrowed: putting one person's employee number on another
     * person's signature block is worse than an empty box.
     */
    public function test_a_typed_employee_number_wins_and_a_missing_one_stays_blank(): void
    {
        $this->first->update(['employee_id' => 'EMP-001']);

        $payload = app(DocumentGenerationService::class)->buildPayload(
            $this->asset->fresh(),
            'BAST',
            ['sender_employee_id' => 'TYPED-BY-HAND'],
            null
        );

        $this->assertSame('TYPED-BY-HAND', $payload['parties']['sender']['employee_id']);

        // Nobody has a number on this asset: no fallback may invent one.
        User::query()->update(['employee_id' => null]);

        $blank = app(DocumentGenerationService::class)->buildPayload(
            $this->asset->fresh(),
            'FAT',
            [],
            null
        );

        $this->assertNull($blank['parties']['sender']['employee_id']);
        $this->assertNull($blank['parties']['receiver']['employee_id']);
    }

    /**
     * The column has to actually exist, or the whole feature is decoration again.
     *
     * Asserting against the schema rather than against a model attribute, because a
     * missing column in Eloquent reads as null - the same as a column that exists and
     * is empty. Only the schema can tell those apart, which is exactly the confusion
     * that let the original broken default through.
     */
    public function test_the_users_table_really_has_an_employee_id_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('users', 'employee_id'),
            'users.employee_id must exist - the document forms read it.'
        );

        // And it has to be fillable, or saving one silently drops it.
        $user = User::factory()->create(['role' => 'support', 'employee_id' => 'EMP-999']);

        $this->assertSame('EMP-999', $user->fresh()->employee_id);
    }

    public function test_the_form_still_renders_for_an_asset_with_no_history(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('documents.generate', ['asset' => $this->asset]))
            ->assertOk();
    }
}
