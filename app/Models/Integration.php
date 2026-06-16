<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Integration extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'site_id',
        'type',
        'name',
        'base_url',
        'vault_entry_id',
        'credentials',
        'config',
        'is_active',
        'last_synced_at',
        'last_tested_at',
        'last_test_status',
        'last_test_message',
        'last_test_meta',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted',
            'config' => 'json',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_tested_at' => 'datetime',
            'last_test_meta' => 'json',
        ];
    }

    /**
     * Available integration types.
     */
    public const TYPES = [
        'proxmox' => 'Proxmox VE',
        'docker' => 'Docker Engine',
        'nvr' => 'Hikvision NVR',
        'custom_api' => 'Custom API',
    ];

    public function metrics(): HasMany
    {
        return $this->hasMany(Metric::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function vaultEntry(): BelongsTo
    {
        return $this->belongsTo(VaultEntry::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Get the display name for this integration type.
     */
    public function getTypeNameAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
