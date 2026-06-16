<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Metric extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'integration_id',
        'key',
        'value',
        'labels',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'labels' => 'json',
            'recorded_at' => 'datetime',
        ];
    }

    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
