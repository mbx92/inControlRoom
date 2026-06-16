<?php

namespace App\Http\Controllers;

use App\Models\Integration;
use App\Models\InventoryAsset;
use App\Models\Site;
use App\Models\TopologyLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class TopologyController extends Controller
{
    use \App\Http\Controllers\Concerns\AppliesSiteScope;
    public function index(Request $request): Response
    {
        $sites = $this->scopedSitesQuery()
            ->withCount(['inventoryAssets', 'integrations'])
            ->get()
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'code' => $site->code,
                'assets_count' => $site->inventory_assets_count,
                'integrations_count' => $site->integrations_count,
                'color' => $this->siteColor($site),
            ]);

        $selectedSite = $request->query('site', $sites->first()['id'] ?? null);

        if ($this->isSiteEnforced() && $selectedSite && ! $sites->pluck('id')->contains($selectedSite)) {
            abort(403, 'You do not have access to this site.');
        }
        $mode = $request->query('mode', 'infrastructure');
        if (! in_array($mode, ['infrastructure', 'network', 'proxmox'], true)) {
            $mode = 'infrastructure';
        }

        $topologyGraph = match ($mode) {
            'network' => $this->buildNetworkTopologyGraph($selectedSite),
            'proxmox' => $this->buildProxmoxTopologyGraph($selectedSite),
            default => $this->buildInfrastructureTopologyGraph($selectedSite),
        };

        return Inertia::render('Topology/Index', [
            'sites' => $sites,
            'selectedSite' => $selectedSite,
            'topologyMode' => $mode,
            'topologyGraph' => $topologyGraph,
            'topologyLayout' => $this->presentTopologyLayout($selectedSite, $mode),
        ]);
    }

    public function updateLayout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'uuid', 'exists:sites,id'],
            'mode' => ['required', 'in:infrastructure,network,proxmox'],
            'positions' => ['required', 'array'],
            'positions.*.x' => ['required', 'numeric'],
            'positions.*.y' => ['required', 'numeric'],
            'is_locked' => ['sometimes', 'boolean'],
        ]);

        $layout = TopologyLayout::query()->updateOrCreate(
            [
                'site_id' => $validated['site_id'],
                'mode' => $validated['mode'],
            ],
            [
                'positions' => $validated['positions'],
                'is_locked' => $validated['is_locked'] ?? false,
                'updated_by' => $request->user()->id,
            ],
        );

        return response()->json([
            'ok' => true,
            'updated_at' => $layout->updated_at?->toIso8601String(),
        ]);
    }

    public function destroyLayout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'site_id' => ['required', 'uuid', 'exists:sites,id'],
            'mode' => ['required', 'in:infrastructure,network,proxmox'],
        ]);

        TopologyLayout::query()
            ->where('site_id', $validated['site_id'])
            ->where('mode', $validated['mode'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    private function presentTopologyLayout(?string $siteId, string $mode): ?array
    {
        if (! $siteId) {
            return null;
        }

        $layout = TopologyLayout::query()
            ->where('site_id', $siteId)
            ->where('mode', $mode)
            ->first();

        if (! $layout) {
            return null;
        }

        return [
            'positions' => $layout->positions ?? [],
            'is_locked' => $layout->is_locked,
            'updated_at' => $layout->updated_at?->toIso8601String(),
        ];
    }

    private function buildInfrastructureTopologyGraph(?string $siteId): array
    {
        $nodes = [];
        $edges = [];

        if (! $siteId) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['hasVirtualLayer' => false]];
        }

        $site = Site::find($siteId);
        if (! $site) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['hasVirtualLayer' => false]];
        }

        $siteColor = $this->siteColor($site);

        $allAssets = InventoryAsset::query()
            ->where('site_id', $siteId)
            ->orderBy('location_label')
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $locationSummary = $this->siteLocationSummary($allAssets);
        $floorSummary = $this->siteFloorSummary($allAssets);

        $siteNodeId = 'site:'.$site->id;
        $nodes[] = [
            'id' => $siteNodeId,
            'type' => 'site-group',
            'position' => ['x' => 0, 'y' => 0],
            'data' => [
                'label' => $site->name,
                'subtitle' => count($locationSummary).' location'.(count($locationSummary) !== 1 ? 's' : '')
                    .' · '.$allAssets->count().' asset'.($allAssets->count() !== 1 ? 's' : ''),
                'icon' => 'site',
                'siteColor' => $siteColor,
                'layer' => 'physical',
                'locations' => $locationSummary,
                'locationCount' => count($locationSummary),
                'floors' => $floorSummary,
                'floorCount' => count($floorSummary),
                'assetCount' => $allAssets->count(),
            ],
        ];

        $byLocation = $allAssets
            ->groupBy(fn (InventoryAsset $asset) => $this->locationLabel($asset))
            ->sortKeys();

        foreach ($byLocation as $locationName => $locationAssets) {
            $locationNodeId = 'location:'.$site->id.':'.md5($locationName);

            $nodes[] = [
                'id' => $locationNodeId,
                'type' => 'location-group',
                'position' => ['x' => 0, 'y' => 0],
                'parentId' => $siteNodeId,
                'data' => [
                    'label' => $locationName,
                    'subtitle' => count($locationAssets).' device'.(count($locationAssets) !== 1 ? 's' : ''),
                    'siteColor' => $siteColor,
                    'layer' => 'physical',
                ],
            ];

            $edges[] = $this->topologyEdge(
                id: "e-site-location-{$locationNodeId}",
                source: $siteNodeId,
                target: $locationNodeId,
                variant: 'physical',
            );

            foreach ($locationAssets as $asset) {
                $assetId = 'asset:'.$asset->id;
                $location = $this->parseLocation($asset);

                $nodes[] = [
                    'id' => $assetId,
                    'parentId' => $locationNodeId,
                    'type' => 'inventory-asset',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => [
                        'label' => $asset->name,
                        'subtitle' => collect([$asset->category, $asset->primary_ip, $asset->model])
                            ->filter()
                            ->implode(' · '),
                        'icon' => $this->categoryIcon($asset->category),
                        'category' => $asset->category,
                        'status' => $asset->status,
                        'assetId' => $asset->id,
                        'href' => route('inventory.show', $asset->id),
                        'layer' => 'physical',
                        'floor' => $location['floor'],
                        'room' => $location['room'],
                        'location' => $locationName,
                    ],
                ];

                $edges[] = $this->topologyEdge(
                    id: "e-location-asset-{$assetId}",
                    source: $locationNodeId,
                    target: $assetId,
                    variant: 'physical',
                );
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => [
                'mode' => 'infrastructure',
                'locationCount' => count($locationSummary),
                'floorCount' => count($floorSummary),
            ],
        ];
    }

    private function buildProxmoxTopologyGraph(?string $siteId): array
    {
        $nodes = [];
        $edges = [];

        if (! $siteId) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['mode' => 'proxmox', 'hasIntegration' => false]];
        }

        $site = Site::find($siteId);
        if (! $site) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['mode' => 'proxmox', 'hasIntegration' => false]];
        }

        $siteColor = $this->siteColor($site);
        $siteNodeId = 'site:'.$site->id;

        $proxmoxIntegration = Integration::query()
            ->where('type', 'proxmox')
            ->where('is_active', true)
            ->where('site_id', $siteId)
            ->with('site', 'vaultEntry')
            ->first();

        $allAssets = InventoryAsset::query()
            ->where('site_id', $siteId)
            ->orderBy('name')
            ->get();

        $hostAsset = $proxmoxIntegration
            ? $this->resolveHostAsset($proxmoxIntegration, $allAssets)
            : null;

        $guests = $proxmoxIntegration
            ? $this->fetchProxmoxGuestsForTopology($proxmoxIntegration)
            : [];

        $nodes[] = [
            'id' => $siteNodeId,
            'type' => 'site-group',
            'position' => ['x' => 0, 'y' => 0],
            'data' => [
                'label' => $site->name,
                'subtitle' => $proxmoxIntegration
                    ? count($guests).' workload'.(count($guests) !== 1 ? 's' : '')
                    : 'No active Proxmox integration',
                'icon' => 'site',
                'siteColor' => $siteColor,
                'layer' => 'virtual',
                'assetCount' => count($guests),
            ],
        ];

        if (! $proxmoxIntegration) {
            return [
                'nodes' => $nodes,
                'edges' => $edges,
                'meta' => [
                    'mode' => 'proxmox',
                    'hasIntegration' => false,
                    'guestCount' => 0,
                ],
            ];
        }

        $intNodeId = 'integration:'.$proxmoxIntegration->id;

        $nodes[] = [
            'id' => $intNodeId,
            'type' => 'proxmox-integration',
            'position' => ['x' => 0, 'y' => 0],
            'data' => [
                'label' => $proxmoxIntegration->name,
                'subtitle' => 'Proxmox VE',
                'icon' => 'hypervisor',
                'siteColor' => $siteColor,
                'layer' => 'virtual',
                'hostAssetName' => $hostAsset?->name,
            ],
        ];

        $edges[] = $this->topologyEdge(
            id: "e-proxmox-site-{$proxmoxIntegration->id}",
            source: $siteNodeId,
            target: $intNodeId,
            variant: 'virtual',
        );

        foreach ($guests as $guest) {
            $gid = 'guest:'.$proxmoxIntegration->id.':'.($guest['vmid'] ?? md5(json_encode($guest)));

            $nodes[] = [
                'id' => $gid,
                'type' => 'proxmox-guest',
                'position' => ['x' => 0, 'y' => 0],
                'data' => [
                    'label' => $guest['name'],
                    'subtitle' => $guest['type'] === 'qemu' ? 'VM' : 'CT',
                    'icon' => $guest['type'] === 'qemu' ? 'vm' : 'container',
                    'status' => $guest['status'] ?? 'unknown',
                    'vmid' => $guest['vmid'],
                    'node' => $guest['node'],
                    'cpu' => $guest['cpu_usage_percent'] ?? null,
                    'mem' => $guest['memory_usage_percent'] ?? null,
                    'layer' => 'virtual',
                ],
            ];

            $edges[] = $this->topologyEdge(
                id: "e-proxmox-{$gid}",
                source: $intNodeId,
                target: $gid,
                variant: 'virtual',
            );
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => [
                'mode' => 'proxmox',
                'hasIntegration' => true,
                'hostAssetName' => $hostAsset?->name,
                'proxmoxName' => $proxmoxIntegration->name,
                'guestCount' => count($guests),
            ],
        ];
    }

    private function buildNetworkTopologyGraph(?string $siteId): array
    {
        $nodes = [];
        $edges = [];
        $nodeId = 0;

        $nextId = function () use (&$nodeId): string {
            $nodeId++;

            return (string) $nodeId;
        };

        if (! $siteId) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['mode' => 'network', 'floorCount' => 0]];
        }

        $site = Site::find($siteId);
        if (! $site) {
            return ['nodes' => [], 'edges' => [], 'meta' => ['mode' => 'network', 'floorCount' => 0]];
        }

        $siteColor = $this->siteColor($site);

        $allAssets = InventoryAsset::query()
            ->where('site_id', $siteId)
            ->with(['uplinkLink'])
            ->orderBy('location_label')
            ->orderBy('name')
            ->get();

        $byFloor = $allAssets->groupBy(fn (InventoryAsset $asset) => $this->parseLocation($asset)['floor']);
        $floorSummary = $this->siteFloorSummary($allAssets);

        $siteNodeId = $nextId();
        $nodes[] = [
            'id' => $siteNodeId,
            'type' => 'site-group',
            'position' => ['x' => 0, 'y' => 0],
            'data' => [
                'label' => $site->name,
                'subtitle' => count($floorSummary).' floor'.(count($floorSummary) !== 1 ? 's' : '')
                    .' · '.$allAssets->count().' device'.($allAssets->count() !== 1 ? 's' : ''),
                'icon' => 'site',
                'siteColor' => $siteColor,
                'layer' => 'network',
                'floors' => $floorSummary,
                'floorCount' => count($floorSummary),
                'assetCount' => $allAssets->count(),
            ],
        ];

        $assetNodeIds = [];

        foreach ($byFloor as $floorName => $floorAssets) {
            $floorNodeId = $nextId();
            $floorAssetIds = $floorAssets->pluck('id')->all();

            $nodes[] = [
                'id' => $floorNodeId,
                'type' => 'floor-group',
                'position' => ['x' => 0, 'y' => 0],
                'parentId' => $siteNodeId,
                'data' => [
                    'label' => $floorName,
                    'subtitle' => count($floorAssets).' device'.(count($floorAssets) !== 1 ? 's' : ''),
                    'siteColor' => $siteColor,
                    'layer' => 'network',
                ],
            ];

            $edges[] = $this->topologyEdge(
                id: "e-site-floor-{$floorNodeId}",
                source: $siteNodeId,
                target: $floorNodeId,
                variant: 'network',
            );

            $childrenOf = [];
            foreach ($floorAssets as $asset) {
                $uplinkTargetId = $asset->uplinkLink?->target_asset_id;
                if ($uplinkTargetId && in_array($uplinkTargetId, $floorAssetIds, true)) {
                    $childrenOf[$uplinkTargetId][] = $asset;
                }
            }

            $roots = $floorAssets->filter(function (InventoryAsset $asset) use ($floorAssetIds) {
                $uplinkTargetId = $asset->uplinkLink?->target_asset_id;
                if (! $uplinkTargetId) {
                    return true;
                }

                return ! in_array($uplinkTargetId, $floorAssetIds, true);
            });

            $placeAsset = function (
                InventoryAsset $asset,
                string $parentNodeId,
            ) use (
                &$placeAsset,
                &$nodes,
                &$edges,
                &$assetNodeIds,
                &$nextId,
                $childrenOf,
            ): void {
                if (isset($assetNodeIds[$asset->id])) {
                    return;
                }

                $assetNodeId = $nextId();
                $assetNodeIds[$asset->id] = $assetNodeId;
                $location = $this->parseLocation($asset);

                $nodes[] = [
                    'id' => $assetNodeId,
                    'parentId' => $parentNodeId,
                    'type' => 'inventory-asset',
                    'position' => ['x' => 0, 'y' => 0],
                    'data' => [
                        'label' => $asset->name,
                        'subtitle' => collect([$asset->category, $asset->primary_ip, $location['room']])
                            ->filter()
                            ->implode(' · '),
                        'icon' => $this->categoryIcon($asset->category),
                        'category' => $asset->category,
                        'networkRole' => $this->assetNetworkRole($asset),
                        'status' => $asset->status,
                        'assetId' => $asset->id,
                        'href' => route('inventory.show', $asset->id),
                        'layer' => 'network',
                    ],
                ];

                $edges[] = $this->topologyEdge(
                    id: "e-net-{$parentNodeId}-{$assetNodeId}",
                    source: $parentNodeId,
                    target: $assetNodeId,
                    variant: 'network',
                );

                foreach ($childrenOf[$asset->id] ?? [] as $child) {
                    $placeAsset($child, $assetNodeId);
                }
            };

            foreach ($roots as $root) {
                if (isset($assetNodeIds[$root->id])) {
                    continue;
                }

                $uplinkTargetId = $root->uplinkLink?->target_asset_id;
                if ($uplinkTargetId && in_array($uplinkTargetId, $floorAssetIds, true)) {
                    continue;
                }

                $placeAsset($root, $floorNodeId);
            }

            $unlinked = $floorAssets->filter(fn (InventoryAsset $asset) => ! isset($assetNodeIds[$asset->id]));
            $byRoom = $unlinked->groupBy(fn (InventoryAsset $asset) => $this->parseLocation($asset)['room'] ?? 'General');

            foreach ($byRoom as $roomName => $roomAssets) {
                $parentNodeId = $floorNodeId;

                if ($roomName !== 'General') {
                    $roomNodeId = $nextId();
                    $nodes[] = [
                        'id' => $roomNodeId,
                        'parentId' => $floorNodeId,
                        'type' => 'room-group',
                        'position' => ['x' => 0, 'y' => 0],
                        'data' => [
                            'label' => $roomName,
                            'subtitle' => count($roomAssets).' device'.(count($roomAssets) !== 1 ? 's' : ''),
                            'layer' => 'network',
                        ],
                    ];

                    $edges[] = $this->topologyEdge(
                        id: "e-floor-room-{$roomNodeId}",
                        source: $floorNodeId,
                        target: $roomNodeId,
                        variant: 'network',
                    );

                    $parentNodeId = $roomNodeId;
                }

                foreach ($roomAssets as $asset) {
                    $placeAsset($asset, $parentNodeId);
                }
            }
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => [
                'mode' => 'network',
                'floorCount' => $byFloor->count(),
            ],
        ];
    }

    private function siteFloorSummary(Collection $assets): array
    {
        return $assets
            ->groupBy(fn (InventoryAsset $asset) => $this->parseLocation($asset)['floor'])
            ->map(fn (Collection $group, string $floor) => [
                'name' => $floor,
                'count' => $group->count(),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function siteLocationSummary(Collection $assets): array
    {
        return $assets
            ->groupBy(fn (InventoryAsset $asset) => $this->locationLabel($asset))
            ->map(fn (Collection $group, string $location) => [
                'name' => $location,
                'count' => $group->count(),
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function parseLocation(InventoryAsset $asset): array
    {
        $label = trim((string) ($asset->location_label ?? ''));

        if ($label === '') {
            return ['floor' => 'Unassigned', 'room' => null];
        }

        $parts = array_map('trim', explode('/', $label, 2));

        return [
            'floor' => $parts[0] !== '' ? $parts[0] : 'Unassigned',
            'room' => isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null,
        ];
    }

    private function locationLabel(InventoryAsset $asset): string
    {
        $location = $this->parseLocation($asset);

        return $location['room']
            ? $location['floor'].' / '.$location['room']
            : $location['floor'];
    }

    private function resolveHostAsset(Integration $integration, Collection $assets): ?InventoryAsset
    {
        $hostAssetId = $integration->config['host_asset_id'] ?? null;

        if ($hostAssetId) {
            $matched = $assets->firstWhere('id', $hostAssetId);

            if ($matched) {
                return $matched;
            }
        }

        $host = parse_url($integration->base_url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        $host = strtolower($host);

        $matchedByHost = $assets->first(function (InventoryAsset $asset) use ($host) {
            if ($asset->primary_ip && strtolower($asset->primary_ip) === $host) {
                return true;
            }

            $customFields = $asset->custom_fields ?? [];

            foreach (['proxmox_host', 'hostname', 'fqdn'] as $key) {
                if (! empty($customFields[$key]) && strtolower((string) $customFields[$key]) === $host) {
                    return true;
                }
            }

            return false;
        });

        if ($matchedByHost) {
            return $matchedByHost;
        }

        $hypervisors = $assets->filter(
            fn (InventoryAsset $asset) => in_array(strtolower($asset->category), ['hypervisor', 'server'], true),
        );

        if ($hypervisors->count() === 1) {
            return $hypervisors->first();
        }

        return null;
    }

    private function fetchProxmoxGuestsForTopology(Integration $integration): array
    {
        try {
            $credentials = $this->resolveCredentials($integration);
            $config = $integration->config ?? [];
            $verifySsl = $config['verify_ssl'] ?? true;

            return $this->fetchProxmoxGuestsSnapshot(
                $integration->base_url,
                $credentials,
                $verifySsl,
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function resolveCredentials(Integration $integration): array
    {
        $vaultEntry = $integration->vaultEntry;

        if ($vaultEntry) {
            return ['token' => $vaultEntry->revealSecret()];
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
            ->get("{$baseUrl}/api2/json/cluster/resources", ['type' => 'vm']);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('data', []))
            ->filter(fn (array $guest) => in_array($guest['type'] ?? null, ['qemu', 'lxc'], true))
            ->sortBy([['type', 'asc'], ['vmid', 'asc']])
            ->map(function (array $guest) {
                $memoryUsed = $guest['mem'] ?? null;
                $memoryTotal = $guest['maxmem'] ?? null;
                $cpuFraction = $guest['cpu'] ?? null;

                return [
                    'type' => $guest['type'] ?? 'unknown',
                    'name' => $guest['name'] ?? ('Guest '.($guest['vmid'] ?? 'unknown')),
                    'vmid' => $guest['vmid'] ?? null,
                    'node' => $guest['node'] ?? null,
                    'status' => $guest['status'] ?? 'unknown',
                    'cpu_usage_percent' => $cpuFraction !== null ? round($cpuFraction * 100, 1) : null,
                    'cpu_cores' => $guest['maxcpu'] ?? $guest['cpus'] ?? null,
                    'memory_usage_percent' => $this->percentage($memoryUsed, $memoryTotal),
                ];
            })
            ->values()
            ->all();
    }

    private function topologyEdge(
        string $id,
        string $source,
        string $target,
        string $variant = 'physical',
        ?string $sourceHandle = null,
        ?string $targetHandle = null,
        bool $animated = false,
    ): array {
        $stroke = match ($variant) {
            'virtual' => '#F0B90B',
            'hosts' => '#F0B90B',
            'network' => '#3B82F6',
            default => '#707A8A',
        };

        $edge = [
            'id' => $id,
            'source' => $source,
            'target' => $target,
            'type' => 'smoothstep',
            'sourceHandle' => $sourceHandle ?? 'bottom',
            'targetHandle' => $targetHandle ?? 'top',
            'data' => ['variant' => $variant],
            'style' => [
                'stroke' => $stroke,
                'strokeWidth' => $variant === 'hosts' ? 3 : 2.5,
            ],
            'pathOptions' => [
                'borderRadius' => 12,
                'offset' => 24,
            ],
        ];

        if ($animated || $variant === 'hosts') {
            $edge['animated'] = true;
            $edge['style']['strokeDasharray'] = '6 4';
        }

        return $edge;
    }

    private function percentage($used, $total): ?float
    {
        if ($used === null || $total === null || (float) $total <= 0.0) {
            return null;
        }

        return round(((float) $used / (float) $total) * 100, 1);
    }

    private function siteColor($site): string
    {
        if (! $site) {
            return '#707A8A';
        }

        $colors = [
            0 => '#3B82F6',
            1 => '#FCD535',
            2 => '#0ECB81',
            3 => '#F6465D',
            4 => '#2DBDb6',
            5 => '#8B5CF6',
            6 => '#F97316',
            7 => '#EC4899',
        ];

        return $colors[crc32($site->name) % count($colors)];
    }

    private function categoryIcon(string $category): string
    {
        return match (strtolower($category)) {
            'server' => 'server',
            'hypervisor' => 'hypervisor',
            'storage' => 'storage',
            'firewall' => 'firewall',
            'switch' => 'switch',
            'router' => 'router',
            'access point' => 'ap',
            'ups' => 'ups',
            'pdu' => 'pdu',
            default => 'device',
        };
    }

    private function assetNetworkRole(InventoryAsset $asset): string
    {
        $customFields = $asset->custom_fields ?? [];
        $explicitRole = strtolower(trim((string) ($customFields['role'] ?? '')));

        if ($explicitRole !== '') {
            return $explicitRole;
        }

        $category = strtolower($asset->category);
        $name = strtolower($asset->name);

        return match (true) {
            str_contains($name, 'core') && $category === 'switch' => 'core-switch',
            str_contains($name, 'distribution') && $category === 'switch' => 'distribution-switch',
            str_contains($name, 'access') && $category === 'switch' => 'access-switch',
            str_contains($name, 'edge') && $category === 'router' => 'edge-router',
            $category === 'router' => 'router',
            $category === 'switch' => 'switch',
            $category === 'hypervisor' => 'hypervisor',
            $category === 'server' => 'server',
            $category === 'nas' => 'nas',
            $category === 'nvr' => 'nvr',
            $category === 'cctv' => 'cctv',
            $category === 'access door' => 'access-door',
            $category === 'access point' => 'access-point',
            $category === 'printer' => 'printer',
            $category === 'pc' => 'pc',
            $category === 'laptop' => 'laptop',
            default => 'device',
        };
    }
}
