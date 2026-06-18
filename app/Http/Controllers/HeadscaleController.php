<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesSiteScope;
use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\Site;
use App\Models\VaultEntry;
use App\Services\Alerting\IntegrationCredentialsResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class HeadscaleController extends Controller
{
    use AppliesSiteScope;

    public function __construct(
        private readonly IntegrationCredentialsResolver $credentialsResolver,
    ) {
    }

    public function index(Request $request): Response
    {
        $query = Integration::query()
            ->with(['site', 'vaultEntry.site'])
            ->where('type', 'headscale')
            ->orderBy('name');

        $this->applySiteScope($query);
        $this->applyRequestedSiteFilter($query, $request->query('site'), nullFilterValue: 'global');

        $integrations = $query->get()
            ->map(fn (Integration $integration) => $this->presentIntegration($integration))
            ->values();

        return Inertia::render('Headscale/Index', [
            'integrations' => $integrations,
            'sites' => $this->siteOptions(),
            'filters' => $request->only(['site']),
            'stats' => [
                'server_total' => $integrations->count(),
                'healthy_total' => $integrations->where('last_test_status', 'success')->count(),
                'node_total' => $integrations->sum(fn (array $item) => (int) ($item['source_summary']['node_count'] ?? 0)),
                'user_total' => $integrations->sum(fn (array $item) => (int) ($item['source_summary']['user_count'] ?? 0)),
            ],
        ]);
    }

    public function show(Integration $integration): Response
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);

        $integration->load(['site', 'vaultEntry.site']);

        $users = [];
        $nodes = [];
        $apiError = null;

        try {
            $credentials = $this->credentialsResolver->resolve($integration);
            $users = $this->fetchUsers($integration, $credentials);
            $nodes = $this->fetchNodes($integration, $credentials);
        } catch (\Throwable $e) {
            $apiError = $this->formatConnectionExceptionForDisplay(
                $e,
                $integration->base_url,
                (bool) ($integration->config['verify_ssl'] ?? true),
            );
        }

        return Inertia::render('Headscale/Show', [
            'integration' => $this->presentIntegration($integration),
            'users' => $users,
            'nodes' => $nodes,
            'stats' => [
                'user_total' => count($users),
                'node_total' => count($nodes),
                'online_total' => collect($nodes)->where('is_online', true)->count(),
                'tagged_total' => collect($nodes)->filter(fn (array $node) => count($node['tags']) > 0)->count(),
            ],
            'apiError' => $apiError,
        ]);
    }

    public function terminalPage(Request $request, Integration $integration): Response
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->canExecute(), 403);

        $integration->load(['site', 'vaultEntry.site']);

        return Inertia::render('Headscale/Terminal', [
            'integration' => $this->presentIntegration($integration),
            'sshVaultEntries' => $this->sshVaultEntryOptions($integration->site_id),
            'initialNode' => [
                'name' => (string) $request->query('node_name', ''),
                'host' => (string) $request->query('host', ''),
            ],
        ]);
    }

    public function createTerminalSession(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);

        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'node_name' => ['nullable', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'vault_entry_id' => ['required', 'uuid', 'exists:vault_entries,id'],
            'auth_type' => ['required', 'in:password,private_key'],
        ]);

        $vaultEntry = VaultEntry::query()->findOrFail($validated['vault_entry_id']);
        $this->assertVaultEntryUsableForScope($vaultEntry, $integration->site_id);

        $credentials = $this->resolveTerminalCredentials($vaultEntry, $validated['auth_type']);
        $proxyToken = (string) Str::uuid();

        Cache::put($this->terminalProxyCacheKey($proxyToken), [
            'host' => $validated['host'],
            'port' => (int) ($validated['port'] ?? 22),
            'username' => $validated['username'],
            'auth_type' => $validated['auth_type'],
            'password' => $credentials['password'] ?? null,
            'private_key' => $credentials['private_key'] ?? null,
            'passphrase' => $credentials['passphrase'] ?? null,
            'node_name' => $validated['node_name'] ?? $validated['host'],
        ], now()->addMinutes(5));

        AuditLog::record(
            userId: $request->user()->id,
            action: 'headscale.terminal.create',
            targetType: 'integration',
            targetId: $integration->id,
            payload: [
                'host' => $validated['host'],
                'node_name' => $validated['node_name'] ?? null,
                'ssh_username' => $validated['username'],
                'vault_entry_id' => $vaultEntry->id,
                'auth_type' => $validated['auth_type'],
            ],
            ipAddress: $request->ip(),
            siteId: $integration->site_id,
        );

        return response()->json([
            'terminal_type' => 'ssh',
            'proxy_resolve_url' => URL::temporarySignedRoute(
                'headscale.terminal.proxy-payload',
                now()->addMinutes(5),
                ['token' => $proxyToken],
            ),
            'proxy_websocket_url' => $this->sshTerminalProxyWebsocketUrl(),
        ]);
    }

    public function terminalProxyPayload(Request $request, string $token): JsonResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $payload = Cache::get($this->terminalProxyCacheKey($token));

        abort_unless(is_array($payload), 404);

        return response()->json($payload);
    }

    private function presentIntegration(Integration $integration): array
    {
        $meta = $integration->last_test_meta ?? [];
        $status = $integration->last_test_status;
        $verifySsl = $meta['verify_ssl'] ?? ($integration->config['verify_ssl'] ?? true);

        return [
            'id' => $integration->id,
            'name' => $integration->name,
            'type' => $integration->type,
            'type_name' => $integration->type_name,
            'base_url' => $integration->base_url,
            'base_host' => parse_url($integration->base_url, PHP_URL_HOST) ?: $integration->base_url,
            'site_id' => $integration->site_id,
            'scope_label' => $integration->site?->name ?? 'Global',
            'scope_kind' => $integration->site ? 'site' : 'global',
            'is_active' => $integration->is_active,
            'last_test_status' => $status,
            'last_test_message' => $integration->last_test_message,
            'last_tested_at' => $integration->last_tested_at?->diffForHumans(),
            'last_tested_at_full' => $integration->last_tested_at?->toDateTimeString(),
            'vault_entry_id' => $integration->vault_entry_id,
            'secret_source_label' => $integration->vaultEntry?->name ?? 'No vault secret',
            'config' => $integration->config,
            'api_health' => [
                'label' => match ($status) {
                    'success' => 'API healthy',
                    'failure' => 'API degraded',
                    default => 'Not tested',
                },
                'tone' => match ($status) {
                    'success' => 'success',
                    'failure' => 'critical',
                    default => 'warning',
                },
                'reachable' => $meta['api_reachable'] ?? ($status === 'success'),
                'auth_status' => $meta['auth_status'] ?? ($status === 'success' ? 'valid' : 'unknown'),
                'latency_ms' => $meta['latency_ms'] ?? null,
                'http_status' => $meta['http_status'] ?? null,
                'endpoint' => $meta['health_endpoint'] ?? $this->headscaleApiBase($integration->base_url).'/node',
                'method' => $meta['health_method'] ?? 'GET',
                'expected_status' => $meta['expected_status'] ?? 200,
                'verify_ssl' => $verifySsl,
            ],
            'source_summary' => [
                'headline' => trim(collect([$meta['product'] ?? 'Headscale', $meta['base_domain'] ?? null])->filter()->implode(' ')),
                'node_count' => $meta['node_count'] ?? null,
                'user_count' => $meta['user_count'] ?? null,
                'verify_ssl' => $verifySsl,
            ],
        ];
    }

    private function fetchUsers(Integration $integration, array $credentials): array
    {
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->get($this->headscaleApiBase($integration->base_url).'/user');

        if (! $response->successful()) {
            throw new \RuntimeException("Headscale returned HTTP {$response->status()} while fetching users.");
        }

        return collect($response->json('users', $response->json('user', $response->json() ?? [])))
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $user) => [
                'id' => (string) ($user['id'] ?? $user['name'] ?? uniqid('hs-user-', true)),
                'name' => (string) ($user['name'] ?? $user['displayName'] ?? $user['email'] ?? 'unknown'),
                'display_name' => $user['displayName'] ?? $user['name'] ?? null,
                'email' => $user['email'] ?? null,
                'provider' => $user['provider'] ?? $user['providerIdentifier'] ?? null,
                'created_at' => $user['createdAt'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function fetchNodes(Integration $integration, array $credentials): array
    {
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->get($this->headscaleApiBase($integration->base_url).'/node');

        if (! $response->successful()) {
            throw new \RuntimeException("Headscale returned HTTP {$response->status()} while fetching nodes.");
        }

        return collect($response->json('nodes', $response->json('node', $response->json() ?? [])))
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $node) {
                $user = $node['user'] ?? [];
                $tags = collect([
                    ...((array) ($node['forcedTags'] ?? [])),
                    ...((array) ($node['invalidTags'] ?? [])),
                    ...((array) ($node['validTags'] ?? [])),
                    ...((array) ($node['approvedTags'] ?? [])),
                ])->filter()->unique()->values()->all();

                return [
                    'id' => (string) ($node['id'] ?? uniqid('hs-node-', true)),
                    'name' => (string) ($node['name'] ?? $node['givenName'] ?? 'unknown'),
                    'given_name' => $node['givenName'] ?? null,
                    'user_name' => is_array($user) ? ($user['name'] ?? $user['displayName'] ?? 'unknown') : (string) $user,
                    'is_online' => (bool) ($node['online'] ?? false),
                    'ips' => array_values(array_filter((array) ($node['ipAddresses'] ?? []))),
                    'tags' => $tags,
                    'last_seen' => $node['lastSeen'] ?? null,
                    'expiry' => $node['expiry'] ?? null,
                    'created_at' => $node['createdAt'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    private function headscaleHttpClient(Integration $integration, array $credentials)
    {
        return Http::withOptions([
            'verify' => (bool) ($integration->config['verify_ssl'] ?? true),
        ])->withToken((string) ($credentials['token'] ?? ''))
            ->timeout(10);
    }

    private function headscaleApiBase(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        return str_ends_with($baseUrl, '/api/v1')
            ? $baseUrl
            : "{$baseUrl}/api/v1";
    }

    private function formatConnectionExceptionForDisplay(\Throwable $e, string $baseUrl, bool $verifySsl): string
    {
        if ($e instanceof ConnectionException) {
            $message = $e->getMessage();
            $host = parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
            $isIpAddress = filter_var($host, FILTER_VALIDATE_IP) !== false;
            $hostnameMismatch = str_contains(
                strtolower($message),
                'no alternative certificate subject name matches target'
            );

            if ($verifySsl && $isIpAddress && $hostnameMismatch) {
                return "SSL certificate mismatch for {$host}. Use the Headscale domain/FQDN instead of the IP, or disable SSL verification only in a trusted internal lab.";
            }

            return "Connection failed: {$message}";
        }

        return $e->getMessage();
    }

    private function siteOptions(): array
    {
        return $this->scopedSitesQuery()
            ->get(['id', 'name', 'code', 'is_active'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'code' => $site->code,
                'is_active' => $site->is_active,
            ])
            ->all();
    }

    private function sshVaultEntryOptions(?string $siteId): array
    {
        return VaultEntry::query()
            ->with('site')
            ->where('is_active', true)
            ->whereIn('kind', ['ssh_private_key', 'service_password', 'generic_secret'])
            ->where(function ($query) use ($siteId) {
                $query->whereNull('site_id');

                if ($siteId !== null) {
                    $query->orWhere('site_id', $siteId);
                }
            })
            ->orderBy('name')
            ->get()
            ->map(fn (VaultEntry $entry) => [
                'id' => $entry->id,
                'name' => $entry->name,
                'kind' => $entry->kind,
                'kind_label' => $entry->kind_label,
                'scope_label' => $entry->site?->name ?? 'Global',
                'suggested_auth_type' => $entry->kind === 'ssh_private_key' ? 'private_key' : 'password',
            ])
            ->all();
    }

    private function resolveTerminalCredentials(VaultEntry $vaultEntry, string $authType): array
    {
        $secret = $vaultEntry->revealSecret();
        $decoded = json_decode(trim($secret), true);
        $payload = is_array($decoded) ? $decoded : [];

        if ($authType === 'password') {
            $password = trim((string) ($payload['password'] ?? $payload['secret'] ?? $secret));

            if ($password === '') {
                throw ValidationException::withMessages([
                    'vault_entry_id' => 'Selected vault entry does not contain a usable SSH password.',
                ]);
            }

            return ['password' => $password];
        }

        $privateKey = (string) ($payload['private_key'] ?? $payload['key'] ?? $secret);
        $passphrase = isset($payload['passphrase']) ? (string) $payload['passphrase'] : null;

        if (trim($privateKey) === '') {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'Selected vault entry does not contain a usable SSH private key.',
            ]);
        }

        return [
            'private_key' => $privateKey,
            'passphrase' => $passphrase,
        ];
    }

    private function assertVaultEntryUsableForScope(VaultEntry $vaultEntry, ?string $siteId): void
    {
        if (! $vaultEntry->is_active) {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'Selected vault entry is inactive.',
            ]);
        }

        if ($vaultEntry->site_id !== null && $vaultEntry->site_id !== $siteId) {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'Selected vault entry scope does not match this Headscale integration.',
            ]);
        }
    }

    private function terminalProxyCacheKey(string $token): string
    {
        return "headscale-terminal-proxy:{$token}";
    }

    private function sshTerminalProxyWebsocketUrl(): string
    {
        $configured = config('app.ssh_terminal_proxy_url');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appUrl = config('app.url', 'http://127.0.0.1:8000');
        $parts = parse_url($appUrl) ?: [];
        $scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'wss' : 'ws';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = config('app.ssh_terminal_proxy_port', 8078);

        return "{$scheme}://{$host}:{$port}/terminal";
    }
}
