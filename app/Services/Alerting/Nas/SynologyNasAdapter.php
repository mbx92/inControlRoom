<?php

namespace App\Services\Alerting\Nas;

use App\Models\Integration;

class SynologyNasAdapter extends AbstractNasAdapter
{
    private const AUTH_QUERY = 'SYNO.API.Auth';

    private const STORAGE_QUERY = 'SYNO.Core.Storage.Volume,SYNO.Core.Storage.Disk,SYNO.Core.Storage.Drive,SYNO.FileStation.List';

    public function supports(string $vendor): bool
    {
        return $vendor === 'synology';
    }

    public function label(): string
    {
        return 'Synology DSM';
    }

    public function check(Integration $integration): array
    {
        $apiInfo = $this->fetchApiInfo($integration, self::AUTH_QUERY);
        $startedAt = microtime(true);
        $infoResponse = $apiInfo['response'];
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $baseUrl = $this->baseUrl($integration);
        $infoEndpoint = "{$baseUrl}/webapi/query.cgi";

        if (! $infoResponse->successful()) {
            return [
                'success' => false,
                'message' => "Synology DSM returned HTTP {$infoResponse->status()}",
                'meta' => $this->buildReachabilityMeta(
                    $integration,
                    $infoEndpoint,
                    $infoResponse->status(),
                    $latencyMs,
                    false,
                ),
            ];
        }

        $infoPayload = $this->json($infoResponse);
        $authInfo = $infoPayload['data'][self::AUTH_QUERY] ?? [];
        $authPath = $authInfo['path'] ?? 'auth.cgi';
        $authVersion = (int) ($authInfo['maxVersion'] ?? 6);
        $authEndpoint = "{$baseUrl}/webapi/".ltrim((string) $authPath, '/');

        $username = $this->username($integration);
        $password = $this->password($integration);

        if ($username === '' || $password === '') {
            return [
                'success' => true,
                'message' => 'Synology API info reachable, but no DSM credentials are attached yet.',
                'meta' => $this->buildReachabilityMeta(
                    $integration,
                    $infoEndpoint,
                    $infoResponse->status(),
                    $latencyMs,
                    true,
                    [
                        'auth_status' => 'missing',
                        'auth_endpoint' => $authEndpoint,
                    ],
                ),
            ];
        }

        $loginResponse = $this->http($integration)->get($authEndpoint, [
            'api' => 'SYNO.API.Auth',
            'version' => max(1, $authVersion),
            'method' => 'login',
            'account' => $username,
            'passwd' => $password,
            'session' => 'InfraControl',
            'format' => 'sid',
        ]);

        $loginPayload = $this->json($loginResponse);
        $sid = $loginPayload['data']['sid'] ?? null;
        $success = $loginResponse->successful() && ($loginPayload['success'] ?? true) && is_string($sid) && $sid !== '';

        return [
            'success' => $success,
            'message' => $success
                ? 'Connected to Synology DSM'
                : (($loginPayload['error']['code'] ?? null)
                    ? 'Synology DSM authentication failed.'
                    : "Synology DSM returned HTTP {$loginResponse->status()}"),
            'meta' => $this->buildReachabilityMeta(
                $integration,
                $authEndpoint,
                $loginResponse->status(),
                $latencyMs,
                $success,
                [
                    'auth_endpoint' => $authEndpoint,
                    'auth_version' => $authVersion,
                    'api_catalog_reachable' => true,
                ],
            ),
        ];
    }

    public function capture(Integration $integration): array
    {
        $storageInfo = $this->fetchApiInfo($integration, self::STORAGE_QUERY);
        $storageCatalog = $this->json($storageInfo['response'])['data'] ?? [];
        $session = $this->login($integration, $storageCatalog[self::AUTH_QUERY] ?? []);

        try {
            $volumes = $this->fetchVolumes($integration, $session, $storageCatalog);
            $disks = $this->fetchDisks($integration, $session, $storageCatalog);

            return [
                'volumes' => $volumes,
                'disks' => $disks,
                'shares' => [],
                'services' => [],
            ];
        } finally {
            $this->logout($integration, $session);
        }
    }

