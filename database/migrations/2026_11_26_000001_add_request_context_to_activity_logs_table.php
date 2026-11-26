<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An audit entry must answer who, when, where and what. The old table
     * stored the "who" and "what" but nothing about the request itself, so an
     * operator could not tell which browser or machine performed an action.
     */
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('ip_address', 64)->nullable()->after('source');
            $table->text('user_agent')->nullable()->after('ip_address');
            $table->string('request_method', 10)->nullable()->after('user_agent');
            $table->text('request_url')->nullable()->after('request_method');
            $table->text('referer')->nullable()->after('request_url');
            $table->string('server_host', 255)->nullable()->after('referer');
            $table->string('route_name', 128)->nullable()->after('server_host');
            $table->string('request_id', 64)->nullable()->after('route_name')->index();
            // A raw session id is a secret (OWASP): only a one-way hash is kept,
            // enough to correlate events without being usable to hijack a session.
            $table->string('session_hash', 64)->nullable()->after('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['request_id']);
            $table->dropColumn([
                'ip_address', 'user_agent', 'request_method', 'request_url',
                'referer', 'server_host', 'route_name', 'request_id', 'session_hash',
            ]);
        });
    }
};
