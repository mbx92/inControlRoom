<?php

namespace App\Services\Alerting;

use App\Models\Integration;
use App\Models\Metric;
use Illuminate\Support\Facades\Http;

class ProxmoxMetricSnapshotService
{
    public function __construct(
        private readonly IntegrationCredentialsResolver $credentialsResolver,
    ) {
    }

    public function capture(Integration $integration): array
    {
        if ($integration->type !== 'proxmox') {
            return [];
        }

        $credentials = $this->credentialsResolver->resolve($integration);
        $verifySsl = (bool) (($integration->config ?? [])['verify_ssl'] ?? true);
        $guests = $this->fetchGuestsSnapshot($integration->base_url, $credentials, $verifySsl);
        $recordedAt = now();

        foreach ($guests as $guest) {
            $labels = [
                'site_id' => $integration->site_id,
                'node' => $guest['node'] ?? null,
                'vmid' => $guest['vmid'] ?? null,
                'guest_type' => $guest['type'] ?? null,
                'guest_name' => $guest['name'] ?? null,
            ];

            $this->recordMetric($integration, 'guest.status', (string) ($guest['status'] ?? 'unknown'), $labels, $recordedAt);
            $this->recordMetric($integration, 'guest.cpu_usage_percent', $guest['cpu_usage_percent'], $labels, $recordedAt);
            $this->recordMetric($integration, 'guest.memory_usage_percent', $guest['memory_usage_percent'], $labels, $recordedAt);
            $this->recordMetric($integration, 'guest.disk_usage_percent', $guest['disk_usage_percent'], $labels, $recordedAt);
        }

        return $guests;
    }

    private function recordMetric(Integration $integration, string $key, mixed $value, array $labels, \Illuminate\Support\Carbon $recordedAt): void
    {
        if ($value === null || $value === '') {
            return;
        }

        Metric::create([
            'integration_id' => $integration->id,
            'key' => $key,
            'value' => (string) $value,
            'labels' => $labels,
            'recorded_at' => $recordedAt,
        ]);
    }

    private function fetchGuestsSnapshot(string $baseUrl, array $credentials, bool $verifySsl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $response = Http::withOptions(['verify' => $verifySsl])
            ->withHeaders([
                'Authorization' => 'PVEAPIToken='.($credentials['token'] ?? ''),
            ])
            ->timeout(15)
            ->get("{$baseUrl}/api2/json/cluster/resources", [
                'type' => 'vm',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException("Proxmox returned HTTP {$response->status()} while fetching cluster resources.");
        }

        return collect($response->json('data', []))
            ->filter(fn (array $guest) => in_array($guest['type'] ?? null, ['qemu', 'lxc'], true))
            ->sortBy([
                ['type', 'asc'],
                ['vmid', 'asc'],
            ])
            ->map(function (array $guest) {
                $memoryUsed = $guest['mem'] ?? null;
                $memoryTotal = $guest['maxmem'] ?? null;
                $diskUsed = $guest['disk'] ?? null;
                $diskTotal = $guest['maxdisk'] ?? null;
                $cpuFraction = $guest['cpu'] ?? null;

                return [
                    'id' => $guest['id'] ?? (($guest['type'] ?? 'guest').'/'.($guest['vmid'] ?? 'unknown')),
                    'type' => $guest['type'] ?? 'unknown',
                    'name' => $guest['name'] ?? ('Guest '.($guest['vmid'] ?? 'unknown')),
                    'vmid' => $guest['vmid'] ?? null,
                    'node' => $guest['node'] ?? null,
                    'status' => $guest['status'] ?? 'unknown',
                    'is_online' => ($guest['status'] ?? null) === 'running',
                    'cpu_usage_percent' => $cpuFraction !== null ? round($cpuFraction * 100, 1) : null,
                    'memory_usage_percent' => $this->percentage($memoryUsed, $memoryTotal),
                    'disk_usage_percent' => $this->percentage($diskUsed, $diskTotal),
                ];
            })
            ->values()
            ->all();
    }

    private function percentage($used, $total): ?float
    {
        if (! is_numeric($used) || ! is_numeric($total) || (float) $total <= 0.0) {
            return null;
        }

        return round((((float) $used) / ((float) $total)) * 100, 1);
    }
}
