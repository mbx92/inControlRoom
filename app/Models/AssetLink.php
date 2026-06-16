<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetLink extends Model
{
    use HasUuids;

    public const TYPE_UPLINK = 'uplink';

    protected $fillable = [
        'source_asset_id',
        'target_asset_id',
        'link_type',
        'label',
    ];

    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(InventoryAsset::class, 'source_asset_id');
    }

    public function targetAsset(): BelongsTo
    {
        return $this->belongsTo(InventoryAsset::class, 'target_asset_id');
    }
}
