<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    /**
     * Audit logs are immutable — no updated_at.
     */
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'site_id',
        'action',
        'target_type',
        'target_id',
        'payload',
        'ip_address',
        'result',
        'error_message',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'json',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Prevent deletion of audit logs via Eloquent.
     */
    public static function boot(): void
    {
        parent::boot();

        static::deleting(function () {
            return false; // Prevent deletion
        });

        static::updating(function () {
            return false; // Prevent updates — logs are immutable
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Record a new audit log entry.
     */
    public static function record(
        int $userId,
        string $action,
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $payload = null,
        ?string $ipAddress = null,
        string $result = 'success',
        ?string $errorMessage = null,
        ?string $siteId = null,
    ): static {
        return static::create([
            'user_id' => $userId,
            'site_id' => $siteId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $payload,
            'ip_address' => $ipAddress,
            'result' => $result,
            'error_message' => $errorMessage,
            'created_at' => now(),
        ]);
    }
}
