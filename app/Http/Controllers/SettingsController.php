<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Process\Process;

class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'runtimeServices' => [
                'ssh_terminal_proxy' => $this->getRuntimeServiceStatus('ssh-terminal-proxy'),
            ],
        ]);
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
        $healthy = false;
        $message = 'Service is not responding.';

        try {
            $response = Http::timeout(2)->get($healthUrl);
            $healthy = $response->successful() && ($response->json('ok') === true);
            $message = $healthy
                ? 'Service is healthy.'
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
            'pid' => $this->readRuntimeServicePid($service),
        ];
    }

    private function runtimeServiceHealthUrl(string $service): string
    {
        $appUrl = config('app.url', 'http://127.0.0.1:8000');
        $parts = parse_url($appUrl) ?: [];
        $scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'https' : 'http';
        $host = $parts['host'] ?? '127.0.0.1';

        return match ($service) {
            'ssh-terminal-proxy' => "{$scheme}://{$host}:".config('app.ssh_terminal_proxy_port', 8078).'/healthz',
            default => abort(404),
        };
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
