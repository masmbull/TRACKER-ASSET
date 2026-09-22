<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Machine actions (audit agent API) have no human actor (PRD §40: "user or system").
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('actor_id')->nullable(false)->change();
        });
    }
};
