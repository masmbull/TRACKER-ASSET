<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->references('id')->on('assets');
            $table->foreignId('user_id')->references('id')->on('users');
            $table->date('assigned_date');
            $table->date('returned_date')->nullable();
            $table->foreignId('from_location_id')->nullable()->references('id')->on('locations')->nullify();
            $table->foreignId('to_location_id')->nullable()->references('id')->on('locations')->nullify();
            $table->foreignId('transfer_id')->nullable()->references('id')->on('asset_transfers')->nullify();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->references('id')->on('users')->nullify();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_assignments');
    }
};
