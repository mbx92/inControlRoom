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

class AlertController extends Controller
{
    use AppliesSiteScope;

    public function index(Request $request): Response
    {
        $query = Event::query()
            ->with(['integration.site', 'site', 'acknowledgedByUser'])
            ->orderByRaw("case when status = 'open' then 0 when status = 'acknowledged' then 1 else 2 end")
            ->orderByDesc('last_seen_at')
            ->orderByDesc('created_at');

        $this->applySiteScope($query);
        $this->applyRequestedSiteFilter($query, $request->query('site'), nullFilterValue: 'global');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->string('severity')->toString());
        }

        if ($request->filled('integration')) {
            $query->where('integration_id', $request->string('integration')->toString());
        }

        $alerts = $query->get()->map(fn (Event $event) => $this->presentAlert($event));

        return Inertia::render('Alerts/Index', [
            'alerts' => $alerts,
            'filters' => $request->only(['site', 'status', 'severity', 'integration']),
            'sites' => $this->siteOptions(),
            'integrations' => $this->integrationOptions(),
            'statusOptions' => [
                Event::STATUS_OPEN,
                Event::STATUS_ACKNOWLEDGED,
                Event::STATUS_RESOLVED,
            ],
            'severityOptions' => [
                Event::SEVERITY_CRITICAL,
                Event::SEVERITY_WARNING,
                Event::SEVERITY_INFO,
            ],
        ]);
    }

    public function show(Event $event): Response
    {
        $this->authorizeSiteAccess($event->site_id);
        $event->load(['integration.site', 'site', 'acknowledgedByUser']);

        return Inertia::render('Alerts/Show', [
            'alert' => $this->presentAlert($event, includeContext: true),
        ]);
    }

    public function acknowledge(Request $request, Event $event)
    {
        $this->authorizeSiteAccess($event->site_id);

        abort_if($event->status === Event::STATUS_RESOLVED, 422, 'Resolved alerts cannot be acknowledged.');

        $validated = $request->validate([
            'comment' => 'nullable|string|max:500',
        ]);

        $event->acknowledge($request->user(), $validated['comment'] ?? null);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'alert.acknowledge',
            targetType: 'event',
            targetId: (string) $event->id,
            payload: [
                'title' => $event->title,
                'severity' => $event->severity,
                'comment' => $validated['comment'] ?? null,
            ],
            ipAddress: $request->ip(),
            siteId: $event->site_id,
        );

        return redirect()->route('alerts.show', $event)
            ->with('success', 'Alert acknowledged successfully.');
    }

    private function presentAlert(Event $event, bool $includeContext = false): array
    {
        return [
            'id' => $event->id,
            'rule_key' => $event->rule_key,
            'severity' => $event->severity,
            'title' => $event->title,
            'message' => $event->message,
            'status' => $event->status,
            'integration_id' => $event->integration_id,
            'integration_name' => $event->integration?->name ?? 'Unknown',
            'site_label' => $event->site?->name ?? $event->integration?->site?->name ?? 'Global',
            'site_id' => $event->site_id,
            'acknowledged_by_name' => $event->acknowledgedByUser?->name,
            'acknowledged_at' => $event->acknowledged_at?->diffForHumans(),
            'acknowledged_at_full' => $event->acknowledged_at?->toDateTimeString(),
            'acknowledge_comment' => $event->acknowledge_comment,
            'first_seen_at' => $event->first_seen_at?->diffForHumans() ?? $event->created_at?->diffForHumans(),
            'first_seen_at_full' => $event->first_seen_at?->toDateTimeString() ?? $event->created_at?->toDateTimeString(),
            'last_seen_at' => $event->last_seen_at?->diffForHumans(),
            'last_seen_at_full' => $event->last_seen_at?->toDateTimeString(),
            'resolved_at' => $event->resolved_at?->diffForHumans(),
            'resolved_at_full' => $event->resolved_at?->toDateTimeString(),
            'context' => $includeContext ? ($event->context ?? []) : null,
        ];
    }

    private function siteOptions(): array
    {
        return $this->scopedSitesQuery()
            ->get(['id', 'name', 'code'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'code' => $site->code,
            ])
            ->all();
    }

    private function integrationOptions(): array
    {
        $query = Integration::query()->with('site')->orderBy('name');
        $this->applySiteScope($query);

        return $query->get()->map(fn (Integration $integration) => [
            'id' => $integration->id,
            'name' => $integration->name,
            'scope_label' => $integration->site?->name ?? 'Global',
        ])->all();
    }
}
