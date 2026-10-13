<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('asset_code_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('entity_code', 8);
            $table->string('category_code', 8);
            $table->unsignedSmallInteger('purchase_year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(
                ['entity_code', 'category_code', 'purchase_year'],
                'asset_code_sequences_scope_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_code_sequences');
    }
};
