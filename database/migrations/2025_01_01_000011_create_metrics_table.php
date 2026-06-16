<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Metrics buffer — stores polled data from integrations.
     * Frontend reads from here, never hits integrations directly.
     */
    public function up(): void
    {
        Schema::create('metrics', function (Blueprint $table) {
            $table->id();
            $table->uuid('integration_id');
            $table->string('key'); // e.g., "node.cpu_usage", "vm.status", "backup.last_success"
            $table->string('value');
            $table->jsonb('labels')->nullable(); // additional context (node_name, vmid, etc.)
            $table->timestamp('recorded_at');

            $table->foreign('integration_id')
                ->references('id')
                ->on('integrations')
                ->onDelete('cascade');

            $table->index(['integration_id', 'key']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics');
    }
};
