<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'name',
        'rule_key',
        'metric_key',
        'default_severity',
        'warning_threshold',
        'critical_threshold',
        'is_active',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'warning_threshold' => 'float',
            'critical_threshold' => 'float',
            'is_active' => 'boolean',
            'config' => 'json',
        ];
    }

    public const RULE_INTEGRATION_HEALTH_FAILURE = 'integration_health_failure';
    public const RULE_PROXMOX_GUEST_STOPPED = 'proxmox_guest_stopped';
    public const RULE_PROXMOX_GUEST_CPU_USAGE = 'proxmox_guest_cpu_usage_percent';
    public const RULE_PROXMOX_GUEST_MEMORY_USAGE = 'proxmox_guest_memory_usage_percent';
    public const RULE_PROXMOX_GUEST_DISK_USAGE = 'proxmox_guest_disk_usage_percent';

    public const RULES = [
        self::RULE_INTEGRATION_HEALTH_FAILURE => 'Integration Health Failure',
        self::RULE_PROXMOX_GUEST_STOPPED => 'Proxmox Guest Stopped',
        self::RULE_PROXMOX_GUEST_CPU_USAGE => 'Proxmox Guest CPU Usage',
        self::RULE_PROXMOX_GUEST_MEMORY_USAGE => 'Proxmox Guest Memory Usage',
        self::RULE_PROXMOX_GUEST_DISK_USAGE => 'Proxmox Guest Disk Usage',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
