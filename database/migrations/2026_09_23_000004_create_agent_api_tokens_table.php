<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // API tokens for the lightweight audit agent (PRD §11).
        // Plaintext token is shown once at issue time; only its SHA-256 hash is stored.
        Schema::create('agent_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('token_hash', 64)->unique();
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_api_tokens');
    }
};
