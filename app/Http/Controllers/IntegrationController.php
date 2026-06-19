<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\InventoryAsset;
use App\Models\Site;
use App\Models\VaultEntry;
use App\Services\Alerting\DockerMonitoringService;
use App\Services\Alerting\IntegrationMonitoringService;
use App\Services\Alerting\NasMonitoringService;
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

class IntegrationController extends Controller
{
    use \App\Http\Controllers\Concerns\AppliesSiteScope;
    public function index(Request $request): Response
    {
        $query = Integration::query()
            ->with(['site', 'vaultEntry.site'])
            ->orderBy('name');

        $this->applySiteScope($query);
        $this->applyRequestedSiteFilter($query, $request->query('site'), nullFilterValue: 'global');

        $integrations = $query
            ->get()
            ->map(fn ($i) => $this->presentIntegration($i));

        return Inertia::render('Settings/Integrations/Index', [
            'integrations' => $integrations,
            'availableTypes' => Integration::TYPES,
            'sites' => $this->siteOptions(),
            'filters' => $request->only(['site']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Settings/Integrations/Create', [
            'availableTypes' => Integration::TYPES,
            'nasVendors' => Integration::NAS_VENDORS,
            'sites' => $this->siteOptions(),
            'vaultEntries' => $this->vaultEntryOptions(),
            'inventoryAssets' => $this->inventoryAssetOptions(),
        ]);
    }

    public function guestShow(
        Request $request,
        Integration $integration,
        string $guestType,
        string $node,
        string $vmid,
    ): Response {
        $this->authorizeSiteAccess($integration->site_id);
        abort_unless($integration->type === 'proxmox', 404);
        abort_unless(in_array($guestType, ['qemu', 'lxc'], true), 404);

        $integration->load(['site', 'vaultEntry.site']);

        $credentials = $this->resolveIntegrationCredentials($integration);
        $config = $integration->config ?? [];
        $verifySsl = $config['verify_ssl'] ?? true;

        $guest = $this->fetchProxmoxGuestDetail(
            $integration->base_url,
            $credentials,
            $verifySsl,
            $guestType,
            $node,
            $vmid,
        );

        $guestTasks = $this->paginateItems(
            $this->fetchProxmoxGuestTasks(
                $integration->base_url,
                $credentials,
                $verifySsl,
                $guestType,
                $node,
                $vmid,
            ),
            max(1, (int) $request->integer('tasks_page', 1)),
            10,
            'tasks_page',
        );

        return Inertia::render('Settings/Integrations/GuestShow', [
            'integration' => [
                ...$this->presentIntegration($integration),
                'created_at' => $integration->created_at?->toDateTimeString(),
                'updated_at' => $integration->updated_at?->toDateTimeString(),
            ],
            'guest' => $guest,
            'guestTasks' => $guestTasks,
        ]);
    }

    public function guestConsole(
        Integration $integration,
        string $guestType,
        string $node,
        string $vmid,
    ): JsonResponse {
        try {
            $this->authorizeSiteAccess($integration->site_id);
            abort_unless($integration->type === 'proxmox', 404);
            abort_unless(in_array($guestType, ['qemu', 'lxc'], true), 404);

            $credentials = $this->resolveIntegrationCredentials($integration);
            $config = $integration->config ?? [];
            $verifySsl = $config['verify_ssl'] ?? true;
            $consoleAccess = $this->hasProxmoxConsoleCredentials($credentials)
                ? $this->fetchProxmoxAccessTicket($integration->base_url, $credentials, $verifySsl)
                : null;

            if (! $consoleAccess) {
                return response()->json([
                    'message' => 'Interactive console requires Proxmox console username/password on this integration. API token alone can sync data, but it cannot authorize the noVNC websocket session.',
                ], 422);
            }

            $session = $this->fetchProxmoxConsoleSession(
                $integration->base_url,
                $credentials,
                $verifySsl,
                $guestType,
                $node,
                $vmid,
                $consoleAccess,
            );
            $proxyToken = (string) Str::uuid();

            Cache::put($this->proxmoxConsoleProxyCacheKey($proxyToken), [
                'websocket_url' => $session['websocket_url'],
                'ticket' => $session['ticket'],
                'auth_cookie' => $consoleAccess['ticket'],
                'verify_ssl' => $session['verify_ssl'],
            ], now()->addMinutes(2));

            return response()->json([
                'console_type' => 'novnc',
                'proxy_resolve_url' => URL::temporarySignedRoute(
                    'integrations.guests.console.proxy-payload',
                    now()->addMinutes(2),
                    ['token' => $proxyToken],
                ),
                ...$session,
            ]);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => $this->formatConnectionException(
                    $e,
                    $integration->base_url,
                    $integration->config['verify_ssl'] ?? true,
                ),
            ], 502);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $status = str_contains(strtolower($message), 'authentication failure')
                || str_contains(strtolower($message), 'username/password')
                ? 422
                : 500;

            return response()->json([
                'message' => $message,
            ], $status);
        }
    }

    public function guestConsoleProxyPayload(Request $request, string $token): JsonResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        $payload = Cache::get($this->proxmoxConsoleProxyCacheKey($token));

        abort_unless(is_array($payload), 404);

        return response()->json($payload);
    }

    public function show(Request $request, Integration $integration): Response
    {
        $this->authorizeSiteAccess($integration->site_id);
        $integration->load(['site', 'vaultEntry.site']);

        $localActivity = AuditLog::query()
            ->with('user')
            ->where('target_type', 'integration')
            ->where('target_id', $integration->id)
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'result' => $log->result,
                'error_message' => $log->error_message,
                'user_name' => $log->user?->name ?? 'System',
                'created_at' => $log->created_at->diffForHumans(),
                'created_at_full' => $log->created_at->toDateTimeString(),
            ]);

        $activity = $this->paginateItems(
            $localActivity->all(),
            max(1, (int) $request->integer('tasks_page', 1)),
            8,
            'tasks_page',
        );
        $activitySource = 'local';
        $proxmoxGuests = null;
        $proxmoxSummary = null;
        $dockerContainers = null;
        $dockerSummary = null;
        $nvrChannels = null;
        $nvrSummary = null;
        $nvrMeta = null;
        $nasSnapshot = null;
        $nasSummary = null;
        $activityError = null;
        $proxmoxJournal = null;

        if ($integration->type === 'proxmox') {
            $activitySource = 'proxmox';

            try {
                $credentials = $this->resolveIntegrationCredentials($integration);
                $config = $integration->config ?? [];
                $verifySsl = $config['verify_ssl'] ?? true;

                $proxmoxGuests = $this->fetchProxmoxGuestsSnapshot(
                    $integration->base_url,
                    $credentials,
                    $verifySsl,
                );

                $proxmoxSummary = $this->summarizeProxmoxGuests($proxmoxGuests);
                $activity = $this->paginateItems(
                    $this->fetchProxmoxActivity(
                        $integration->base_url,
                        $credentials,
                        $verifySsl,
                    ),
                    max(1, (int) $request->integer('tasks_page', 1)),
                    10,
                    'tasks_page',
                );
                $journalEntries = $this->fetchProxmoxJournal(
                    $integration->base_url,
                    $credentials,
                    $verifySsl,
                );

                if (empty($journalEntries)) {
                    $journalEntries = $this->fetchProxmoxSyslog(
                        $integration->base_url,
                        $credentials,
                        $verifySsl,
                    );
                }

                $proxmoxJournal = $this->paginateItems(
                    $journalEntries,
                    max(1, (int) $request->integer('journal_page', 1)),
                    12,
                    'journal_page',
                );
            } catch (\Throwable $e) {
                $activityError = $this->formatConnectionExceptionForDisplay(
                    $e,
                    $integration->base_url,
                    $integration->config['verify_ssl'] ?? true,
                );
            }
        }

        if ($integration->type === 'docker') {
            try {
                $dockerMonitoring = app(DockerMonitoringService::class);
                $dockerContainers = $dockerMonitoring->capture($integration, persistMetrics: false);
                $dockerSummary = $dockerMonitoring->summarize($dockerContainers);
            } catch (\Throwable $e) {
                try {
                    $dockerMonitoring ??= app(DockerMonitoringService::class);
                    $dockerContainers = $dockerMonitoring->listBasic($integration);
                    $dockerSummary = $dockerMonitoring->summarize($dockerContainers);
                    $activityError = 'Container detail enrichment failed, showing basic container list only.';
                } catch (\Throwable $fallbackException) {
                    $activityError = $this->formatConnectionExceptionForDisplay(
                        $fallbackException,
                        $integration->base_url,
                        $integration->config['verify_ssl'] ?? true,
                    );
                }
            }
        }

        if ($integration->type === 'nvr') {
            try {
                $nvrMonitoring = app(\App\Services\Alerting\NvrMonitoringService::class);
                $nvrCheck = $nvrMonitoring->check($integration);
                $nvrMeta = is_array($nvrCheck['meta'] ?? null) ? $nvrCheck['meta'] : null;
                $nvrChannels = $nvrMonitoring->capture($integration);
                $nvrSummary = $nvrMonitoring->summarize($nvrChannels);
            } catch (\Throwable $e) {
                $activityError = $this->formatConnectionExceptionForDisplay(
                    $e,
                    $integration->base_url,
                    $integration->config['verify_ssl'] ?? true,
                );
            }
        }

        if ($integration->type === 'nas') {
            try {
                $nasMonitoring = app(NasMonitoringService::class);
                $nasSnapshot = $nasMonitoring->capture($integration);
                $nasSummary = $nasMonitoring->summarize($integration, $nasSnapshot);
            } catch (\Throwable $e) {
                $activityError = $this->formatConnectionExceptionForDisplay(
                    $e,
                    $integration->base_url,
                    $integration->config['verify_ssl'] ?? true,
                );
            }
        }

        $presentedIntegration = [
            ...$this->presentIntegration($integration),
            'created_at' => $integration->created_at?->toDateTimeString(),
            'updated_at' => $integration->updated_at?->toDateTimeString(),
            'metrics_count' => $integration->metrics()->count(),
            'events_count' => $integration->events()->count(),
        ];

        if ($integration->type === 'nvr' && $nvrSummary !== null) {
            $sourceSummary = $presentedIntegration['source_summary'] ?? [];
            $presentedIntegration['source_summary'] = [
                ...$sourceSummary,
                'headline' => trim(collect([
                    $nvrMeta['product'] ?? null,
                    $nvrMeta['model'] ?? null,
                    $nvrMeta['firmware'] ?? null,
                ])->filter()->implode(' ')),
                'firmware' => $nvrMeta['firmware'] ?? ($sourceSummary['firmware'] ?? null),
                'channel_count' => $nvrSummary['channel_total'] ?? ($sourceSummary['channel_count'] ?? null),
                'recording_count' => $nvrSummary['recording_total'] ?? ($sourceSummary['recording_count'] ?? null),
                'verify_ssl' => $nvrMeta['verify_ssl'] ?? ($sourceSummary['verify_ssl'] ?? null),
            ];
        }

        if ($integration->type === 'nas' && $nasSummary !== null) {
            $sourceSummary = $presentedIntegration['source_summary'] ?? [];
            $presentedIntegration['source_summary'] = [
                ...$sourceSummary,
                'vendor' => Integration::NAS_VENDORS[$integration->config['vendor'] ?? ''] ?? ($sourceSummary['vendor'] ?? null),
                'volume_count' => $nasSummary['volume_total'] ?? ($sourceSummary['volume_count'] ?? null),
                'disk_count' => $nasSummary['disk_total'] ?? ($sourceSummary['disk_count'] ?? null),
                'storage_total_bytes' => $nasSummary['storage_total_bytes'] ?? ($sourceSummary['storage_total_bytes'] ?? null),
                'storage_used_bytes' => $nasSummary['storage_used_bytes'] ?? ($sourceSummary['storage_used_bytes'] ?? null),
                'verify_ssl' => $integration->config['verify_ssl'] ?? ($sourceSummary['verify_ssl'] ?? null),
            ];
        }

        return Inertia::render('Settings/Integrations/Show', [
            'integration' => $presentedIntegration,
            'activity' => $activity,
            'activitySource' => $activitySource,
            'activityError' => $activityError,
            'proxmoxGuests' => $proxmoxGuests,
            'proxmoxSummary' => $proxmoxSummary,
            'proxmoxJournal' => $proxmoxJournal,
            'dockerContainers' => $dockerContainers,
            'dockerSummary' => $dockerSummary,
            'nvrChannels' => $nvrChannels,
            'nvrSummary' => $nvrSummary,
            'nasSnapshot' => $nasSnapshot,
            'nasSummary' => $nasSummary,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'site_id' => 'nullable|exists:sites,id',
            'type' => 'required|string|in:'.implode(',', array_keys(Integration::TYPES)),
            'name' => 'required|string|max:255',
            'base_url' => 'required|url|max:500',
            'vault_entry_id' => 'nullable|exists:vault_entries,id',
            'config' => 'nullable|array',
            'config.verify_ssl' => 'nullable|boolean',
            'config.host_asset_id' => 'nullable|uuid|exists:inventory_assets,id',
            'config.username' => 'nullable|string|max:255',
            'config.vendor' => 'nullable|string|in:'.implode(',', array_keys(Integration::NAS_VENDORS)),
        ]);

        $validated = $this->normalizeIntegrationPayload($validated);
        $validated['credentials'] = json_encode([]);
        $this->assertIntegrationSecretPolicy($validated);
        $this->assertIntegrationHostAssetPolicy($validated);

        $integration = Integration::create($validated);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'integration.create',
            targetType: 'integration',
            targetId: $integration->id,
            payload: [
                'type' => $integration->type,
                'name' => $integration->name,
                'site_id' => $integration->site_id,
            ],
            ipAddress: $request->ip(),
            siteId: $integration->site_id,
        );

        return redirect()->route('integrations.index')
            ->with('success', "Integration \"{$integration->name}\" created successfully.");
    }

    public function edit(Integration $integration): Response
    {
        $this->authorizeSiteAccess($integration->site_id);

        return Inertia::render('Settings/Integrations/Edit', [
            'integration' => $this->presentIntegration($integration->load(['site', 'vaultEntry.site'])),
            'availableTypes' => Integration::TYPES,
            'nasVendors' => Integration::NAS_VENDORS,
            'sites' => $this->siteOptions(),
            'vaultEntries' => $this->vaultEntryOptions(),
            'inventoryAssets' => $this->inventoryAssetOptions(),
        ]);
    }

    public function update(Request $request, Integration $integration)
    {
        $this->authorizeSiteAccess($integration->site_id);
        $validated = $request->validate([
            'site_id' => 'nullable|exists:sites,id',
            'name' => 'required|string|max:255',
            'base_url' => 'required|url|max:500',
            'vault_entry_id' => 'nullable|exists:vault_entries,id',
            'config' => 'nullable|array',
            'config.verify_ssl' => 'nullable|boolean',
            'config.host_asset_id' => 'nullable|uuid|exists:inventory_assets,id',
            'config.username' => 'nullable|string|max:255',
            'config.vendor' => 'nullable|string|in:'.implode(',', array_keys(Integration::NAS_VENDORS)),
            'is_active' => 'boolean',
        ]);

        $validated['type'] = $integration->type;
        $validated = $this->normalizeIntegrationPayload($validated);
        $this->assertIntegrationSecretPolicy($validated);
        $this->assertIntegrationHostAssetPolicy($validated);

        $integration->update($validated);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'integration.update',
            targetType: 'integration',
            targetId: $integration->id,
            payload: [
                'name' => $integration->name,
                'site_id' => $integration->site_id,
            ],
            ipAddress: $request->ip(),
            siteId: $integration->site_id,
        );

        return redirect()->route('integrations.index')
            ->with('success', "Integration \"{$integration->name}\" updated successfully.");
    }

    public function destroy(Request $request, Integration $integration)
    {
        $this->authorizeSiteAccess($integration->site_id);
        $name = $integration->name;
        $siteId = $integration->site_id;
        $integration->delete();

        AuditLog::record(
            userId: $request->user()->id,
            action: 'integration.delete',
            targetType: 'integration',
            targetId: $integration->id,
            payload: ['name' => $name],
            ipAddress: $request->ip(),
            siteId: $siteId,
        );

        return redirect()->route('integrations.index')
            ->with('success', "Integration \"{$name}\" deleted successfully.");
    }

    /**
     * Test connection to an integration.
     */
    public function test(
        Request $request,
        Integration $integration,
        IntegrationMonitoringService $monitoringService,
    )
    {
        $this->authorizeSiteAccess($integration->site_id);
        $result = $monitoringService->run($integration);
        $this->recordTestAudit($request, $integration, $result);

        $message = $result['message'];

        if (! empty($result['metric_error'])) {
            $message .= " Metric snapshot warning: {$result['metric_error']}";
        }

        return back()->with(
            $result['success'] ? 'success' : 'error',
            $message
        );
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
            return "SSL certificate mismatch for {$host}. This server certificate does not include the IP address. Use the service domain/FQDN instead, or disable SSL verification only if this is an internal lab environment.";
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

    private function storeTestResult(Integration $integration, array $result): void
    {
        $integration->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result['success'] ? 'success' : 'failure',
            'last_test_message' => $result['message'],
            'last_test_meta' => array_key_exists('meta', $result)
                ? $result['meta']
                : $integration->last_test_meta,
        ])->save();
    }

    private function recordTestAudit(Request $request, Integration $integration, array $result): void
    {
        AuditLog::record(
            userId: $request->user()->id,
            action: 'integration.test',
            targetType: 'integration',
            targetId: $integration->id,
            payload: [
                'type' => $integration->type,
                'name' => $integration->name,
                'base_url' => $integration->base_url,
                'meta' => $result['meta'] ?? null,
            ],
            ipAddress: $request->ip(),
            result: $result['success'] ? 'success' : 'failure',
            errorMessage: $result['success'] ? null : $result['message'],
            siteId: $integration->site_id,
        );
    }

    private function presentIntegration(Integration $integration): array
    {
        return [
            'id' => $integration->id,
            'type' => $integration->type,
            'type_name' => $integration->type_name,
            'name' => $integration->name,
            'base_url' => $integration->base_url,
            'vault_entry_id' => $integration->vault_entry_id,
            'vault_entry' => $integration->vaultEntry ? [
                'id' => $integration->vaultEntry->id,
                'name' => $integration->vaultEntry->name,
                'kind' => $integration->vaultEntry->kind,
                'kind_label' => $integration->vaultEntry->kind_label,
                'scope_label' => $integration->vaultEntry->site?->name ?? 'Global',
            ] : null,
            'site_id' => $integration->site_id,
            'site' => $integration->site ? [
                'id' => $integration->site->id,
                'name' => $integration->site->name,
                'code' => $integration->site->code,
            ] : null,
            'scope_label' => $integration->site?->name ?? 'Global',
            'scope_kind' => $integration->site ? 'site' : 'global',
            'is_active' => $integration->is_active,
            'config' => $integration->config,
            'last_synced_at' => $integration->last_synced_at?->diffForHumans(),
            'last_tested_at' => $integration->last_tested_at?->diffForHumans(),
            'last_tested_at_full' => $integration->last_tested_at?->toDateTimeString(),
            'last_test_status' => $integration->last_test_status,
            'last_test_message' => $integration->last_test_message,
            'last_test_meta' => $integration->last_test_meta,
            'source_summary' => $this->buildSourceSummary($integration),
            'api_health' => $this->buildApiHealth($integration),
            'base_host' => parse_url($integration->base_url, PHP_URL_HOST) ?: $integration->base_url,
            'secret_source_label' => $integration->vaultEntry?->name ?? 'No vault secret',
        ];
    }

    private function buildSourceSummary(Integration $integration): ?array
    {
        $meta = $integration->last_test_meta ?? [];

        if ($integration->type === 'nvr' && ! empty($meta)) {
            return [
                'headline' => trim(collect([$meta['product'] ?? null, $meta['model'] ?? null, $meta['firmware'] ?? null])->filter()->implode(' ')),
                'firmware' => $meta['firmware'] ?? null,
                'channel_count' => $meta['channel_count'] ?? null,
                'recording_count' => $meta['recording_count'] ?? null,
                'verify_ssl' => $meta['verify_ssl'] ?? null,
            ];
        }

        if ($integration->type === 'headscale' && ! empty($meta)) {
            return [
                'headline' => trim(collect([$meta['product'] ?? null, $meta['base_domain'] ?? null])->filter()->implode(' ')),
                'node_count' => $meta['node_count'] ?? null,
                'user_count' => $meta['user_count'] ?? null,
                'verify_ssl' => $meta['verify_ssl'] ?? null,
            ];
        }

        if ($integration->type === 'nas' && ! empty($meta)) {
            return [
                'headline' => trim(collect([
                    $meta['product'] ?? null,
                    Integration::NAS_VENDORS[$meta['vendor'] ?? ''] ?? null,
                ])->filter()->implode(' ')),
                'vendor' => Integration::NAS_VENDORS[$meta['vendor'] ?? ''] ?? ($meta['vendor'] ?? null),
                'volume_count' => $meta['volume_count'] ?? null,
                'disk_count' => $meta['disk_count'] ?? null,
                'storage_total_bytes' => $meta['storage_total_bytes'] ?? null,
                'storage_used_bytes' => $meta['storage_used_bytes'] ?? null,
                'verify_ssl' => $meta['verify_ssl'] ?? null,
            ];
        }

        if ($integration->type !== 'proxmox' || empty($meta)) {
            if ($integration->type !== 'docker' || empty($meta)) {
                return null;
            }

            return [
                'headline' => trim(collect([$meta['product'] ?? null, $meta['version'] ?? null])->filter()->implode(' ')),
                'api_version' => $meta['api_version'] ?? null,
                'container_count' => $meta['container_count'] ?? null,
                'running_count' => $meta['running_count'] ?? null,
                'stopped_count' => $meta['stopped_count'] ?? null,
                'os' => $meta['os'] ?? null,
                'kernel_version' => $meta['kernel_version'] ?? null,
                'verify_ssl' => $meta['verify_ssl'] ?? null,
            ];
        }

        return [
            'headline' => trim(collect([$meta['product'] ?? null, $meta['version'] ?? null])->filter()->implode(' ')),
            'release' => $meta['release'] ?? null,
            'repoid' => $meta['repoid'] ?? null,
            'node_count' => $meta['node_count'] ?? null,
            'vm_count' => $meta['vm_count'] ?? null,
            'ct_count' => $meta['ct_count'] ?? null,
            'verify_ssl' => $meta['verify_ssl'] ?? null,
        ];
    }

    private function buildApiHealth(Integration $integration): array
    {
        $meta = $integration->last_test_meta ?? [];
        $status = $integration->last_test_status;

        return [
            'status' => $status ?? 'unknown',
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
            'endpoint' => $meta['health_endpoint'] ?? $this->defaultHealthEndpoint($integration),
            'reachable' => $meta['api_reachable'] ?? ($status === 'success'),
            'auth_status' => $meta['auth_status'] ?? ($status === 'success' ? 'valid' : 'unknown'),
            'latency_ms' => $meta['latency_ms'] ?? null,
            'version' => $meta['version'] ?? null,
            'http_status' => $meta['http_status'] ?? null,
            'checked_at' => $integration->last_tested_at?->toDateTimeString(),
            'verify_ssl' => $meta['verify_ssl'] ?? ($integration->config['verify_ssl'] ?? true),
            'method' => $meta['health_method'] ?? (in_array($integration->type, ['docker', 'headscale', 'nas'], true) ? 'GET' : ($integration->config['health_method'] ?? 'GET')),
            'expected_status' => $meta['expected_status'] ?? (in_array($integration->type, ['docker', 'headscale', 'nas'], true) ? 200 : ($integration->config['health_expected_status'] ?? 200)),
        ];
    }

    private function buildApiFailureMeta(Integration $integration, bool $verifySsl, string $message): array
    {
        $messageLower = strtolower($message);

        return [
            'kind' => $integration->type,
            'product' => $integration->type_name,
            'verify_ssl' => $verifySsl,
            'health_endpoint' => $this->defaultHealthEndpoint($integration),
            'api_reachable' => false,
            'auth_status' => str_contains($messageLower, 'auth')
                || str_contains($messageLower, 'token')
                || str_contains($messageLower, '401')
                || str_contains($messageLower, '403')
                ? 'failed'
                : 'unknown',
            'latency_ms' => null,
            'health_method' => in_array($integration->type, ['docker', 'headscale'], true) ? 'GET' : ($integration->config['health_method'] ?? 'GET'),
            'expected_status' => in_array($integration->type, ['docker', 'headscale'], true) ? 200 : ($integration->config['health_expected_status'] ?? 200),
        ];
    }

    private function siteOptions(): array
    {
        return Site::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'is_active'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'code' => $site->code,
                'is_active' => $site->is_active,
            ])
            ->all();
    }

    private function vaultEntryOptions(): array
    {
        return VaultEntry::query()
            ->with('site')
            ->where('is_active', true)
            ->whereIn('kind', ['proxmox_api_token', 'generic_secret'])
            ->orderBy('name')
            ->get()
            ->map(fn (VaultEntry $entry) => [
                'id' => $entry->id,
                'name' => $entry->name,
                'kind' => $entry->kind,
                'kind_label' => $entry->kind_label,
                'site_id' => $entry->site_id,
                'scope_label' => $entry->site?->name ?? 'Global',
            ])
            ->all();
    }

    private function inventoryAssetOptions(): array
    {
        return InventoryAsset::query()
            ->with('site')
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryAsset $asset) => [
                'id' => $asset->id,
                'name' => $asset->name,
                'site_id' => $asset->site_id,
                'primary_ip' => $asset->primary_ip,
                'category' => $asset->category,
                'label' => collect([$asset->name, $asset->primary_ip, $asset->category])
                    ->filter()
                    ->implode(' · '),
            ])
            ->all();
    }

    private function normalizeIntegrationPayload(array $validated): array
    {
        $config = $validated['config'] ?? [];

        $validated['config'] = [
            'verify_ssl' => (bool) ($config['verify_ssl'] ?? true),
            'auth_mode' => (string) ($config['auth_mode'] ?? 'none'),
            'health_path' => '/'.ltrim((string) ($config['health_path'] ?? '/health'), '/'),
            'health_method' => strtoupper((string) ($config['health_method'] ?? 'GET')),
            'health_expected_status' => (int) ($config['health_expected_status'] ?? 200),
            'host_asset_id' => ! empty($config['host_asset_id']) ? (string) $config['host_asset_id'] : null,
            'username' => trim((string) ($config['username'] ?? '')),
            'vendor' => ! empty($config['vendor']) ? strtolower((string) $config['vendor']) : null,
        ];

        if (($validated['type'] ?? null) === 'headscale') {
            $validated['config'] = [
                ...$validated['config'],
                'auth_mode' => 'bearer',
                'health_path' => '/api/v1/node',
                'health_method' => 'GET',
                'health_expected_status' => 200,
                'host_asset_id' => null,
                'username' => '',
            ];
        }

        if (($validated['type'] ?? null) === 'nas') {
            $vendor = $validated['config']['vendor'] ?? 'synology';
            $healthPath = match ($vendor) {
                'synology' => '/webapi/query.cgi?api=SYNO.API.Info&version=1&method=query&query=SYNO.API.Auth',
                'qnap' => '/cgi-bin/',
                'netgear' => '/',
                default => '/',
            };

            $validated['config'] = [
                ...$validated['config'],
                'auth_mode' => 'vendor',
                'health_path' => $healthPath,
                'health_method' => 'GET',
                'health_expected_status' => 200,
            ];
        }

        return $validated;
    }

    private function resolveIntegrationCredentials(Integration $integration): array
    {
        if ($integration->relationLoaded('vaultEntry')) {
            $vaultEntry = $integration->vaultEntry;
        } else {
            $vaultEntry = $integration->vaultEntry()->first();
        }

        if ($vaultEntry) {
            return [
                'token' => $vaultEntry->revealSecret(),
            ];
        }

        $credentials = $integration->credentials;

        if (is_array($credentials)) {
            return $credentials;
        }

        if (is_string($credentials) && $credentials !== '') {
            return json_decode($credentials, true) ?: [];
        }

        return [];
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
                'vault_entry_id' => 'Selected vault entry scope does not match this integration.',
            ]);
        }
    }

    private function assertIntegrationSecretPolicy(array $validated): void
    {
        $type = $validated['type'] ?? null;
        $siteId = $validated['site_id'] ?? null;
        $vaultEntryId = $validated['vault_entry_id'] ?? null;
        $authMode = $validated['config']['auth_mode'] ?? 'none';

        if ($type === 'proxmox' && ! $vaultEntryId) {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'Proxmox integration requires a vault entry.',
            ]);
        }

        if ($type === 'nvr' && ! $vaultEntryId) {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'Hikvision NVR integration requires a vault entry (username + password).',
            ]);
        }

        if ($type === 'nvr' && trim((string) ($validated['config']['username'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'config.username' => 'Hikvision NVR integration requires a username.',
            ]);
        }

        if ($type === 'nas' && ! $vaultEntryId) {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'NAS integration requires a vault entry with the appliance password.',
            ]);
        }

        if ($type === 'nas' && trim((string) ($validated['config']['username'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'config.username' => 'NAS integration requires an admin username.',
            ]);
        }

        if ($type === 'nas' && trim((string) ($validated['config']['vendor'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'config.vendor' => 'NAS integration requires a vendor selection.',
            ]);
        }

        if ($type === 'headscale' && ! $vaultEntryId) {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'Headscale integration requires an API key from vault.',
            ]);
        }

        if ($type === 'custom_api' && $authMode === 'bearer' && ! $vaultEntryId) {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'Bearer authentication requires a vault entry.',
            ]);
        }

        if ($type === 'docker' && $authMode === 'bearer' && ! $vaultEntryId) {
            throw ValidationException::withMessages([
                'vault_entry_id' => 'Bearer authentication requires a vault entry.',
            ]);
        }

        if (! $vaultEntryId) {
            return;
        }

        $vaultEntry = VaultEntry::query()->findOrFail($vaultEntryId);
        $this->assertVaultEntryUsableForScope($vaultEntry, $siteId);
    }

    private function headscaleApiBase(string $baseUrl): string
    {
        $baseUrl = rtrim($baseUrl, '/');

        return str_ends_with($baseUrl, '/api/v1')
            ? $baseUrl
            : "{$baseUrl}/api/v1";
    }

    private function defaultHealthEndpoint(Integration $integration): string
    {
        return match ($integration->type) {
            'proxmox' => rtrim($integration->base_url, '/').'/api2/json/version',
            'docker' => rtrim($integration->base_url, '/').'/_ping',
            'headscale' => $this->headscaleApiBase($integration->base_url).'/node',
            'nas' => rtrim($integration->base_url, '/').($integration->config['health_path'] ?? '/'),
            default => rtrim($integration->base_url, '/').($integration->config['health_path'] ?? '/health'),
        };
    }

    private function assertIntegrationHostAssetPolicy(array $validated): void
    {
        $type = $validated['type'] ?? null;
        $siteId = $validated['site_id'] ?? null;
        $hostAssetId = $validated['config']['host_asset_id'] ?? null;

        if (! $hostAssetId) {
            return;
        }

        if ($type !== 'proxmox' && $type !== 'nvr' && $type !== 'nas') {
            throw ValidationException::withMessages([
                'config.host_asset_id' => 'Host machine can only be linked for Proxmox, Hikvision NVR, and NAS integrations.',
            ]);
        }

        $asset = InventoryAsset::query()->findOrFail($hostAssetId);

        if ($asset->site_id !== $siteId) {
            throw ValidationException::withMessages([
                'config.host_asset_id' => 'Selected host machine must belong to the same site scope as this integration.',
            ]);
        }
    }

    private function proxmoxHttpClient(array $credentials, bool $verifySsl)
    {
        return Http::withOptions(['verify' => $verifySsl])
            ->withHeaders([
                'Authorization' => 'PVEAPIToken='.($credentials['token'] ?? ''),
            ])
            ->timeout(15);
    }

    private function fetchProxmoxGuestsSnapshot(string $baseUrl, array $credentials, bool $verifySsl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $response = $this->proxmoxHttpClient($credentials, $verifySsl)
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
                    'type_label' => ($guest['type'] ?? null) === 'qemu' ? 'VM' : 'CT',
                    'name' => $guest['name'] ?? ('Guest '.($guest['vmid'] ?? 'unknown')),
                    'vmid' => $guest['vmid'] ?? null,
                    'node' => $guest['node'] ?? null,
                    'status' => $guest['status'] ?? 'unknown',
                    'is_online' => ($guest['status'] ?? null) === 'running',
                    'uptime' => $guest['uptime'] ?? null,
                    'cpu_usage_percent' => $cpuFraction !== null ? round($cpuFraction * 100, 1) : null,
                    'cpu_cores' => $guest['maxcpu'] ?? $guest['cpus'] ?? null,
                    'memory_used_bytes' => $memoryUsed,
                    'memory_total_bytes' => $memoryTotal,
                    'memory_usage_percent' => $this->percentage($memoryUsed, $memoryTotal),
                    'disk_used_bytes' => $diskUsed,
                    'disk_total_bytes' => $diskTotal,
                    'disk_usage_percent' => $this->percentage($diskUsed, $diskTotal),
                ];
            })
            ->values()
            ->all();
    }

    private function summarizeProxmoxGuests(array $guests): array
    {
        $collection = collect($guests);
        $vmGuests = $collection->where('type', 'qemu');
        $ctGuests = $collection->where('type', 'lxc');

        return [
            'vm_total' => $vmGuests->count(),
            'vm_online' => $vmGuests->where('is_online', true)->count(),
            'vm_offline' => $vmGuests->where('is_online', false)->count(),
            'ct_total' => $ctGuests->count(),
            'ct_online' => $ctGuests->where('is_online', true)->count(),
            'ct_offline' => $ctGuests->where('is_online', false)->count(),
            'memory_used_bytes' => $collection->sum(fn (array $guest) => $guest['memory_used_bytes'] ?? 0),
            'memory_total_bytes' => $collection->sum(fn (array $guest) => $guest['memory_total_bytes'] ?? 0),
            'disk_used_bytes' => $collection->sum(fn (array $guest) => $guest['disk_used_bytes'] ?? 0),
            'disk_total_bytes' => $collection->sum(fn (array $guest) => $guest['disk_total_bytes'] ?? 0),
        ];
    }

    private function fetchProxmoxActivity(string $baseUrl, array $credentials, bool $verifySsl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $nodesResponse = $this->proxmoxHttpClient($credentials, $verifySsl)
            ->get("{$baseUrl}/api2/json/nodes");

        if (! $nodesResponse->successful()) {
            throw new \RuntimeException("Proxmox returned HTTP {$nodesResponse->status()} while fetching nodes.");
        }

        $nodeNames = collect($nodesResponse->json('data', []))
            ->pluck('node')
            ->filter()
            ->values();

        $tasks = collect();

        foreach ($nodeNames as $nodeName) {
            $encodedNode = rawurlencode($nodeName);
            $client = $this->proxmoxHttpClient($credentials, $verifySsl);

            $response = $client->get("{$baseUrl}/api2/json/nodes/{$encodedNode}/tasks");

            if (! $response->successful()) {
                throw new \RuntimeException("Proxmox returned HTTP {$response->status()} while fetching task history.");
            }

            $nodeTasks = collect($response->json('data', []))
                ->map(fn (array $task) => [
                    ...$task,
                    'node' => $task['node'] ?? $nodeName,
                ]);

            $tasks = $tasks->merge($nodeTasks);
        }

        return $tasks
            ->sortByDesc(fn (array $task) => (int) ($task['starttime'] ?? 0))
            ->map(fn (array $task) => [
                'id' => $task['upid'] ?? uniqid('pve-task-', true),
                'action' => $task['type'] ?? 'task',
                'result' => ($task['status'] ?? 'unknown') === 'OK' ? 'success' : (($task['status'] ?? '') === 'running' ? 'running' : 'failure'),
                'status_label' => $task['status'] ?? 'unknown',
                'user_name' => $task['user'] ?? 'system',
                'node' => $task['node'] ?? null,
                'target' => $task['id'] ?? null,
                'start_time' => isset($task['starttime']) ? now()->setTimestamp((int) $task['starttime'])->toDateTimeString() : null,
                'created_at' => isset($task['starttime']) ? now()->setTimestamp((int) $task['starttime'])->diffForHumans() : 'Unknown time',
                'created_at_full' => isset($task['starttime']) ? now()->setTimestamp((int) $task['starttime'])->toDateTimeString() : null,
                'end_time' => isset($task['endtime']) ? now()->setTimestamp((int) $task['endtime'])->toDateTimeString() : null,
            ])
            ->all();
    }

    private function fetchProxmoxGuestDetail(
        string $baseUrl,
        array $credentials,
        bool $verifySsl,
        string $guestType,
        string $node,
        string $vmid,
    ): array {
        $baseUrl = rtrim($baseUrl, '/');
        $encodedNode = rawurlencode($node);
        $encodedVmid = rawurlencode($vmid);
        $client = $this->proxmoxHttpClient($credentials, $verifySsl);

        $statusResponse = $client->get("{$baseUrl}/api2/json/nodes/{$encodedNode}/{$guestType}/{$encodedVmid}/status/current");
        $configResponse = $client->get("{$baseUrl}/api2/json/nodes/{$encodedNode}/{$guestType}/{$encodedVmid}/config");

        if (! $statusResponse->successful()) {
            throw new \RuntimeException("Proxmox returned HTTP {$statusResponse->status()} while fetching guest status.");
        }

        if (! $configResponse->successful()) {
            throw new \RuntimeException("Proxmox returned HTTP {$configResponse->status()} while fetching guest config.");
        }

        $status = $statusResponse->json('data', []);
        $guestConfig = $configResponse->json('data', []);

        $memoryUsed = $status['mem'] ?? null;
        $memoryTotal = $status['maxmem'] ?? ($guestConfig['memory'] ?? null);
        if (is_numeric($memoryTotal) && $memoryTotal < 1024 * 1024) {
            $memoryTotal = (int) $memoryTotal * 1024 * 1024;
        }

        $diskUsed = $status['disk'] ?? null;
        $diskTotal = $status['maxdisk'] ?? null;
        $cpuFraction = $status['cpu'] ?? null;
        $configName = $guestConfig['name'] ?? $guestConfig['hostname'] ?? null;
        $statusName = $status['name'] ?? null;

        return [
            'id' => "{$guestType}/{$vmid}",
            'type' => $guestType,
            'type_label' => $guestType === 'qemu' ? 'Virtual Machine' : 'Container',
            'node' => $node,
            'vmid' => (int) $vmid,
            'name' => $configName ?? $statusName ?? "Guest {$vmid}",
            'status' => $status['status'] ?? 'unknown',
            'is_online' => ($status['status'] ?? null) === 'running',
            'uptime' => $status['uptime'] ?? null,
            'cpu_usage_percent' => $cpuFraction !== null ? round($cpuFraction * 100, 1) : null,
            'cpu_cores' => $status['cpus'] ?? $guestConfig['cores'] ?? null,
            'memory_used_bytes' => $memoryUsed,
            'memory_total_bytes' => $memoryTotal,
            'memory_usage_percent' => $this->percentage($memoryUsed, $memoryTotal),
            'disk_used_bytes' => $diskUsed,
            'disk_total_bytes' => $diskTotal,
            'disk_usage_percent' => $this->percentage($diskUsed, $diskTotal),
            'os_type' => $guestConfig['ostype'] ?? ($guestType === 'lxc' ? ($guestConfig['unprivileged'] ?? null ? 'LXC Unprivileged' : 'LXC') : null),
            'onboot' => (bool) ($guestConfig['onboot'] ?? false),
            'agent' => $guestConfig['agent'] ?? null,
            'description' => $guestConfig['description'] ?? null,
            'tags' => $guestConfig['tags'] ?? null,
            'networks' => $this->extractGuestNetworks($guestConfig),
            'storage' => $this->extractGuestStorage($guestType, $guestConfig),
            'config' => collect($guestConfig)
                ->map(fn ($value, $key) => [
                    'key' => $key,
                    'value' => is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value),
                ])
                ->values()
                ->all(),
        ];
    }

    private function fetchProxmoxGuestTasks(
        string $baseUrl,
        array $credentials,
        bool $verifySsl,
        string $guestType,
        string $node,
        string $vmid,
    ): array {
        $baseUrl = rtrim($baseUrl, '/');
        $encodedNode = rawurlencode($node);
        $response = $this->proxmoxHttpClient($credentials, $verifySsl)
            ->get("{$baseUrl}/api2/json/nodes/{$encodedNode}/tasks");

        if (! $response->successful()) {
            throw new \RuntimeException("Proxmox returned HTTP {$response->status()} while fetching guest tasks.");
        }

        $targetId = "{$guestType}/{$vmid}";

        return collect($response->json('data', []))
            ->filter(function (array $task) use ($targetId, $vmid) {
                $id = (string) ($task['id'] ?? '');
                $upid = (string) ($task['upid'] ?? '');

                return $id === $targetId
                    || str_contains($id, "/{$vmid}")
                    || str_contains($upid, ":{$vmid}:")
                    || str_contains($upid, $targetId);
            })
            ->sortByDesc(fn (array $task) => (int) ($task['starttime'] ?? 0))
            ->map(fn (array $task) => [
                'id' => $task['upid'] ?? uniqid('pve-guest-task-', true),
                'action' => $task['type'] ?? 'task',
                'result' => ($task['status'] ?? 'unknown') === 'OK' ? 'success' : (($task['status'] ?? '') === 'running' ? 'running' : 'failure'),
                'status_label' => $task['status'] ?? 'unknown',
                'user_name' => $task['user'] ?? 'system',
                'target' => $task['id'] ?? $targetId,
                'created_at' => isset($task['starttime']) ? now()->setTimestamp((int) $task['starttime'])->diffForHumans() : 'Unknown time',
                'created_at_full' => isset($task['starttime']) ? now()->setTimestamp((int) $task['starttime'])->toDateTimeString() : null,
                'end_time' => isset($task['endtime']) ? now()->setTimestamp((int) $task['endtime'])->toDateTimeString() : null,
            ])
            ->values()
            ->all();
    }

    private function fetchProxmoxConsoleSession(
        string $baseUrl,
        array $credentials,
        bool $verifySsl,
        string $guestType,
        string $node,
        string $vmid,
        array $consoleAccess,
    ): array {
        $baseUrl = rtrim($baseUrl, '/');
        $encodedNode = rawurlencode($node);
        $encodedVmid = rawurlencode($vmid);
        $endpoint = "{$baseUrl}/api2/json/nodes/{$encodedNode}/{$guestType}/{$encodedVmid}/vncproxy";
        $response = $this->proxmoxConsoleHttpClient($consoleAccess, $verifySsl)
            ->asForm()
            ->post($endpoint, [
                'websocket' => 1,
                'generate-password' => 1,
            ]);

        if (
            ! $response->successful()
            && $response->status() === 400
            && str_contains(strtolower((string) $response->json('message', '')), 'parameter verification failed')
            && str_contains(json_encode($response->json('errors', [])) ?: '', 'generate-password')
        ) {
            $response = $this->proxmoxConsoleHttpClient($consoleAccess, $verifySsl)
                ->asForm()
                ->post($endpoint, [
                    'websocket' => 1,
                ]);
        }

        if (! $response->successful()) {
            throw new \RuntimeException(
                $this->buildProxmoxApiErrorMessage($response, 'creating console session')
            );
        }

        $data = $response->json('data', []);
        $ticket = $data['ticket'] ?? null;
        $password = $data['password'] ?? $ticket;
        $port = $data['port'] ?? null;

        if (! $ticket || ! $port || ! $password) {
            throw new \RuntimeException('Proxmox console session is missing required connection details.');
        }

        $wsUrl = $this->buildProxmoxWebsocketUrl(
            $baseUrl,
            $guestType,
            $encodedNode,
            $encodedVmid,
            $port,
            $ticket,
        );

        return [
            'websocket_url' => $wsUrl,
            'password' => $password,
            'ticket' => $ticket,
            'port' => $port,
            'user' => $data['user'] ?? null,
            'upid' => $data['upid'] ?? null,
            'verify_ssl' => $verifySsl,
            'expires_note' => 'Console session is short-lived and should be used immediately.',
        ];
    }

    private function fetchProxmoxSyslog(string $baseUrl, array $credentials, bool $verifySsl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $nodesResponse = $this->proxmoxHttpClient($credentials, $verifySsl)
            ->get("{$baseUrl}/api2/json/nodes");

        if (! $nodesResponse->successful()) {
            throw new \RuntimeException("Proxmox returned HTTP {$nodesResponse->status()} while fetching nodes for syslog.");
        }

        $nodeNames = collect($nodesResponse->json('data', []))
            ->pluck('node')
            ->filter()
            ->values();

        $entries = collect();

        foreach ($nodeNames as $nodeName) {
            $encodedNode = rawurlencode($nodeName);
            $response = $this->proxmoxHttpClient($credentials, $verifySsl)
                ->get("{$baseUrl}/api2/json/nodes/{$encodedNode}/syslog", [
                    'limit' => 200,
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException("Proxmox returned HTTP {$response->status()} while fetching syslog.");
            }

            $nodeEntries = collect($response->json('data', []))
                ->map(function (array $entry) use ($nodeName) {
                    $parsed = $this->parseSyslogLine($entry['t'] ?? '');
                    $lineNumber = isset($entry['n']) ? (int) $entry['n'] : 0;

                    return [
                        'id' => ($entry['n'] ?? uniqid('pve-syslog-', true)).'-'.$nodeName,
                        'node' => $nodeName,
                        'line_number' => $lineNumber,
                        'time' => $parsed['time'],
                        'time_human' => $parsed['time_human'],
                        'sort_key' => $parsed['sort_key'] ?: $lineNumber,
                        'tag' => $parsed['tag'],
                        'message' => $parsed['message'],
                        'raw' => $entry['t'] ?? '',
                    ];
                });

            $entries = $entries->merge($nodeEntries);
        }

        return $entries
            ->sortByDesc('sort_key')
            ->values()
            ->all();
    }

    private function fetchProxmoxJournal(string $baseUrl, array $credentials, bool $verifySsl): array
    {
        $baseUrl = rtrim($baseUrl, '/');

        $nodesResponse = $this->proxmoxHttpClient($credentials, $verifySsl)
            ->get("{$baseUrl}/api2/json/nodes");

        if (! $nodesResponse->successful()) {
            throw new \RuntimeException("Proxmox returned HTTP {$nodesResponse->status()} while fetching nodes for journal.");
        }

        $nodeNames = collect($nodesResponse->json('data', []))
            ->pluck('node')
            ->filter()
            ->values();

        $entries = collect();

        foreach ($nodeNames as $nodeName) {
            $encodedNode = rawurlencode($nodeName);
            $response = $this->proxmoxHttpClient($credentials, $verifySsl)
                ->withOptions(['decode_content' => false])
                ->get("{$baseUrl}/api2/json/nodes/{$encodedNode}/journal", [
                    'lastentries' => 200,
                ]);

            if (! $response->successful()) {
                continue;
            }

            $payload = $response->body();
            $decoded = json_decode($payload, true);

            if ($decoded === null && function_exists('gzdecode')) {
                $gunzipped = @gzdecode($payload);
                if ($gunzipped !== false) {
                    $decoded = json_decode($gunzipped, true);
                }
            }

            if (is_array($decoded) && array_key_exists('data', $decoded) && is_array($decoded['data'])) {
                $decoded = $decoded['data'];
            }

            if (! is_array($decoded)) {
                continue;
            }

            $nodeEntries = collect($decoded)
                ->values()
                ->map(fn ($entry, $index) => $this->mapJournalEntry($entry, $nodeName, (int) $index))
                ->filter()
                ->values();

            $entries = $entries->merge($nodeEntries);
        }

        return $entries
            ->sortByDesc('sort_key')
            ->values()
            ->all();
    }

    private function percentage($used, $total): ?float
    {
        if ($used === null || $total === null || (float) $total <= 0.0) {
            return null;
        }

        return round(((float) $used / (float) $total) * 100, 1);
    }

    private function formatConnectionExceptionForDisplay(\Throwable $e, string $baseUrl, bool $verifySsl): string
    {
        if ($e instanceof ConnectionException) {
            return $this->formatConnectionException($e, $baseUrl, $verifySsl);
        }

        return $e->getMessage();
    }

    private function buildProxmoxApiErrorMessage($response, string $action): string
    {
        $message = "Proxmox returned HTTP {$response->status()} while {$action}.";
        $payload = $response->json();

        if (! is_array($payload)) {
            return $message;
        }

        $parts = collect([
            $payload['message'] ?? null,
            isset($payload['errors']) && is_array($payload['errors'])
                ? collect($payload['errors'])
                    ->map(fn ($error, $field) => "{$field}: {$error}")
                    ->implode('; ')
                : null,
        ])->filter()->implode(' ');

        return $parts !== '' ? "{$message} {$parts}" : $message;
    }

    private function hasProxmoxConsoleCredentials(array $credentials): bool
    {
        return trim((string) ($credentials['console_username'] ?? '')) !== ''
            && trim((string) ($credentials['console_password'] ?? '')) !== '';
    }

    private function fetchProxmoxAccessTicket(string $baseUrl, array $credentials, bool $verifySsl): array
    {
        $username = trim((string) ($credentials['console_username'] ?? ''));
        $password = (string) ($credentials['console_password'] ?? '');

        if ($username === '' || $password === '') {
            throw new \RuntimeException('Missing Proxmox console credentials.');
        }

        $response = Http::withOptions(['verify' => $verifySsl])
            ->asForm()
            ->timeout(10)
            ->post(rtrim($baseUrl, '/').'/api2/json/access/ticket', [
                'username' => $username,
                'password' => $password,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                $this->buildProxmoxApiErrorMessage($response, 'creating console access ticket')
            );
        }

        $ticket = $response->json('data.ticket');
        $csrf = $response->json('data.CSRFPreventionToken');

        if (! $ticket || ! $csrf) {
            throw new \RuntimeException('Proxmox console access ticket is missing required fields.');
        }

        return [
            'ticket' => $ticket,
            'csrf' => $csrf,
            'username' => $username,
        ];
    }

    private function proxmoxConsoleHttpClient(array $consoleAccess, bool $verifySsl)
    {
        return Http::withOptions(['verify' => $verifySsl])
            ->withHeaders([
                'Cookie' => 'PVEAuthCookie='.$consoleAccess['ticket'],
                'CSRFPreventionToken' => $consoleAccess['csrf'],
            ])
            ->timeout(10);
    }

    private function proxmoxConsoleProxyCacheKey(string $token): string
    {
        return "proxmox-console-proxy:{$token}";
    }

    private function paginateItems(array $items, int $page, int $perPage, string $pageParam): array
    {
        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'data' => array_values(array_slice($items, $offset, $perPage)),
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => $perPage,
            'total' => $total,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
            'page_param' => $pageParam,
            'has_prev' => $page > 1,
            'has_next' => $page < $lastPage,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $lastPage ? $page + 1 : null,
        ];
    }

    private function parseSyslogLine(string $line): array
    {
        $line = trim($line);

        if ($line === '') {
            return [
                'time' => null,
                'time_human' => null,
                'sort_key' => 0,
                'tag' => 'syslog',
                'message' => '',
            ];
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2}T[^\s]+)\s+(\S+)\s+([^:]+):\s*(.*)$/', $line, $matches)) {
            $timestamp = strtotime($matches[1]) ?: 0;

            return [
                'time' => $timestamp ? now()->setTimestamp($timestamp)->toDateTimeString() : $matches[1],
                'time_human' => $timestamp ? now()->setTimestamp($timestamp)->diffForHumans() : $matches[1],
                'sort_key' => $timestamp,
                'tag' => trim($matches[3]),
                'message' => trim($matches[4]),
            ];
        }

        if (preg_match('/^([A-Z][a-z]{2}\s+\d+\s+\d{2}:\d{2}:\d{2})\s+(\S+)\s+([^:]+):\s*(.*)$/', $line, $matches)) {
            $currentYear = now()->year;
            $timestamp = strtotime("{$currentYear} {$matches[1]}") ?: 0;

            return [
                'time' => $timestamp ? now()->setTimestamp($timestamp)->toDateTimeString() : $matches[1],
                'time_human' => $timestamp ? now()->setTimestamp($timestamp)->diffForHumans() : $matches[1],
                'sort_key' => $timestamp,
                'tag' => trim($matches[3]),
                'message' => trim($matches[4]),
            ];
        }

        return [
            'time' => null,
            'time_human' => null,
            'sort_key' => 0,
            'tag' => 'syslog',
            'message' => $line,
        ];
    }

    private function mapJournalEntry($entry, string $nodeName, int $index): ?array
    {
        if (is_string($entry)) {
            $decoded = json_decode($entry, true);

            if (is_array($decoded)) {
                return $this->mapJournalEntry($decoded, $nodeName, $index);
            }

            $parsed = $this->parseSyslogLine($entry);

            return [
                'id' => "{$nodeName}-journal-{$index}",
                'node' => $nodeName,
                'line_number' => $index + 1,
                'time' => $parsed['time'],
                'time_human' => $parsed['time_human'],
                'sort_key' => $parsed['sort_key'] ?: $index + 1,
                'tag' => $parsed['tag'],
                'message' => $parsed['message'],
                'raw' => $entry,
            ];
        }

        if (is_array($entry)) {
            $timestampRaw = $entry['__REALTIME_TIMESTAMP']
                ?? $entry['realtime_timestamp']
                ?? $entry['_SOURCE_REALTIME_TIMESTAMP']
                ?? $entry['timestamp']
                ?? null;
            $timestamp = $this->normalizeJournalTimestamp($timestampRaw);
            $message = $entry['MESSAGE'] ?? $entry['message'] ?? $entry['msg'] ?? json_encode($entry);
            $tag = $entry['SYSLOG_IDENTIFIER']
                ?? $entry['_COMM']
                ?? $entry['tag']
                ?? $entry['UNIT']
                ?? 'journal';

            return [
                'id' => $entry['__CURSOR'] ?? "{$nodeName}-journal-{$index}",
                'node' => $entry['_HOSTNAME'] ?? $entry['hostname'] ?? $nodeName,
                'line_number' => $index + 1,
                'time' => $timestamp ? now()->setTimestamp($timestamp)->toDateTimeString() : null,
                'time_human' => $timestamp ? now()->setTimestamp($timestamp)->diffForHumans() : null,
                'sort_key' => $timestamp ?: $index + 1,
                'tag' => is_string($tag) ? $tag : 'journal',
                'message' => is_string($message) ? $message : json_encode($message),
                'raw' => json_encode($entry),
            ];
        }

        return null;
    }

    private function normalizeJournalTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = (string) $value;

        if (ctype_digit($value)) {
            if (strlen($value) > 10) {
                return (int) floor(((int) $value) / 1000000);
            }

            return (int) $value;
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? $timestamp : null;
    }

    private function extractGuestNetworks(array $config): array
    {
        return collect($config)
            ->filter(fn ($value, $key) => preg_match('/^(net\d+|ipconfig\d+)$/', (string) $key))
            ->map(fn ($value, $key) => [
                'key' => $key,
                'value' => is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value),
            ])
            ->values()
            ->all();
    }

    private function extractGuestStorage(string $guestType, array $config): array
    {
        $pattern = $guestType === 'qemu'
            ? '/^(virtio\d+|scsi\d+|sata\d+|ide\d+|efidisk\d+|tpmstate\d+)$/'
            : '/^(rootfs|mp\d+)$/';

        return collect($config)
            ->filter(fn ($value, $key) => preg_match($pattern, (string) $key))
            ->map(fn ($value, $key) => [
                'key' => $key,
                'value' => is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value),
            ])
            ->values()
            ->all();
    }

    private function buildProxmoxWebsocketUrl(
        string $baseUrl,
        string $guestType,
        string $encodedNode,
        string $encodedVmid,
        int|string $port,
        string $ticket,
    ): string {
        $parts = parse_url($baseUrl);
        $scheme = ($parts['scheme'] ?? 'https') === 'https' ? 'wss' : 'ws';
        $host = $parts['host'] ?? '';
        $portPart = isset($parts['port']) ? ':'.$parts['port'] : '';
        $pathPrefix = rtrim($parts['path'] ?? '', '/');
        $query = http_build_query([
            'port' => $port,
            'vncticket' => $ticket,
        ]);

        return "{$scheme}://{$host}{$portPart}{$pathPrefix}/api2/json/nodes/{$encodedNode}/{$guestType}/{$encodedVmid}/vncwebsocket?{$query}";
    }
}
