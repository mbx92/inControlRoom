<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesSiteScope;
use App\Models\AuditLog;
use App\Models\InventoryAsset;
use App\Models\Site;
use App\Services\Inventory\AssetReachabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssetMonitoringController extends Controller
{
    use AppliesSiteScope;

    public function index(Request $request): Response
    {
        $query = InventoryAsset::query()
            ->with('site')
            ->whereNotNull('primary_ip')
            ->where('primary_ip', '!=', '')
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $operator = $this->caseInsensitiveLikeOperator();
            $like = '%'.$search.'%';

            $query->where(function ($builder) use ($like, $operator) {
                $builder
                    ->where('name', $operator, $like)
                    ->orWhere('primary_ip', $operator, $like)
                    ->orWhere('asset_tag', $operator, $like)
                    ->orWhere('category', $operator, $like)
                    ->orWhere('location_label', $operator, $like);
            });
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();

            if ($status === InventoryAsset::REACHABILITY_UNKNOWN) {
                $query->where(function ($builder) {
                    $builder
                        ->where('reachability_status', InventoryAsset::REACHABILITY_UNKNOWN)
                        ->orWhereNull('reachability_status');
                });
            } else {
                $query->where('reachability_status', $status);
            }
        }

        $this->applySiteScope($query);
        $this->applyRequestedSiteFilter($query, $request->query('site'), nullFilterValue: 'unassigned');

        $statsQuery = clone $query;
        $statsAssets = $statsQuery->get([
            'id',
            'reachability_status',
            'reachability_fail_count',
        ]);

        $assets = $query
            ->paginate(25)
            ->withQueryString()
            ->through(fn (InventoryAsset $asset) => $this->presentAsset($asset));

        return Inertia::render('Inventory/Monitoring', [
            'assets' => $assets,
            'sites' => $this->siteOptions(),
            'statusOptions' => $this->statusOptions(),
            'filters' => $request->only(['search', 'site', 'status']),
            'stats' => [
                'total' => $statsAssets->count(),
                'online' => $statsAssets->where('reachability_status', InventoryAsset::REACHABILITY_ONLINE)->count(),
                'offline' => $statsAssets->where('reachability_status', InventoryAsset::REACHABILITY_OFFLINE)->count(),
                'unknown' => $statsAssets->where('reachability_status', InventoryAsset::REACHABILITY_UNKNOWN)->count()
                    + $statsAssets->whereNull('reachability_status')->count(),
                'flapping' => $statsAssets->filter(fn (InventoryAsset $asset) => $asset->reachability_fail_count >= 3)->count(),
            ],
        ]);
    }

    public function check(Request $request, InventoryAsset $asset, AssetReachabilityService $reachabilityService): RedirectResponse
    {
        $this->authorizeSiteAccess($asset->site_id);

        $asset = $reachabilityService->checkAndStore($asset);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'inventory_asset.reachability_check',
            targetType: 'inventory_asset',
            targetId: $asset->id,
            payload: [
                'name' => $asset->name,
                'primary_ip' => $asset->primary_ip,
                'status' => $asset->reachability_status,
            ],
            ipAddress: $request->ip(),
            siteId: $asset->site_id,
        );

        return back()->with(
            'success',
            "Reachability check completed for \"{$asset->name}\" ({$asset->reachability_status_label}).",
        );
    }

    private function presentAsset(InventoryAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'category' => $asset->category,
            'asset_tag' => $asset->asset_tag,
            'primary_ip' => $asset->primary_ip,
            'location_label' => $asset->location_label,
            'owner_name' => $asset->owner_name,
            'site_id' => $asset->site_id,
            'site' => $asset->site ? [
                'id' => $asset->site->id,
                'name' => $asset->site->name,
                'code' => $asset->site->code,
            ] : null,
            'scope_label' => $asset->site?->name ?? 'Unassigned',
            'monitoring_enabled' => $asset->monitoring_enabled,
            'reachability_status' => $asset->reachability_status ?? InventoryAsset::REACHABILITY_UNKNOWN,
            'reachability_status_label' => $asset->reachability_status_label,
            'reachability_checked_at' => $asset->reachability_checked_at?->format('Y-m-d H:i:s'),
            'reachability_checked_at_human' => $asset->reachability_checked_at?->diffForHumans(),
            'reachability_last_seen_at' => $asset->reachability_last_seen_at?->format('Y-m-d H:i:s'),
            'reachability_last_seen_at_human' => $asset->reachability_last_seen_at?->diffForHumans(),
            'reachability_latency_ms' => $asset->reachability_latency_ms,
            'reachability_fail_count' => $asset->reachability_fail_count,
            'reachability_message' => $asset->reachability_message,
            'show_url' => route('inventory.show', $asset->id),
        ];
    }

    private function statusOptions(): array
    {
        return collect(InventoryAsset::REACHABILITY_STATUSES)
            ->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
            ->all();
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

    private function caseInsensitiveLikeOperator(): string
    {
        return InventoryAsset::query()->getConnection()->getDriverName() === 'pgsql'
            ? 'ilike'
            : 'like';
    }
}
