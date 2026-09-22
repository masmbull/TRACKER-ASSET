<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ike_bast_inspection_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->references('id')->on('assets')->nullify();
            $table->foreignId('transfer_id')->nullable()->references('id')->on('asset_transfers')->nullify();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullify();
            $table->string('session_number')->unique();
            $table->date('inspection_date')->nullable();
            $table->string('kind')->default('IKE_BAST'); // IKE_BAST | BAST | FAT | CUSTOM
            $table->string('status')->default('draft'); // draft, submitted, approved, rejected
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('ike_bast_inspection_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('ike_bast_inspection_sessions')->cascadeOnDelete();
            $table->integer('seq')->default(1);
            $table->string('item_desc')->nullable();
            $table->string('item_code')->nullable();
            $table->string('barcode')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('unit')->nullable();
            $table->decimal('qty_planned', 15, 4)->nullable();
            $table->decimal('qty_received', 15, 4)->nullable();
            $table->text('physical_condition')->nullable();
            $table->enum('test_result', ['pass', 'fail', 'na'])->default('na');
            $table->text('remarks')->nullable();
            $table->boolean('is_defective')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ike_bast_inspection_line_items');
        Schema::dropIfExists('ike_bast_inspection_sessions');
    }
};
