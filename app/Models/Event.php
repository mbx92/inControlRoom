<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'integration_id',
        'site_id',
        'rule_key',
        'fingerprint',
        'active_fingerprint',
        'severity',
        'title',
        'message',
        'context',
        'status',
        'first_seen_at',
        'last_seen_at',
        'acknowledged_by',
        'acknowledged_at',
        'acknowledge_comment',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'json',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function acknowledgedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    /**
     * Acknowledge this event with an optional comment.
     */
    public function acknowledge(User $user, ?string $comment = null): void
    {
        $this->update([
            'status' => self::STATUS_ACKNOWLEDGED,
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
            'acknowledge_comment' => $comment,
        ]);
    }

    public function resolve(?array $context = null): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'active_fingerprint' => null,
            'resolved_at' => now(),
            'last_seen_at' => now(),
            'context' => $context ?? $this->context,
        ]);
    }
}
