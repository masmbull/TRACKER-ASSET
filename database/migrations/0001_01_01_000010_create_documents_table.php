<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->references('id')->on('assets')->nullify();
            $table->foreignId('transfer_id')->nullable()->references('id')->on('asset_transfers')->nullify();
            $table->string('document_type'); // FAT, BAST, Transfer, Return, Assignment
            $table->string('document_number')->unique();
            $table->string('title');
            $table->foreignId('template_id')->nullable()->references('id')->on('document_templates')->nullify();
            $table->string('file_path');
            $table->string('file_type'); // pdf, docx, etc.
            $table->date('generated_date')->nullable();
            $table->foreignId('generated_by')->references('id')->on('users');
            $table->string('status')->default('draft'); // draft, final
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
