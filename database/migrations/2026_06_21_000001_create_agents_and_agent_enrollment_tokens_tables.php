<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_enrollment_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['site_id', 'revoked_at']);
        });

        Schema::create('agents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('enrollment_token_id')->nullable()->constrained('agent_enrollment_tokens')->nullOnDelete();
            $table->string('hostname');
            $table->string('device_id');
            $table->string('os')->nullable();
            $table->string('os_version')->nullable();
            $table->string('arch')->nullable();
            $table->string('primary_ip')->nullable();
            $table->string('agent_version')->nullable();
            $table->string('agent_token_hash')->unique();
            $table->string('inventory_asset_id')->nullable();
            $table->json('labels')->nullable();
            $table->json('last_metrics')->nullable();
            $table->json('last_services')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('last_ip_address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'device_id']);
            $table->index(['site_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
        Schema::dropIfExists('agent_enrollment_tokens');
    }
};
