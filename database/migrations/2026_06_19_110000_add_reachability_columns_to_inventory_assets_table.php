<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->boolean('monitoring_enabled')->default(true)->after('primary_ip');
            $table->string('reachability_status', 16)->nullable()->after('monitoring_enabled');
            $table->timestamp('reachability_checked_at')->nullable()->after('reachability_status');
            $table->timestamp('reachability_last_seen_at')->nullable()->after('reachability_checked_at');
            $table->unsignedInteger('reachability_latency_ms')->nullable()->after('reachability_last_seen_at');
            $table->unsignedSmallInteger('reachability_fail_count')->default(0)->after('reachability_latency_ms');
            $table->string('reachability_message')->nullable()->after('reachability_fail_count');

            $table->index(['monitoring_enabled', 'reachability_status'], 'inventory_assets_monitoring_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_assets', function (Blueprint $table) {
            $table->dropIndex('inventory_assets_monitoring_status_index');
            $table->dropColumn([
                'monitoring_enabled',
                'reachability_status',
                'reachability_checked_at',
                'reachability_last_seen_at',
                'reachability_latency_ms',
                'reachability_fail_count',
                'reachability_message',
            ]);
        });
    }
};
