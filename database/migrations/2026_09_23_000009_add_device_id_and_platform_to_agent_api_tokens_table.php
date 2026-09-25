<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_api_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('agent_api_tokens', 'device_id')) {
                $table->string('device_id')->nullable()->index();
            }

            if (! Schema::hasColumn('agent_api_tokens', 'platform')) {
                $table->string('platform')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('agent_api_tokens', function (Blueprint $table) {
            if (Schema::hasColumn('agent_api_tokens', 'device_id')) {
                $table->dropColumn('device_id');
            }

            if (Schema::hasColumn('agent_api_tokens', 'platform')) {
                $table->dropColumn('platform');
            }
        });
    }
};
