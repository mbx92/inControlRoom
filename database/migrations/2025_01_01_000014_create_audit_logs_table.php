<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit logs — immutable record of every action taken through the dashboard.
     * Cannot be deleted via UI. No updated_at column — logs are write-once.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // e.g., "vm.start", "integration.create", "alert.acknowledge"
            $table->string('target_type')->nullable(); // e.g., "integration", "vm", "event"
            $table->string('target_id')->nullable(); // ID of the target resource
            $table->jsonb('payload')->nullable(); // additional context (before/after, params, etc.)
            $table->string('ip_address', 45)->nullable();
            $table->string('result')->default('success'); // success | failure
            $table->text('error_message')->nullable();
            $table->timestamp('created_at');

            $table->index('user_id');
            $table->index('action');
            $table->index('created_at');
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
