<?php

namespace Tests\Unit;

use App\Support\ScriptPresets;
use PHPUnit\Framework\TestCase;

/**
 * Presets are pushed as plain text to an agent that runs them unattended.
 * A prompt or a self-elevation would hang the command until its timeout, so
 * the "silent" contract is tested rather than assumed.
 */
class ScriptPresetsTest extends TestCase
{
    public function test_every_preset_is_silent_and_has_an_exit_code(): void
    {
        foreach (ScriptPresets::definitions() as $key => $preset) {
            $body = $preset['body'];

            // The read-only credential probe returns its answer on stdout so
            // the tracker can show it; the other presets log to a file.
            if ($key === 'ultraviewer-credentials') {
                $this->assertStringContainsString('Write-Output', $body, $key);
                $this->assertStringContainsString('exit 0', $body, $key);
                $this->assertStringNotContainsString('Read-Host', $body, $key);
                $this->assertStringNotContainsString('pause', $body, $key);

                continue;
            }

            $this->assertStringContainsString('Write-Log', $body, $key);
            $this->assertStringContainsString('exit 0', $body, $key);
            $this->assertStringContainsString('exit 1', $body, $key);
            $this->assertStringNotContainsString('Start-Process', $body, $key);
            $this->assertStringNotContainsString('Read-Host', $body, $key);
            $this->assertStringNotContainsString('set /p', $body, $key);
            $this->assertStringNotContainsString('pause', $body, $key);
            $this->assertStringNotContainsString('Invoke-WebRequest', $body, $key);
            $this->assertStringNotContainsString('iex', $body, $key);
        }
    }

    public function test_presets_cover_the_office_reference_scripts(): void
    {
        $presets = ScriptPresets::definitions();

        foreach (['outlook-50', 'outlook-100', 'outlook-150', 'folder-sharing', 'printer-rpc', 'disable-usb', 'enable-usb', 'disable-windows-update', 'restore-windows-update'] as $key) {
            $this->assertArrayHasKey($key, $presets);
            $this->assertNotEmpty($presets[$key]['body']);
        }
    }

    public function test_preset_metadata_is_consistent(): void
    {
        foreach (ScriptPresets::definitions() as $preset) {
            $this->assertNotEmpty($preset['label']);
            $this->assertNotEmpty($preset['notes']);
            $this->assertContains($preset['runAs'], ['current-user', 'admin', 'system']);
            $this->assertContains($preset['target'], ['all', 'device', 'devices']);
            $this->assertSame('C:\\Temp\\Scripts', $preset['workingDir']);
            $this->assertNotEmpty($preset['section']);
        }
    }
}
