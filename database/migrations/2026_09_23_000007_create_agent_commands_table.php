<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Command queue consumed by resident agents (PRD §11 fleet control).
        // agent_token_id = null means a broadcast command delivered to every agent.
        Schema::create('agent_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_token_id')->nullable()->constrained('agent_api_tokens')->nullOnDelete();
            $table->string('command'); // scan | update
            $table->json('payload')->nullable();
            $table->string('status')->default('pending'); // pending | done | failed
            $table->timestamp('executed_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();

            $table->index(['agent_token_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_commands');
    }
};
