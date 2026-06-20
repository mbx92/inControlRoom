<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Site;
use App\Models\VaultEntry;
use App\Models\VaultEntryAccessLog;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VaultEntryController extends Controller
{
    use \App\Http\Controllers\Concerns\AppliesSiteScope;
    public function index(Request $request): Response
    {
        $query = VaultEntry::query()
            ->with(['site', 'integrations'])
            ->orderBy('name');

        $this->applySiteScope($query);
        $this->applyRequestedSiteFilter($query, $request->query('site'), nullFilterValue: 'global');

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            $query->where('is_active', $status === 'active');
        }

        $entries = $query
            ->get()
            ->map(fn (VaultEntry $entry) => $this->presentVaultEntry($entry));

        return Inertia::render('Settings/Vault/Index', [
            'entries' => $entries,
            'sites' => $this->siteOptions(),
            'kindOptions' => VaultEntry::KINDS,
            'filters' => $request->only(['site', 'status']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Settings/Vault/Create', [
            'sites' => $this->siteOptions(),
            'kindOptions' => VaultEntry::KINDS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'site_id' => 'nullable|exists:sites,id',
            'name' => 'required|string|max:255',
            'kind' => 'required|string|in:'.implode(',', array_keys(VaultEntry::KINDS)),
            'secret' => 'required|string',
            'public_key' => 'nullable|string',
            'fingerprint' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'rotation_interval_days' => 'nullable|integer|min:1|max:3650',
            'last_rotated_at' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $entry = VaultEntry::create([
            'site_id' => $validated['site_id'] ?? null,
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'ciphertext' => $validated['secret'],
            'public_key' => $this->normalizeNullableString($validated['public_key'] ?? null),
            'fingerprint' => $this->resolveFingerprint($validated['public_key'] ?? null, $validated['fingerprint'] ?? null),
            'notes' => $validated['notes'] ?? null,
            'rotation_interval_days' => $validated['rotation_interval_days'] ?? null,
            'last_rotated_at' => $validated['last_rotated_at'] ?? now(),
            'is_active' => (bool) ($validated['is_active'] ?? true),
        ]);

        $this->recordVaultAction($request, $entry, 'create');

        return redirect()->route('vault.index')
            ->with('success', "Vault entry \"{$entry->name}\" created successfully.");
    }

    public function show(Request $request, VaultEntry $vault): Response
    {
        $this->authorizeSiteAccess($vault->site_id);
        $vault->load([
            'site',
            'integrations.site',
            'accessLogs.user',
        ]);

        return Inertia::render('Settings/Vault/Show', [
            'entry' => $this->presentVaultEntry($vault, includeRelations: true),
            'revealedSecret' => $request->session()->get('revealed_secret'),
        ]);
    }

    public function edit(VaultEntry $vault): Response
    {
        $this->authorizeSiteAccess($vault->site_id);

        return Inertia::render('Settings/Vault/Edit', [
            'entry' => $this->presentVaultEntry($vault->load('site'), includeRelations: true),
            'sites' => $this->siteOptions(),
            'kindOptions' => VaultEntry::KINDS,
        ]);
    }

    public function update(Request $request, VaultEntry $vault)
    {
        $this->authorizeSiteAccess($vault->site_id);
        $validated = $request->validate([
            'site_id' => 'nullable|exists:sites,id',
            'name' => 'required|string|max:255',
            'kind' => 'required|string|in:'.implode(',', array_keys(VaultEntry::KINDS)),
            'secret' => 'nullable|string',
            'public_key' => 'nullable|string',
            'fingerprint' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'rotation_interval_days' => 'nullable|integer|min:1|max:3650',
            'last_rotated_at' => 'nullable|date',
            'is_active' => 'boolean',
        ]);

        $payload = [
            'site_id' => $validated['site_id'] ?? null,
            'name' => $validated['name'],
            'kind' => $validated['kind'],
            'public_key' => $this->normalizeNullableString($validated['public_key'] ?? null),
            'fingerprint' => $this->resolveFingerprint($validated['public_key'] ?? null, $validated['fingerprint'] ?? null),
            'notes' => $validated['notes'] ?? null,
            'rotation_interval_days' => $validated['rotation_interval_days'] ?? null,
            'last_rotated_at' => $validated['last_rotated_at'] ?? $vault->last_rotated_at,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ];

        if (array_key_exists('secret', $validated) && $validated['secret'] !== null && trim($validated['secret']) !== '') {
            $payload['ciphertext'] = $validated['secret'];
            $payload['last_rotated_at'] = $validated['last_rotated_at'] ?? now();
        }

        $vault->update($payload);

        $this->recordVaultAction($request, $vault, 'update');

        return redirect()->route('vault.show', $vault)
            ->with('success', "Vault entry \"{$vault->name}\" updated successfully.");
    }

    public function reveal(Request $request, VaultEntry $vault)
    {
        $this->authorizeSiteAccess($vault->site_id);

        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $this->recordVaultAction($request, $vault, 'reveal');

        return redirect()->route('vault.show', $vault)
            ->with('success', "Vault entry \"{$vault->name}\" revealed.")
            ->with('revealed_secret', $vault->revealSecret());
    }

    private function recordVaultAction(Request $request, VaultEntry $entry, string $action): void
    {
        VaultEntryAccessLog::create([
            'vault_entry_id' => $entry->id,
            'user_id' => $request->user()?->id,
            'action' => $action,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'vault.'.$action,
            targetType: 'vault_entry',
            targetId: $entry->id,
            payload: [
                'name' => $entry->name,
                'kind' => $entry->kind,
                'site_id' => $entry->site_id,
            ],
            ipAddress: $request->ip(),
            siteId: $entry->site_id,
        );
    }

    private function presentVaultEntry(VaultEntry $entry, bool $includeRelations = false): array
    {
        return [
            'id' => $entry->id,
            'name' => $entry->name,
            'kind' => $entry->kind,
            'kind_label' => $entry->kind_label,
            'site_id' => $entry->site_id,
            'site' => $entry->site ? [
                'id' => $entry->site->id,
                'name' => $entry->site->name,
                'code' => $entry->site->code,
            ] : null,
            'scope_label' => $entry->site?->name ?? 'Global',
            'scope_kind' => $entry->site ? 'site' : 'global',
            'public_key' => $entry->public_key,
            'fingerprint' => $entry->fingerprint,
            'notes' => $entry->notes,
            'rotation_interval_days' => $entry->rotation_interval_days,
            'last_rotated_at' => $entry->last_rotated_at?->format('Y-m-d'),
            'last_rotated_human' => $entry->last_rotated_at?->diffForHumans(),
            'is_active' => $entry->is_active,
            'masked_preview' => str_repeat('•', 12),
            'updated_at' => $entry->updated_at?->diffForHumans(),
            'integrations_count' => $entry->relationLoaded('integrations')
                ? $entry->integrations->count()
                : $entry->integrations()->count(),
            'integrations' => $includeRelations
                ? $entry->integrations->map(fn ($integration) => [
                    'id' => $integration->id,
                    'name' => $integration->name,
                    'type_name' => $integration->type_name,
                    'scope_label' => $integration->site?->name ?? 'Global',
                ])->values()->all()
                : [],
            'access_logs' => $includeRelations
                ? $entry->accessLogs
                    ->sortByDesc('created_at')
                    ->take(12)
                    ->map(fn (VaultEntryAccessLog $log) => [
                        'id' => $log->id,
                        'action' => $log->action,
                        'user_name' => $log->user?->name ?? 'System',
                        'ip_address' => $log->ip_address,
                        'created_at' => $log->created_at?->diffForHumans(),
                        'created_at_full' => $log->created_at?->toDateTimeString(),
                    ])
                    ->values()
                    ->all()
                : [],
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

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function resolveFingerprint(?string $publicKey, ?string $fingerprint): ?string
    {
        $normalizedPublicKey = $this->normalizeNullableString($publicKey);
        $derivedFingerprint = $this->deriveSshFingerprint($normalizedPublicKey);

        if ($derivedFingerprint !== null) {
            return $derivedFingerprint;
        }

        return $this->normalizeNullableString($fingerprint);
    }

    private function deriveSshFingerprint(?string $publicKey): ?string
    {
        if ($publicKey === null) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($publicKey));

        if (! is_array($parts) || count($parts) < 2) {
            return null;
        }

        $decodedKey = base64_decode($parts[1], true);

        if ($decodedKey === false) {
            return null;
        }

        $hash = base64_encode(hash('sha256', $decodedKey, true));

        return 'SHA256:'.rtrim($hash, '=');
    }
}
