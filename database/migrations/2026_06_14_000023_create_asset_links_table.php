<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_asset_id');
            $table->uuid('target_asset_id');
            $table->string('link_type', 50)->default('uplink');
            $table->string('label')->nullable();
            $table->timestamps();

            $table->foreign('source_asset_id')
                ->references('id')
                ->on('inventory_assets')
                ->cascadeOnDelete();

            $table->foreign('target_asset_id')
                ->references('id')
                ->on('inventory_assets')
                ->cascadeOnDelete();

            $table->unique(['source_asset_id', 'target_asset_id', 'link_type']);
            $table->index('target_asset_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_links');
    }
};
