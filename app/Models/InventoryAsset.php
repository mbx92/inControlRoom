<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class InventoryAsset extends Model
{
    use HasFactory, HasUuids;

    public const REACHABILITY_ONLINE = 'online';

    public const REACHABILITY_OFFLINE = 'offline';

    public const REACHABILITY_UNKNOWN = 'unknown';

    public const REACHABILITY_STATUSES = [
        self::REACHABILITY_ONLINE => 'Online',
        self::REACHABILITY_OFFLINE => 'Offline',
        self::REACHABILITY_UNKNOWN => 'Unknown',
    ];

    public const STATUSES = [
        'active' => 'Active',
        'standby' => 'Standby',
        'planned' => 'Planned',
        'repair' => 'Under Repair',
        'retired' => 'Retired',
    ];

    public const CATEGORY_SUGGESTIONS = [
        'Server',
        'Hypervisor',
        'Storage',
        'Firewall',
        'Switch',
        'Access Point',
        'Router',
        'Printer',
        'UPS',
        'PC',
        'Mini PC',
        'Laptop',
        'Monitor',
        'NAS',
        'Endpoint',
        'Medical Device',
        'License',
        'Spare Part',
    ];

    protected $fillable = [
        'site_id',
        'name',
        'category',
        'status',
        'asset_tag',
        'serial_number',
        'manufacturer',
        'model',
        'primary_ip',
        'monitoring_enabled',
        'reachability_status',
        'reachability_checked_at',
        'reachability_last_seen_at',
        'reachability_latency_ms',
        'reachability_fail_count',
        'reachability_message',
        'location_label',
        'owner_name',
        'acquired_at',
        'warranty_expires_at',
        'custom_fields',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'acquired_at' => 'date',
            'warranty_expires_at' => 'date',
            'monitoring_enabled' => 'boolean',
            'reachability_checked_at' => 'datetime',
            'reachability_last_seen_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** This device uplinks to one upstream asset. */
    public function uplinkLink(): HasOne
    {
        return $this->hasOne(AssetLink::class, 'source_asset_id')
            ->where('link_type', AssetLink::TYPE_UPLINK);
    }

    /** Devices that uplink to this asset (this asset is target/parent). */
    public function downlinkLinks(): HasMany
    {
        return $this->hasMany(AssetLink::class, 'target_asset_id')
            ->where('link_type', AssetLink::TYPE_UPLINK);
    }

    public function labelPrintJobs(): HasMany
    {
        return $this->hasMany(InventoryLabelPrintJob::class, 'asset_id');
    }

    public function getStatusLabelAttribute(): string
    {
        return static::STATUSES[$this->status] ?? Str::headline($this->status);
    }

    public function getReachabilityStatusLabelAttribute(): string
    {
        $status = $this->reachability_status ?: self::REACHABILITY_UNKNOWN;

        return static::REACHABILITY_STATUSES[$status] ?? Str::headline($status);
    }
}
