<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->references('id')->on('assets');
            $table->date('date');
            $table->text('problem');
            $table->text('action')->nullable();
            $table->string('vendor')->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->string('status')->default('open');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->references('id')->on('users')->nullify();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_repairs');
    }
};
