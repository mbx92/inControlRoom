<?php

namespace App\Services\Alerting\Nas;

use App\Models\Integration;

class QnapNasAdapter extends AbstractNasAdapter
{
    public function supports(string $vendor): bool
    {
        return $vendor === 'qnap';
    }

    public function label(): string
    {
        return 'QNAP QTS';
    }

    public function check(Integration $integration): array
    {
        $baseUrl = $this->baseUrl($integration);
        $loginEndpoint = "{$baseUrl}/cgi-bin/authLogin.cgi";
        $startedAt = microtime(true);
        $response = $this->http($integration)->get($loginEndpoint, [
            'user' => $this->username($integration),
            'plain_pwd' => $this->password($integration),
            'service' => 1,
        ]);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => "QNAP QTS returned HTTP {$response->status()}",
                'meta' => $this->buildReachabilityMeta(
                    $integration,
                    $loginEndpoint,
                    $response->status(),
                    $latencyMs,
                    false,
                ),
            ];
        }

        $username = $this->username($integration);
        $password = $this->password($integration);

        if ($username === '' || $password === '') {
            return [
                'success' => true,
                'message' => 'QNAP login endpoint reachable, but no QTS credentials are attached yet.',
                'meta' => $this->buildReachabilityMeta(
                    $integration,
                    $loginEndpoint,
                    $response->status(),
                    $latencyMs,
                    true,
                    [
                        'auth_status' => 'missing',
                    ],
                ),
            ];
        }

        $payload = $this->xml($response->body());
        $success = ($payload['authPassed'] ?? null) === '1';
        $sid = trim((string) ($payload['authSid'] ?? ''));

        if (! $success || $sid === '') {
            return [
                'success' => false,
                'message' => 'QNAP QTS authentication failed.',
                'meta' => $this->buildReachabilityMeta(
                    $integration,
                    $loginEndpoint,
                    $response->status(),
                    $latencyMs,
                    false,
                ),
            ];
        }

        $checkEndpoint = "{$baseUrl}/cgi-bin/filemanager/utilRequest.cgi";
        $checkResponse = $this->http($integration)->get($checkEndpoint, [
            'func' => 'check_sid',
            'sid' => $sid,
        ]);
        $checkPayload = $this->json($checkResponse);

        return [
            'success' => $checkResponse->successful() && (int) ($checkPayload['status'] ?? 0) === 1,
            'message' => $checkResponse->successful()
                ? 'Connected to QNAP QTS'
                : "QNAP QTS returned HTTP {$checkResponse->status()}",
            'meta' => $this->buildReachabilityMeta(
                $integration,
                $checkEndpoint,
                $checkResponse->status(),
                $latencyMs,
                $checkResponse->successful() && (int) ($checkPayload['status'] ?? 0) === 1,
                [
                    'servername' => $checkPayload['servername'] ?? null,
                    'version' => $checkPayload['version'] ?? null,
                    'build' => $checkPayload['build'] ?? null,
                ],
            ),
        ];
    }

    public function capture(Integration $integration): array
    {
        $session = $this->login($integration);
        $volumes = $this->fetchVolumes($integration, $session['sid']);
        $physicalDisks = $this->mapPhysicalDisksFromVolumes($volumes);
        $raidDisks = $this->mapRaidDisksFromVolumes($volumes);
        $notes = $this->buildNotes($physicalDisks, $raidDisks, $volumes);

        return [
            'volumes' => $volumes,
            'disks' => $physicalDisks,
            'physical_disks' => $physicalDisks,
            'raid_disks' => $raidDisks,
            'shares' => [],
            'services' => [],
            'notes' => $notes,
        ];
    }

    public function summarize(array $snapshot): array
    {
        return $this->summarizeDefault($snapshot);
    }

    private function login(Integration $integration): array
    {
        $username = $this->username($integration);
        $password = $this->password($integration);

        if ($username === '' || $password === '') {
            throw new \RuntimeException('QNAP QTS credentials are missing. Set the admin username on the integration and attach a vault entry with the password.');
        }

        $response = $this->http($integration)->get($this->baseUrl($integration).'/cgi-bin/authLogin.cgi', [
            'user' => $username,
            'plain_pwd' => $password,
            'service' => 1,
        ]);

        $payload = $this->xml($response->body());
        $sid = trim((string) ($payload['authSid'] ?? ''));

        if (! $response->successful() || ($payload['authPassed'] ?? null) !== '1' || $sid === '') {
            throw new \RuntimeException('QNAP QTS login failed while requesting storage snapshot.');
        }

        return [
            'sid' => $sid,
        ];
    }

    private function fetchVolumes(Integration $integration, string $sid): array
    {
        $response = $this->http($integration)->get($this->baseUrl($integration).'/cgi-bin/filemanager/utilRequest.cgi', [
            'func' => 'get_tree',
            'sid' => $sid,
            'node' => 'vol_root',
            'is_iso' => 0,
            'hidden_file' => 0,
            'check_acl' => 0,
            'recycle' => 0,
        ]);

        $payload = $this->json($response);

        if (! $response->successful() || ! is_array($payload)) {
            throw new \RuntimeException('QNAP QTS volume query failed while requesting storage snapshot.');
        }

        return collect($payload)
            ->filter(fn ($volume) => is_array($volume))
            ->map(fn (array $volume) => $this->mapVolume($volume))
            ->values()
            ->all();
    }

    private function mapVolume(array $volume): array
    {
        $total = $this->toBytes(
            $volume['capacity'] ?? $volume['total_size'] ?? $volume['size'] ?? null,
            $volume['volume_unit'] ?? $volume['unit'] ?? null,
        );
        $used = $this->toBytes(
            $volume['used_size'] ?? $volume['used'] ?? null,
            $volume['volume_unit'] ?? $volume['unit'] ?? null,
        );
        $free = $this->toBytes(
            $volume['free_size'] ?? $volume['available_size'] ?? null,
            $volume['volume_free_unit'] ?? $volume['volume_unit'] ?? $volume['unit'] ?? null,
        );

        return [
            'id' => (string) ($volume['volume_id'] ?? $volume['volume_name'] ?? uniqid('qnap-volume-', true)),
            'name' => $volume['volume_name'] ?? 'Volume',
            'status' => $this->mapVolumeStatus($volume['volume_status'] ?? null, $volume['pool_status'] ?? null),
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free > 0 ? $free : max(0, $total - $used),
            'raw' => $volume,
        ];
    }

    private function mapPhysicalDisksFromVolumes(array $volumes): array
    {
        $inventory = [];

        foreach ($volumes as $volume) {
            $raw = is_array($volume['raw'] ?? null) ? $volume['raw'] : [];

            foreach ($this->extractDiskMembership($raw, includeAllSources: true) as $diskId => $sources) {
                if (isset($inventory[$diskId])) {
                    $inventory[$diskId]['raw']['sources'] = array_values(array_unique([
                        ...($inventory[$diskId]['raw']['sources'] ?? []),
                        ...$sources,
                    ]));

                    continue;
                }

                $inventory[$diskId] = $this->makeDiskRecord($diskId, 'physical', $sources);
            }
        }

        return array_values($inventory);
    }

    private function mapRaidDisksFromVolumes(array $volumes): array
    {
        $disks = [];

        foreach ($volumes as $volume) {
            $raw = is_array($volume['raw'] ?? null) ? $volume['raw'] : [];

            foreach ($this->extractDiskMembership($raw, includeAllSources: false) as $diskId => $sources) {
                if (isset($disks[$diskId])) {
                    continue;
                }

                $disks[$diskId] = $this->makeDiskRecord($diskId, 'raid', $sources);
            }
        }

        return array_values($disks);
    }

    private function makeDiskRecord(string $diskId, string $kind, array $sources): array
    {
        return [
            'id' => $diskId,
            'name' => "Disk {$diskId}",
            'slot' => is_numeric($diskId) ? (int) $diskId : null,
            'model' => null,
            'serial' => null,
            'temperature_c' => null,
            'total_bytes' => 0,
            'status' => 'online',
            'health' => 'healthy',
            'raw' => [
                'kind' => $kind,
                'sources' => array_values(array_unique($sources)),
            ],
        ];
    }

    private function extractDiskMembership(array $raw, bool $includeAllSources): array
    {
        $fieldPriority = $includeAllSources
            ? [
                'raid_disk_list',
                'disk_list',
                'sys_disk_list',
                'spare_disk_list',
                'global_spare_disk_list',
                'cache_disk_list',
                'ssd_disk_list',
                'tier_disk_list',
            ]
            : [
                'raid_disk_list',
            ];

        $membership = [];

        foreach ($fieldPriority as $field) {
            foreach ($this->extractDiskIds($raw[$field] ?? null) as $diskId) {
                $membership[$diskId] ??= [];
                $membership[$diskId][] = $field;
            }
        }

        if ($includeAllSources) {
            foreach ($raw as $key => $value) {
                if (! is_string($key) || in_array($key, $fieldPriority, true)) {
                    continue;
                }

                if (! preg_match('/(^|_)(disk|slot)(s|_list|_ids?)?$/i', $key)
                    && ! preg_match('/(disk|slot)_list/i', $key)
                    && ! preg_match('/(spare|cache|ssd|tier).*disk/i', $key)) {
                    continue;
                }

                foreach ($this->extractDiskIds($value) as $diskId) {
                    $membership[$diskId] ??= [];
                    $membership[$diskId][] = $key;
                }
            }
        }

        return array_map(
            fn (array $sources) => array_values(array_unique($sources)),
            $membership,
        );
    }

    private function extractDiskIds(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return collect($value)
                ->flatMap(fn ($item) => $this->extractDiskIds($item))
                ->values()
                ->all();
        }

        $string = trim((string) $value);

        if ($string === '' || $string === '0') {
            return [];
        }

        preg_match_all('/\d+/', $string, $matches);

        return collect($matches[0] ?? [])
            ->map(fn ($diskId) => ltrim((string) $diskId, '0'))
            ->map(fn ($diskId) => $diskId === '' ? '0' : $diskId)
            ->filter(fn ($diskId) => $diskId !== '0')
            ->unique()
            ->values()
            ->all();
    }

    private function buildNotes(array $physicalDisks, array $raidDisks, array $volumes): array
    {
        $notes = [];

        if ($physicalDisks !== []) {
            $notes[] = sprintf(
                'Physical disk inventory is inferred from QNAP storage metadata fields exposed by the volume API. Current snapshot found %d physical disk candidate(s).',
                count($physicalDisks),
            );
        }

        if ($raidDisks !== []) {
            $notes[] = sprintf(
                'RAID member disk list is built from QNAP RAID membership fields such as raid_disk_list. Current snapshot found %d RAID member disk(s).',
                count($raidDisks),
            );
        }

        if (count($physicalDisks) > count($raidDisks) && $raidDisks !== []) {
            $notes[] = 'Physical disk count is higher than RAID member count. This usually means some disks are spare, cache, system, or belong to metadata fields outside the active RAID membership list.';
        }

        if ($physicalDisks === [] && $volumes !== []) {
            $notes[] = 'QNAP volume API returned storage capacity, but no disk inventory fields were exposed in the current response. Disk count may be incomplete for this firmware/API combination.';
        }

        return $notes;
    }

    private function mapVolumeStatus(mixed $volumeStatus, mixed $poolStatus): string
    {
        $volume = is_numeric($volumeStatus) ? (int) $volumeStatus : null;
        $pool = is_numeric($poolStatus) ? (int) $poolStatus : null;

        if ($volume === 0 && ($pool === null || in_array($pool, [0, -1], true))) {
            return 'healthy';
        }

        if ($volume === 1 || $pool === 1) {
            return 'degraded';
        }

        return 'unknown';
    }

    private function toBytes(mixed $value, mixed $unit): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $amount = is_numeric($value) ? (float) $value : 0.0;
        $normalizedUnit = strtoupper(trim((string) $unit));

        $multipliers = [
            'B' => 1,
            'KB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4,
            'PB' => 1024 ** 5,
        ];

        return (int) round($amount * ($multipliers[$normalizedUnit] ?? 1));
    }

    private function xml(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        try {
            $parsed = simplexml_load_string($xml);
        } catch (\Throwable) {
            return [];
        }

        if ($parsed === false) {
            return [];
        }

        $values = [];

        foreach ($parsed->children() as $key => $value) {
            $values[$key] = trim((string) $value);
        }

        return $values;
    }
}