    public function summarize(array $snapshot): array
    {
        return $this->summarizeDefault($snapshot);
    }

    private function fetchApiInfo(Integration $integration, string $query): array
    {
        $baseUrl = $this->baseUrl($integration);
        $response = $this->http($integration)->get("{$baseUrl}/webapi/query.cgi", [
            'api' => 'SYNO.API.Info',
            'version' => 1,
            'method' => 'query',
            'query' => $query,
        ]);

        return [
            'response' => $response,
            'payload' => $this->json($response),
        ];
    }

    private function login(Integration $integration, array $authInfo): array
    {
        $username = $this->username($integration);
        $password = $this->password($integration);

        if ($username === '' || $password === '') {
            throw new \RuntimeException('Synology DSM credentials are missing. Set the admin username on the integration and attach a vault entry with the password.');
        }

        $baseUrl = $this->baseUrl($integration);
        $authPath = $authInfo['path'] ?? 'auth.cgi';
        $authVersion = (int) ($authInfo['maxVersion'] ?? 6);
        $authEndpoint = "{$baseUrl}/webapi/".ltrim((string) $authPath, '/');

        $response = $this->http($integration)->get($authEndpoint, [
            'api' => self::AUTH_QUERY,
            'version' => max(1, $authVersion),
            'method' => 'login',
            'account' => $username,
            'passwd' => $password,
            'session' => 'InfraControl',
            'format' => 'sid',
        ]);

        $payload = $this->json($response);
        $sid = $payload['data']['sid'] ?? null;

        if (! $response->successful() || ! ($payload['success'] ?? false) || ! is_string($sid) || $sid === '') {
            throw new \RuntimeException('Synology DSM login failed while requesting storage snapshot.');
        }

        return [
            'sid' => $sid,
            'auth_path' => $authPath,
            'auth_version' => $authVersion,
        ];
    }

    private function logout(Integration $integration, array $session): void
    {
        if (! isset($session['sid'])) {
            return;
        }

        $baseUrl = $this->baseUrl($integration);
        $authPath = $session['auth_path'] ?? 'auth.cgi';
        $authVersion = (int) ($session['auth_version'] ?? 6);
        $authEndpoint = "{$baseUrl}/webapi/".ltrim((string) $authPath, '/');

        try {
            $this->http($integration)->get($authEndpoint, [
                'api' => self::AUTH_QUERY,
                'version' => max(1, $authVersion),
                'method' => 'logout',
                'session' => 'InfraControl',
                '_sid' => $session['sid'],
            ]);
        } catch (\Throwable) {
            // Ignore logout failures; snapshot data is already collected.
        }
    }

    private function fetchVolumes(Integration $integration, array $session, array $storageCatalog): array
    {
        $volumeApi = $storageCatalog['SYNO.Core.Storage.Volume'] ?? [
            'path' => 'entry.cgi',
            'maxVersion' => 1,
        ];

        $payload = $this->callApi(
            $integration,
            $session,
            'SYNO.Core.Storage.Volume',
            'list',
            (int) ($volumeApi['maxVersion'] ?? 1),
            [
                'limit' => -1,
                'offset' => 0,
                'location' => 'internal',
            ],
            (string) ($volumeApi['path'] ?? 'entry.cgi'),
        );

        $rawVolumes = $payload['volumes']
            ?? $payload['items']
            ?? $payload['data']
            ?? [];

        if (isset($rawVolumes['volume_path']) || isset($rawVolumes['display_name'])) {
            $rawVolumes = [$rawVolumes];
        }

        $volumes = collect(is_array($rawVolumes) ? $rawVolumes : [])
            ->filter(fn ($volume) => is_array($volume))
            ->map(fn (array $volume) => $this->mapVolume($volume))
            ->values()
            ->all();

        return $this->enrichVolumesFromShares($integration, $session, $storageCatalog, $volumes);
    }

