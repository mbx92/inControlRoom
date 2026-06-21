<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class MaintenanceMode
{
    public const KEY = 'maintenance_mode';

    public const CACHE_KEY = 'system.maintenance.payload';

    public static function enabled(): bool
    {
        return (bool) (static::payload()['enabled'] ?? false);
    }

    public static function message(): ?string
    {
        $message = static::payload()['message'] ?? null;

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        return Cache::rememberForever(static::CACHE_KEY, function (): array {
            $stored = SystemSetting::getValue(static::KEY, []);

            return is_array($stored) ? $stored : [];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicPayload(): array
    {
        $payload = static::payload();

        return [
            'enabled' => (bool) ($payload['enabled'] ?? false),
            'message' => static::message(),
            'enabled_at' => $payload['enabled_at'] ?? null,
            'enabled_by_name' => $payload['enabled_by_name'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminPayload(): array
    {
        return static::publicPayload();
    }

    public static function enable(User $user, ?string $message = null): void
    {
        static::store([
            'enabled' => true,
            'message' => $message,
            'enabled_at' => now()->toIso8601String(),
            'enabled_by' => $user->id,
            'enabled_by_name' => $user->name,
        ]);
    }

    public static function disable(User $user): void
    {
        static::store([
            'enabled' => false,
            'message' => null,
            'disabled_at' => now()->toIso8601String(),
            'disabled_by' => $user->id,
            'disabled_by_name' => $user->name,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function store(array $payload): void
    {
        SystemSetting::putValue(static::KEY, $payload);
        Cache::forget(static::CACHE_KEY);
    }
}
