<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_audits', function (Blueprint $table) {
            // Actual brand/model collected during the audit (PRD §28).
            $table->string('device_brand')->nullable()->after('device_serial');
            $table->string('device_model')->nullable()->after('device_brand');
            // Server-side comparison result detail (PRD §28/§29): field => [registered, actual].
            $table->json('mismatches')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('asset_audits', function (Blueprint $table) {
            $table->dropColumn('mismatches');
        });
    }
};