    private function fetchDisks(Integration $integration, array $session, array $storageCatalog): array
    {
        $candidates = [
            'SYNO.Core.Storage.Disk',
            'SYNO.Core.Storage.Drive',
        ];

        foreach ($candidates as $apiName) {
            $apiInfo = $storageCatalog[$apiName] ?? null;

            if (! is_array($apiInfo)) {
                continue;
            }

            try {
                $payload = $this->callApi(
                    $integration,
                    $session,
                    $apiName,
                    'list',
                    (int) ($apiInfo['maxVersion'] ?? 1),
                    [
                        'limit' => -1,
                        'offset' => 0,
                    ],
                    (string) ($apiInfo['path'] ?? 'entry.cgi'),
                );

                $rawDisks = $payload['disks']
                    ?? $payload['items']
                    ?? $payload['drives']
                    ?? [];

                if (isset($rawDisks['id']) || isset($rawDisks['disk_path']) || isset($rawDisks['device'])) {
                    $rawDisks = [$rawDisks];
                }

                $disks = collect(is_array($rawDisks) ? $rawDisks : [])
                    ->filter(fn ($disk) => is_array($disk))
                    ->map(fn (array $disk) => $this->mapDisk($disk))
                    ->values()
                    ->all();

                if ($disks !== []) {
                    return $disks;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
    }

    private function callApi(
        Integration $integration,
        array $session,
        string $api,
        string $method,
        int $version,
        array $params,
        string $path = 'entry.cgi',
    ): array {
        $baseUrl = $this->baseUrl($integration);
        $endpoint = "{$baseUrl}/webapi/".ltrim($path, '/');
        $response = $this->http($integration)->get($endpoint, [
            'api' => $api,
            'version' => max(1, $version),
            'method' => $method,
            '_sid' => $session['sid'],
            ...$params,
        ]);

        $payload = $this->json($response);

        if (! $response->successful() || ! ($payload['success'] ?? false)) {
            throw new \RuntimeException("Synology API {$api}.{$method} failed.");
        }

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    private function enrichVolumesFromShares(
        Integration $integration,
        array $session,
        array $storageCatalog,
        array $volumes,
    ): array {
        $needsCapacityFallback = $volumes === []
            || collect($volumes)->every(
                fn (array $volume) => (($volume['total_bytes'] ?? 0) <= 0)
                    && (($volume['used_bytes'] ?? 0) <= 0)
                    && (($volume['free_bytes'] ?? 0) <= 0)
            );

        if (! $needsCapacityFallback) {
            return $volumes;
        }

        $fileStationApi = $storageCatalog['SYNO.FileStation.List'] ?? null;

        if (! is_array($fileStationApi)) {
            return $volumes;
        }

        try {
            $payload = $this->callApi(
                $integration,
                $session,
                'SYNO.FileStation.List',
                'list_share',
                (int) ($fileStationApi['maxVersion'] ?? 2),
                [
                    'offset' => 0,
                    'limit' => 0,
                    'onlywritable' => 'false',
                    'additional' => json_encode(['real_path', 'volume_status']),
                ],
                (string) ($fileStationApi['path'] ?? 'entry.cgi'),
            );
        } catch (\Throwable) {
            return $volumes;
        }

        $shareVolumes = collect(is_array($payload['shares'] ?? null) ? $payload['shares'] : [])
            ->filter(fn ($share) => is_array($share))
            ->map(fn (array $share) => $this->mapShareVolume($share))
            ->filter(fn ($volume) => is_array($volume))
            ->keyBy(fn (array $volume) => $volume['id'])
            ->all();

        if ($shareVolumes === []) {
            return $volumes;
        }

        if ($volumes === []) {
            return array_values($shareVolumes);
        }

        return collect($volumes)
            ->map(function (array $volume) use ($shareVolumes) {
                $id = (string) ($volume['id'] ?? '');
                $shareVolume = $shareVolumes[$id] ?? null;

                if (! is_array($shareVolume)) {
                    return $volume;
                }

                return [
                    ...$volume,
                    'total_bytes' => max((int) ($volume['total_bytes'] ?? 0), (int) ($shareVolume['total_bytes'] ?? 0)),
                    'used_bytes' => max((int) ($volume['used_bytes'] ?? 0), (int) ($shareVolume['used_bytes'] ?? 0)),
                    'free_bytes' => max((int) ($volume['free_bytes'] ?? 0), (int) ($shareVolume['free_bytes'] ?? 0)),
                    'raw' => [
                        'storage' => $volume['raw'] ?? [],
                        'file_station' => $shareVolume['raw'] ?? [],
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function mapVolume(array $volume): array
    {
        $total = $this->toInt(
            $volume['total_size']
            ?? $volume['size_total']
            ?? $volume['size']
            ?? $volume['total']
            ?? null
        );
        $used = $this->toInt(
            $volume['used_size']
            ?? $volume['size_used']
            ?? $volume['used']
            ?? null
        );
        $free = $this->toInt(
            $volume['free_size']
            ?? $volume['size_free']
            ?? $volume['free']
            ?? max(0, $total - $used)
        );

        return [
            'id' => $volume['volume_path'] ?? $volume['id'] ?? $volume['name'] ?? uniqid('syno-volume-', true),
            'name' => $volume['display_name'] ?? $volume['name'] ?? $volume['volume_path'] ?? 'Volume',
            'status' => $volume['status'] ?? $volume['health'] ?? null,
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
            'raw' => $volume,
        ];
    }

    private function mapShareVolume(array $share): ?array
    {
        $additional = is_array($share['additional'] ?? null) ? $share['additional'] : [];
        $volumeStatus = is_array($additional['volume_status'] ?? null) ? $additional['volume_status'] : [];
        $realPath = (string) ($additional['real_path'] ?? '');
        $volumePath = $this->extractVolumePath($realPath);

        if ($volumePath === '' || $volumeStatus === []) {
            return null;
        }

        $total = $this->toInt($volumeStatus['totalspace'] ?? null);
        $free = $this->toInt($volumeStatus['freespace'] ?? null);
        $used = max(0, $total - $free);

        return [
            'id' => $volumePath,
            'name' => $volumePath,
            'status' => ($volumeStatus['readonly'] ?? false) ? 'readonly' : 'normal',
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
            'raw' => $share,
        ];
    }

    private function mapDisk(array $disk): array
    {
        $status = strtolower((string) ($disk['status'] ?? $disk['health'] ?? $disk['smart_status'] ?? 'unknown'));
        $health = str_contains($status, 'normal') || str_contains($status, 'healthy') || str_contains($status, 'initialized')
            ? 'healthy'
            : ($status !== '' ? $status : 'unknown');

        return [
            'id' => $disk['disk_path'] ?? $disk['device'] ?? $disk['id'] ?? uniqid('syno-disk-', true),
            'name' => $disk['disk_path'] ?? $disk['device'] ?? $disk['id'] ?? 'Disk',
            'slot' => $disk['device_no'] ?? $disk['slot'] ?? $disk['tray'] ?? null,
            'model' => $disk['model'] ?? null,
            'serial' => $disk['serial'] ?? null,
            'temperature_c' => $this->toInt($disk['temp'] ?? $disk['temperature'] ?? null),
            'total_bytes' => $this->toInt($disk['size_total'] ?? $disk['size'] ?? $disk['total_size'] ?? null),
            'status' => $disk['status'] ?? null,
            'health' => $health,
            'raw' => $disk,
        ];
    }

    private function toInt(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }

    private function extractVolumePath(string $realPath): string
    {
        if ($realPath === '') {
            return '';
        }

        if (preg_match('#^(/volume\d+)#', $realPath, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }
}
