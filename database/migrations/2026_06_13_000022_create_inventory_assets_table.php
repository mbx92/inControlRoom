<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('site_id')->nullable();
            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->string('name');
            $table->string('category', 100);
            $table->string('status', 50)->default('active');
            $table->string('asset_tag', 100)->nullable()->unique();
            $table->string('serial_number', 150)->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('primary_ip')->nullable();
            $table->string('location_label')->nullable();
            $table->string('owner_name')->nullable();
            $table->date('acquired_at')->nullable();
            $table->date('warranty_expires_at')->nullable();
            $table->json('custom_fields')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status']);
            $table->index('category');
            $table->index('serial_number');
            $table->index('primary_ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_assets');
    }
};
