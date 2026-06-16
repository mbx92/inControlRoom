<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'site_id',
        'config',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }

    public const TYPE_TELEGRAM = 'telegram';
    public const TYPE_EMAIL = 'email';
    public const TYPE_WEBHOOK = 'webhook';

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
