<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->references('id')->on('users');
            $table->string('action'); // CREATE, UPDATE, TRANSFER, AUDIT, GENERATE_DOCUMENT, IMPORT, EXPORT, LOGIN, ARCHIVE, RETIRE
            $table->string('entity_type');
            $table->bigInteger('entity_id')->nullable();
            $table->string('entity_identifier')->nullable();
            $table->string('field_changed')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('source'); // Web UI, Import, API, Audit, FAT, Admin
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Indexes auto-created for primary keys; additional indexes not supported in SQLite
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
