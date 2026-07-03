<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\PresentsAgentInventoryLink;
use App\Models\Agent;
use App\Models\AgentEnrollmentToken;
use App\Models\AuditLog;
use App\Models\InventoryAsset;
use App\Models\Site;
use App\Services\Agent\AgentInstallerStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AgentController extends Controller
{
    use PresentsAgentInventoryLink;

    public function __construct(
        private readonly AgentInstallerStorage $installerStorage,
    ) {}

    public function index(): Response
    {
        abort_unless(request()->user()?->isAdmin(), 403);

        $tokens = AgentEnrollmentToken::query()
            ->with(['site:id,name,code', 'creator:id,name'])
            ->latest()
            ->get()
            ->map(fn (AgentEnrollmentToken $token) => $this->presentEnrollmentToken($token))
            ->all();

        $agents = Agent::query()
            ->with(['site:id,name,code', 'enrollmentToken:id,name', 'inventoryAsset:id,name,asset_tag,category,primary_ip,status'])
            ->latest('last_seen_at')
            ->latest('created_at')
            ->get()
            ->map(fn (Agent $agent) => $this->presentAgent($agent))
            ->all();

        $sites = Site::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(fn (Site $site) => [
                'id' => $site->id,
                'name' => $site->name,
                'code' => $site->code,
            ])
            ->all();

        return Inertia::render('Settings/Agents/Index', [
            'tokens' => $tokens,
            'agents' => $agents,
            'sites' => $sites,
            'inventoryAssets' => InventoryAsset::query()
                ->with('agent:id,inventory_asset_id,hostname')
                ->orderBy('name')
                ->get(['id', 'site_id', 'name', 'asset_tag', 'category', 'primary_ip'])
                ->map(fn (InventoryAsset $asset) => [
                    'id' => $asset->id,
                    'site_id' => $asset->site_id,
                    'name' => $asset->name,
                    'asset_tag' => $asset->asset_tag,
                    'category' => $asset->category,
                    'primary_ip' => $asset->primary_ip,
                    'linked_agent_id' => $asset->agent?->id,
                ])
                ->all(),
            'generatedToken' => request()->session()->get('generated_agent_enrollment_token'),
            'installer' => $this->installerStorage->present(),
        ]);
    }

    public function downloadInstaller(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($this->installerStorage->isConfigured(), 503, 'Agent installer storage is not configured.');
        abort_unless($this->installerStorage->exists(), 404, 'Agent installer is not available yet.');

        AuditLog::record(
            userId: $request->user()->id,
            action: 'agent.installer.download',
            targetType: 'agent_installer',
            targetId: null,
            payload: [
                'filename' => config('agent.installer.filename'),
                'version' => config('agent.installer.version'),
            ],
        );

        return $this->installerStorage->downloadResponse();
    }

    public function updateInventoryLink(Request $request, Agent $agent): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'inventory_asset_id' => ['nullable', 'uuid', 'exists:inventory_assets,id'],
        ]);

        $assetId = $validated['inventory_asset_id'] ?? null;
        $previousAssetId = $agent->inventory_asset_id;

        if ($assetId !== null) {
            $asset = InventoryAsset::query()->findOrFail($assetId);

            if ($asset->site_id !== $agent->site_id) {
                throw ValidationException::withMessages([
                    'inventory_asset_id' => 'Inventory asset must belong to the same site as the agent.',
                ]);
            }

            $alreadyLinked = Agent::query()
                ->where('inventory_asset_id', $assetId)
                ->where('id', '!=', $agent->id)
                ->exists();

            if ($alreadyLinked) {
                throw ValidationException::withMessages([
                    'inventory_asset_id' => 'This inventory asset is already linked to another agent.',
                ]);
            }
        }

        $agent->forceFill([
            'inventory_asset_id' => $assetId,
        ])->save();

        AuditLog::record(
            userId: $request->user()->id,
            action: 'agent.inventory-link.update',
            targetType: 'agent',
            targetId: $agent->id,
            payload: [
                'site_id' => $agent->site_id,
                'hostname' => $agent->hostname,
                'previous_inventory_asset_id' => $previousAssetId,
                'inventory_asset_id' => $assetId,
            ],
            ipAddress: $request->ip(),
            siteId: $agent->site_id,
        );

        return redirect()
            ->back()
            ->with('success', $assetId === null ? 'Agent inventory link removed.' : 'Agent linked to inventory asset.');
    }

    public function storeToken(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $validated = $request->validate([
            'site_id' => ['required', 'string', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:255'],
            'expires_in_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'max_uses' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $plainToken = 'enroll_'.Str::random(40);

        $token = AgentEnrollmentToken::create([
            'site_id' => $validated['site_id'],
            'name' => $validated['name'],
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHours((int) $validated['expires_in_hours']),
            'max_uses' => (int) $validated['max_uses'],
            'used_count' => 0,
            'created_by' => $request->user()?->id,
        ]);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'agent.enrollment-token.create',
            targetType: 'agent_enrollment_token',
            targetId: $token->id,
            payload: [
                'site_id' => $token->site_id,
                'name' => $token->name,
                'expires_at' => optional($token->expires_at)?->toIso8601String(),
                'max_uses' => $token->max_uses,
            ],
            ipAddress: $request->ip(),
            siteId: $token->site_id,
        );

        return redirect()
            ->route('settings.agents.index')
            ->with('success', 'Enrollment token created. Copy it now because the full token is only shown once.')
            ->with('generated_agent_enrollment_token', $plainToken);
    }

    public function revokeToken(Request $request, AgentEnrollmentToken $agentEnrollmentToken): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        if ($agentEnrollmentToken->revoked_at === null) {
            $agentEnrollmentToken->forceFill([
                'revoked_at' => now(),
            ])->save();

            AuditLog::record(
                userId: $request->user()->id,
                action: 'agent.enrollment-token.revoke',
                targetType: 'agent_enrollment_token',
                targetId: $agentEnrollmentToken->id,
                payload: [
                    'site_id' => $agentEnrollmentToken->site_id,
                    'name' => $agentEnrollmentToken->name,
                ],
                ipAddress: $request->ip(),
                siteId: $agentEnrollmentToken->site_id,
            );
        }

        return redirect()
            ->route('settings.agents.index')
            ->with('success', 'Enrollment token revoked.');
    }

    public function enroll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'enroll_token' => ['required', 'string'],
            'device_id' => ['required', 'string', 'max:255'],
            'hostname' => ['required', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'arch' => ['nullable', 'string', 'max:255'],
            'agent_version' => ['nullable', 'string', 'max:255'],
            'primary_ip' => ['nullable', 'string', 'max:255'],
            'inventory_asset_id' => ['nullable', 'uuid', 'exists:inventory_assets,id'],
        ]);

        $token = AgentEnrollmentToken::query()
            ->where('token_hash', hash('sha256', $validated['enroll_token']))
            ->with('site')
            ->first();

        if (! $token || ! $token->isAvailable()) {
            return response()->json([
                'message' => 'Enrollment token is invalid, expired, exhausted, or revoked.',
            ], 422);
        }

        $inventoryAssetId = $validated['inventory_asset_id'] ?? null;

        if ($inventoryAssetId !== null) {
            $asset = InventoryAsset::query()->find($inventoryAssetId);

            if ($asset === null || $asset->site_id !== $token->site_id) {
                return response()->json([
                    'message' => 'Inventory asset is invalid for this enrollment site.',
                ], 422);
            }

            $alreadyLinked = Agent::query()
                ->where('inventory_asset_id', $inventoryAssetId)
                ->exists();

            if ($alreadyLinked) {
                return response()->json([
                    'message' => 'Inventory asset is already linked to another agent.',
                ], 422);
            }
        }

        $plainAgentToken = 'agent_'.Str::random(48);

        $agent = DB::transaction(function () use ($request, $validated, $token, $plainAgentToken) {
            $agent = Agent::query()
                ->where('site_id', $token->site_id)
                ->where('device_id', $validated['device_id'])
                ->first();

            if (! $agent) {
                $agent = new Agent([
                    'site_id' => $token->site_id,
                    'device_id' => $validated['device_id'],
                ]);
            }

            $agent->fill([
                'site_id' => $token->site_id,
                'enrollment_token_id' => $token->id,
                'hostname' => $validated['hostname'],
                'device_id' => $validated['device_id'],
                'os' => $validated['os'] ?? null,
                'os_version' => $validated['os_version'] ?? null,
                'arch' => $validated['arch'] ?? null,
                'primary_ip' => $validated['primary_ip'] ?? null,
                'agent_version' => $validated['agent_version'] ?? null,
                'agent_token_hash' => hash('sha256', $plainAgentToken),
                'inventory_asset_id' => $inventoryAssetId,
                'enrolled_at' => $agent->enrolled_at ?? now(),
                'last_seen_at' => now(),
                'last_ip_address' => $request->ip(),
                'is_active' => true,
            ]);
            $agent->save();

            $token->forceFill([
                'used_count' => $token->used_count + 1,
                'last_used_at' => now(),
            ])->save();

            return $agent;
        });

        return response()->json([
            'agent_id' => $agent->id,
            'agent_token' => $plainAgentToken,
            'site_id' => $agent->site_id,
            'interval_seconds' => 30,
        ]);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $bearerToken = (string) $request->bearerToken();

        if ($bearerToken === '') {
            return response()->json([
                'message' => 'Missing agent bearer token.',
            ], 401);
        }

        $agent = Agent::query()
            ->where('agent_token_hash', hash('sha256', $bearerToken))
            ->first();

        if (! $agent) {
            return response()->json([
                'message' => 'Invalid agent bearer token.',
            ], 401);
        }

        $validated = $request->validate([
            'agent_version' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:255'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:255'],
            'os_version' => ['nullable', 'string', 'max:255'],
            'arch' => ['nullable', 'string', 'max:255'],
            'primary_ip' => ['nullable', 'string', 'max:255'],
            'timestamp' => ['nullable', 'date'],
            'metrics' => ['nullable', 'array'],
            'services' => ['nullable', 'array'],
            'labels' => ['nullable', 'array'],
        ]);

        $agent->forceFill([
            'agent_version' => $validated['agent_version'] ?? $agent->agent_version,
            'device_id' => $validated['device_id'] ?? $agent->device_id,
            'hostname' => $validated['hostname'] ?? $agent->hostname,
            'os' => $validated['os'] ?? $agent->os,
            'os_version' => $validated['os_version'] ?? $agent->os_version,
            'arch' => $validated['arch'] ?? $agent->arch,
            'primary_ip' => $validated['primary_ip'] ?? $agent->primary_ip,
            'labels' => $validated['labels'] ?? [],
            'last_metrics' => $validated['metrics'] ?? [],
            'last_services' => $validated['services'] ?? [],
            'last_seen_at' => isset($validated['timestamp']) ? Carbon::parse($validated['timestamp']) : now(),
            'last_heartbeat_at' => now(),
            'last_ip_address' => $request->ip(),
            'is_active' => true,
        ])->save();

        return response()->json([
            'ok' => true,
            'next_interval_seconds' => 30,
            'commands' => [],
        ]);
    }

    private function presentEnrollmentToken(AgentEnrollmentToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'site_name' => $token->site?->name ?? 'Unknown site',
            'site_code' => $token->site?->code ?? null,
            'created_by' => $token->creator?->name ?? 'System',
            'expires_at' => optional($token->expires_at)?->toIso8601String(),
            'revoked_at' => optional($token->revoked_at)?->toIso8601String(),
            'last_used_at' => optional($token->last_used_at)?->toIso8601String(),
            'max_uses' => $token->max_uses,
            'used_count' => $token->used_count,
            'remaining_uses' => max(0, $token->max_uses - $token->used_count),
            'status' => $token->isRevoked()
                ? 'Revoked'
                : ($token->isExpired()
                    ? 'Expired'
                    : ($token->hasRemainingUses() ? 'Active' : 'Exhausted')),
            'is_available' => $token->isAvailable(),
        ];
    }

    private function presentAgent(Agent $agent): array
    {
        $lastSeenAt = $agent->last_seen_at;
        $status = 'Never Seen';

        if ($lastSeenAt !== null) {
            $status = $lastSeenAt->gt(now()->subMinutes(5)) ? 'Online' : 'Idle';
        }

        return [
            'id' => $agent->id,
            'site_id' => $agent->site_id,
            'site_name' => $agent->site?->name ?? 'Unknown site',
            'hostname' => $agent->hostname,
            'device_id' => $agent->device_id,
            'primary_ip' => $agent->primary_ip,
            'os' => trim(collect([$agent->os, $agent->os_version])->filter()->implode(' ')),
            'arch' => $agent->arch,
            'agent_version' => $agent->agent_version,
            'inventory_asset' => $this->presentInventoryAssetLink($agent->inventoryAsset),
            'status' => $status,
            'last_seen_at' => optional($agent->last_seen_at)?->toIso8601String(),
            'enrolled_at' => optional($agent->enrolled_at)?->toIso8601String(),
            'token_name' => $agent->enrollmentToken?->name,
        ];
    }
}
