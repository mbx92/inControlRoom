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
use Illuminate\Http\Client\Response as HttpResponse;
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
        $preAuthKeys = [];
        $apiKeys = [];
        $policy = null;
        $health = null;
        $apiError = null;
        $featureErrors = [];

        try {
            $credentials = $this->credentialsResolver->resolve($integration);
            $users = $this->fetchUsers($integration, $credentials);
            $nodes = $this->fetchNodes($integration, $credentials);
            [$preAuthKeysResult, $featureErrors['pre_auth_keys']] = $this->attemptHeadscaleSection(
                fn () => $this->fetchPreAuthKeys($integration, $credentials)
            );
            $preAuthKeys = is_array($preAuthKeysResult) ? $preAuthKeysResult : [];

            [$apiKeysResult, $featureErrors['api_keys']] = $this->attemptHeadscaleSection(
                fn () => $this->fetchApiKeys($integration, $credentials)
            );
            $apiKeys = is_array($apiKeysResult) ? $apiKeysResult : [];

            [$policyResult, $featureErrors['policy']] = $this->attemptHeadscaleSection(
                fn () => $this->fetchPolicy($integration, $credentials)
            );
            $policy = is_array($policyResult) ? $policyResult : null;

            [$healthResult, $featureErrors['health']] = $this->attemptHeadscaleSection(
                fn () => $this->fetchHealth($integration, $credentials)
            );
            $health = is_array($healthResult) ? $healthResult : null;
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
            'preAuthKeys' => $preAuthKeys,
            'apiKeys' => $apiKeys,
            'policy' => $policy,
            'health' => $health,
            'stats' => [
                'user_total' => count($users),
                'node_total' => count($nodes),
                'online_total' => collect($nodes)->where('is_online', true)->count(),
                'tagged_total' => collect($nodes)->filter(fn (array $node) => count($node['tags']) > 0)->count(),
                'subnet_router_total' => collect($nodes)->filter(fn (array $node) => count($node['available_routes']) > 0)->count(),
                'pre_auth_key_total' => count($preAuthKeys),
                'api_key_total' => count($apiKeys),
            ],
            'apiError' => $apiError,
            'featureErrors' => array_filter($featureErrors),
        ]);
    }

    public function updateNodeTags(Request $request, Integration $integration, string $nodeId): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->canExecute(), 403);

        $validated = $request->validate([
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:255'],
        ]);

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->post($this->headscaleApiBase($integration->base_url)."/node/{$nodeId}/tags", [
                'tags' => array_values(array_filter($validated['tags'] ?? [])),
            ]);

        $this->ensureHeadscaleSuccess($response, 'updating node tags');

        return response()->json([
            'message' => 'Node tags updated.',
            'node' => $this->mapNode((array) $response->json('node', [])),
        ]);
    }

    public function updateNodeRoutes(Request $request, Integration $integration, string $nodeId): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->canExecute(), 403);

        $validated = $request->validate([
            'routes' => ['nullable', 'array'],
            'routes.*' => ['string', 'max:255'],
        ]);

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->post($this->headscaleApiBase($integration->base_url)."/node/{$nodeId}/approve_routes", [
                'routes' => array_values(array_filter($validated['routes'] ?? [])),
            ]);

        $this->ensureHeadscaleSuccess($response, 'approving node routes');

        return response()->json([
            'message' => 'Approved routes updated.',
            'node' => $this->mapNode((array) $response->json('node', [])),
        ]);
    }

    public function renameNode(Request $request, Integration $integration, string $nodeId): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->canExecute(), 403);

        $validated = $request->validate([
            'new_name' => ['required', 'string', 'max:255'],
        ]);

        $credentials = $this->credentialsResolver->resolve($integration);
        $encodedName = rawurlencode($validated['new_name']);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->post($this->headscaleApiBase($integration->base_url)."/node/{$nodeId}/rename/{$encodedName}");

        $this->ensureHeadscaleSuccess($response, 'renaming node');

        return response()->json([
            'message' => 'Node renamed.',
            'node' => $this->mapNode((array) $response->json('node', [])),
        ]);
    }

    public function expireNode(Request $request, Integration $integration, string $nodeId): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->canExecute(), 403);

        $validated = $request->validate([
            'disable_expiry' => ['nullable', 'boolean'],
        ]);

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->post($this->headscaleApiBase($integration->base_url)."/node/{$nodeId}/expire", [
                'disable_expiry' => (bool) ($validated['disable_expiry'] ?? false),
            ]);

        $this->ensureHeadscaleSuccess($response, 'expiring node');

        return response()->json([
            'message' => ($validated['disable_expiry'] ?? false) ? 'Node expiry disabled.' : 'Node expired.',
            'node' => $this->mapNode((array) $response->json('node', [])),
        ]);
    }

    public function createPreAuthKey(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->canExecute(), 403);

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'min:1'],
            'reusable' => ['nullable', 'boolean'],
            'ephemeral' => ['nullable', 'boolean'],
            'expiration' => ['nullable', 'date'],
            'acl_tags' => ['nullable', 'array'],
            'acl_tags.*' => ['string', 'max:255'],
        ]);

        $payload = [
            'user' => (int) $validated['user_id'],
            'reusable' => (bool) ($validated['reusable'] ?? false),
            'ephemeral' => (bool) ($validated['ephemeral'] ?? false),
            'aclTags' => array_values(array_filter($validated['acl_tags'] ?? [])),
        ];

        if (! empty($validated['expiration'])) {
            $payload['expiration'] = $validated['expiration'];
        }

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->post($this->headscaleApiBase($integration->base_url).'/preauthkey', $payload);

        $this->ensureHeadscaleSuccess($response, 'creating pre-auth key');

        return response()->json([
            'message' => 'Pre-auth key created.',
            'pre_auth_key' => $this->mapPreAuthKey((array) $response->json('preAuthKey', $response->json('pre_auth_key', []))),
        ]);
    }

    public function createApiKey(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'expiration' => ['nullable', 'date'],
        ]);

        $payload = [];
        if (! empty($validated['expiration'])) {
            $payload['expiration'] = $validated['expiration'];
        }

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->post($this->headscaleApiBase($integration->base_url).'/apikey', $payload);

        $this->ensureHeadscaleSuccess($response, 'creating API key');

        return response()->json([
            'message' => 'API key created. Save it now, Headscale will not show it again.',
            'api_key' => (string) ($response->json('apiKey') ?? $response->json('api_key') ?? ''),
        ]);
    }

    public function deleteApiKey(Request $request, Integration $integration, string $prefix): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->isAdmin(), 403);

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->delete($this->headscaleApiBase($integration->base_url).'/apikey/'.rawurlencode($prefix));

        $this->ensureHeadscaleSuccess($response, 'deleting API key');

        return response()->json([
            'message' => 'API key deleted.',
        ]);
    }

    public function createUser(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->post($this->headscaleApiBase($integration->base_url).'/user', [
                'name' => $validated['name'],
            ]);

        $this->ensureHeadscaleSuccess($response, 'creating user');

        return response()->json([
            'message' => 'User created.',
            'user' => $this->mapUser((array) $response->json('user', [])),
        ]);
    }

    public function renameUser(Request $request, Integration $integration, string $userId): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'new_name' => ['required', 'string', 'max:255'],
        ]);

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->post($this->headscaleApiBase($integration->base_url).'/user/'.$userId.'/rename/'.rawurlencode($validated['new_name']));

        $this->ensureHeadscaleSuccess($response, 'renaming user');

        return response()->json([
            'message' => 'User renamed.',
            'user' => $this->mapUser((array) $response->json('user', [])),
        ]);
    }

    public function deleteUser(Request $request, Integration $integration, string $userId): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->isAdmin(), 403);

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->delete($this->headscaleApiBase($integration->base_url).'/user/'.$userId);

        $this->ensureHeadscaleSuccess($response, 'deleting user');

        return response()->json([
            'message' => 'User deleted.',
        ]);
    }

    public function expirePreAuthKey(Request $request, Integration $integration, string $keyId): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->canExecute(), 403);

        $credentials = $this->credentialsResolver->resolve($integration);
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->post($this->headscaleApiBase($integration->base_url).'/preauthkey/expire', [
                'id' => (int) $keyId,
            ]);

        $this->ensureHeadscaleSuccess($response, 'expiring pre-auth key');

        return response()->json([
            'message' => 'Pre-auth key expired.',
        ]);
    }

    public function updatePolicy(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'policy' => ['required', 'string'],
        ]);

        $credentials = $this->credentialsResolver->resolve($integration);
        $base = $this->headscaleApiBase($integration->base_url);

        $checkResponse = $this->headscaleHttpClient($integration, $credentials)
            ->post("{$base}/policy/check", [
                'policy' => $validated['policy'],
            ]);

        $this->ensureHeadscaleSuccess($checkResponse, 'checking policy');

        $response = $this->headscaleHttpClient($integration, $credentials)
            ->put("{$base}/policy", [
                'policy' => $validated['policy'],
            ]);

        $this->ensureHeadscaleSuccess($response, 'updating policy');

        return response()->json([
            'message' => 'Policy updated.',
            'policy' => $this->mapPolicy((array) $response->json()),
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

    public function vncPage(Request $request, Integration $integration): Response
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($request->user()?->canExecute(), 403);

        $integration->load(['site', 'vaultEntry.site']);

        return Inertia::render('Headscale/Vnc', [
            'integration' => $this->presentIntegration($integration),
            'vncVaultEntries' => $this->vncVaultEntryOptions($integration->site_id),
            'initialTarget' => [
                'name' => (string) $request->query('node_name', ''),
                'host' => (string) $request->query('host', ''),
                'port' => (int) $request->integer('port', 5900),
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

    public function createVncSession(Request $request, Integration $integration): JsonResponse
    {
        abort_unless($integration->type === 'headscale', 404);
        $this->authorizeSiteAccess($integration->site_id);

        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'node_name' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'vault_entry_id' => ['required', 'uuid', 'exists:vault_entries,id'],
            'view_only' => ['nullable', 'boolean'],
        ]);

        $vaultEntry = VaultEntry::query()->findOrFail($validated['vault_entry_id']);
        $this->assertVaultEntryUsableForScope($vaultEntry, $integration->site_id);

        $password = $this->resolveVncPassword($vaultEntry);
        $proxyToken = (string) Str::uuid();

        Cache::put($this->vncProxyCacheKey($proxyToken), [
            'host' => $validated['host'],
            'port' => (int) ($validated['port'] ?? 5900),
            'password' => $password,
            'node_name' => $validated['node_name'] ?? $validated['host'],
            'view_only' => (bool) ($validated['view_only'] ?? false),
        ], now()->addMinutes(5));

        AuditLog::record(
            userId: $request->user()->id,
            action: 'headscale.vnc.create',
            targetType: 'integration',
            targetId: $integration->id,
            payload: [
                'host' => $validated['host'],
                'node_name' => $validated['node_name'] ?? null,
                'port' => (int) ($validated['port'] ?? 5900),
                'vault_entry_id' => $vaultEntry->id,
                'view_only' => (bool) ($validated['view_only'] ?? false),
            ],
            ipAddress: $request->ip(),
            siteId: $integration->site_id,
        );

        return response()->json([
            'console_type' => 'novnc',
            'proxy_resolve_url' => URL::temporarySignedRoute(
                'headscale.vnc.proxy-payload',
                now()->addMinutes(5),
                ['token' => $proxyToken],
            ),
            'proxy_websocket_url' => $this->vncProxyWebsocketUrl(),
        ]);
    }

    public function terminalProxyPayload(Request $request, string $token): JsonResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $payload = Cache::get($this->terminalProxyCacheKey($token));

        abort_unless(is_array($payload), 404);

        return response()->json($payload);
    }

    public function vncProxyPayload(Request $request, string $token): JsonResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $payload = Cache::get($this->vncProxyCacheKey($token));

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

        $this->ensureHeadscaleSuccess($response, 'fetching users');

        return collect($response->json('users', $response->json('user', $response->json() ?? [])))
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $user) => $this->mapUser($user))
            ->values()
            ->all();
    }

    private function fetchNodes(Integration $integration, array $credentials): array
    {
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->get($this->headscaleApiBase($integration->base_url).'/node');

        $this->ensureHeadscaleSuccess($response, 'fetching nodes');

        return collect($response->json('nodes', $response->json('node', $response->json() ?? [])))
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $node) => $this->mapNode($node))
            ->values()
            ->all();
    }

    private function fetchPreAuthKeys(Integration $integration, array $credentials): array
    {
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->get($this->headscaleApiBase($integration->base_url).'/preauthkey');

        $this->ensureHeadscaleSuccess($response, 'fetching pre-auth keys');

        return collect($response->json('preAuthKeys', $response->json('pre_auth_keys', [])))
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $key) => $this->mapPreAuthKey($key))
            ->values()
            ->all();
    }

    private function fetchApiKeys(Integration $integration, array $credentials): array
    {
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->get($this->headscaleApiBase($integration->base_url).'/apikey');

        $this->ensureHeadscaleSuccess($response, 'fetching API keys');

        return collect($response->json('apiKeys', $response->json('api_keys', [])))
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $key) => [
                'id' => (string) ($key['id'] ?? uniqid('hs-api-key-', true)),
                'prefix' => (string) ($key['prefix'] ?? ''),
                'expiration' => $key['expiration'] ?? null,
                'created_at' => $key['createdAt'] ?? $key['created_at'] ?? null,
                'last_seen' => $key['lastSeen'] ?? $key['last_seen'] ?? null,
            ])
            ->values()
            ->all();
    }

    private function fetchPolicy(Integration $integration, array $credentials): ?array
    {
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->get($this->headscaleApiBase($integration->base_url).'/policy');

        $this->ensureHeadscaleSuccess($response, 'fetching policy');

        return $this->mapPolicy((array) $response->json());
    }

    private function fetchHealth(Integration $integration, array $credentials): ?array
    {
        $response = $this->headscaleHttpClient($integration, $credentials)
            ->get($this->headscaleApiBase($integration->base_url).'/health');

        $this->ensureHeadscaleSuccess($response, 'fetching health status');

        return [
            'database_connectivity' => (bool) ($response->json('databaseConnectivity') ?? $response->json('database_connectivity') ?? false),
        ];
    }

    private function mapUser(array $user): array
    {
        return [
            'id' => (string) ($user['id'] ?? $user['name'] ?? uniqid('hs-user-', true)),
            'name' => (string) ($user['name'] ?? $user['displayName'] ?? $user['email'] ?? 'unknown'),
            'display_name' => $user['displayName'] ?? $user['display_name'] ?? $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'provider' => $user['provider'] ?? $user['providerIdentifier'] ?? $user['provider_identifier'] ?? null,
            'created_at' => $user['createdAt'] ?? $user['created_at'] ?? null,
        ];
    }

    private function mapNode(array $node): array
    {
        $user = $node['user'] ?? [];
        $tags = collect([
            ...((array) ($node['tags'] ?? [])),
            ...((array) ($node['forcedTags'] ?? [])),
            ...((array) ($node['invalidTags'] ?? [])),
            ...((array) ($node['validTags'] ?? [])),
            ...((array) ($node['approvedTags'] ?? [])),
        ])->filter()->unique()->values()->all();

        $preAuthKey = is_array($node['preAuthKey'] ?? null)
            ? $this->mapPreAuthKey((array) $node['preAuthKey'])
            : null;

        return [
            'id' => (string) ($node['id'] ?? uniqid('hs-node-', true)),
            'name' => (string) ($node['name'] ?? $node['givenName'] ?? 'unknown'),
            'given_name' => $node['givenName'] ?? $node['given_name'] ?? null,
            'user_name' => is_array($user) ? ($user['name'] ?? $user['displayName'] ?? 'unknown') : (string) $user,
            'user_id' => is_array($user) ? (string) ($user['id'] ?? '') : null,
            'is_online' => (bool) ($node['online'] ?? false),
            'ips' => array_values(array_filter((array) ($node['ipAddresses'] ?? $node['ip_addresses'] ?? []))),
            'tags' => $tags,
            'last_seen' => $node['lastSeen'] ?? $node['last_seen'] ?? null,
            'expiry' => $node['expiry'] ?? null,
            'created_at' => $node['createdAt'] ?? $node['created_at'] ?? null,
            'register_method' => $this->formatRegisterMethod($node['registerMethod'] ?? $node['register_method'] ?? null),
            'approved_routes' => array_values(array_filter((array) ($node['approvedRoutes'] ?? $node['approved_routes'] ?? []))),
            'available_routes' => array_values(array_filter((array) ($node['availableRoutes'] ?? $node['available_routes'] ?? []))),
            'subnet_routes' => array_values(array_filter((array) ($node['subnetRoutes'] ?? $node['subnet_routes'] ?? []))),
            'machine_key' => $this->maskHeadscaleKey($node['machineKey'] ?? $node['machine_key'] ?? null),
            'node_key' => $this->maskHeadscaleKey($node['nodeKey'] ?? $node['node_key'] ?? null),
            'disco_key' => $this->maskHeadscaleKey($node['discoKey'] ?? $node['disco_key'] ?? null),
            'pre_auth_key' => $preAuthKey,
        ];
    }

    private function mapPreAuthKey(array $key): array
    {
        $user = $key['user'] ?? [];
        $rawKey = (string) ($key['key'] ?? '');

        return [
            'id' => (string) ($key['id'] ?? uniqid('hs-preauth-', true)),
            'user_id' => is_array($user) ? (string) ($user['id'] ?? '') : null,
            'user_name' => is_array($user) ? ($user['name'] ?? $user['displayName'] ?? 'unknown') : (string) $user,
            'reusable' => (bool) ($key['reusable'] ?? false),
            'ephemeral' => (bool) ($key['ephemeral'] ?? false),
            'used' => (bool) ($key['used'] ?? false),
            'expiration' => $key['expiration'] ?? null,
            'created_at' => $key['createdAt'] ?? $key['created_at'] ?? null,
            'acl_tags' => array_values(array_filter((array) ($key['aclTags'] ?? $key['acl_tags'] ?? []))),
            'key_preview' => $this->maskHeadscaleKey($rawKey),
            'key_full' => $rawKey !== '' ? $rawKey : null,
        ];
    }

    private function mapPolicy(array $policy): array
    {
        $text = (string) ($policy['policy'] ?? '');

        return [
            'text' => $text,
            'updated_at' => $policy['updatedAt'] ?? $policy['updated_at'] ?? null,
            'line_count' => $text === '' ? 0 : count(preg_split("/\r\n|\n|\r/", $text)),
        ];
    }

    private function formatRegisterMethod(mixed $registerMethod): string
    {
        return match ((string) $registerMethod) {
            'REGISTER_METHOD_AUTH_KEY', '1' => 'Auth Key',
            'REGISTER_METHOD_CLI', '2' => 'CLI',
            'REGISTER_METHOD_OIDC', '3' => 'OIDC',
            default => 'Unknown',
        };
    }

    private function maskHeadscaleKey(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return null;
        }

        if (strlen($value) <= 12) {
            return $value;
        }

        return substr($value, 0, 8).'...'.substr($value, -4);
    }

    private function attemptHeadscaleSection(callable $callback): array
    {
        try {
            return [$callback(), null];
        } catch (\Throwable $e) {
            return [null, $e->getMessage()];
        }
    }

    private function ensureHeadscaleSuccess(HttpResponse $response, string $action): void
    {
        if (! $response->successful()) {
            throw new \RuntimeException("Headscale returned HTTP {$response->status()} while {$action}.");
        }
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

    private function vncVaultEntryOptions(?string $siteId): array
    {
        return VaultEntry::query()
            ->with('site')
            ->where('is_active', true)
            ->whereIn('kind', ['service_password', 'generic_secret'])
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

    private function resolveVncPassword(VaultEntry $vaultEntry): string
    {
        $secret = $vaultEntry->revealSecret();
        $decoded = json_decode(trim($secret), true);
        $payload = is_array($decoded) ? $decoded : [];
        $password = trim((string) ($payload['password'] ?? $payload['secret'] ?? $secret));

        if ($password === '') {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'Selected vault entry does not contain a usable VNC password.',
            ]);
        }

        return $password;
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

    private function vncProxyCacheKey(string $token): string
    {
        return "headscale-vnc-proxy:{$token}";
    }

    private function sshTerminalProxyWebsocketUrl(): string
    {
        $configured = config('app.ssh_terminal_proxy_url');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appUrl = config('app.url', 'http://127.0.0.1:8088');
        $parts = parse_url($appUrl) ?: [];
        $scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'wss' : 'ws';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = config('app.ssh_terminal_proxy_port', 8078);

        return "{$scheme}://{$host}:{$port}/terminal";
    }

    private function vncProxyWebsocketUrl(): string
    {
        $configured = config('app.vnc_proxy_url');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appUrl = config('app.url', 'http://127.0.0.1:8088');
        $parts = parse_url($appUrl) ?: [];
        $scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'wss' : 'ws';
        $host = $parts['host'] ?? '127.0.0.1';
        $port = config('app.vnc_proxy_port', 8079);

        return "{$scheme}://{$host}:{$port}/vnc";
    }
}
