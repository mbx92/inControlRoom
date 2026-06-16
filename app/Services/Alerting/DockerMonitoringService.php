<?php

namespace App\Services\Alerting;

use App\Models\Integration;
use App\Models\Metric;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class DockerMonitoringService
{
    public function __construct(
        private readonly IntegrationCredentialsResolver $credentialsResolver,
    ) {
    }

    public function check(Integration $integration): array
    {
        if ($integration->type !== 'docker') {
            return [
                'success' => false,
                'message' => "Unsupported integration type: {$integration->type}",
                'meta' => [],
            ];
        }

        $config = $integration->config ?? [];
        $verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $client = $this->dockerHttpClient($integration);
        $baseUrl = rtrim($integration->base_url, '/');

        $startedAt = microtime(true);
        $pingResponse = $client->get("{$baseUrl}/_ping");
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $pingResponse->successful() || trim((string) $pingResponse->body()) !== 'OK') {
            return [
                'success' => false,
                'message' => "Docker returned HTTP {$pingResponse->status()} for /_ping",
                'meta' => [
                    'kind' => 'docker',
                    'product' => 'Docker Engine',
                    'verify_ssl' => $verifySsl,
                    'health_endpoint' => "{$baseUrl}/_ping",
                    'api_reachable' => $pingResponse->successful(),
                    'auth_status' => in_array($pingResponse->status(), [401, 403], true) ? 'failed' : 'unknown',
                    'latency_ms' => $latencyMs,
                    'http_status' => $pingResponse->status(),
                    'health_method' => 'GET',
                    'expected_status' => 200,
                ],
            ];
        }

        $versionResponse = $client->get("{$baseUrl}/version");
        $containersResponse = $client->get("{$baseUrl}/containers/json", ['all' => 1]);

        $containers = $containersResponse->successful() ? collect($containersResponse->json()) : collect();
        $runningCount = $containers->filter(fn (array $container) => ($container['State'] ?? null) === 'running')->count();
        $stoppedCount = $containers->count() - $runningCount;

        return [
            'success' => true,
            'message' => $versionResponse->successful()
                ? 'Connected to Docker Engine '.($versionResponse->json('Version') ?? 'unknown')
                : 'Connected to Docker Engine',
            'meta' => [
                'kind' => 'docker',
                'product' => 'Docker Engine',
                'version' => $versionResponse->json('Version'),
                'api_version' => $versionResponse->json('ApiVersion'),
                'os' => $versionResponse->json('Os'),
                'kernel_version' => $versionResponse->json('KernelVersion'),
                'container_count' => $containers->count(),
                'running_count' => $runningCount,
                'stopped_count' => $stoppedCount,
                'verify_ssl' => $verifySsl,
                'health_endpoint' => "{$baseUrl}/_ping",
                'api_reachable' => true,
                'auth_status' => 'valid',
                'latency_ms' => $latencyMs,
                'http_status' => 200,
                'health_method' => 'GET',
                'expected_status' => 200,
            ],
        ];
    }

    public function capture(Integration $integration, bool $persistMetrics = true): array
    {
        if ($integration->type !== 'docker') {
            return [];
        }

        $recordedAt = now();
        $containerRows = collect($this->fetchContainerRows($integration))
            ->map(function (array $container) {
                $id = (string) ($container['Id'] ?? '');

                return [
                    ...$container,
                    '_id' => $id,
                    '_name' => ltrim((string) collect($container['Names'] ?? [])->first(), '/'),
                    '_is_running' => ($container['State'] ?? null) === 'running',
                ];
            })
            ->filter(fn (array $container) => $container['_id'] !== '')
            ->values();

        $inspectResponses = $this->fetchContainerInspects($integration, $containerRows->pluck('_id')->all());
        $statsResponses = $this->fetchContainerStats(
            $integration,
            $containerRows->filter(fn (array $container) => $container['_is_running'])->pluck('_id')->all(),
        );

        $containers = $containerRows
            ->map(function (array $container) use ($inspectResponses, $statsResponses) {
                $id = $container['_id'];
                $name = $container['_name'];
                $inspectResponse = $inspectResponses[$id] ?? null;
                $statsResponse = $statsResponses[$id] ?? null;
                $inspect = $inspectResponse?->successful() ? $inspectResponse->json() : [];
                $stats = $statsResponse?->successful() ? $statsResponse->json() : [];

                $memoryUsage = data_get($stats, 'memory_stats.usage');
                $memoryLimit = data_get($stats, 'memory_stats.limit');

                return [
                    'id' => $id,
                    'name' => $name !== '' ? $name : substr($id, 0, 12),
                    'image' => $container['Image'] ?? null,
                    'state' => $inspect['State']['Status'] ?? ($container['State'] ?? 'unknown'),
                    'status' => $container['Status'] ?? ($inspect['State']['Status'] ?? 'unknown'),
                    'is_running' => ($inspect['State']['Running'] ?? $container['_is_running']) === true,
                    'health' => $inspect['State']['Health']['Status'] ?? null,
                    'restart_count' => (int) ($inspect['RestartCount'] ?? 0),
                    'cpu_usage_percent' => $this->cpuPercent($stats),
                    'memory_usage_bytes' => is_numeric($memoryUsage) ? (int) $memoryUsage : null,
                    'memory_limit_bytes' => is_numeric($memoryLimit) ? (int) $memoryLimit : null,
                    'memory_usage_percent' => $this->percentage($memoryUsage, $memoryLimit),
                    'started_at' => $inspect['State']['StartedAt'] ?? null,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        if ($persistMetrics) {
            foreach ($containers as $container) {
                $labels = [
                    'site_id' => $integration->site_id,
                    'container_id' => $container['id'],
                    'container_name' => $container['name'],
                    'image' => $container['image'],
                    'state' => $container['state'],
                ];

                $this->recordMetric($integration, 'container.state', (string) $container['state'], $labels, $recordedAt);
                $this->recordMetric($integration, 'container.cpu_usage_percent', $container['cpu_usage_percent'], $labels, $recordedAt);
                $this->recordMetric($integration, 'container.memory_usage_percent', $container['memory_usage_percent'], $labels, $recordedAt);
                $this->recordMetric($integration, 'container.memory_usage_bytes', $container['memory_usage_bytes'], $labels, $recordedAt);
            }
        }

        return $containers;
    }

    public function listBasic(Integration $integration): array
    {
        $containerRows = collect($this->fetchContainerRows($integration));

        $inspectResponses = $this->fetchContainerInspects(
            $integration,
            $containerRows->pluck('Id')->map(fn ($id) => (string) $id)->all(),
        );

        return $containerRows
            ->map(function (array $container) use ($inspectResponses) {
                $id = (string) ($container['Id'] ?? '');
                $name = ltrim((string) collect($container['Names'] ?? [])->first(), '/');
                $state = (string) ($container['State'] ?? 'unknown');
                $inspectResponse = $inspectResponses[$id] ?? null;
                $inspect = $inspectResponse?->successful() ? $inspectResponse->json() : [];
                $isRunning = ($inspect['State']['Running'] ?? ($state === 'running')) === true;

                return [
                    'id' => $id,
                    'name' => $name !== '' ? $name : substr($id, 0, 12),
                    'image' => $container['Image'] ?? null,
                    'state' => $inspect['State']['Status'] ?? $state,
                    'status' => $container['Status'] ?? $state,
                    'is_running' => $isRunning,
                    'health' => $inspect['State']['Health']['Status'] ?? null,
                    'restart_count' => is_numeric($inspect['RestartCount'] ?? null) ? (int) $inspect['RestartCount'] : null,
                    'cpu_usage_percent' => null,
                    'memory_usage_bytes' => null,
                    'memory_limit_bytes' => null,
                    'memory_usage_percent' => null,
                    'started_at' => $inspect['State']['StartedAt'] ?? null,
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();
    }

    public function summarize(array $containers): array
    {
        $collection = collect($containers);

        return [
            'container_total' => $collection->count(),
            'running_total' => $collection->where('is_running', true)->count(),
            'stopped_total' => $collection->where('is_running', false)->count(),
            'healthy_total' => $collection->where('health', 'healthy')->count(),
            'cpu_usage_percent_avg' => round((float) $collection->pluck('cpu_usage_percent')->filter(fn ($value) => is_numeric($value))->avg(), 1),
            'memory_usage_bytes_total' => (int) $collection->pluck('memory_usage_bytes')->filter(fn ($value) => is_numeric($value))->sum(),
        ];
    }

    private function dockerHttpClient(Integration $integration): PendingRequest
    {
        $credentials = $this->credentialsResolver->resolve($integration);
        $config = $integration->config ?? [];
        $verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $authMode = (string) ($config['auth_mode'] ?? 'none');

        $request = Http::withOptions(['verify' => $verifySsl])->timeout(15);

        if ($authMode === 'bearer' && trim((string) ($credentials['token'] ?? '')) !== '') {
            $request = $request->withToken((string) $credentials['token']);
        }

        return $request;
    }

    private function fetchContainerRows(Integration $integration): array
    {
        $client = $this->dockerHttpClient($integration);
        $baseUrl = rtrim($integration->base_url, '/');
        $containersResponse = $client->get("{$baseUrl}/containers/json", ['all' => 1]);

        if (! $containersResponse->successful()) {
            throw new \RuntimeException("Docker returned HTTP {$containersResponse->status()} while fetching containers.");
        }

        return $containersResponse->json();
    }

    private function fetchContainerInspects(Integration $integration, array $containerIds): array
    {
        return $this->poolContainerRequests(
            integration: $integration,
            containerIds: $containerIds,
            requestFactory: fn (PendingRequest $request, string $baseUrl, string $containerId) => $request
                ->get("{$baseUrl}/containers/{$containerId}/json"),
        );
    }

    private function fetchContainerStats(Integration $integration, array $containerIds): array
    {
        return $this->poolContainerRequests(
            integration: $integration,
            containerIds: $containerIds,
            requestFactory: fn (PendingRequest $request, string $baseUrl, string $containerId) => $request
                ->get("{$baseUrl}/containers/{$containerId}/stats", ['stream' => 'false']),
        );
    }

    private function poolContainerRequests(Integration $integration, array $containerIds, callable $requestFactory): array
    {
        if ($containerIds === []) {
            return [];
        }

        $baseUrl = rtrim($integration->base_url, '/');
        $responses = [];

        foreach (array_chunk($containerIds, 10) as $chunk) {
            $batch = Http::pool(function (Pool $pool) use ($chunk, $requestFactory, $integration, $baseUrl) {
                $requests = [];

                foreach ($chunk as $containerId) {
                    $request = $this->applyDockerRequestDefaults($pool, $integration);
                    $requests[] = $requestFactory($request, $baseUrl, $containerId);
                }

                return $requests;
            });

            foreach ($batch as $index => $response) {
                $containerId = $chunk[$index] ?? null;
                if ($containerId === null) {
                    continue;
                }

                if ($response instanceof Response) {
                    $responses[$containerId] = $response;
                }
            }
        }

        return $responses;
    }

    private function applyDockerRequestDefaults(Pool $pool, Integration $integration): PendingRequest
    {
        $credentials = $this->credentialsResolver->resolve($integration);
        $config = $integration->config ?? [];
        $verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $authMode = (string) ($config['auth_mode'] ?? 'none');

        $request = $pool->withOptions(['verify' => $verifySsl])->timeout(15);

        if ($authMode === 'bearer' && trim((string) ($credentials['token'] ?? '')) !== '') {
            $request = $request->withToken((string) $credentials['token']);
        }

        return $request;
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

    private function percentage(mixed $used, mixed $total): ?float
    {
        if (! is_numeric($used) || ! is_numeric($total) || (float) $total <= 0.0) {
            return null;
        }

        return round((((float) $used) / ((float) $total)) * 100, 1);
    }

    private function cpuPercent(array $stats): ?float
    {
        $currentTotal = data_get($stats, 'cpu_stats.cpu_usage.total_usage');
        $previousTotal = data_get($stats, 'precpu_stats.cpu_usage.total_usage');
        $currentSystem = data_get($stats, 'cpu_stats.system_cpu_usage');
        $previousSystem = data_get($stats, 'precpu_stats.system_cpu_usage');
        $onlineCpus = data_get($stats, 'cpu_stats.online_cpus')
            ?? count(data_get($stats, 'cpu_stats.cpu_usage.percpu_usage', []));

        if (! is_numeric($currentTotal) || ! is_numeric($previousTotal) || ! is_numeric($currentSystem) || ! is_numeric($previousSystem)) {
            return null;
        }

        $cpuDelta = (float) $currentTotal - (float) $previousTotal;
        $systemDelta = (float) $currentSystem - (float) $previousSystem;

        if ($cpuDelta <= 0 || $systemDelta <= 0 || ! is_numeric($onlineCpus) || (int) $onlineCpus <= 0) {
            return null;
        }

        return round(($cpuDelta / $systemDelta) * ((int) $onlineCpus) * 100, 1);
    }
}
