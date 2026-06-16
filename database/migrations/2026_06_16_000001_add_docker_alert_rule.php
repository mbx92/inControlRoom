<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('alert_rules')->updateOrInsert(
            [
                'site_id' => null,
                'rule_key' => 'docker_container_stopped',
            ],
            [
                'name' => 'Docker Container Stopped',
                'metric_key' => 'container.state',
                'default_severity' => 'critical',
                'warning_threshold' => null,
                'critical_threshold' => null,
                'is_active' => true,
                'config' => json_encode(['expected_state' => 'running']),
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('alert_rules')
            ->whereNull('site_id')
            ->where('rule_key', 'docker_container_stopped')
            ->delete();
    }
};
