<?php

namespace App\Services\Alerting;

use App\Models\Integration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class IntegrationHealthCheckService
{
    public function __construct(
        private readonly IntegrationCredentialsResolver $credentialsResolver,
        private readonly DockerMonitoringService $dockerMonitoringService,
        private readonly NvrMonitoringService $nvrMonitoringService,
    ) {
    }

    public function check(Integration $integration): array
    {
        $credentials = $this->credentialsResolver->resolve($integration);
        $config = $integration->config ?? [];
        $verifySsl = (bool) ($config['verify_ssl'] ?? true);

        try {
            return match ($integration->type) {
                'proxmox' => $this->testProxmox($integration->base_url, $credentials, $verifySsl),
                'docker' => $this->dockerMonitoringService->check($integration),
                'nvr' => $this->nvrMonitoringService->check($integration),
                'headscale' => $this->testHeadscale($integration->base_url, $credentials, $verifySsl),
                'custom_api' => $this->testCustomApi($integration->base_url, $credentials, $config),
                default => [
                    'success' => false,
                    'message' => "Test not implemented for type: {$integration->type}",
                    'meta' => $this->buildApiFailureMeta($integration, $verifySsl, 'Unsupported integration type'),
                ],
            };
        } catch (ConnectionException $e) {
            return [
                'success' => false,
                'message' => $this->formatConnectionException($e, $integration->base_url, $verifySsl),
                'meta' => $this->buildApiFailureMeta($integration, $verifySsl, $e->getMessage()),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => "Connection failed: {$e->getMessage()}",
                'meta' => $this->buildApiFailureMeta($integration, $verifySsl, $e->getMessage()),
            ];
        }
    }

    private function testProxmox(string $baseUrl, array $credentials, bool $verifySsl): array
    {
        $token = $credentials['token'] ?? '';
        $baseUrl = rtrim($baseUrl, '/');
        $versionEndpoint = "{$baseUrl}/api2/json/version";
        $nodesEndpoint = "{$baseUrl}/api2/json/nodes";

        $http = Http::withOptions(['verify' => $verifySsl])
            ->withHeaders(['Authorization' => "PVEAPIToken={$token}"])
            ->timeout(10);

        $startedAt = microtime(true);
        $response = $http->get($versionEndpoint);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($response->successful()) {
            $version = $response->json('data.version', 'unknown');
            $release = $response->json('data.release');
            $repoId = $response->json('data.repoid');

            $nodeCount = null;
            $vmCount = null;
            $ctCount = null;
            $nodeNames = [];

            $nodesResponse = $http->get($nodesEndpoint);
            if ($nodesResponse->successful()) {
                $nodes = collect($nodesResponse->json('data', []))
                    ->pluck('node')
                    ->filter()
                    ->values();

                $nodeNames = $nodes->all();
                $nodeCount = $nodes->count();

                [$vmCount, $ctCount] = $this->fetchProxmoxGuestCounts($http, $baseUrl, $nodeNames);
            }

            return [
                'success' => true,
                'message' => "Connected to Proxmox VE {$version}",
                'meta' => [
                    'kind' => 'proxmox',
                    'product' => 'Proxmox VE',
                    'version' => $version,
                    'release' => $release,
                    'repoid' => $repoId,
                    'node_count' => $nodeCount,
                    'vm_count' => $vmCount,
                    'ct_count' => $ctCount,
                    'node_names' => $nodeNames,
                    'verify_ssl' => $verifySsl,
                    'health_endpoint' => $versionEndpoint,
                    'api_reachable' => true,
                    'auth_status' => 'valid',
                    'latency_ms' => $latencyMs,
                ],
            ];
        }

        return [
            'success' => false,
            'message' => "Proxmox returned HTTP {$response->status()}",
            'meta' => [
                'kind' => 'proxmox',
                'product' => 'Proxmox VE',
                'verify_ssl' => $verifySsl,
                'health_endpoint' => $versionEndpoint,
                'api_reachable' => true,
                'auth_status' => in_array($response->status(), [401, 403], true) ? 'failed' : 'unknown',
                'latency_ms' => $latencyMs,
                'http_status' => $response->status(),
            ],
        ];
    }

    private function testHeadscale(string $baseUrl, array $credentials, bool $verifySsl): array
    {
        $apiBase = $this->headscaleApiBase($baseUrl);
        $nodesEndpoint = "{$apiBase}/node";
        $usersEndpoint = "{$apiBase}/user";

        $request = Http::withOptions(['verify' => $verifySsl])
            ->timeout(10)
            ->withToken((string) ($credentials['token'] ?? ''));

        $startedAt = microtime(true);
        $response = $request->get($nodesEndpoint);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            return [
                'success' => false,
                'message' => "Headscale returned HTTP {$response->status()}",
                'meta' => [
                    'kind' => 'headscale',
                    'product' => 'Headscale',
                    'verify_ssl' => $verifySsl,
                    'health_endpoint' => $nodesEndpoint,
                    'api_reachable' => true,
                    'auth_status' => in_array($response->status(), [401, 403], true) ? 'failed' : 'unknown',
                    'latency_ms' => $latencyMs,
                    'http_status' => $response->status(),
                    'health_method' => 'GET',
                    'expected_status' => 200,
                ],
            ];
        }

        $nodesPayload = $response->json();
        $nodes = collect($nodesPayload['nodes'] ?? $nodesPayload['node'] ?? $nodesPayload ?? [])
            ->filter(fn ($item) => is_array($item))
            ->values();

        $usersResponse = $request->get($usersEndpoint);
        $usersPayload = $usersResponse->successful() ? $usersResponse->json() : [];
        $users = collect($usersPayload['users'] ?? $usersPayload['user'] ?? $usersPayload ?? [])
            ->filter(fn ($item) => is_array($item))
            ->values();

        return [
            'success' => true,
            'message' => "Connected to Headscale with {$nodes->count()} nodes discovered",
            'meta' => [
                'kind' => 'headscale',
                'product' => 'Headscale',
                'verify_ssl' => $verifySsl,
                'health_endpoint' => $nodesEndpoint,
                'api_reachable' => true,
                'auth_status' => 'valid',
                'latency_ms' => $latencyMs,
                'http_status' => $response->status(),
                'health_method' => 'GET',
                'expected_status' => 200,
                'node_count' => $nodes->count(),
                'user_count' => $users->count(),
                'base_domain' => parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl,
            ],
        ];
    }

    private function testCustomApi(string $baseUrl, array $credentials, array $config): array
    {
        $verifySsl = (bool) ($config['verify_ssl'] ?? true);
        $healthPath = '/'.ltrim((string) ($config['health_path'] ?? '/health'), '/');
        $method = strtoupper((string) ($config['health_method'] ?? 'GET'));
        $expectedStatus = (int) ($config['health_expected_status'] ?? 200);
        $authMode = (string) ($config['auth_mode'] ?? 'none');
        $endpoint = rtrim($baseUrl, '/').$healthPath;

        $request = Http::withOptions(['verify' => $verifySsl])->timeout(10);

        if ($authMode === 'bearer' && trim((string) ($credentials['token'] ?? '')) !== '') {
            $request = $request->withToken((string) $credentials['token']);
        }

        $startedAt = microtime(true);
        $response = $request->send($method, $endpoint);
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $success = $response->status() === $expectedStatus;

        return [
            'success' => $success,
            'message' => $success
                ? "API health check succeeded with HTTP {$response->status()}"
                : "API health check expected HTTP {$expectedStatus} but received {$response->status()}",
            'meta' => [
                'kind' => 'custom_api',
                'product' => 'Custom API',
                'verify_ssl' => $verifySsl,
                'health_endpoint' => $endpoint,
                'api_reachable' => true,
                'auth_status' => $authMode === 'bearer'
                    ? (in_array($response->status(), [401, 403], true) ? 'failed' : 'valid')
                    : 'not_required',
                'latency_ms' => $latencyMs,
                'http_status' => $response->status(),
                'expected_status' => $expectedStatus,
                'health_method' => $method,
            ],
        ];
    }

    private function formatConnectionException(ConnectionException $e, string $baseUrl, bool $verifySsl): string
    {
        $message = $e->getMessage();
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
        $isIpAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $hasCertificateHostnameMismatch = str_contains(
            strtolower($message),
            'no alternative certificate subject name matches target'
        );

        if ($verifySsl && $isIpAddress && $hasCertificateHostnameMismatch) {
            return "SSL certificate mismatch for {$host}. This server certificate does not include the IP address. Use the Proxmox domain/FQDN instead, or disable SSL verification only if this is an internal lab environment.";
        }

        return "Connection failed: {$message}";
    }

    private function fetchProxmoxGuestCounts($http, string $baseUrl, array $nodeNames): array
    {
        $vmCount = 0;
        $ctCount = 0;

        foreach ($nodeNames as $nodeName) {
            $encodedNode = rawurlencode($nodeName);

            $qemuResponse = $http->get("{$baseUrl}/api2/json/nodes/{$encodedNode}/qemu");
            if ($qemuResponse->successful()) {
                $vmCount += count($qemuResponse->json('data', []));
            }

            $lxcResponse = $http->get("{$baseUrl}/api2/json/nodes/{$encodedNode}/lxc");
            if ($lxcResponse->successful()) {
                $ctCount += count($lxcResponse->json('data', []));
            }
        }

        return [$vmCount, $ctCount];
    }

    private function buildApiFailureMeta(Integration $integration, bool $verifySsl, string $message): array
    {
        $messageLower = strtolower($message);

        return [
            'kind' => $integration->type,
            'product' => $integration->type_name,
            'verify_ssl' => $verifySsl,
            'health_endpoint' => $integration->type === 'proxmox'
                ? rtrim($integration->base_url, '/').'/api2/json/version'
                : ($integration->type === 'docker'
                    ? rtrim($integration->base_url, '/').'/_ping'
                    : ($integration->type === 'headscale'
                        ? $this->headscaleApiBase($integration->base_url).'/node'
                        : rtrim($integration->base_url, '/').($integration->config['health_path'] ?? '/health'))),
            'api_reachable' => false,
            'auth_status' => str_contains($messageLower, 'auth')
                || str_contains($messageLower, 'token')
                || str_contains($messageLower, '401')
                || str_contains($messageLower, '403')
                ? 'failed'
                : 'unknown',
            'latency_ms' => null,
            'health_method' => in_array($integration->type, ['docker', 'headscale'], true)
                ? 'GET'
                : ($integration->config['health_method'] ?? 'GET'),
            'expected_status' => in_array($integration->type, ['docker', 'headscale'], true)
                ? 200
                : ($integration->config['health_expected_status'] ?? 200),
        ];
    }

    private function headscaleApiBase(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        return str_ends_with($baseUrl, '/api/v1')
            ? $baseUrl
            : "{$baseUrl}/api/v1";
    }
}
