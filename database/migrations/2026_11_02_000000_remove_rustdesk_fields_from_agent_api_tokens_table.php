<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_api_tokens', function (Blueprint $table) {
            $table->dropColumn(['rustdesk_id', 'rustdesk_password', 'rustdesk_reported_at']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_api_tokens', function (Blueprint $table) {
            $table->string('rustdesk_id', 64)->nullable()->after('mac_address');
            $table->string('rustdesk_password', 128)->nullable()->after('rustdesk_id');
            $table->timestamp('rustdesk_reported_at')->nullable()->after('rustdesk_password');
        });
    }
};
