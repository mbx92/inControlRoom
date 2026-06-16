<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->uuid('site_id')->nullable();
            $table->string('name');
            $table->string('rule_key');
            $table->string('metric_key')->nullable();
            $table->string('default_severity')->nullable();
            $table->decimal('warning_threshold', 8, 2)->nullable();
            $table->decimal('critical_threshold', 8, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('config')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('id')->on('sites')->nullOnDelete();
            $table->index('site_id');
            $table->index('rule_key');
            $table->unique(['site_id', 'rule_key']);
        });

        DB::table('alert_rules')->insert([
            [
                'site_id' => null,
                'name' => 'Integration Health Failure',
                'rule_key' => 'integration_health_failure',
                'metric_key' => null,
                'default_severity' => 'critical',
                'warning_threshold' => null,
                'critical_threshold' => null,
                'is_active' => true,
                'config' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => null,
                'name' => 'Proxmox Guest Stopped',
                'rule_key' => 'proxmox_guest_stopped',
                'metric_key' => 'guest.status',
                'default_severity' => 'critical',
                'warning_threshold' => null,
                'critical_threshold' => null,
                'is_active' => true,
                'config' => json_encode(['expected_status' => 'running']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => null,
                'name' => 'Proxmox Guest CPU Usage',
                'rule_key' => 'proxmox_guest_cpu_usage_percent',
                'metric_key' => 'guest.cpu_usage_percent',
                'default_severity' => null,
                'warning_threshold' => 80,
                'critical_threshold' => 90,
                'is_active' => true,
                'config' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => null,
                'name' => 'Proxmox Guest Memory Usage',
                'rule_key' => 'proxmox_guest_memory_usage_percent',
                'metric_key' => 'guest.memory_usage_percent',
                'default_severity' => null,
                'warning_threshold' => 80,
                'critical_threshold' => 90,
                'is_active' => true,
                'config' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => null,
                'name' => 'Proxmox Guest Disk Usage',
                'rule_key' => 'proxmox_guest_disk_usage_percent',
                'metric_key' => 'guest.disk_usage_percent',
                'default_severity' => null,
                'warning_threshold' => 80,
                'critical_threshold' => 90,
                'is_active' => true,
                'config' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
