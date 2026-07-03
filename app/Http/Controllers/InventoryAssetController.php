<?php

namespace App\Http\Controllers;

use App\Exports\InventoryAssetImportTemplateExport;
use App\Http\Controllers\Concerns\AppliesSiteScope;
use App\Http\Controllers\Concerns\PresentsAgentInventoryLink;
use App\Imports\InventoryAssetImportRowsImport;
use App\Jobs\ProcessInventoryLabelPrintJob;
use App\Models\Agent;
use App\Models\AssetLink;
use App\Models\AuditLog;
use App\Models\InventoryAsset;
use App\Models\InventoryLabelPrintJob;
use App\Models\LabelPrinter;
use App\Models\Site;
use App\Services\Inventory\InventoryAssetImportService;
use App\Services\LabelPrinting\InventoryLabelPrintService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InventoryAssetController extends Controller
{
    use AppliesSiteScope;
    use PresentsAgentInventoryLink;

    public function index(Request $request): Response
    {
        $query = InventoryAsset::query()
            ->with('site')
            ->orderBy('name');

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $operator = $this->caseInsensitiveLikeOperator();

            $query->where(function ($builder) use ($search, $operator) {
                $like = '%'.$search.'%';

                $builder
                    ->where('name', $operator, $like)
                    ->orWhere('category', $operator, $like)
                    ->orWhere('asset_tag', $operator, $like)
                    ->orWhere('serial_number', $operator, $like)
                    ->orWhere('manufacturer', $operator, $like)
                    ->orWhere('model', $operator, $like)
                    ->orWhere('primary_ip', $operator, $like)
                    ->orWhere('location_label', $operator, $like)
                    ->orWhere('owner_name', $operator, $like)
                    ->orWhere('notes', $operator, $like);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $this->applySiteScope($query);
        $this->applyRequestedSiteFilter($query, $request->query('site'), nullFilterValue: 'unassigned');

        $assets = $query
            ->paginate(25)
            ->withQueryString()
            ->through(fn (InventoryAsset $asset) => $this->presentAsset($asset));

        return Inertia::render('Inventory/Index', [
            'assets' => $assets,
            'sites' => $this->siteOptions(),
            'statusOptions' => $this->statusOptions(),
            'filters' => $request->only(['search', 'site', 'status']),
        ]);
    }

    public function import(Request $request, InventoryAssetImportService $importService): RedirectResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv,txt', 'max:10240'],
        ]);

        $rows = Excel::toCollection(new InventoryAssetImportRowsImport, $validated['file'])->first() ?? collect();
        $report = $importService->import($rows, $request->user(), $request->ip());

        $message = "Import selesai: {$report['created']} asset dibuat, {$report['updated']} diperbarui, {$report['failed']} gagal.";

        return redirect()
            ->route('settings.index')
            ->with($report['created'] > 0 || $report['updated'] > 0 ? 'success' : 'error', $message)
            ->with('inventory_import_report', $report);
    }

    public function importTemplate(): BinaryFileResponse
    {
        return Excel::download(
            new InventoryAssetImportTemplateExport(
                $this->siteOptions(),
                InventoryAsset::STATUSES,
                InventoryAsset::CATEGORY_SUGGESTIONS,
            ),
            'inventory-asset-import-template.xlsx',
        );
    }

    public function show(InventoryAsset $asset): Response
    {
        $this->authorizeSiteAccess($asset->site_id);
        $asset->load(['site', 'agent']);

        return Inertia::render('Inventory/Show', [
            'asset' => $this->presentAsset($asset, includeCustomFieldText: true),
            'history' => $this->assetHistory($asset),
            'labelPrint' => $this->labelPrintMeta($asset),
            'linkedAgent' => $this->presentLinkedAgent($asset->agent),
            'availableAgents' => $this->agentLinkOptions($asset),
            'canManageAgentLink' => request()->user()?->isAdmin() ?? false,
        ]);
    }

    public function updateAgentLink(Request $request, InventoryAsset $asset): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $this->authorizeSiteAccess($asset->site_id);

        $validated = $request->validate([
            'agent_id' => ['nullable', 'uuid', 'exists:agents,id'],
        ]);

        $asset->load('agent');
        $agentId = $validated['agent_id'] ?? null;
        $previousAgentId = $asset->agent?->id;

        if ($asset->site_id === null) {
            throw ValidationException::withMessages([
                'agent_id' => 'Assign this asset to a site before linking an agent.',
            ]);
        }

        if ($agentId !== null) {
            $agent = Agent::query()->findOrFail($agentId);

            if ($agent->site_id !== $asset->site_id) {
                throw ValidationException::withMessages([
                    'agent_id' => 'Agent must belong to the same site as this inventory asset.',
                ]);
            }
        }

        DB::transaction(function () use ($asset, $agentId, $previousAgentId, $request) {
            Agent::query()
                ->where('inventory_asset_id', $asset->id)
                ->update(['inventory_asset_id' => null]);

            if ($agentId !== null) {
                Agent::query()
                    ->whereKey($agentId)
                    ->update(['inventory_asset_id' => $asset->id]);
            }

            AuditLog::record(
                userId: $request->user()->id,
                action: 'inventory_asset.agent-link.update',
                targetType: 'inventory_asset',
                targetId: $asset->id,
                payload: [
                    'site_id' => $asset->site_id,
                    'asset_name' => $asset->name,
                    'previous_agent_id' => $previousAgentId,
                    'agent_id' => $agentId,
                ],
                ipAddress: $request->ip(),
                siteId: $asset->site_id,
            );
        });

        return redirect()
            ->back()
            ->with('success', $agentId === null ? 'Agent link removed from inventory asset.' : 'Inventory asset linked to agent.');
    }

    public function scan(InventoryAsset $asset): Response
    {
        $asset->load('site');

        return Inertia::render('Inventory/Scan', [
            'asset' => $this->presentAsset($asset, includeCustomFieldText: true),
            'history' => $this->assetHistory($asset),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Inventory/Create', [
            'sites' => $this->siteOptions(),
            'statusOptions' => $this->statusOptions(),
            'categorySuggestions' => InventoryAsset::CATEGORY_SUGGESTIONS,
            'siteAssets' => $this->siteAssetOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateAsset($request);

        $asset = InventoryAsset::create([
            'site_id' => $validated['site_id'] ?? null,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'status' => $validated['status'],
            'asset_tag' => $this->normalizeNullableString($validated['asset_tag'] ?? null),
            'serial_number' => $this->normalizeNullableString($validated['serial_number'] ?? null),
            'manufacturer' => $this->normalizeNullableString($validated['manufacturer'] ?? null),
            'model' => $this->normalizeNullableString($validated['model'] ?? null),
            'primary_ip' => $this->normalizeNullableString($validated['primary_ip'] ?? null),
            'location_label' => $this->normalizeNullableString($validated['location_label'] ?? null),
            'owner_name' => $this->normalizeNullableString($validated['owner_name'] ?? null),
            'acquired_at' => $validated['acquired_at'] ?? null,
            'warranty_expires_at' => $validated['warranty_expires_at'] ?? null,
            'custom_fields' => $this->parseCustomFields($validated['custom_fields_text'] ?? null),
            'notes' => $this->normalizeNullableString($validated['notes'] ?? null),
        ]);

        $this->syncUplinkAsset($asset, $validated['uplink_asset_id'] ?? null);

        $this->recordInventoryAction($request, $asset, 'create');

        return redirect()->route('inventory.index')
            ->with('success', "Inventory asset \"{$asset->name}\" created successfully.");
    }

    public function edit(InventoryAsset $asset): Response
    {
        $this->authorizeSiteAccess($asset->site_id);

        return Inertia::render('Inventory/Edit', [
            'asset' => $this->presentAsset($asset->load(['site', 'uplinkLink.targetAsset']), includeCustomFieldText: true),
            'sites' => $this->siteOptions(),
            'statusOptions' => $this->statusOptions(),
            'categorySuggestions' => InventoryAsset::CATEGORY_SUGGESTIONS,
            'siteAssets' => $this->siteAssetOptions(),
        ]);
    }

    public function update(Request $request, InventoryAsset $asset)
    {
        $this->authorizeSiteAccess($asset->site_id);
        $validated = $this->validateAsset($request, $asset);

        $asset->update([
            'site_id' => $validated['site_id'] ?? null,
            'name' => $validated['name'],
            'category' => $validated['category'],
            'status' => $validated['status'],
            'asset_tag' => $this->normalizeNullableString($validated['asset_tag'] ?? null),
            'serial_number' => $this->normalizeNullableString($validated['serial_number'] ?? null),
            'manufacturer' => $this->normalizeNullableString($validated['manufacturer'] ?? null),
            'model' => $this->normalizeNullableString($validated['model'] ?? null),
            'primary_ip' => $this->normalizeNullableString($validated['primary_ip'] ?? null),
            'location_label' => $this->normalizeNullableString($validated['location_label'] ?? null),
            'owner_name' => $this->normalizeNullableString($validated['owner_name'] ?? null),
            'acquired_at' => $validated['acquired_at'] ?? null,
            'warranty_expires_at' => $validated['warranty_expires_at'] ?? null,
            'custom_fields' => $this->parseCustomFields($validated['custom_fields_text'] ?? null),
            'notes' => $this->normalizeNullableString($validated['notes'] ?? null),
        ]);

        $this->syncUplinkAsset($asset, $validated['uplink_asset_id'] ?? null);

        $this->recordInventoryAction($request, $asset, 'update');

        return redirect()->route('inventory.show', $asset)
            ->with('success', "Inventory asset \"{$asset->name}\" updated successfully.");
    }

    public function printLabel(Request $request, InventoryAsset $asset)
    {
        $this->authorizeSiteAccess($asset->site_id);
        $printer = LabelPrinter::defaultPrinter();

        if (! $printer || ! $printer->enabled) {
            return redirect()->route('inventory.show', $asset)
                ->with('error', 'No enabled label printer is configured yet. Set one up in Settings → Label Printers.');
        }

        $job = app(InventoryLabelPrintService::class)
            ->queueAssetLabel($asset->loadMissing('site'), $printer, $request->user());

        $processedImmediately = $this->dispatchLabelPrintJob($job);

        if ($processedImmediately) {
            $job->refresh();

            if ($job->status === InventoryLabelPrintJob::STATUS_FAILED) {
                return redirect()->route('inventory.show', $asset)
                    ->with('error', $job->error_message ?: 'Label print failed.');
            }

            return redirect()->route('inventory.show', $asset)
                ->with('success', "Label sent to {$printer->display_name}.");
        }

        return redirect()->route('inventory.show', $asset)
            ->with('success', "Label print queued for \"{$asset->name}\".");
    }

    private function dispatchLabelPrintJob(InventoryLabelPrintJob $job): bool
    {
        if ($this->shouldProcessPrintImmediately()) {
            ProcessInventoryLabelPrintJob::dispatchSync($job->id);

            return true;
        }

        ProcessInventoryLabelPrintJob::dispatch($job->id);

        return false;
    }

    private function shouldProcessPrintImmediately(): bool
    {
        return (bool) config(
            'inventory.label_print_process_immediately',
            app()->environment('local') && config('queue.default') !== 'sync',
        );
    }

    private function validateAsset(Request $request, ?InventoryAsset $asset = null): array
    {
        $validated = $request->validate([
            'site_id' => ['nullable', 'exists:sites,id'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:100'],
            'status' => ['required', 'string', Rule::in(array_keys(InventoryAsset::STATUSES))],
            'asset_tag' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('inventory_assets', 'asset_tag')->ignore($asset?->id),
            ],
            'serial_number' => ['nullable', 'string', 'max:150'],
            'manufacturer' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'primary_ip' => ['nullable', 'string', 'max:255'],
            'location_label' => ['nullable', 'string', 'max:255'],
            'owner_name' => ['nullable', 'string', 'max:255'],
            'acquired_at' => ['nullable', 'date'],
            'warranty_expires_at' => ['nullable', 'date'],
            'custom_fields_text' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'uplink_asset_id' => ['nullable', 'uuid', 'exists:inventory_assets,id'],
        ]);

        if (! empty($validated['uplink_asset_id']) && $asset && $validated['uplink_asset_id'] === $asset->id) {
            throw ValidationException::withMessages([
                'uplink_asset_id' => 'An asset cannot uplink to itself.',
            ]);
        }

        return $validated;
    }

    private function presentAsset(InventoryAsset $asset, bool $includeCustomFieldText = false): array
    {
        $customFields = $asset->custom_fields ?? [];

        return [
            'id' => $asset->id,
            'site_id' => $asset->site_id,
            'site' => $asset->site ? [
                'id' => $asset->site->id,
                'name' => $asset->site->name,
                'code' => $asset->site->code,
            ] : null,
            'scope_label' => $asset->site?->name ?? 'Unassigned',
            'name' => $asset->name,
            'category' => $asset->category,
            'category_icon' => $this->categoryIcon($asset->category),
            'status' => $asset->status,
            'status_label' => $asset->status_label,
            'asset_tag' => $asset->asset_tag,
            'serial_number' => $asset->serial_number,
            'manufacturer' => $asset->manufacturer,
            'model' => $asset->model,
            'primary_ip' => $asset->primary_ip,
            'location_label' => $asset->location_label,
            'owner_name' => $asset->owner_name,
            'acquired_at' => $asset->acquired_at?->format('Y-m-d'),
            'warranty_expires_at' => $asset->warranty_expires_at?->format('Y-m-d'),
            'notes' => $asset->notes,
            'custom_fields' => collect($customFields)
                ->map(fn ($value, $key) => [
                    'key' => $key,
                    'value' => $value,
                ])
                ->values()
                ->all(),
            'custom_fields_count' => count($customFields),
            'custom_fields_text' => $includeCustomFieldText ? $this->formatCustomFields($customFields) : null,
            'created_at' => $asset->created_at?->format('Y-m-d H:i:s'),
            'created_at_human' => $asset->created_at?->diffForHumans(),
            'updated_at' => $asset->updated_at?->diffForHumans(),
            'uplink_asset_id' => $asset->relationLoaded('uplinkLink')
                ? ($asset->uplinkLink?->target_asset_id)
                : null,
            'uplink_asset_label' => $asset->relationLoaded('uplinkLink') && $asset->uplinkLink?->targetAsset
                ? collect([$asset->uplinkLink->targetAsset->name, $asset->uplinkLink->targetAsset->primary_ip])
                    ->filter()
                    ->implode(' · ')
                : null,
            'scan_url' => $this->assetScanUrl($asset),
        ];
    }

    private function categoryIcon(string $category): string
    {
        return match (strtolower($category)) {
            'server' => 'server',
            'hypervisor' => 'hypervisor',
            'storage', 'nas' => 'storage',
            'firewall' => 'firewall',
            'switch' => 'switch',
            'router' => 'router',
            'access point' => 'ap',
            'printer' => 'printer',
            'ups' => 'ups',
            'pc', 'mini pc', 'endpoint' => 'pc',
            'laptop' => 'laptop',
            'monitor' => 'monitor',
            'medical device' => 'medical',
            'license' => 'license',
            'spare part' => 'spare',
            default => 'device',
        };
    }

    private function assetHistory(InventoryAsset $asset, int $limit = 12): array
    {
        return AuditLog::query()
            ->with('user')
            ->where('target_type', 'inventory_asset')
            ->where('target_id', $asset->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'result' => $log->result,
                'error_message' => $log->error_message,
                'payload' => $log->payload,
                'user_name' => $log->user?->name ?? 'System',
                'created_at' => $log->created_at?->format('Y-m-d H:i:s'),
                'created_at_human' => $log->created_at?->diffForHumans(),
            ])
            ->all();
    }

    private function labelPrintMeta(InventoryAsset $asset): array
    {
        $printer = LabelPrinter::defaultPrinter();
        $latestJob = InventoryLabelPrintJob::query()
            ->where('asset_id', $asset->id)
            ->where('is_test', false)
            ->latest()
            ->first();

        return [
            'available' => (bool) ($printer?->enabled),
            'printer_name' => $printer?->display_name,
            'driver_language' => $printer?->driver_language,
            'last_job' => $latestJob ? [
                'status' => $latestJob->status,
                'error_message' => $latestJob->error_message,
                'created_at' => $latestJob->created_at?->diffForHumans(),
                'completed_at' => $latestJob->completed_at?->diffForHumans(),
                'label_identifier' => $latestJob->label_identifier,
            ] : null,
        ];
    }

    private function syncUplinkAsset(InventoryAsset $asset, ?string $uplinkAssetId): void
    {
        AssetLink::query()
            ->where('source_asset_id', $asset->id)
            ->where('link_type', AssetLink::TYPE_UPLINK)
            ->delete();

        if (! $uplinkAssetId) {
            return;
        }

        $upstream = InventoryAsset::query()->findOrFail($uplinkAssetId);

        if ($upstream->site_id !== $asset->site_id) {
            throw ValidationException::withMessages([
                'uplink_asset_id' => 'Uplink target must belong to the same site.',
            ]);
        }

        AssetLink::create([
            'source_asset_id' => $asset->id,
            'target_asset_id' => $uplinkAssetId,
            'link_type' => AssetLink::TYPE_UPLINK,
        ]);
    }

    private function siteAssetOptions(): array
    {
        return InventoryAsset::query()
            ->with('site')
            ->orderBy('name')
            ->get()
            ->map(fn (InventoryAsset $asset) => [
                'id' => $asset->id,
                'site_id' => $asset->site_id,
                'label' => collect([$asset->name, $asset->category, $asset->primary_ip, $asset->location_label])
                    ->filter()
                    ->implode(' · '),
            ])
            ->all();
    }

    private function recordInventoryAction(Request $request, InventoryAsset $asset, string $action): void
    {
        AuditLog::record(
            userId: $request->user()->id,
            action: 'inventory_asset.'.$action,
            targetType: 'inventory_asset',
            targetId: $asset->id,
            payload: [
                'name' => $asset->name,
                'category' => $asset->category,
                'status' => $asset->status,
                'site_id' => $asset->site_id,
                'asset_tag' => $asset->asset_tag,
            ],
            ipAddress: $request->ip(),
            siteId: $asset->site_id,
        );
    }

    private function assetScanUrl(InventoryAsset $asset): string
    {
        return URL::signedRoute('inventory.scan', ['asset' => $asset->id]);
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

    private function statusOptions(): array
    {
        return collect(InventoryAsset::STATUSES)
            ->map(fn ($label, $value) => [
                'value' => $value,
                'label' => $label,
            ])
            ->values()
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

    private function caseInsensitiveLikeOperator(): string
    {
        return InventoryAsset::query()->getConnection()->getDriverName() === 'pgsql'
            ? 'ilike'
            : 'like';
    }

    private function parseCustomFields(?string $customFieldsText): ?array
    {
        $normalized = $this->normalizeNullableString($customFieldsText);

        if ($normalized === null) {
            return null;
        }

        $fields = [];

        foreach (preg_split('/\r\n|\r|\n/', $normalized) as $index => $line) {
            $trimmedLine = trim($line);

            if ($trimmedLine === '') {
                continue;
            }

            $parts = explode(':', $trimmedLine, 2);

            if (count($parts) !== 2) {
                throw ValidationException::withMessages([
                    'custom_fields_text' => 'Each extra attribute must use the format "key: value".',
                ]);
            }

            $key = trim($parts[0]);
            $value = trim($parts[1]);

            if ($key === '') {
                throw ValidationException::withMessages([
                    'custom_fields_text' => 'Extra attribute keys cannot be empty.',
                ]);
            }

            $fields[$key] = $value;
        }

        return $fields === [] ? null : $fields;
    }

    private function formatCustomFields(?array $customFields): ?string
    {
        if ($customFields === null || $customFields === []) {
            return null;
        }

        return collect($customFields)
            ->map(fn ($value, $key) => $key.': '.Str::of((string) $value)->trim())
            ->implode("\n");
    }
}
