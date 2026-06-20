<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Sentry\State\HubInterface;
use Symfony\Component\Process\Process;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'runtimeServices' => [
                'ssh_terminal_proxy' => $this->getRuntimeServiceStatus('ssh-terminal-proxy'),
            ],
            'inventoryImportReport' => request()->session()->get('inventory_import_report'),
            'glitchtip' => [
                'enabled' => filled(config('sentry.dsn')),
                'backend_environment' => config('sentry.environment'),
                'frontend_enabled' => (bool) env('VITE_SENTRY_ENABLED', false),
                'frontend_environment' => env('VITE_SENTRY_ENVIRONMENT', env('APP_ENV')),
                'release' => config('sentry.release'),
                'security_endpoint' => config('glitchtip.security_endpoint'),
                'csp_report_only' => (bool) config('glitchtip.csp_report_only', true),
            ],
        ]);
    }

    public function sendGlitchtipTestEvent(HubInterface $hub): RedirectResponse
    {
        abort_unless(request()->user()?->isAdmin(), 403);

        if (! filled(config('sentry.dsn'))) {
            return back()->with('error', 'GlitchTip DSN belum dikonfigurasi, jadi test event tidak dikirim.');
        }

        $message = 'InfraControl backend test event triggered at '.now()->toIso8601String();
        $eventId = $hub->captureMessage($message);

        return back()->with(
            'success',
            $eventId
                ? "GlitchTip backend test event sent. Event ID: {$eventId}"
                : 'GlitchTip backend test event was attempted, but no event ID was returned.',
        );
    }

    public function glitchtipCspTestPage(): HttpResponse|RedirectResponse
    {
        abort_unless(request()->user()?->isAdmin(), 403);

        $endpoint = trim((string) config('glitchtip.security_endpoint', ''));

        if ($endpoint === '') {
            return redirect()
                ->route('settings.index')
                ->with('error', 'GlitchTip security endpoint belum dikonfigurasi, jadi CSP test belum bisa dijalankan.');
        }

        return response()
            ->view('glitchtip-csp-test', [
                'securityEndpoint' => $endpoint,
                'policy' => $this->glitchtipCspTestPolicy($endpoint),
            ])
            ->header('Content-Security-Policy-Report-Only', $this->glitchtipCspTestPolicy($endpoint));
    }

    public function runtimeServiceStatus(string $service): JsonResponse
    {
        abort_unless($service === 'ssh-terminal-proxy', 404);
        abort_unless(request()->user()?->isAdmin(), 403);

        try {
            return response()->json($this->getRuntimeServiceStatus($service));
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Unable to read runtime service status.',
            ], 500);
        }
    }

    public function startRuntimeService(string $service): JsonResponse
    {
        abort_unless($service === 'ssh-terminal-proxy', 404);
        abort_unless(request()->user()?->isAdmin(), 403);

        if ($response = $this->managedRuntimeServiceResponse($service)) {
            return $response;
        }

        try {
            $status = $this->getRuntimeServiceStatus($service);

            if ($status['healthy']) {
                return response()->json([
                    'message' => 'SSH terminal proxy is already running.',
                    'service' => $status,
                ]);
            }

            $processId = $this->spawnRuntimeService($service);

            return response()->json([
                'message' => 'SSH terminal proxy start requested.',
                'pid' => $processId,
                'service' => $this->getRuntimeServiceStatus($service),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Unable to start SSH terminal proxy.',
            ], 500);
        }
    }

    public function stopRuntimeService(string $service): JsonResponse
    {
        abort_unless($service === 'ssh-terminal-proxy', 404);
        abort_unless(request()->user()?->isAdmin(), 403);

        if ($response = $this->managedRuntimeServiceResponse($service)) {
            return $response;
        }

        try {
            $pid = $this->readRuntimeServicePid($service) ?? $this->findRuntimeServicePid($service);

            if ($pid !== null) {
                $this->terminateRuntimeService($pid);
            }

            $this->forgetRuntimeServicePid($service);

            return response()->json([
                'message' => 'SSH terminal proxy stop requested.',
                'service' => $this->getRuntimeServiceStatus($service),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Unable to stop SSH terminal proxy.',
            ], 500);
        }
    }

    public function restartRuntimeService(string $service): JsonResponse
    {
        abort_unless($service === 'ssh-terminal-proxy', 404);
        abort_unless(request()->user()?->isAdmin(), 403);

        if ($response = $this->managedRuntimeServiceResponse($service)) {
            return $response;
        }

        try {
            $pid = $this->readRuntimeServicePid($service) ?? $this->findRuntimeServicePid($service);

            if ($pid !== null) {
                $this->terminateRuntimeService($pid);
                $this->forgetRuntimeServicePid($service);
                usleep(300000);
            }

            $processId = $this->spawnRuntimeService($service);

            return response()->json([
                'message' => 'SSH terminal proxy restart requested.',
                'pid' => $processId,
                'service' => $this->getRuntimeServiceStatus($service),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage() ?: 'Unable to restart SSH terminal proxy.',
            ], 500);
        }
    }

    private function getRuntimeServiceStatus(string $service): array
    {
        $healthUrl = $this->runtimeServiceHealthUrl($service);
        $managedExternally = $this->runtimeServiceManagedExternally($service);
        $healthy = false;
        $message = $managedExternally
            ? 'External runtime service is not responding.'
            : 'Service is not responding.';

        try {
            $response = Http::timeout(2)->get($healthUrl);
            $healthy = $response->successful() && ($response->json('ok') === true);
            $message = $healthy
                ? ($managedExternally ? 'Service is healthy and managed by your deployment platform.' : 'Service is healthy.')
                : "Health check returned HTTP {$response->status()}.";
        } catch (ConnectionException) {
            $healthy = false;
        } catch (\Throwable $e) {
            $healthy = false;
            $message = $e->getMessage();
        }

        return [
            'id' => $service,
            'label' => 'SSH Terminal Proxy',
            'healthy' => $healthy,
            'message' => $message,
            'health_url' => $healthUrl,
            'pid' => $managedExternally ? null : $this->readRuntimeServicePid($service),
            'managed_externally' => $managedExternally,
            'supports_process_control' => ! $managedExternally,
        ];
    }

    private function glitchtipCspTestPolicy(string $endpoint): string
    {
        return "default-src 'self'; img-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; report-uri {$endpoint};";
    }

    private function runtimeServiceHealthUrl(string $service): string
    {
        $configured = match ($service) {
            'ssh-terminal-proxy' => config('app.ssh_terminal_proxy_health_url'),
            default => null,
        };

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $appUrl = config('app.url', 'http://127.0.0.1:8088');
        $parts = parse_url($appUrl) ?: [];
        $scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'https' : 'http';
        $host = $parts['host'] ?? '127.0.0.1';

        return match ($service) {
            'ssh-terminal-proxy' => "{$scheme}://{$host}:".config('app.ssh_terminal_proxy_port', 8078).'/healthz',
            default => abort(404),
        };
    }

    private function runtimeServiceManagedExternally(string $service): bool
    {
        return match ($service) {
            'ssh-terminal-proxy' => (bool) config('app.ssh_terminal_proxy_managed_externally', false),
            default => false,
        };
    }

    private function managedRuntimeServiceResponse(string $service): ?JsonResponse
    {
        if (! $this->runtimeServiceManagedExternally($service)) {
            return null;
        }

        return response()->json([
            'message' => 'SSH terminal proxy is managed by your deployment platform. Use Coolify service controls instead.',
            'service' => $this->getRuntimeServiceStatus($service),
        ], 409);
    }

    private function spawnRuntimeService(string $service): ?int
    {
        $workingDirectory = base_path();
        $logPath = storage_path("logs/{$service}.log");

        if (DIRECTORY_SEPARATOR === '\\') {
            $commandLine = match ($service) {
                'ssh-terminal-proxy' => 'cmd.exe /c cd /d '.$this->quoteWindowsCommandArgument($workingDirectory).' && npm.cmd run ssh-terminal-proxy >> '.$this->quoteWindowsCommandArgument($logPath).' 2>&1',
                default => abort(404),
            };

            $process = new Process(['wmic', 'process', 'call', 'create', $commandLine], $workingDirectory);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput() ?: $process->getOutput() ?: 'Unable to start runtime service.');
            }

            preg_match('/ProcessId\s*=\s*(\d+)/i', $process->getOutput(), $matches);
            $wmicPid = isset($matches[1]) ? (int) $matches[1] : 0;

            if (! $this->waitForRuntimeServiceHealth($service)) {
                throw new \RuntimeException('Runtime service did not become healthy after start request.');
            }

            $pid = $this->findRuntimeServicePid($service) ?? ($wmicPid > 0 ? $wmicPid : null);

            if ($pid !== null) {
                $this->writeRuntimeServicePid($service, $pid);
            }

            return $pid;
        }

        $command = match ($service) {
            'ssh-terminal-proxy' => 'cd '.escapeshellarg($workingDirectory).' && nohup npm run ssh-terminal-proxy > '.escapeshellarg($logPath).' 2>&1 & echo $!',
            default => abort(404),
        };

        $process = Process::fromShellCommandline($command, $workingDirectory);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException($process->getErrorOutput() ?: $process->getOutput() ?: 'Unable to start runtime service.');
        }

        $pid = (int) trim($process->getOutput());

        if (! $this->waitForRuntimeServiceHealth($service)) {
            throw new \RuntimeException('Runtime service did not become healthy after start request.');
        }

        if ($pid > 0) {
            $this->writeRuntimeServicePid($service, $pid);
        }

        return $pid > 0 ? $pid : null;
    }

    private function terminateRuntimeService(int $pid): void
    {
        if ($pid <= 0) {
            return;
        }

        $process = DIRECTORY_SEPARATOR === '\\'
            ? new Process(['taskkill', '/PID', (string) $pid, '/T', '/F'])
            : new Process(['kill', '-TERM', (string) $pid]);

        $process->run();
    }

    private function runtimeServicePidPath(string $service): string
    {
        return "runtime/{$service}.pid";
    }

    private function readRuntimeServicePid(string $service): ?int
    {
        $disk = Storage::disk('local');
        $path = $this->runtimeServicePidPath($service);

        if (! $disk->exists($path)) {
            return null;
        }

        $value = trim((string) $disk->get($path));
        $pid = (int) $value;

        return $pid > 0 ? $pid : null;
    }

    private function writeRuntimeServicePid(string $service, int $pid): void
    {
        Storage::disk('local')->put($this->runtimeServicePidPath($service), (string) $pid);
    }

    private function forgetRuntimeServicePid(string $service): void
    {
        Storage::disk('local')->delete($this->runtimeServicePidPath($service));
    }

    private function waitForRuntimeServiceHealth(string $service, int $attempts = 12, int $sleepMilliseconds = 500): bool
    {
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $status = $this->getRuntimeServiceStatus($service);

            if ($status['healthy']) {
                return true;
            }

            usleep($sleepMilliseconds * 1000);
        }

        return false;
    }

    private function findRuntimeServicePid(string $service): ?int
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return match ($service) {
                'ssh-terminal-proxy' => $this->findWindowsPidByPort((int) config('app.ssh_terminal_proxy_port', 8078)),
                default => null,
            };
        }

        return null;
    }

    private function findWindowsPidByPort(int $port): ?int
    {
        if ($port <= 0) {
            return null;
        }

        $process = new Process(['cmd', '/c', "netstat -ano -p tcp | findstr LISTENING | findstr :{$port}"]);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        foreach (preg_split("/\r\n|\n|\r/", trim($process->getOutput())) as $line) {
            if (! preg_match('/\s+(\d+)\s*$/', trim($line), $matches)) {
                continue;
            }

            $pid = (int) $matches[1];

            if ($pid > 0) {
                return $pid;
            }
        }

        return null;
    }

    private function quoteWindowsCommandArgument(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
