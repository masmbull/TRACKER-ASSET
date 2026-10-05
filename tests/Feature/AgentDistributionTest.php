<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AgentDistributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_agents_and_others_cannot(): void
    {
        $this->get(route('agent.index'))->assertRedirect('/login');

        $admin = User::factory()->create(['role' => 'admin']);
        $support = User::factory()->create(['role' => 'support']);

        $this->actingAs($admin)->get(route('agent.index'))->assertOk();

        $this->actingAs($support)->get(route('agent.index'))->assertForbidden();
    }

    /**
     * The latest-binary route serves the binary directly, with no token involved.
     *
     * It used to look up an enrolled agent and redirect to that device's
     * token-scoped URL. That made the very first deployment impossible: with an
     * empty token table the route refused to serve anything, so there was no way
     * to obtain the agent that would have registered the first token. The Tokens
     * page still tells the operator that "a unique token is generated
     * automatically" on download, which is true - the agent mints it against
     * /api/agent/register from the server address in the config trailer - but the
     * redirect meant the download that starts all of that could never happen.
     */
    public function test_download_latest_serves_the_binary_directly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->withStubbedExe(function () use ($admin) {
            $response = $this->actingAs($admin)->get(route('agent.download.latest'))
                ->assertOk()
                ->assertDownload('TrackerAgent.exe')
                ->assertHeader('Content-Type', 'application/vnd.microsoft.portable-executable');

            // The whole point: a real binary, not a redirect page saved as .exe.
            // An agent that does not follow redirects would otherwise write this
            // HTML to disk as TrackerAgent.exe and fail to run.
            //
            // The first two bytes are read straight off the stream callback. The
            // obvious alternative, streamedContent(), buffers the whole binary in
            // memory and exhausts the runner's limit - which is also a fair
            // warning that the real 162 MB must never be read that way anywhere.
            $this->assertSame(
                'MZ',
                substr($this->capturedStream($response, 2), 0, 2),
                'A Windows PE must start with MZ.'
            );
        });
    }

    /**
     * A public download must never grow the token table.
     *
     * This is the real reason the route originally reached for a token: it used
     * to invent one with an empty hash and no device_id, so the binary would be
     * served against a credential that could never authenticate, alongside a row
     * the fleet page would list as a machine. Serving the binary directly keeps
     * that guarantee and makes it unconditional - there is no code path left that
     * can write a token from a GET.
     */
    public function test_download_latest_does_not_invent_a_token_when_none_is_enrolled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withStubbedExe(function () use ($admin) {
            $this->actingAs($admin)->get(route('agent.download.latest'))->assertOk();

            $this->assertSame(0, AgentApiToken::count(), 'A public download must not create tokens.');
        });
    }

    /**
     * The first agent must be obtainable on an empty fleet.
     *
     * This is the regression that mattered: with no enrolled device the download
     * bounced back to the Tokens page, so nobody could ever install the agent
     * that would have created the first row. Locked separately from the
     * no-token-created rule because those are different properties - one is
     * "it works", the other is "it does not write".
     */
    public function test_the_first_agent_can_be_downloaded_from_an_empty_fleet(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withStubbedExe(function () use ($admin) {
            $this->actingAs($admin)->get(route('agent.download.latest'))
                ->assertOk()
                ->assertDownload('TrackerAgent.exe');
        });
    }

    /**
     * Rows without a device_id are bookkeeping, not machines: they are what the
     * `audit:token` command and older enrollments leave behind. The download no
     * longer selects a token at all, which makes that distinction moot here - but
     * it must still hold that a download against such a table stays anonymous and
     * creates nothing.
     */
    public function test_download_latest_ignores_tokens_with_no_device(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // A bookkeeping row with no device must not become the download's identity.
        AgentApiToken::create([
            'name' => 'bookkeeping',
            'token_hash' => hash('sha256', 'other'),
            'token_plain' => 'other',
        ]);

        $this->withStubbedExe(function () use ($admin) {
            $this->actingAs($admin)->get(route('agent.download.latest'))
                ->assertOk()
                ->assertDownload('TrackerAgent.exe');
        });
    }

    /**
     * Send a streamed response, keeping only its first $limit bytes.
     *
     * The buffer handler returns an empty string, which discards each chunk as
     * it arrives. That is the entire point: a plain ob_start() would hold the
     * full 162 MB self-contained binary and blow the memory limit, which is
     * exactly what the earlier streamedContent() attempt did.
     */
    private function capturedStream(TestResponse $response, int $limit): string
    {
        $captured = '';

        ob_start(function (string $chunk) use (&$captured, $limit): string {
            if (strlen($captured) < $limit) {
                $captured .= substr($chunk, 0, $limit - strlen($captured));
            }

            return '';
        });

        $response->baseResponse->sendContent();

        ob_end_flush();

        return $captured;
    }

    /**
     * Run a callback against a stub executable, restoring the real one after.
     *
     * Forcing the stub even when a real build exists is deliberate. The real
     * self-contained agent is 162 MB, and a test that actually streams it
     * exhausts the runner's 128 MB memory limit before it can assert anything.
     * The thing under test is the download path, not the size of the binary, so
     * the test must not depend on a build artifact being present at all.
     */
    private function withStubbedExe(callable $test): void
    {
        $exePath = storage_path('app/agent/TrackerAgent.exe');
        $backup = $exePath.'.realtest';

        $hadReal = file_exists($exePath);

        if ($hadReal) {
            rename($exePath, $backup);
        }

        if (! is_dir(dirname($exePath))) {
            mkdir(dirname($exePath), 0755, true);
        }

        // "MZ" plus a little filler, so the assertions exercise a real PE prefix
        // without dragging the 162 MB along.
        file_put_contents($exePath, "MZ\x90\x00stub-binary");

        try {
            $test();
        } finally {
            unlink($exePath);

            if ($hadReal) {
                rename($backup, $exePath);
            }
        }
    }

    public function test_download_serves_exe(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = AgentApiToken::create([
            'name' => 'exe-device',
            'token_hash' => hash('sha256', 'tok'),
            'token_plain' => 'tok',
        ]);

        // CI checkouts have no built exe (storage/app is gitignored) — create a
        // stub so the download path is exercised everywhere, then clean it up.
        $exePath = storage_path('app/agent/TrackerAgent.exe');
        $createdStub = false;
        if (! file_exists($exePath)) {
            if (! is_dir(dirname($exePath))) {
                mkdir(dirname($exePath), 0755, true);
            }
            file_put_contents($exePath, "MZ\x90\x00stub");
            $createdStub = true;
        }

        try {
            $this->actingAs($admin)->get(route('agent.download', $token))
                ->assertOk()
                ->assertDownload('TrackerAgent.exe')
                ->assertHeader('Content-Type', 'application/vnd.microsoft.portable-executable');
        } finally {
            if ($createdStub) {
                unlink($exePath);
            }
        }
    }

    /**
     * Enrolment hands out a token *and* a readable device number.
     *
     * The number is the server's to assign. The agent's own id is base64 of 32
     * random bytes - "ZGV2aWNlLTAwMQ==" is unreadable in a screenshot and
     * unquotable over the phone - and letting the agent invent it also let two
     * machines racing each other pick the same identity. It is kept in
     * hardware_id now, which is what re-registration matches on.
     */
    public function test_device_registration_issues_token(): void
    {
        $payload = [
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'hardware_id' => 'ZGV2aWNlLTAwMQ==',
            'name' => 'field-laptop',
            'platform' => 'Windows 11',
        ];

        $first = $this->postJson('/api/agent/register', $payload);
        $first->assertOk()->assertJsonStructure(['token', 'message', 'device_id']);
        $this->assertNotEmpty($first->json('token'));
        $this->assertSame('MITO-0001', $first->json('device_id'));

        // Registering again is the agent restarting or re-authenticating, not a
        // second machine: same token, same number, one row.
        $second = $this->postJson('/api/agent/register', $payload);
        $second->assertOk()->assertJsonPath('token', $first->json('token'));
        $second->assertJsonPath('device_id', 'MITO-0001');

        $this->assertDatabaseHas('agent_api_tokens', [
            'device_id' => 'MITO-0001',
            'hardware_id' => 'ZGV2aWNlLTAwMQ==',
            'platform' => 'Windows 11',
        ]);
        $this->assertSame(1, AgentApiToken::count());
    }

    /**
     * A device that has just enrolled must be told to report itself.
     *
     * The heartbeat endpoint will not invent a telemetry row - with no snapshot
     * it answers 202 "run a full scan" - and the only other things that queue a
     * scan are the weekly audit sweep and a manual button on the fleet page. So
     * without this, a device deployed on a Monday reported nothing at all until
     * the sweep ran days later, and the deployment looked like it had simply
     * failed. The agent treats 202 as success, so no error surfaced anywhere.
     */
    public function test_registering_a_device_queues_a_scan_for_it(): void
    {
        $this->postJson('/api/agent/register', [
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'hardware_id' => 'ZGV2aWNlLTAwMQ==',
            'name' => 'new-laptop',
            'platform' => 'Windows 11',
        ])->assertOk();

        $token = AgentApiToken::where('hardware_id', 'ZGV2aWNlLTAwMQ==')->firstOrFail();

        $this->assertDatabaseHas('agent_commands', [
            'agent_token_id' => $token->id,
            'command' => 'scan',
            'status' => 'pending',
        ]);
    }

    /**
     * Exactly one scan, on first enrolment only.
     *
     * The agent re-registers whenever it cannot authenticate, and a restart
     * re-runs registration. Queuing on every call would pile up identical scans
     * in the inbox, and a device that has already been scanned would be made to
     * walk its whole registry again for no reason.
     */
    public function test_re_registering_an_existing_device_does_not_queue_another_scan(): void
    {
        $payload = [
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'hardware_id' => 'ZGV2aWNlLTAwMQ==',
            'name' => 'field-laptop',
            'platform' => 'Windows 11',
        ];

        $this->postJson('/api/agent/register', $payload)->assertOk();

        $token = AgentApiToken::where('hardware_id', 'ZGV2aWNlLTAwMQ==')->firstOrFail();
        $afterFirst = AgentCommand::where('agent_token_id', $token->id)
            ->where('command', 'scan')
            ->count();

        $this->assertSame(1, $afterFirst);

        // Re-register twice, as a restarting agent would.
        $this->postJson('/api/agent/register', $payload)->assertOk();
        $this->postJson('/api/agent/register', $payload)->assertOk();

        $this->assertSame(
            $afterFirst,
            AgentCommand::where('agent_token_id', $token->id)->where('command', 'scan')->count(),
            'Re-registration must not stack up duplicate scans.'
        );
    }
}
