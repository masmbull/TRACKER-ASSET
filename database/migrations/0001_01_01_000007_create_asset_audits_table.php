<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->references('id')->on('assets');
            $table->timestamp('audit_date');
            $table->foreignId('auditor_id')->references('id')->on('users');
            $table->string('device_hostname')->nullable();
            $table->string('device_serial')->nullable();
            $table->string('device_processor')->nullable();
            $table->string('device_ram')->nullable();
            $table->string('device_storage')->nullable();
            $table->string('device_os')->nullable();
            $table->string('device_mac')->nullable();
            $table->string('device_ip')->nullable();
            $table->string('result'); // MATCH, MISMATCH, NEW DEVICE, UNKNOWN DEVICE, INCOMPLETE
            $table->text('notes')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status')->default('draft'); // draft, submitted, approved
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_audits');
    }
};
