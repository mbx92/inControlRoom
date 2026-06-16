<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LabelPrinter extends Model
{
    use HasFactory, HasUuids;

    public const DRIVER_ZPL = 'zpl';

    public const DRIVER_TSPL = 'tspl';

    public const CONNECTION_SMB = 'smb';

    public const CONNECTION_RAW_TCP = 'raw_tcp';

    public const DRIVERS = [
        self::DRIVER_ZPL => 'ZPL',
        self::DRIVER_TSPL => 'TSPL',
    ];

    public const CONNECTION_MODES = [
        self::CONNECTION_SMB => 'SMB Share (Windows)',
        self::CONNECTION_RAW_TCP => 'LAN Raw TCP (port 9100)',
    ];

    protected $fillable = [
        'display_name',
        'enabled',
        'connection_mode',
        'smb_host',
        'share_name',
        'lan_port',
        'username',
        'password',
        'domain',
        'driver_language',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'password' => 'encrypted',
            'is_default' => 'boolean',
            'lan_port' => 'integer',
        ];
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(InventoryLabelPrintJob::class, 'printer_id');
    }

    public static function defaultPrinter(): ?self
    {
        return static::query()
            ->where('is_default', true)
            ->first();
    }

    public function usesSmb(): bool
    {
        return ($this->connection_mode ?: self::CONNECTION_SMB) === self::CONNECTION_SMB;
    }

    public function usesRawTcp(): bool
    {
        return $this->connection_mode === self::CONNECTION_RAW_TCP;
    }

    public function sharePath(): string
    {
        return sprintf('//%s/%s', trim($this->smb_host), trim($this->share_name));
    }

    public function lanEndpoint(): string
    {
        $port = $this->lan_port ?: 9100;

        return sprintf('tcp://%s:%d', trim($this->smb_host), $port);
    }
}
