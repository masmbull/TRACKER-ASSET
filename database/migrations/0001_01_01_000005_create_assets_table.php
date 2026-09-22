<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_id')->unique();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('category_id')->references('id')->on('asset_categories');
            $table->string('brand');
            $table->string('model');
            $table->string('serial_number');
            $table->string('hostname');
            $table->string('processor')->nullable();
            $table->string('storage')->nullable();
            $table->string('ram')->nullable();
            $table->string('os_version')->nullable();
            $table->foreignId('location_id')->references('id')->on('locations');
            $table->integer('purchase_year')->nullable();
            $table->string('vendor')->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->integer('useful_life_years')->nullable();
            $table->foreignId('asset_status_id')->references('id')->on('asset_statuses');
            $table->date('warranty_until')->nullable();
            $table->date('deploy_date')->nullable();
            $table->string('software_license')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('static_ip')->nullable();
            $table->foreignId('current_user_id')->nullable()->references('id')->on('users')->nullify();
            $table->foreignId('admin_it_pic')->nullable()->references('id')->on('users')->nullify();
            $table->text('repair_notes')->nullable();
            $table->date('retire_date')->nullable();
            $table->string('qr_code')->nullable();
            $table->string('barcode')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Indexes are auto-created for primary keys and foreign keys
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
