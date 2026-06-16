<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TopologyLayout extends Model
{
    use HasUuids;

    protected $fillable = [
        'site_id',
        'mode',
        'positions',
        'is_locked',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'positions' => 'array',
            'is_locked' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
