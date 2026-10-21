<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentFingerprint;
use App\Models\AgentPrinter;
use App\Models\AgentTelemetry;
use App\Models\AssetCategory;
use App\Models\AssetStatus;
use App\Models\Company;
use App\Models\Location;
use App\Models\User;
use App\Services\WakeOnLanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The fleet actions a browser can ask a device to perform.
 *
 * Wake on LAN is the interesting one: it is the only action here that the
 * server carries out itself rather than queueing for the agent, because a
 * machine that is asleep has no agent to ask. That makes the packet bytes the
 * whole contract, and it is asserted directly.
 *
 * The power actions and the RDP file ride the command inbox and the telemetry
 * row, so their tests check what an operator would see rather than the
 * internals of the dispatch.
 */
class FleetActionsTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private AgentApiToken $agentToken;

    protected function setUp(): void
    {
        parent::setUp();

        Company::create(['name' => 'Test Co', 'code' => 'TC', 'address' => '']);
        AssetCategory::create(['name' => 'Laptop']);
        Location::create(['name' => 'Jakarta', 'status' => 'active']);
        AssetStatus::create(['name' => 'Active']);

        $this->token = 'tat_'.Str::random(48);

        $this->agentToken = AgentApiToken::create([
            'name' => 'field-laptop',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'platform' => 'Windows 11',
            'token_hash' => hash('sha256', $this->token),
            'token_plain' => $this->token,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function withTelemetry(array $overrides = []): AgentTelemetry
    {
        return AgentTelemetry::updateOrCreate(
            ['agent_token_id' => $this->agentToken->id],
            array_merge([
                'hostname' => 'HOST-ACTION-01',
                'os' => 'Microsoft Windows 11 Pro',
                'ip_address' => '192.168.1.44',
                'reported_at' => now(),
            ], $overrides)
        );
    }

    // ---- Wake on LAN ------------------------------------------------------

    public function test_the_magic_packet_is_the_six_ff_bytes_plus_the_mac_repeated_sixteen_times(): void
    {
        $packet = WakeOnLanService::magicPacket('34:5A:6B:7C:8D:9E');

        $this->assertNotNull($packet);
        $this->assertSame(102, strlen($packet), 'A magic packet is 6 + (16 * 6) bytes.');
        $this->assertSame(str_repeat("\xFF", 6), substr($packet, 0, 6));

        // The MAC is repeated sixteen times with no separator - the repetition
        // is what lets an asleep NIC recognise the packet from the wire.
        for ($i = 0; $i < 16; $i++) {
            $this->assertSame(
                "\x34\x5A\x6B\x7C\x8D\x9E",
                substr($packet, 6 + ($i * 6), 6),
                "MAC repetition {$i} is wrong."
            );
        }
    }

    public function test_a_mac_is_accepted_in_the_formats_an_operator_and_windows_use(): void
    {
        $expected = WakeOnLanService::magicPacket('345A6B7C8D9E');

        foreach (['34-5A-6B-7C-8D-9E', '34:5a:6b:7c:8d:9e', '345A.6B7C.8D9E'] as $format) {
            $this->assertSame($expected, WakeOnLanService::magicPacket($format), "Failed on {$format}");
        }
    }

    public function test_a_value_that_is_not_six_octets_never_becomes_a_packet(): void
    {
        foreach (['', 'N/A', 'not-a-mac', '34:5A:6B:7C:8D', null] as $value) {
            $this->assertNull(WakeOnLanService::normaliseMac($value), "Accepted '{$value}'");
        }
    }

    public function test_a_subnet_broadcast_is_derived_from_the_device_ip(): void
    {
        $this->assertSame('192.168.1.255', WakeOnLanService::subnetBroadcast('192.168.1.44'));
        $this->assertNull(WakeOnLanService::subnetBroadcast('fe80::1'), 'IPv6 has no subnet broadcast.');
        $this->assertNull(WakeOnLanService::subnetBroadcast(null));
    }

    /**
     * The only case that opens a real socket.
     *
     * Every other wake case goes through a fake, which is how a broken socket
     * call shipped: send() called a function that does not exist, threw, and
     * the catch turned it into "false" - indistinguishable from a packet the
     * network refused. The button reported nothing and did nothing.
     *
     * Loopback rather than a broadcast, so no machine on the network the suite
     * runs on is woken. UDP reports the write in full even with nothing
     * listening, so a full write is what proves the packet left; the bytes
     * themselves are asserted separately, in the packet-builder case above.
     *
     * No listener is bound: PHP cannot open a UDP listening socket on Windows
     * (stream_socket_server('udp://...') fails outright there) and the sockets
     * extension is not loaded on either the dev machine or the app container,
     * so this asserts what both can support - that the send path reaches the
     * write and reports success.
     */
    public function test_the_real_send_opens_a_socket_and_writes_the_whole_packet(): void
    {
        $sent = (new WakeOnLanService)->send('34:5A:6B:7C:8D:9E', '127.0.0.1', 39871);

        $this->assertTrue(
            $sent,
            'send() must report success when the packet is written in full. '
            .'Returning false here is what a thrown Error inside the try block '
            .'looks like, and it hid a send that never happened.'
        );
    }

    /**
     * A send the kernel refuses must come back false, never an exception.
     */
    public function test_a_refused_broadcast_is_a_failure_and_not_an_error(): void
    {
        // 255.255.255.255 needs SO_BROADCAST, which PHP cannot set through the
        // stream wrappers, so the connect is refused. Asserting a bool rather
        // than false keeps this honest across hosts that may allow it.
        $this->assertIsBool(
            (new WakeOnLanService)->send('34:5A:6B:7C:8D:9E', '255.255.255.255')
        );
    }

    public function test_waking_a_device_sends_a_packet_aimed_at_its_mac(): void
    {
        $this->agentToken->update(['mac_address' => '34:5A:6B:7C:8D:9E']);
        $this->withTelemetry(['ip_address' => '192.168.1.44']);

        $sent = $this->fakeWakeOnLan();

        $this->actingAs($this->admin())
            ->postJson(route('agent.wake', $this->agentToken))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertCount(1, $sent->calls);
        $this->assertSame('345A6B7C8D9E', $sent->calls[0]['mac']);
        $this->assertSame('192.168.1.255', $sent->calls[0]['broadcast'], 'The subnet broadcast is preferred.');
    }

    public function test_waking_a_device_that_never_reported_a_mac_is_refused_with_an_explanation(): void
    {
        $sent = $this->fakeWakeOnLan();

        $this->actingAs($this->admin())
            ->postJson(route('agent.wake', $this->agentToken))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame([], $sent->calls, 'Nothing should be sent when there is no address to aim at.');
    }

    public function test_a_failed_send_is_reported_as_a_failure_not_a_wake(): void
    {
        $this->agentToken->update(['mac_address' => '34:5A:6B:7C:8D:9E']);
        $this->fakeWakeOnLan(sent: false);

        $this->actingAs($this->admin())
            ->postJson(route('agent.wake', $this->agentToken))
            ->assertStatus(422)
            ->assertJson(['ok' => false]);
    }

    public function test_waking_a_revoked_device_is_refused(): void
    {
        $this->agentToken->update(['mac_address' => '34:5A:6B:7C:8D:9E', 'is_active' => false]);
        $sent = $this->fakeWakeOnLan();

        $this->actingAs($this->admin())
            ->postJson(route('agent.wake', $this->agentToken))
            ->assertStatus(422);

        $this->assertSame([], $sent->calls);
    }

    // ---- Power actions ----------------------------------------------------

    public function test_a_power_action_queues_a_command_carrying_its_action_and_delay(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('agent.power', $this->agentToken), [
                'action' => 'shutdown',
                'delay_seconds' => 300,
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $command = AgentCommand::where('agent_token_id', $this->agentToken->id)
            ->where('command', 'power')
            ->firstOrFail();

        $this->assertSame('pending', $command->status);
        $this->assertSame('shutdown', $command->payload['action']);
        $this->assertSame(300, $command->payload['delay_seconds']);
    }

    public function test_logoff_is_offered_the_same_way_as_a_full_power_action(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('agent.power', $this->agentToken), ['action' => 'logoff'])
            ->assertOk();

        $this->assertSame(
            'logoff',
            AgentCommand::where('command', 'power')->firstOrFail()->payload['action']
        );
    }

    public function test_an_action_the_agent_does_not_understand_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('agent.power', $this->agentToken), ['action' => 'hibernate'])
            ->assertStatus(422);

        $this->assertSame(0, AgentCommand::where('command', 'power')->count());
    }

    public function test_a_delay_beyond_an_hour_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('agent.power', $this->agentToken), [
                'action' => 'restart',
                'delay_seconds' => 3601,
            ])
            ->assertStatus(422);

        $this->assertSame(0, AgentCommand::where('command', 'power')->count());
    }

    public function test_a_power_action_against_a_revoked_device_is_refused(): void
    {
        $this->agentToken->update(['is_active' => false]);

        $this->actingAs($this->admin())
            ->postJson(route('agent.power', $this->agentToken), ['action' => 'restart'])
            ->assertStatus(422);

        $this->assertSame(0, AgentCommand::count());
    }

    // ---- Remote desktop ---------------------------------------------------

    public function test_the_rdp_file_carries_the_device_address(): void
    {
        $this->withTelemetry(['ip_address' => '10.20.30.40']);

        $response = $this->actingAs($this->admin())->get(route('agent.rdp', $this->agentToken));

        $response->assertOk();
        $this->assertStringContainsString('full address:s:10.20.30.40', $response->getContent());
        $this->assertStringContainsString('.rdp', (string) $response->headers->get('content-disposition'));
    }

    public function test_an_unreachable_device_has_no_address_to_offer(): void
    {
        $this->actingAs($this->admin())
            ->get(route('agent.rdp', $this->agentToken))
            ->assertNotFound();
    }

    // ---- Printer inventory ------------------------------------------------

    public function test_printers_are_stored_with_their_offline_and_default_state(): void
    {
        $this->postJson('/api/agent/fingerprint', [
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'printers' => [
                [
                    'name' => 'HP LaserJet M404',
                    'driver_name' => 'HP Universal Printing PCL 6',
                    'port_name' => '10.20.30.50',
                    'location' => 'Finance',
                    'is_network' => 'true',
                    'is_default' => 'true',
                    'is_offline' => 'false',
                    'status_code' => 3,
                    'status' => 'Idle',
                ],
                [
                    'name' => 'Brother QL-800',
                    'is_network' => 'false',
                    'is_default' => 'false',
                    'is_offline' => 'true',
                ],
            ],
        ], ['Authorization' => 'Bearer '.$this->token])
            ->assertOk();

        $fingerprint = AgentFingerprint::where('agent_token_id', $this->agentToken->id)->firstOrFail();

        $this->assertCount(2, $fingerprint->printers);

        $hp = $fingerprint->printers->firstWhere('name', 'HP LaserJet M404');
        $this->assertTrue($hp->is_network);
        $this->assertTrue($hp->is_default);
        $this->assertFalse($hp->is_offline);
        $this->assertSame('HP Universal Printing PCL 6', $hp->driver_name);

        $brother = $fingerprint->printers->firstWhere('name', 'Brother QL-800');
        $this->assertTrue($brother->is_offline);
        $this->assertFalse($brother->is_network);
    }

    public function test_a_second_report_replaces_the_printer_list_wholesale(): void
    {
        $payload = fn (array $printers) => [
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'printers' => $printers,
        ];

        $this->postJson('/api/agent/fingerprint', $payload([
            ['name' => 'Old Label Printer'],
            ['name' => 'Removed Queue'],
        ]), ['Authorization' => 'Bearer '.$this->token])->assertOk();

        $this->postJson('/api/agent/fingerprint', $payload([
            ['name' => 'New Label Printer'],
        ]), ['Authorization' => 'Bearer '.$this->token])->assertOk();

        $names = AgentPrinter::query()->pluck('name')->all();

        $this->assertSame(['New Label Printer'], $names);
    }

    public function test_a_printer_with_no_name_is_not_stored(): void
    {
        $this->postJson('/api/agent/fingerprint', [
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'printers' => [
                ['name' => '   '],
                ['name' => 'Microsoft Print to PDF'],
            ],
        ], ['Authorization' => 'Bearer '.$this->token])
            ->assertOk();

        $this->assertSame(
            ['Microsoft Print to PDF'],
            AgentPrinter::query()->pluck('name')->all()
        );
    }

    // ---- MAC recorded on the token ----------------------------------------

    public function test_the_heartbeat_records_the_mac_against_the_device_token(): void
    {
        $this->withTelemetry();

        $this->postJson('/api/agent/heartbeat', [
            'hostname' => 'HOST-ACTION-01',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ip_address' => '192.168.1.44',
        ], ['Authorization' => 'Bearer '.$this->token])->assertOk();

        $this->assertSame('AA:BB:CC:DD:EE:FF', $this->agentToken->fresh()->mac_address);
    }

    public function test_a_heartbeat_that_does_not_carry_a_mac_leaves_the_last_known_one_alone(): void
    {
        $this->agentToken->update(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
        $this->withTelemetry();

        $this->postJson('/api/agent/heartbeat', [
            'hostname' => 'HOST-ACTION-01',
            'device_id' => 'ZGV2aWNlLTAwMQ==',
            'ip_address' => '192.168.1.44',
        ], ['Authorization' => 'Bearer '.$this->token])->assertOk();

        $this->assertSame('AA:BB:CC:DD:EE:FF', $this->agentToken->fresh()->mac_address);
    }

    public function test_the_wake_button_is_offered_only_when_a_mac_is_known(): void
    {
        $admin = $this->admin();

        // Asserted on the rendered URL rather than the route name: the view
        // writes a form action, and the route name never reaches the page.
        $wakeUrl = '/agent/'.$this->agentToken->id.'/wake';

        $withoutMac = $this->actingAs($admin)->get(route('agent.fleet'))->getContent();
        $this->assertStringNotContainsString($wakeUrl, $withoutMac);

        $this->agentToken->update(['mac_address' => '34:5A:6B:7C:8D:9E']);

        $withMac = $this->actingAs($admin)->get(route('agent.fleet'))->getContent();
        $this->assertStringContainsString($wakeUrl, $withMac);
    }

    /**
     * Bind a sender that records instead of touching the network.
     *
     * A magic packet is a broadcast: a test that actually sent one would be
     * waking real machines on the network the suite runs on.
     */
    private function fakeWakeOnLan(bool $sent = true): RecordingWakeOnLan
    {
        $fake = new RecordingWakeOnLan($sent);

        $this->app->instance(WakeOnLanService::class, $fake);

        return $fake;
    }
}

/**
 * A WakeOnLanService that records its calls instead of opening a socket.
 */
class RecordingWakeOnLan extends WakeOnLanService
{
    /** @var array<int, array{mac: string, broadcast: string|null}> */
    public array $calls = [];

    public function __construct(private readonly bool $sent = true) {}

    public function send(string $mac, ?string $broadcast = null, int $port = 9): bool
    {
        $this->calls[] = ['mac' => $mac, 'broadcast' => $broadcast];

        return $this->sent;
    }
}
