<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agent audits are machine-submitted: no human auditor (PRD §11).
        Schema::table('asset_audits', function (Blueprint $table) {
            $table->foreignId('auditor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('asset_audits', function (Blueprint $table) {
            $table->foreignId('auditor_id')->nullable(false)->change();
        });
    }
};
