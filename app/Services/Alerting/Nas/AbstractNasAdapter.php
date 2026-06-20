<?php

namespace App\Services\Alerting\Nas;

use App\Models\Integration;
use App\Services\Alerting\IntegrationCredentialsResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

abstract class AbstractNasAdapter implements NasVendorAdapter
{
    public function __construct(
        protected readonly IntegrationCredentialsResolver $credentialsResolver,
    ) {
    }

    protected function config(Integration $integration): array
    {
        return $integration->config ?? [];
    }

    protected function verifySsl(Integration $integration): bool
    {
        return (bool) ($this->config($integration)['verify_ssl'] ?? true);
    }

    protected function vendor(Integration $integration): string
    {
        return (string) ($this->config($integration)['vendor'] ?? '');
    }

    protected function username(Integration $integration): string
    {
        $config = $this->config($integration);
        $credentials = $this->credentialsResolver->resolve($integration);

        return trim((string) ($config['username'] ?? $credentials['username'] ?? ''));
    }

    protected function password(Integration $integration): string
    {
        $credentials = $this->credentialsResolver->resolve($integration);

        return trim((string) ($credentials['password'] ?? $credentials['token'] ?? ''));
    }

    protected function baseUrl(Integration $integration): string
    {
        return rtrim($integration->base_url, '/');
    }

    protected function http(Integration $integration)
    {
        return Http::withOptions(['verify' => $this->verifySsl($integration)])
            ->timeout(15);
    }

    protected function defaultSnapshot(): array
    {
        return [
            'volumes' => [],
            'disks' => [],
            'physical_disks' => [],
            'raid_disks' => [],
            'shares' => [],
            'services' => [],
            'notes' => [],
        ];
    }

    protected function summarizeDefault(array $snapshot): array
    {
        $volumes = collect($snapshot['volumes'] ?? []);
        $physicalDisks = collect($snapshot['physical_disks'] ?? []);
        $raidDisks = collect($snapshot['raid_disks'] ?? []);
        $disks = $physicalDisks->isNotEmpty() ? $physicalDisks : collect($snapshot['disks'] ?? []);
        $raidDiskSource = $raidDisks->isNotEmpty() ? $raidDisks : $disks;

        return [
            'volume_total' => $volumes->count(),
            'disk_total' => $disks->count(),
            'physical_disk_total' => $disks->count(),
            'raid_disk_total' => $raidDiskSource->count(),
            'healthy_disk_total' => $disks->where('health', 'healthy')->count(),
            'degraded_disk_total' => $disks->filter(fn (array $disk) => ($disk['health'] ?? null) !== 'healthy')->count(),
            'storage_total_bytes' => $volumes->sum(fn (array $volume) => (int) ($volume['total_bytes'] ?? 0)),
            'storage_used_bytes' => $volumes->sum(fn (array $volume) => (int) ($volume['used_bytes'] ?? 0)),
            'storage_free_bytes' => $volumes->sum(fn (array $volume) => (int) ($volume['free_bytes'] ?? 0)),
        ];
    }

    protected function buildReachabilityMeta(
        Integration $integration,
        string $endpoint,
        int $status,
        int $latencyMs,
        bool $success,
        array $extra = [],
    ): array {
        return [
            'kind' => 'nas',
            'product' => $this->label(),
            'vendor' => $this->vendor($integration),
            'verify_ssl' => $this->verifySsl($integration),
            'health_endpoint' => $endpoint,
            'api_reachable' => true,
            'auth_status' => $success ? 'valid' : (in_array($status, [401, 403], true) ? 'failed' : 'unknown'),
            'latency_ms' => $latencyMs,
            'http_status' => $status,
            'health_method' => 'GET',
            'expected_status' => 200,
            ...$extra,
        ];
    }

    protected function genericHealthCheck(Integration $integration, string $path): array
    {
        $endpoint = $this->baseUrl($integration).$path;
        $startedAt = microtime(true);
        $response = $this->http($integration)->get($endpoint);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $success = $response->successful();

        return [
            'success' => $success,
            'message' => $success
                ? "Connected to {$this->label()}"
                : "{$this->label()} returned HTTP {$response->status()}",
            'meta' => $this->buildReachabilityMeta(
                $integration,
                $endpoint,
                $response->status(),
                $latencyMs,
                $success,
            ),
        ];
    }

    protected function json(Response $response): array
    {
        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }
}
