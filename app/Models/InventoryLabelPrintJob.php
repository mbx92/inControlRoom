<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryLabelPrintJob extends Model
{
    use HasFactory, HasUuids;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'asset_id',
        'printer_id',
        'requested_by',
        'status',
        'is_test',
        'printer_name',
        'share_path',
        'driver_language',
        'label_identifier',
        'qr_url',
        'meta',
        'raw_content',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'meta' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(InventoryAsset::class, 'asset_id');
    }

    public function printer(): BelongsTo
    {
        return $this->belongsTo(LabelPrinter::class, 'printer_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
