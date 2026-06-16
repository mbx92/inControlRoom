<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vault_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('kind');
            $table->text('ciphertext');
            $table->text('notes')->nullable();
            $table->unsignedInteger('rotation_interval_days')->nullable();
            $table->timestamp('last_rotated_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('kind');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vault_entries');
    }
};
