<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Site;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SiteController extends Controller
{
    public function index(): Response
    {
        $sites = Site::query()
            ->withCount([
                'integrations',
                'integrations as active_integrations_count' => fn ($query) => $query->where('is_active', true),
                'events as open_alerts_count' => fn ($query) => $query->where('status', 'open'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Site $site) => $this->presentSite($site));

        return Inertia::render('Settings/Sites/Index', [
            'sites' => $sites,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Settings/Sites/Create', [
            'defaultTimezone' => config('app.timezone', 'UTC'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:sites,code',
            'business_type' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'timezone' => 'required|string|timezone',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $site = Site::create($validated);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'site.create',
            targetType: 'site',
            targetId: $site->id,
            payload: [
                'name' => $site->name,
                'code' => $site->code,
                'business_type' => $site->business_type,
            ],
            ipAddress: $request->ip(),
            siteId: $site->id,
        );

        return redirect()->route('sites.index')
            ->with('success', "Site \"{$site->name}\" created successfully.");
    }

    public function edit(Site $site): Response
    {
        return Inertia::render('Settings/Sites/Edit', [
            'site' => $this->presentSite($site->loadCount([
                'integrations',
                'integrations as active_integrations_count' => fn ($query) => $query->where('is_active', true),
                'events as open_alerts_count' => fn ($query) => $query->where('status', 'open'),
            ])),
        ]);
    }

    public function update(Request $request, Site $site)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:100|unique:sites,code,' . $site->id,
            'business_type' => 'required|string|max:255',
            'address' => 'nullable|string|max:255',
            'timezone' => 'required|string|timezone',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $site->update($validated);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'site.update',
            targetType: 'site',
            targetId: $site->id,
            payload: [
                'name' => $site->name,
                'code' => $site->code,
                'business_type' => $site->business_type,
            ],
            ipAddress: $request->ip(),
            siteId: $site->id,
        );

        return redirect()->route('sites.index')
            ->with('success', "Site \"{$site->name}\" updated successfully.");
    }

    private function presentSite(Site $site): array
    {
        return [
            'id' => $site->id,
            'name' => $site->name,
            'code' => $site->code,
            'business_type' => $site->business_type,
            'timezone' => $site->timezone,
            'notes' => $site->notes,
            'is_active' => $site->is_active,
            'integrations_count' => $site->integrations_count ?? 0,
            'active_integrations_count' => $site->active_integrations_count ?? 0,
            'open_alerts_count' => $site->open_alerts_count ?? 0,
            'updated_at' => $site->updated_at?->diffForHumans(),
        ];
    }
}
