<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_fingerprints', function (Blueprint $table): void {
            $table->string('monitor_sn', 255)->nullable()->after('collected_at');
            $table->string('keyboard_model', 500)->nullable()->after('monitor_sn');
            $table->string('mouse_model', 500)->nullable()->after('keyboard_model');
            $table->string('headset_model', 500)->nullable()->after('mouse_model');
            $table->string('webcam_model', 500)->nullable()->after('headset_model');
            $table->text('other_accessories')->nullable()->after('webcam_model');
        });
    }

    public function down(): void
    {
        Schema::table('agent_fingerprints', function (Blueprint $table): void {
            $table->dropColumn([
                'monitor_sn', 'keyboard_model', 'mouse_model',
                'headset_model', 'webcam_model', 'other_accessories',
            ]);
        });
    }
};
