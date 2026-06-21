<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agent extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'site_id',
        'enrollment_token_id',
        'hostname',
        'device_id',
        'os',
        'os_version',
        'arch',
        'primary_ip',
        'agent_version',
        'agent_token_hash',
        'inventory_asset_id',
        'labels',
        'last_metrics',
        'last_services',
        'enrolled_at',
        'last_seen_at',
        'last_heartbeat_at',
        'last_ip_address',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'last_metrics' => 'array',
            'last_services' => 'array',
            'enrolled_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function enrollmentToken(): BelongsTo
    {
        return $this->belongsTo(AgentEnrollmentToken::class, 'enrollment_token_id');
    }
}
