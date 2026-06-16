<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VaultEntry extends Model
{
    use HasFactory, HasUuids;

    public const KINDS = [
        'proxmox_api_token' => 'Proxmox API Token',
        'service_password' => 'Service Password',
        'ssh_private_key' => 'SSH Private Key',
        'recovery_code' => 'Recovery Code',
        'generic_secret' => 'Generic Secret',
    ];

    protected $fillable = [
        'site_id',
        'name',
        'kind',
        'ciphertext',
        'public_key',
        'fingerprint',
        'notes',
        'rotation_interval_days',
        'last_rotated_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'ciphertext' => 'encrypted',
            'rotation_interval_days' => 'integer',
            'last_rotated_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(VaultEntryAccessLog::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function revealSecret(): string
    {
        return (string) $this->ciphertext;
    }
}
