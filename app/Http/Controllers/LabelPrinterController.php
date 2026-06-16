<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LabelPrinter;
use App\Services\LabelPrinting\InventoryLabelPrintService;
use App\Services\LabelPrinting\LabelPrintTransportResolver;
use App\Services\LabelPrinting\RawTcpPrintTransport;
use App\Services\LabelPrinting\SmbPrintTransport;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LabelPrinterController extends Controller
{
    public function __construct(
        private readonly SmbPrintTransport $smbTransport,
        private readonly RawTcpPrintTransport $rawTcpTransport,
        private readonly LabelPrintTransportResolver $transportResolver,
        private readonly InventoryLabelPrintService $printService,
    ) {
    }

    public function index(): Response
    {
        $printers = LabelPrinter::query()
            ->orderBy('is_default', 'desc')
            ->orderBy('display_name')
            ->get()
            ->map(fn (LabelPrinter $printer) => $this->presentPrinter($printer));

        return Inertia::render('Settings/PrintSmb/Index', [
            'printers' => $printers,
            'driverOptions' => collect(LabelPrinter::DRIVERS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'connectionModeOptions' => collect(LabelPrinter::CONNECTION_MODES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'transport' => [
                'smbclientAvailable' => $this->smbTransport->isAvailable(),
                'rawTcpAvailable' => $this->rawTcpTransport->isAvailable(),
            ],
            'recentJobs' => $this->recentJobs(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Settings/PrintSmb/Create', [
            'driverOptions' => collect(LabelPrinter::DRIVERS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'connectionModeOptions' => collect(LabelPrinter::CONNECTION_MODES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePrinter($request);

        $printer = LabelPrinter::create($this->buildPayload($validated));

        $this->ensureSingleDefault($printer);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'label_printer.create',
            targetType: 'label_printer',
            targetId: $printer->id,
            payload: $this->auditPayload($printer),
            ipAddress: $request->ip(),
        );

        return redirect()->route('print-smb.index')
            ->with('success', "Printer \"{$printer->display_name}\" created.");
    }

    public function edit(LabelPrinter $printer): Response
    {
        return Inertia::render('Settings/PrintSmb/Edit', [
            'printer' => $this->presentPrinter($printer),
            'driverOptions' => collect(LabelPrinter::DRIVERS)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'connectionModeOptions' => collect(LabelPrinter::CONNECTION_MODES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
        ]);
    }

    public function update(Request $request, LabelPrinter $printer)
    {
        $validated = $this->validatePrinter($request, $printer);

        $payload = $this->buildPayload($validated);

        if ($this->shouldClearPassword($validated)) {
            $payload['password'] = null;
        } elseif (empty($validated['password'])) {
            unset($payload['password']);
        }

        $printer->update($payload);

        $this->ensureSingleDefault($printer);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'label_printer.update',
            targetType: 'label_printer',
            targetId: $printer->id,
            payload: $this->auditPayload($printer->fresh()),
            ipAddress: $request->ip(),
        );

        return redirect()->route('print-smb.index')
            ->with('success', "Printer \"{$printer->display_name}\" updated.");
    }

    public function destroy(Request $request, LabelPrinter $printer)
    {
        $name = $printer->display_name;

        $printer->delete();

        AuditLog::record(
            userId: $request->user()->id,
            action: 'label_printer.delete',
            targetType: 'label_printer',
            targetId: null,
            payload: ['display_name' => $name],
            ipAddress: $request->ip(),
        );

        return redirect()->route('print-smb.index')
            ->with('success', "Printer \"{$name}\" removed.");
    }

    public function setDefault(Request $request, LabelPrinter $printer)
    {
        LabelPrinter::query()->update(['is_default' => false]);

        $printer->update(['is_default' => true]);

        AuditLog::record(
            userId: $request->user()->id,
            action: 'label_printer.set_default',
            targetType: 'label_printer',
            targetId: $printer->id,
            payload: ['display_name' => $printer->display_name],
            ipAddress: $request->ip(),
        );

        return back()->with('success', "\"{$printer->display_name}\" is now the default printer.");
    }

    public function test(Request $request, LabelPrinter $printer)
    {
        $job = $this->printService->runTestPrint($printer, $request->user(), $request->ip());

        if ($job->status === 'failed') {
            return back()->with('error', $job->error_message ?: 'Test print failed.');
        }

        return back()->with('success', "Test label sent to {$printer->display_name}.");
    }

    private function validatePrinter(Request $request, ?LabelPrinter $existing = null): array
    {
        $connectionMode = $request->input('connection_mode', LabelPrinter::CONNECTION_SMB);
        $usesSmb = $connectionMode === LabelPrinter::CONNECTION_SMB;

        return $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'is_default' => ['boolean'],
            'connection_mode' => ['required', 'string', Rule::in(array_keys(LabelPrinter::CONNECTION_MODES))],
            'smb_host' => ['required', 'string', 'max:255'],
            'share_name' => [$usesSmb ? 'required' : 'nullable', 'string', 'max:255'],
            'lan_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => [$usesSmb ? 'required' : 'nullable', 'string', 'max:255'],
            'password' => [
                $usesSmb && ! $existing ? 'required' : 'nullable',
                'string',
                'max:255',
            ],
            'domain' => ['nullable', 'string', 'max:255'],
            'driver_language' => ['required', 'string', Rule::in(array_keys(LabelPrinter::DRIVERS))],
        ]);
    }

    private function buildPayload(array $validated): array
    {
        $usesSmb = ($validated['connection_mode'] ?? LabelPrinter::CONNECTION_SMB) === LabelPrinter::CONNECTION_SMB;

        $payload = [
            'display_name' => $validated['display_name'],
            'enabled' => (bool) ($validated['enabled'] ?? false),
            'is_default' => (bool) ($validated['is_default'] ?? false),
            'connection_mode' => $validated['connection_mode'],
            'smb_host' => trim($validated['smb_host']),
            'share_name' => $usesSmb ? trim((string) $validated['share_name']) : '',
            'lan_port' => (int) ($validated['lan_port'] ?? 9100),
            'username' => $usesSmb ? trim((string) $validated['username']) : null,
            'domain' => $usesSmb ? $this->normalizeNullableString($validated['domain'] ?? null) : null,
            'driver_language' => $validated['driver_language'],
        ];

        if ($usesSmb && ! empty($validated['password'])) {
            $payload['password'] = $validated['password'];
        }

        if (! $usesSmb) {
            $payload['password'] = null;
        }

        return $payload;
    }

    private function shouldClearPassword(array $validated): bool
    {
        return ($validated['connection_mode'] ?? LabelPrinter::CONNECTION_SMB) !== LabelPrinter::CONNECTION_SMB;
    }

    private function ensureSingleDefault(LabelPrinter $printer): void
    {
        if (! $printer->is_default) {
            return;
        }

        LabelPrinter::query()
            ->where('id', '!=', $printer->id)
            ->update(['is_default' => false]);
    }

    private function presentPrinter(LabelPrinter $printer): array
    {
        return [
            'id' => $printer->id,
            'display_name' => $printer->display_name,
            'enabled' => $printer->enabled,
            'is_default' => $printer->is_default,
            'connection_mode' => $printer->connection_mode,
            'connection_mode_label' => LabelPrinter::CONNECTION_MODES[$printer->connection_mode] ?? $printer->connection_mode,
            'smb_host' => $printer->smb_host,
            'share_name' => $printer->share_name ?? '',
            'lan_port' => $printer->lan_port ?? 9100,
            'username' => $printer->username ?? '',
            'password' => '',
            'domain' => $printer->domain ?? '',
            'driver_language' => $printer->driver_language,
            'driver_language_label' => LabelPrinter::DRIVERS[$printer->driver_language] ?? $printer->driver_language,
            'connection_target' => $this->transportResolver->connectionTarget($printer),
            'has_saved_password' => $printer->usesSmb() && $printer->password !== null,
            'created_at' => $printer->created_at?->diffForHumans(),
        ];
    }

    private function recentJobs(): array
    {
        return AuditLog::query()
            ->where('target_type', 'label_printer')
            ->orderByDesc('created_at')
            ->limit(8)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'result' => $log->result,
                'error_message' => $log->error_message,
                'created_at' => $log->created_at?->diffForHumans(),
                'created_at_full' => $log->created_at?->toDateTimeString(),
                'payload' => $log->payload,
            ])
            ->toArray();
    }

    private function auditPayload(LabelPrinter $printer): array
    {
        return [
            'id' => $printer->id,
            'display_name' => $printer->display_name,
            'enabled' => $printer->enabled,
            'is_default' => $printer->is_default,
            'connection_mode' => $printer->connection_mode,
            'connection_target' => $this->transportResolver->connectionTarget($printer),
            'driver_language' => $printer->driver_language,
        ];
    }

    private function normalizeNullableString(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
