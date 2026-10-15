<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Which build each laptop runs, and what we hand out.
 *
 * Both were unanswerable before: the page said "latest" with nothing to verify
 * it against, and every device id was a random string, so an operator reading a
 * number off a screen had nothing they could quote back.
 */
class AgentReleaseTrackingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The number is handed out in sequence and never reused.
     *
     * Deleting a device must not free its number: the next laptop taking
     * MITO-0003 would make two different machines answer to the same name on
     * the phone, which is worse than a gap.
     */
    public function test_device_numbers_are_sequential_and_never_reused(): void
    {
        $first = $this->registerDevice('hw-alpha');
        $second = $this->registerDevice('hw-beta');
        $third = $this->registerDevice('hw-gamma');

        $this->assertSame('MITO-0001', $first);
        $this->assertSame('MITO-0002', $second);
        $this->assertSame('MITO-0003', $third);

        AgentApiToken::where('device_id', 'MITO-0002')->delete();

        $this->assertSame(
            'MITO-0004',
            $this->registerDevice('hw-delta'),
            'a deleted number must not be handed to the next device'
        );
    }

    /**
     * Reinstalling must not create a second device.
     *
     * The agent calls register again on every start and on every update. It
     * still knows its own random id even though the server replaced device_id
     * with a readable number, so matching has to work on the id the agent
     * actually holds - otherwise one laptop produces a new row each launch and
     * the fleet page fills with duplicates of the same machine.
     */
    public function test_reregistration_finds_the_device_by_its_own_hardware_id(): void
    {
        $assigned = $this->registerDevice('hw-stable');
        $this->assertSame('MITO-0001', $assigned);

        $again = $this->postJson('/api/agent/register', [
            'device_id' => 'hw-stable',
            'hardware_id' => 'hw-stable',
            'name' => 'MITO-0001',
        ]);

        $again->assertOk();
        $this->assertSame('MITO-0001', $again->json('device_id'));
        $this->assertSame(1, AgentApiToken::count(), 'the same machine must not be enrolled twice');
    }

    /** The version an agent reports is remembered, per device. */
    public function test_the_agent_version_reported_on_register_is_stored(): void
    {
        $this->postJson('/api/agent/register', [
            'device_id' => 'hw-v1',
            'agent_version' => '1.4.0',
        ])->assertOk();

        $token = AgentApiToken::firstOrFail();

        $this->assertSame('1.4.0', $token->agent_version);
        $this->assertNotNull($token->last_seen_at);
    }

    /**
     * Versions compare as numbers.
     *
     * "1.10.0" sorts below "1.9.0" as a string, so a device two releases behind
     * would be reported as up to date forever - and the fleet would silently
     * refuse to be fixed.
     */
    public function test_versions_are_compared_numerically_not_alphabetically(): void
    {
        AgentRelease::ship('1.10.0');

        $this->assertTrue(AgentRelease::isBehind('1.9.0'));
        $this->assertFalse(AgentRelease::isBehind('1.10.0'));
        $this->assertFalse(AgentRelease::isBehind('1.11.0'));
    }

    /** A device that never reported a version is unknown, not out of date. */
    public function test_an_unreported_version_is_unknown_rather_than_behind(): void
    {
        AgentRelease::ship('2.0.0');

        $this->assertNull(AgentRelease::isBehind(null));
        $this->assertNull(AgentRelease::isBehind(''));
    }

    /**
     * Exactly one release is current.
     *
     * Two would make "what the download button serves" ambiguous, which is the
     * only reason the table exists.
     */
    public function test_shipping_a_new_release_supersedes_the_previous_one(): void
    {
        AgentRelease::ship('1.0.0');
        AgentRelease::ship('1.1.0');

        $this->assertSame('1.1.0', AgentRelease::current()?->version);
        $this->assertSame(1, AgentRelease::where('is_current', true)->count());
        $this->assertSame('1.0.0', AgentRelease::previous()?->version);
    }

    /** The page shows the served version, the one it replaced, and the fleet's state. */
    public function test_the_audit_agents_page_shows_release_and_fleet_versions(): void
    {
        AgentRelease::ship('1.0.0');
        AgentRelease::ship('1.1.0');

        $admin = User::factory()->create(['role' => 'admin']);

        AgentApiToken::create([
            'device_id' => 'MITO-0009',
            'hardware_id' => 'hw-old',
            'name' => 'MITO-0009',
            'token_hash' => hash('sha256', 'old'),
            'token_plain' => 'old',
            'agent_version' => '1.0.0',
            'is_active' => true,
        ]);

        AgentApiToken::create([
            'device_id' => 'MITO-0010',
            'hardware_id' => 'hw-new',
            'name' => 'MITO-0010',
            'token_hash' => hash('sha256', 'new'),
            'token_plain' => 'new',
            'agent_version' => '1.1.0',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('agent.index'))
            ->assertOk()
            ->assertSee('1.1.0')   // current
            ->assertSee('1.0.0')   // previous + the outdated device
            ->assertSee('outdated');
    }

    /**
     * Updating one machine must not disturb the rest of the fleet.
     *
     * Update All was the only way to move a single laptop onto a new build, so
     * fixing the machine in front of you meant restarting every agent in the
     * office. The per-device button has to target exactly one row - a broadcast
     * hiding behind it would keep the old behaviour while looking like the fix.
     */
    public function test_updating_one_device_targets_only_that_device(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = $this->makeToken('MITO-0001', '1.0.0');
        $other = $this->makeToken('MITO-0002', '1.1.0');

        $this->actingAs($admin)
            ->from(route('agent.fleet'))
            ->post(route('agent.updateOne', $target))
            ->assertRedirect(route('agent.fleet'));

        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $target->id,
            'command' => 'update',
            'status' => 'pending',
        ]);

        $this->assertDatabaseMissing('agent_commands', [
            'agent_token_id' => $other->id,
            'command' => 'update',
        ]);
    }

    /** The payload must point at the real download route, not a retired script. */
    public function test_a_per_device_update_carries_the_download_url(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = $this->makeToken('MITO-0001', '1.0.0');

        $this->actingAs($admin)->post(route('agent.updateOne', $target));

        $command = AgentCommand::where('agent_token_id', $target->id)
            ->where('command', 'update')
            ->firstOrFail();

        $this->assertSame(
            URL::route('agent.download.latest'),
            $command->payload['url'] ?? null,
            'the agent has to be told where to pull the binary from'
        );
    }

    /**
     * Both update actions must hand the browser the queued command(s), or the
     * progress modal never opens: the async-action handler only watches a command
     * when the JSON carries `command_id` (single) or `command_ids` (a fleet
     * action). Without one the Update button looked inert - the command was
     * queued and ran, but the operator saw nothing move.
     */
    public function test_update_actions_return_a_command_id_for_the_progress_modal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = $this->makeToken('MITO-0001', '1.0.0');

        $one = $this->actingAs($admin)
            ->postJson(route('agent.updateOne', $target));

        $one->assertOk();
        $oneId = $one->json('command_id');
        $this->assertNotNull($oneId, 'a per-device update must expose its command id');
        $this->assertDatabaseHas('agent_commands', ['id' => $oneId, 'command' => 'update']);

        $all = $this->actingAs($admin)->postJson(route('agent.updateAll'));

        $all->assertOk();
        // Update All queues one command per behind device, so it reports a list:
        // a single broadcast row was settled by whichever agent acked first,
        // closing the dialog while the rest of the fleet was still updating.
        $ids = $all->json('command_ids');
        $this->assertIsArray($ids, 'update-all must expose the queued command ids');
        $this->assertNotEmpty($ids, 'the device behind the release must get a command');
    }

    /**
     * @return string the assigned device number
     */
    private function registerDevice(string $hardwareId): string
    {
        $response = $this->postJson('/api/agent/register', [
            'device_id' => $hardwareId,
            'hardware_id' => $hardwareId,
        ]);

        $response->assertOk();

        return (string) $response->json('device_id');
    }

    /** An enrolled device carrying a given build, for the fleet-page tests. */
    private function makeToken(string $deviceId, ?string $version): AgentApiToken
    {
        return AgentApiToken::create([
            'device_id' => $deviceId,
            'hardware_id' => 'hw-'.strtolower($deviceId),
            'name' => $deviceId,
            'token_hash' => hash('sha256', $deviceId),
            'token_plain' => $deviceId,
            'agent_version' => $version,
            'is_active' => true,
        ]);
    }
}
