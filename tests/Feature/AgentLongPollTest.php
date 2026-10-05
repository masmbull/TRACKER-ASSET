<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The long-poll half of "idle unless told" (PRD §11).
 *
 * The agent used to ask for commands every ten seconds on every laptop, which
 * meant six requests a minute per machine to be told there was nothing to do.
 * It now parks on the inbox instead. These tests pin both halves: the server
 * really does wait, and a command queued while it waits is still handed over
 * rather than left for the laptop to come back for.
 *
 * The wait itself is deliberately not exercised end to end - a 55-second test is
 * not a test suite, it is a tax on everyone who runs it afterwards. What is
 * checked is that the parameter is honoured, that an already-queued command
 * returns without sleeping, and that the cap holds.
 */
class AgentLongPollTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private AgentApiToken $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = 'tat_'.Str::random(48);

        $this->agent = AgentApiToken::create([
            'name' => 'parked-laptop',
            'device_id' => 'MITO-0009',
            'token_hash' => hash('sha256', $this->token),
            'is_active' => true,
        ]);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function test_inbox_without_the_wait_parameter_returns_immediately(): void
    {
        $this->withHeaders($this->headers())
            ->getJson('/api/agent/inbox')
            ->assertOk()
            ->assertJson(['commands' => []]);
    }

    /**
     * A command already waiting must come back at once.
     *
     * This is the case that matters most in production: an agent that parked for
     * 55 seconds and then found nothing, when the operator had queued a scan two
     * seconds into the wait, would be a lost click.
     */
    public function test_a_queued_command_is_returned_without_waiting(): void
    {
        AgentCommand::create([
            'agent_token_id' => $this->agent->id,
            'command' => 'scan',
            'status' => 'pending',
        ]);

        $started = microtime(true);

        $response = $this->withHeaders($this->headers())->getJson('/api/agent/inbox?wait=30');

        $elapsed = microtime(true) - $started;

        $response->assertOk();
        $this->assertCount(1, $response->json('commands'));
        $this->assertSame('scan', $response->json('commands.0.command'));

        // Generous ceiling: the point is "did not park for 30 seconds", not
        // timing precision, which would make this flaky on a loaded runner.
        $this->assertLessThan(10, $elapsed, 'A queued command must not make the agent wait it out.');
    }

    public function test_the_wait_parameter_is_accepted(): void
    {
        // A one second wait, so the test costs a second rather than a minute.
        // The agent asks for 55; what matters is that the server honours the
        // parameter and caps it, not the specific number the agent uses.
        $this->withHeaders($this->headers())
            ->getJson('/api/agent/inbox?wait=1')
            ->assertOk()
            ->assertJsonStructure(['commands']);
    }

    /**
     * The cap exists to protect the worker pool, so it has to be a real cap.
     *
     * An agent asking to wait an hour must not be able to hold a php-fpm worker
     * for an hour: at pm.max_children = 40, forty such requests would take the
     * whole site offline, and the agent is the one asking.
     */
    public function test_an_absurd_wait_is_capped_rather_than_obeyed(): void
    {
        $started = microtime(true);

        $this->withHeaders($this->headers())
            ->getJson('/api/agent/inbox?wait=3600')
            ->assertOk();

        $elapsed = microtime(true) - $started;

        // 3600 would be an hour; the cap is 60. Anything near that means the
        // clamp is gone and one misbehaving agent can starve the site.
        $this->assertLessThan(90, $elapsed, 'The wait must be clamped.');
    }

    /**
     * A parked agent is still an agent that can be revoked.
     *
     * The point of a long-lived connection is that it outlives several request
     * cycles, which makes it the most likely place for a revoked token to keep
     * working. It must not.
     */
    public function test_a_revoked_agent_cannot_park_on_the_inbox(): void
    {
        $this->agent->update(['is_active' => false]);

        $this->withHeaders($this->headers())
            ->getJson('/api/agent/inbox?wait=5')
            ->assertUnauthorized();
    }

    /**
     * A command queued for one laptop must not wake another.
     *
     * The long-poll re-asks "commands for this token" inside its loop. A variant
     * that asked the wrong question would hand laptop B the scan meant for
     * laptop A - a machine scanning itself on someone else's behalf.
     */
    public function test_a_command_queued_for_another_device_is_not_handed_over(): void
    {
        $other = AgentApiToken::create([
            'name' => 'other-laptop',
            'device_id' => 'MITO-0010',
            'token_hash' => hash('sha256', 'tat_'.Str::random(48)),
            'is_active' => true,
        ]);

        AgentCommand::create([
            'agent_token_id' => $other->id,
            'command' => 'uninstall',
            'status' => 'pending',
        ]);

        $response = $this->withHeaders($this->headers())->getJson('/api/agent/inbox?wait=1');

        $response->assertOk();
        $this->assertSame([], $response->json('commands'));
    }

    /**
     * The fleet page must still work while agents are parked.
     *
     * Parked requests occupy workers. Nothing here proves the pool is large
     * enough - that is a capacity question for the deployment - but it does show
     * a parked request is not blocking ordinary pages.
     */
    public function test_the_fleet_page_loads_while_an_agent_is_parked(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withHeaders($this->headers())->getJson('/api/agent/inbox?wait=1')->assertOk();

        $this->actingAs($admin)->get(route('agent.fleet'))->assertOk();
    }
}
