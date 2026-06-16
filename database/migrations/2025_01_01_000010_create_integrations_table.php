<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Integration Hub — stores connection config for Proxmox clusters.
     * Credentials are encrypted with AES-256 via Laravel Crypt.
     */
    public function up(): void
    {
        Schema::create('integrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // proxmox
            $table->string('name'); // user-defined label
            $table->string('base_url');
            $table->text('credentials'); // JSON encrypted with Laravel Crypt (AES-256-CBC)
            $table->jsonb('config')->nullable(); // non-sensitive config (polling_interval, verify_ssl, etc.)
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integrations');
    }
};
