<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_api_tokens', function (Blueprint $table) {
            $table->string('rustdesk_password', 255)->nullable()->change();
            $table->string('rustdesk_id', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('agent_api_tokens', function (Blueprint $table) {
            $table->string('rustdesk_password', 128)->nullable()->change();
            $table->string('rustdesk_id', 64)->nullable()->change();
        });
    }
};
