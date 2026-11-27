<?php

namespace Tests\Feature;

use App\Models\AgentApiToken;
use App\Models\AgentCommand;
use App\Models\AgentRelease;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Releases page: ship a build, activate one, push it, delete one.
 *
 * The registry used to be metadata only - a version string with no file behind
 * it, and a single shared TrackerAgent.exe that every download served. These
 * tests pin the properties that make the page worth having: a release owns its
 * bytes, only a real PE is accepted, exactly one release is current, and a push
 * targets the devices that are actually behind.
 */
class AgentReleasePageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** A stand-in for the ~160 MB self-contained binary: a real PE prefix. */
    private function fakeExe(string $name = 'TrackerAgent.exe'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "MZ\x90\x00".str_repeat('x', 4096));
    }

    public function test_only_managers_can_reach_the_releases_page(): void
    {
        $this->get(route('agent.releases'))->assertRedirect('/login');

        $support = User::factory()->create(['role' => 'support']);
        $this->actingAs($support)->get(route('agent.releases'))->assertForbidden();

        $this->actingAs($this->admin())->get(route('agent.releases'))->assertOk();
    }

    /** Uploading a build records its size and hash and makes it current. */
    public function test_uploading_a_build_records_it_as_current(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin())
            ->post(route('agent.releases.store'), [
                'version' => '1.5.0',
                'notes' => 'Faster WMI scan',
                'binary' => $this->fakeExe(),
            ])
            ->assertRedirect(route('agent.releases'));

        $release = AgentRelease::current();

        $this->assertNotNull($release);
        $this->assertSame('1.5.0', $release->version);
        $this->assertTrue($release->is_current);
        $this->assertNotNull($release->sha256);
        $this->assertSame(64, strlen($release->sha256));
        $this->assertNotNull($release->file_size);
        $this->assertTrue($release->hasBinary());
        Storage::disk('local')->assertExists($release->file_path);
    }

    /**
     * A file that is not a Windows executable is refused.
     *
     * The upload is the only gate between a half-written build (or an HTML error
     * page saved as .exe) and every agent on the next update, so the PE magic is
     * checked before anything is stored.
     */
    public function test_a_non_executable_upload_is_rejected(): void
    {
        Storage::fake('local');

        $notExe = UploadedFile::fake()->createWithContent('TrackerAgent.exe', '<html>not a binary</html>');

        $this->actingAs($this->admin())
            ->post(route('agent.releases.store'), ['version' => '9.9.9', 'binary' => $notExe])
            ->assertSessionHas('error');

        $this->assertSame(0, AgentRelease::count());
        $this->assertSame(0, count(Storage::disk('local')->allFiles()));
    }

    /** A version without a number is refused - it could never be compared. */
    public function test_a_version_without_a_number_is_rejected(): void
    {
        Storage::fake('local');

        $this->actingAs($this->admin())
            ->post(route('agent.releases.store'), ['version' => 'latest', 'binary' => $this->fakeExe()])
            ->assertSessionHasErrors('version');
    }

    /**
     * Activating an older build makes it current and the newer one not.
     *
     * This is the rollback, and the exactly-one-current invariant is what keeps
     * "what the download serves" unambiguous.
     */
    public function test_activating_an_older_release_supersedes_the_newer_one(): void
    {
        Storage::fake('local');

        $this->ship('1.0.0');
        $this->ship('1.1.0');

        $old = AgentRelease::where('version', '1.0.0')->firstOrFail();

        $this->actingAs($this->admin())
            ->post(route('agent.releases.activate', $old))
            ->assertRedirect();

        $this->assertSame('1.0.0', AgentRelease::current()?->version);
        $this->assertSame(1, AgentRelease::where('is_current', true)->count());
    }

    /** An active push queues one targeted update per device behind it. */
    public function test_pushing_a_release_targets_only_devices_that_are_behind(): void
    {
        Storage::fake('local');

        $release = $this->ship('2.0.0');

        $behind = $this->token('MITO-0001', '1.0.0');
        $current = $this->token('MITO-0002', '2.0.0');
        $unknown = $this->token('MITO-0003', null);

        $this->actingAs($this->admin())
            ->post(route('agent.releases.push', $release))
            ->assertRedirect();

        $this->assertDatabaseHas('agent_commands', ['agent_token_id' => $behind->id, 'command' => 'update', 'status' => 'pending']);
        $this->assertDatabaseHas('agent_commands', ['agent_token_id' => $unknown->id, 'command' => 'update']);
        $this->assertDatabaseMissing('agent_commands', ['agent_token_id' => $current->id, 'command' => 'update']);
    }

    /** The push payload points at the specific release, not a generic latest. */
    public function test_a_push_pins_the_command_to_the_specific_release_url(): void
    {
        Storage::fake('local');

        $release = $this->ship('2.0.0');
        $behind = $this->token('MITO-0001', '1.0.0');

        $this->actingAs($this->admin())->post(route('agent.releases.push', $release));

        $command = AgentCommand::where('agent_token_id', $behind->id)->where('command', 'update')->firstOrFail();

        $this->assertSame(route('agent.releases.download', $release), $command->payload['url'] ?? null);
    }

    /** An inactive release cannot be pushed - it is not what the fleet serves. */
    public function test_an_inactive_release_cannot_be_pushed(): void
    {
        Storage::fake('local');

        $this->ship('1.0.0');
        $this->ship('1.1.0');
        $old = AgentRelease::where('version', '1.0.0')->firstOrFail(); // not current
        $behind = $this->token('MITO-0001', '0.9.0');

        $this->actingAs($this->admin())
            ->post(route('agent.releases.push', $old))
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('agent_commands', ['agent_token_id' => $behind->id, 'command' => 'update']);
    }

    /** The current release cannot be deleted out from under the fleet. */
    public function test_the_current_release_cannot_be_deleted(): void
    {
        Storage::fake('local');

        $release = $this->ship('1.0.0');

        $this->actingAs($this->admin())
            ->delete(route('agent.releases.destroy', $release))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('agent_releases', ['id' => $release->id]);
    }

    /** Deleting a stored, non-current release removes its binary too. */
    public function test_deleting_an_old_release_removes_its_binary(): void
    {
        Storage::fake('local');

        $old = $this->ship('1.0.0');
        $this->ship('1.1.0');
        $path = $old->file_path;

        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->admin())
            ->delete(route('agent.releases.destroy', $old))
            ->assertRedirect();

        $this->assertDatabaseMissing('agent_releases', ['id' => $old->id]);
        Storage::disk('local')->assertMissing($path);
    }

    /** Downloading a release streams the stored binary with a PE prefix. */
    public function test_a_release_download_serves_its_stored_binary(): void
    {
        Storage::fake('local');

        $release = $this->ship('1.0.0');

        $response = $this->get(route('agent.releases.download', $release))
            ->assertOk()
            ->assertDownload('TrackerAgent.exe')
            ->assertHeader('Content-Type', 'application/vnd.microsoft.portable-executable');

        // Read the first bytes off the stream rather than buffering the whole
        // binary, the same reason the shared download test does: streamedContent
        // would pull ~160 MB into the runner's memory.
        $this->assertSame('MZ', substr($this->capturedStream($response, 2), 0, 2));
    }

    /**
     * The fleet-wide download serves the current release's stored build.
     *
     * This is the whole point of per-release storage: the bytes the download
     * button hands out are the bytes the current release row describes, not
     * whatever happens to sit at the legacy shared path.
     */
    public function test_the_fleet_download_serves_the_current_stored_release(): void
    {
        Storage::fake('local');

        $release = $this->ship('1.0.0');
        $this->assertTrue($release->is_current);

        $response = $this->get(route('agent.download.latest'))->assertOk()->assertDownload('TrackerAgent.exe');

        // The agent verifies this header before swapping. Without it a stale
        // file on the persistent volume could be installed as an "update".
        $response->assertHeader('X-Agent-Version', '1.0.0');
        $this->assertSame('MZ', substr($this->capturedStream($response, 2), 0, 2));
    }

    /** Register a build as the current release, with a stored binary. */
    private function ship(string $version): AgentRelease
    {
        $release = AgentRelease::ship($version);

        $path = "agent/releases/{$version}.exe";
        Storage::disk('local')->put($path, "MZ\x90\x00".str_repeat('x', 4096));

        $release->update([
            'file_path' => $path,
            'file_size' => 4100,
            'sha256' => hash('sha256', $version),
        ]);

        return $release;
    }

    private function token(string $deviceId, ?string $version): AgentApiToken
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

    /** Capture only the first $limit bytes of a streamed response. */
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
}
