<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('transfer_number')->unique();
            $table->date('transfer_date');
            $table->foreignId('company_id')->references('id')->on('companies');
            $table->foreignId('asset_id')->references('id')->on('assets');
            $table->foreignId('from_user_id')->references('id')->on('users');
            $table->string('from_department')->nullable();
            $table->foreignId('from_location_id')->references('id')->on('locations');
            $table->foreignId('to_user_id')->references('id')->on('users');
            $table->string('to_department')->nullable();
            $table->foreignId('to_location_id')->references('id')->on('locations');
            $table->string('condition')->nullable();
            $table->text('accessories')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullify();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullify();
            $table->string('status')->default('pending'); // pending, approved, completed, rejected
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_transfers');
    }
};
