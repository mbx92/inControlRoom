<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesSiteScope;
use App\Http\Controllers\Concerns\PresentsAgentInventoryLink;
use App\Models\Agent;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AgentMetricsController extends Controller
{
    use AppliesSiteScope;
    use PresentsAgentInventoryLink;

    public function index(Request $request): Response
    {
        $query = Agent::query()
            ->with(['site:id,name,code', 'enrollmentToken:id,name', 'inventoryAsset:id,name,asset_tag,category,primary_ip,status'])
            ->latest('last_seen_at')
            ->latest('created_at');

        $this->applySiteScope($query);
        $this->applyRequestedSiteFilter($query, $request->query('site'));

        $statsQuery = clone $query;
        $statsAgents = $statsQuery->get(['id', 'last_seen_at', 'last_metrics']);

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $operator = $this->caseInsensitiveLikeOperator();
            $like = '%'.$search.'%';

            $query->where(function ($builder) use ($like, $operator) {
                $builder
                    ->where('hostname', $operator, $like)
                    ->orWhere('primary_ip', $operator, $like)
                    ->orWhere('device_id', $operator, $like);
            });
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();

            if ($status === 'online') {
                $query->where('last_seen_at', '>=', now()->subMinutes(5));
            } elseif ($status === 'idle') {
                $query->whereNotNull('last_seen_at')
                    ->where('last_seen_at', '<', now()->subMinutes(5));
            } elseif ($status === 'never_seen') {
                $query->whereNull('last_seen_at');
            }
        }

        $agents = $query
            ->get()
            ->map(fn (Agent $agent) => $this->presentAgentMetricsSummary($agent))
            ->all();

        return Inertia::render('Agents/Metrics/Index', [
            'agents' => $agents,
            'sites' => $this->scopedSitesQuery()
                ->get(['id', 'name', 'code'])
                ->map(fn (Site $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                    'code' => $site->code,
                ])
                ->all(),
            'filters' => [
                'search' => $request->query('search', ''),
                'site' => $request->query('site', ''),
                'status' => $request->query('status', ''),
            ],
            'stats' => $this->buildStats($statsAgents),
        ]);
    }

    public function show(Agent $agent): Response
    {
        $agent->load([
            'site:id,name,code',
            'enrollmentToken:id,name',
            'inventoryAsset:id,name,asset_tag,category,primary_ip,status',
        ]);
        $this->authorizeSiteAccess($agent->site_id, 'You do not have access to this agent.');

        return Inertia::render('Agents/Metrics/Show', [
            'agent' => $this->presentAgentMetricsDetail($agent),
            'inventoryAssets' => $this->inventoryAssetLinkOptions($agent->site_id, $agent->id),
            'canManageInventoryLink' => request()->user()?->isAdmin() ?? false,
        ]);
    }

    private function buildStats(iterable $agents): array
    {
        $collection = collect($agents);

        return [
            'total' => $collection->count(),
            'online' => $collection->filter(fn (Agent $agent) => $this->agentStatus($agent) === 'Online')->count(),
            'with_metrics' => $collection->filter(fn (Agent $agent) => ! empty($agent->last_metrics))->count(),
            'high_cpu' => $collection->filter(function (Agent $agent) {
                $cpu = data_get($agent->last_metrics, 'cpu.usage_percent');

                return $cpu !== null && (float) $cpu >= 80;
            })->count(),
        ];
    }

    private function presentAgentMetricsSummary(Agent $agent): array
    {
        $metrics = $this->extractMetrics($agent->last_metrics);

        return [
            'id' => $agent->id,
            'site_name' => $agent->site?->name ?? 'Unknown site',
            'hostname' => $agent->hostname,
            'device_id' => $agent->device_id,
            'primary_ip' => $agent->primary_ip,
            'os' => trim(collect([$agent->os, $agent->os_version])->filter()->implode(' ')),
            'arch' => $agent->arch,
            'agent_version' => $agent->agent_version,
            'status' => $this->agentStatus($agent),
            'last_seen_at' => optional($agent->last_seen_at)?->toIso8601String(),
            'last_heartbeat_at' => optional($agent->last_heartbeat_at)?->toIso8601String(),
            'token_name' => $agent->enrollmentToken?->name,
            'cpu_usage_percent' => $metrics['cpu_usage_percent'],
            'cpu_brand' => $metrics['cpu_brand'],
            'memory_used_percent' => $metrics['memory_used_percent'],
            'memory_total_bytes' => $metrics['memory_total_bytes'],
            'memory_used_bytes' => $metrics['memory_used_bytes'],
            'storage_total_bytes' => $metrics['storage_total_bytes'],
            'storage_used_percent' => $metrics['storage_used_percent'],
            'physical_storage_total_bytes' => $metrics['physical_storage_total_bytes'],
            'worst_disk_used_percent' => $metrics['worst_disk_used_percent'],
            'has_metrics' => $metrics['has_metrics'],
            'services_count' => count($agent->last_services ?? []),
            'inventory_asset' => $this->presentInventoryAssetLink($agent->inventoryAsset),
        ];
    }

    private function presentAgentMetricsDetail(Agent $agent): array
    {
        $metrics = $this->extractMetrics($agent->last_metrics);

        return [
            'id' => $agent->id,
            'site_id' => $agent->site_id,
            'site_name' => $agent->site?->name ?? 'Unknown site',
            'site_code' => $agent->site?->code,
            'hostname' => $agent->hostname,
            'device_id' => $agent->device_id,
            'primary_ip' => $agent->primary_ip,
            'last_ip_address' => $agent->last_ip_address,
            'os' => $agent->os,
            'os_version' => $agent->os_version,
            'arch' => $agent->arch,
            'agent_version' => $agent->agent_version,
            'status' => $this->agentStatus($agent),
            'labels' => $agent->labels ?? [],
            'last_seen_at' => optional($agent->last_seen_at)?->toIso8601String(),
            'last_heartbeat_at' => optional($agent->last_heartbeat_at)?->toIso8601String(),
            'enrolled_at' => optional($agent->enrolled_at)?->toIso8601String(),
            'token_name' => $agent->enrollmentToken?->name,
            'inventory_asset' => $this->presentInventoryAssetLink($agent->inventoryAsset),
            'metrics' => $metrics,
            'services' => collect($agent->last_services ?? [])
                ->map(function ($service) {
                    $service = is_array($service) ? $service : (array) $service;

                    return [
                        'name' => $service['name'] ?? '-',
                        'display_name' => $service['display_name'] ?? $service['displayName'] ?? $service['name'] ?? '-',
                        'status' => $this->normalizeServiceStatus($service['status'] ?? $service['state'] ?? 'Unknown'),
                        'start_mode' => $this->normalizeStartMode($service['start_mode'] ?? $service['startMode'] ?? $service['start_type'] ?? 'Unknown'),
                    ];
                })
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
        ];
    }

    private function extractMetrics(?array $rawMetrics): array
    {
        $metrics = $rawMetrics ?? [];
        $cpu = data_get($metrics, 'cpu', []);
        $memory = data_get($metrics, 'memory', []);
        $totalBytes = (int) ($memory['total_bytes'] ?? 0);
        $freeBytes = (int) ($memory['free_bytes'] ?? 0);
        $usedBytes = max(0, $totalBytes - $freeBytes);
        $usedPercent = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : null;

        $memorySlots = collect($memory['slots'] ?? [])
            ->map(function (array $slot) {
                return [
                    'slot' => $slot['slot'] ?? '-',
                    'size_bytes' => (int) ($slot['size_bytes'] ?? 0),
                    'type' => $slot['type'] ?? null,
                    'speed_mhz' => $slot['speed_mhz'] ?? null,
                    'manufacturer' => $slot['manufacturer'] ?? null,
                    'part_number' => $slot['part_number'] ?? null,
                    'form_factor' => $slot['form_factor'] ?? null,
                    'serial_number' => $slot['serial_number'] ?? null,
                ];
            })
            ->values()
            ->all();

        $disks = collect(data_get($metrics, 'disks', []))
            ->map(function (array $disk) {
                $total = (int) ($disk['total_bytes'] ?? 0);
                $free = (int) ($disk['free_bytes'] ?? 0);
                $used = max(0, $total - $free);

                return [
                    'name' => $disk['name'] ?? '-',
                    'total_bytes' => $total,
                    'free_bytes' => $free,
                    'used_bytes' => $used,
                    'used_percent' => $total > 0 ? round(($used / $total) * 100, 1) : null,
                    'filesystem' => $disk['filesystem'] ?? null,
                    'type' => $disk['type'] ?? null,
                ];
            })
            ->values()
            ->all();

        $storageDevices = collect(data_get($metrics, 'storage_devices', []))
            ->map(fn (array $device) => [
                'device' => $device['device'] ?? null,
                'name' => $device['name'] ?? '-',
                'type' => $device['type'] ?? null,
                'vendor' => $device['vendor'] ?? null,
                'size_bytes' => (int) ($device['size_bytes'] ?? 0),
                'interface_type' => $device['interface_type'] ?? null,
                'serial_number' => $device['serial_number'] ?? null,
                'firmware_revision' => $device['firmware_revision'] ?? null,
                'smart_status' => $device['smart_status'] ?? null,
            ])
            ->values()
            ->all();

        $network = collect(data_get($metrics, 'network', []))
            ->map(fn (array $entry) => [
                'iface' => $entry['iface'] ?? '-',
                'operstate' => $entry['operstate'] ?? 'unknown',
                'mac' => $entry['mac'] ?? null,
                'ipv4' => $entry['ipv4'] ?? null,
                'speed_mbps' => $entry['speed_mbps'] ?? null,
                'rx_bytes' => (int) ($entry['rx_bytes'] ?? 0),
                'tx_bytes' => (int) ($entry['tx_bytes'] ?? 0),
                'rx_errors' => (int) ($entry['rx_errors'] ?? 0),
                'tx_errors' => (int) ($entry['tx_errors'] ?? 0),
                'rx_dropped' => (int) ($entry['rx_dropped'] ?? 0),
                'tx_dropped' => (int) ($entry['tx_dropped'] ?? 0),
            ])
            ->values()
            ->all();

        $usbDevices = collect(data_get($metrics, 'usb_devices', []))
            ->map(fn (array $device) => [
                'name' => $device['name'] ?? '-',
                'type' => $device['type'] ?? null,
                'vendor' => $device['vendor'] ?? null,
                'manufacturer' => $device['manufacturer'] ?? null,
                'device_id' => $device['device_id'] ?? null,
                'serial_number' => $device['serial_number'] ?? null,
            ])
            ->values()
            ->all();

        $memorySlotsTotalBytes = collect($memorySlots)->sum('size_bytes');
        if ($totalBytes === 0 && $memorySlotsTotalBytes > 0) {
            $totalBytes = $memorySlotsTotalBytes;
            if ($freeBytes > $totalBytes) {
                $freeBytes = 0;
            }
            $usedBytes = max(0, $totalBytes - $freeBytes);
            $usedPercent = $totalBytes > 0 ? round(($usedBytes / $totalBytes) * 100, 1) : null;
        }

        $storageTotalBytes = (int) collect($disks)->sum('total_bytes');
        $storageUsedBytes = (int) collect($disks)->sum('used_bytes');
        $storageFreeBytes = (int) collect($disks)->sum('free_bytes');
        $storageUsedPercent = $storageTotalBytes > 0
            ? round(($storageUsedBytes / $storageTotalBytes) * 100, 1)
            : null;
        $physicalStorageTotalBytes = (int) collect($storageDevices)->sum('size_bytes');

        return [
            'cpu_usage_percent' => data_get($cpu, 'usage_percent') !== null ? round((float) data_get($cpu, 'usage_percent'), 1) : null,
            'cpu_brand' => data_get($cpu, 'brand'),
            'cpu_manufacturer' => data_get($cpu, 'manufacturer'),
            'cpu_cores' => data_get($cpu, 'cores'),
            'cpu_physical_cores' => data_get($cpu, 'physical_cores'),
            'memory_total_bytes' => $totalBytes,
            'memory_free_bytes' => $freeBytes,
            'memory_used_bytes' => $usedBytes,
            'memory_used_percent' => $usedPercent,
            'memory_slots' => $memorySlots,
            'memory_slots_used' => (int) ($memory['slots_used'] ?? count($memorySlots)),
            'memory_installed_bytes' => $memorySlotsTotalBytes,
            'disks' => $disks,
            'storage_total_bytes' => $storageTotalBytes,
            'storage_used_bytes' => $storageUsedBytes,
            'storage_free_bytes' => $storageFreeBytes,
            'storage_used_percent' => $storageUsedPercent,
            'storage_devices' => $storageDevices,
            'physical_storage_total_bytes' => $physicalStorageTotalBytes,
            'network' => $network,
            'usb_devices' => $usbDevices,
            'worst_disk_used_percent' => collect($disks)->pluck('used_percent')->filter()->max(),
            'has_metrics' => $metrics !== [],
        ];
    }

    private function agentStatus(Agent $agent): string
    {
        if ($agent->last_seen_at === null) {
            return 'Never Seen';
        }

        return $agent->last_seen_at->gt(now()->subMinutes(5)) ? 'Online' : 'Idle';
    }

    private function caseInsensitiveLikeOperator(): string
    {
        return config('database.default') === 'pgsql' ? 'ilike' : 'like';
    }

    private function normalizeServiceStatus(mixed $value): string
    {
        $status = trim((string) $value);

        if ($status === '') {
            return 'Unknown';
        }

        if (is_numeric($status)) {
            return (int) $status === 4 ? 'Running' : 'Stopped';
        }

        return ucfirst(strtolower($status));
    }

    private function normalizeStartMode(mixed $value): string
    {
        $mode = trim((string) $value);

        if ($mode === '' || $mode === 'Unknown') {
            return 'Unknown';
        }

        return match ($mode) {
            '2', 'Auto', 'Automatic' => 'Automatic',
            '3', 'Manual' => 'Manual',
            '4', 'Disabled' => 'Disabled',
            default => ucfirst(strtolower($mode)),
        };
    }
}
