<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->references('id')->on('assets')->nullify();
            $table->foreignId('document_id')->nullable()->references('id')->on('documents')->nullify();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type'); // image, pdf, document, etc.
            $table->string('mime_type');
            $table->bigInteger('file_size');
            $table->foreignId('uploaded_by')->references('id')->on('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
