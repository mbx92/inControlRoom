<?php

namespace App\Services\Inventory;

use App\Models\InventoryAsset;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Throwable;

class AssetReachabilityService
{
    /**
     * @return array{
     *     status:string,
     *     checked_at:Carbon,
     *     last_seen_at:?Carbon,
     *     latency_ms:?int,
     *     fail_count:int,
     *     message:string
     * }
     */
    public function probe(InventoryAsset $asset): array
    {
        $checkedAt = now();

        if (! $asset->monitoring_enabled) {
            return [
                'status' => InventoryAsset::REACHABILITY_UNKNOWN,
                'checked_at' => $checkedAt,
                'last_seen_at' => $asset->reachability_last_seen_at,
                'latency_ms' => null,
                'fail_count' => $asset->reachability_fail_count,
                'message' => 'Monitoring disabled for this asset.',
            ];
        }

        $ipAddress = trim((string) ($asset->primary_ip ?? ''));

        if ($ipAddress === '') {
            return [
                'status' => InventoryAsset::REACHABILITY_UNKNOWN,
                'checked_at' => $checkedAt,
                'last_seen_at' => $asset->reachability_last_seen_at,
                'latency_ms' => null,
                'fail_count' => $asset->reachability_fail_count,
                'message' => 'No primary IP configured.',
            ];
        }

        try {
            [$successful, $output, $message] = $this->runPing($ipAddress);
            $latency = $this->extractLatency($output);

            if ($successful) {
                return [
                    'status' => InventoryAsset::REACHABILITY_ONLINE,
                    'checked_at' => $checkedAt,
                    'last_seen_at' => $checkedAt,
                    'latency_ms' => $latency,
                    'fail_count' => 0,
                    'message' => $message ?: 'Ping responded.',
                ];
            }

            return [
                'status' => InventoryAsset::REACHABILITY_OFFLINE,
                'checked_at' => $checkedAt,
                'last_seen_at' => $asset->reachability_last_seen_at,
                'latency_ms' => null,
                'fail_count' => $asset->reachability_fail_count + 1,
                'message' => $message ?: 'Host did not respond to ping.',
            ];
        } catch (Throwable $exception) {
            return [
                'status' => InventoryAsset::REACHABILITY_UNKNOWN,
                'checked_at' => $checkedAt,
                'last_seen_at' => $asset->reachability_last_seen_at,
                'latency_ms' => null,
                'fail_count' => $asset->reachability_fail_count,
                'message' => $exception->getMessage() ?: 'Probe failed unexpectedly.',
            ];
        }
    }

    public function checkAndStore(InventoryAsset $asset): InventoryAsset
    {
        $result = $this->probe($asset);

        $asset->forceFill([
            'reachability_status' => $result['status'],
            'reachability_checked_at' => $result['checked_at'],
            'reachability_last_seen_at' => $result['last_seen_at'],
            'reachability_latency_ms' => $result['latency_ms'],
            'reachability_fail_count' => $result['fail_count'],
            'reachability_message' => $result['message'],
        ])->save();

        return $asset->fresh(['site']);
    }

    /**
     * @return array{0:bool,1:string,2:string}
     */
    private function runPing(string $ipAddress): array
    {
        $process = $this->pingProcess($ipAddress);
        $process->setTimeout(2.0);
        $process->run();

        $output = trim($process->getOutput().' '.$process->getErrorOutput());

        if ($process->isSuccessful()) {
            return [true, $output, 'Ping responded successfully.'];
        }

        return [false, $output, $this->normalizeFailureMessage($process, $output)];
    }

    private function pingProcess(string $ipAddress): Process
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return new Process(['ping', '-n', '1', '-w', '1000', $ipAddress]);
        }

        return new Process(['ping', '-c', '1', $ipAddress]);
    }

    private function extractLatency(string $output): ?int
    {
        if (! preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $output, $matches)) {
            return null;
        }

        return (int) round((float) $matches[1]);
    }

    private function normalizeFailureMessage(Process $process, string $output): string
    {
        if ($process->getExitCode() === 127) {
            return 'Ping command is not available on the server.';
        }

        if (str_contains(strtolower($output), 'could not find host')) {
            return 'DNS resolution failed for this host.';
        }

        if (str_contains(strtolower($output), 'name or service not known')) {
            return 'DNS resolution failed for this host.';
        }

        return 'Host did not respond to ping.';
    }
}
