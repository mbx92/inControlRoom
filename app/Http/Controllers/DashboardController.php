<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AppliesSiteScope;
use App\Models\AuditLog;
use App\Models\Event;
use App\Models\Integration;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    use AppliesSiteScope;

    public function __invoke(Request $request): Response
    {
        $integrationQuery = Integration::query()
            ->with('site')
            ->where('is_active', true);

        $eventQuery = Event::query()
            ->with(['integration.site', 'site'])
            ->where('status', 'open');

        $activityQuery = AuditLog::query()
            ->with(['user', 'site'])
            ->orderByDesc('created_at');

        $this->applySiteScope($integrationQuery);
        $this->applySiteScope($eventQuery);
        $this->applySiteScope($activityQuery);

        $integrations = $integrationQuery
            ->orderByDesc('updated_at')
            ->orderByDesc('last_tested_at')
            ->get();

        $openEvents = $eventQuery->get();

        $stats = [
            'total_integrations' => $integrations->count(),
            'active_alerts' => $openEvents->count(),
            'critical_alerts' => $openEvents->where('severity', 'critical')->count(),
            'warning_alerts' => $openEvents->where('severity', 'warning')->count(),
            'healthy_integrations' => $integrations->where('last_test_status', 'success')->count(),
            'failing_integrations' => $integrations->where('last_test_status', 'failure')->count(),
        ];

        $recentActivity = $activityQuery
            ->limit(5)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'result' => $log->result,
                'user_name' => $log->user?->name ?? 'System',
                'site_label' => $log->site?->name ?? 'Global',
                'created_at' => $log->created_at->diffForHumans(),
                'created_at_full' => $log->created_at->toDateTimeString(),
            ]);

        $recentAlerts = $openEvents
            ->sortByDesc('created_at')
            ->take(5)
            ->map(fn (Event $event) => [
                'id' => $event->id,
                'severity' => $event->severity,
                'title' => $event->title,
                'message' => $event->message,
                'integration_name' => $event->integration?->name ?? 'Unknown',
                'integration_type' => $event->integration?->type ?? 'unknown',
                'site_label' => $event->site?->name ?? $event->integration?->site?->name ?? 'Global',
                'created_at' => $event->created_at->diffForHumans(),
            ])
            ->values();

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'integrations' => $integrations->map(fn (Integration $integration) => $this->presentIntegration($integration)),
            'recentActivity' => $recentActivity,
            'recentAlerts' => $recentAlerts,
            'sites' => $this->siteOptions(),
        ]);
    }

    private function presentIntegration(Integration $integration): array
    {
        return [
            'id' => $integration->id,
            'type' => $integration->type,
            'type_name' => $integration->type_name,
            'name' => $integration->name,
            'site_id' => $integration->site_id,
            'scope_label' => $integration->site?->name ?? 'Global',
            'scope_kind' => $integration->site ? 'site' : 'global',
            'is_active' => $integration->is_active,
            'last_synced_at' => $integration->last_synced_at?->diffForHumans(),
            'last_tested_at' => $integration->last_tested_at?->diffForHumans(),
            'last_test_status' => $integration->last_test_status,
            'last_test_message' => $integration->last_test_message,
            'source_summary' => $this->buildSourceSummary($integration),
            'api_health' => $this->buildApiHealth($integration),
        ];
    }

    private function buildSourceSummary(Integration $integration): ?array
    {
        $meta = $integration->last_test_meta ?? [];

        if ($integration->type !== 'proxmox' || empty($meta)) {
            return null;
        }

        return [
            'headline' => trim(collect([$meta['product'] ?? null, $meta['version'] ?? null])->filter()->implode(' ')),
            'release' => $meta['release'] ?? null,
            'node_count' => $meta['node_count'] ?? null,
            'vm_count' => $meta['vm_count'] ?? null,
            'ct_count' => $meta['ct_count'] ?? null,
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
            'endpoint' => $meta['health_endpoint'] ?? ($integration->type === 'proxmox'
                ? rtrim($integration->base_url, '/').'/api2/json/version'
                : $integration->base_url),
            'reachable' => $meta['api_reachable'] ?? ($status === 'success'),
            'auth_status' => $meta['auth_status'] ?? ($status === 'success' ? 'valid' : 'unknown'),
            'latency_ms' => $meta['latency_ms'] ?? null,
            'version' => $meta['version'] ?? null,
            'http_status' => $meta['http_status'] ?? null,
        ];
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
}
