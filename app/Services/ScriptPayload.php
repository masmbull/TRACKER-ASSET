<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Everything the script-command payload needs beyond the body itself, derived
 * from the body. The operator pastes one script; the type, the file name and the
 * integrity hash all follow from it.
 *
 * A hand-picked type is a second source of truth that can disagree with the
 * pasted body, and a hand-typed SHA-256 is worse than none because it is usually
 * wrong - so neither is asked for any more.
 */
class ScriptPayload
{
    /**
     * PowerShell signals. Public and enumerated so the browser picker renders the
     * same list instead of keeping its own copy that drifts: the two used to
     * disagree, and a body the server read as batch previewed as PowerShell.
     *
     * @var list<string>
     */
    public const POWERSHELL_NEEDLES = [
        'write-host', 'write-output', '$env:', 'get-', 'set-', 'new-', 'invoke-',
        'start-sleep', 'out-file', 'test-path', 'param(', '#requires', 'foreach (',
        '[parameter', 'get-service', 'stop-service', 'start-process', 'remove-item',
        'copy-item', 'select-object', 'where-object', 'convertto-',
    ];

    /**
     * Batch/CMD signals, including the builtins a plain script leans on without
     * any of the classic `for /f`/`goto` verbs: a bare `pause`/`dir`/`copy` body
     * has to read as batch, or it is handed to powershell.exe and dies there.
     *
     * @var list<string>
     */
    public const CMD_NEEDLES = [
        '@echo off', 'goto ', 'for /f', 'if exist', 'if not exist', '%errorlevel%',
        'set /p', 'call ', 'echo.', 'rem ', '%~dp0', 'findstr', 'xcopy', 'robocopy',
        'pause', 'cls', 'dir ', 'del ', 'copy ', 'move ', 'type ', 'md ', 'rd ',
        'start ', 'tasklist', 'taskkill', 'net use', 'net start', 'net stop',
    ];

    /**
     * Detected script type, one of the two the agent can execute from text:
     * `powershell` or `cmd`. Binaries are rejected: a textarea cannot carry one
     * intact, and pretending otherwise ships a corrupt file to every laptop.
     */
    public static function detectType(string $body): string
    {
        if (self::looksBinary($body)) {
            throw ValidationException::withMessages([
                'script_body' => 'This looks like a binary/executable, not a text script. Upload executables through the internal API instead.',
            ]);
        }

        $powershell = self::score($body, self::POWERSHELL_NEEDLES);
        $cmd = self::score($body, self::CMD_NEEDLES);

        if ($powershell === 0 && $cmd === 0) {
            // Nothing decisive: PowerShell is the default the agent and the form
            // both reach for, so an unrecognisable script stays there rather than
            // being run as batch and failing on the first line.
            return 'powershell';
        }

        return $powershell >= $cmd ? 'powershell' : 'cmd';
    }

    /**
     * File name for a script with no name of its own, matching the extension the
     * agent maps each type to. Mirrors AgentRunner's switch exactly, so an
     * explicit `ps1`/`exe` type no longer gets a `.bat` name that the agent then
     * doubles up into `tracker-script.bat.ps1`.
     */
    public static function defaultName(string $type): string
    {
        return match ($type) {
            'ps1', 'powershell' => 'tracker-script.ps1',
            'bat', 'batch', 'cmd' => 'tracker-script.bat',
            'exe' => 'tracker-script.exe',
            default => 'tracker-script.ps1',
        };
    }

    /** Lower-case SHA-256 of the exact bytes the agent will write and run. */
    public static function hash(string $body): string
    {
        return hash('sha256', $body);
    }

    /** @param list<string> $needles */
    private static function score(string $body, array $needles): int
    {
        $lower = strtolower($body);
        $score = 0;

        foreach ($needles as $needle) {
            if (str_contains($lower, $needle)) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * A null byte, or a run of control characters a text script never carries, is
     * the fingerprint of a binary pasted into a text field.
     */
    private static function looksBinary(string $body): bool
    {
        if (str_contains($body, "\0")) {
            return true;
        }

        if ($body === '') {
            return false;
        }

        $control = preg_match_all('/[\x00-\x08\x0E-\x1F]/', $body);

        return $control !== false && $control > 0 && ($control / strlen($body)) > 0.02;
    }
}
