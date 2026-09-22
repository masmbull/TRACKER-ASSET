<?php

namespace App\Console\Commands;

use App\Models\AgentApiToken;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class IssueAuditTokenCommand extends Command
{
    protected $signature = 'audit:token
        {name : Human-readable token name, e.g. lab-agent-01}
        {--expires= : Expiry date, e.g. 2027-01-01}';

    protected $description = 'Issue an API token for the lightweight audit agent (PRD §11)';

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (AgentApiToken::where('name', $name)->exists()) {
            $this->error("Token name '{$name}' is already taken.");

            return self::FAILURE;
        }

        $plainText = 'tat_'.Str::random(48);

        AgentApiToken::create([
            'name' => $name,
            'token_hash' => hash('sha256', $plainText),
            'abilities' => ['audit:submit', 'assets:lookup'],
            'expires_at' => $this->option('expires') ?: null,
        ]);

        $this->info("Token '{$name}' created.");
        $this->warn('Copy this token now — it will never be shown again:');
        $this->line($plainText);

        return self::SUCCESS;
    }
}
