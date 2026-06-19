<?php

namespace App\Services\Alerting;

use App\Models\Integration;

class IntegrationMonitoringService
{
    public function __construct(
        private readonly IntegrationHealthCheckService $healthCheckService,
        private readonly ProxmoxMetricSnapshotService $metricSnapshotService,
        private readonly DockerMonitoringService $dockerMonitoringService,
        private readonly NvrMonitoringService $nvrMonitoringService,
        private readonly NasMonitoringService $nasMonitoringService,
        private readonly AlertEvaluator $alertEvaluator,
    ) {
    }

    public function run(Integration $integration, bool $collectMetrics = true): array
    {
        $result = $this->healthCheckService->check($integration);

        $integration->forceFill([
            'last_tested_at' => now(),
            'last_test_status' => $result['success'] ? 'success' : 'failure',
            'last_test_message' => $result['message'],
            'last_test_meta' => $result['meta'] ?? $integration->last_test_meta,
        ])->save();

        $this->alertEvaluator->syncIntegrationHealth($integration, $result);

        $metricError = null;
        $guestCount = 0;

        if ($collectMetrics && $integration->type === 'proxmox' && $result['success']) {
            try {
                $guests = $this->metricSnapshotService->capture($integration);
                $guestCount = count($guests);
                $this->alertEvaluator->evaluateProxmoxGuests($integration, $guests);
            } catch (\Throwable $e) {
                $metricError = $e->getMessage();
            }
        }

        if ($collectMetrics && $integration->type === 'docker' && $result['success']) {
            try {
                $containers = $this->dockerMonitoringService->capture($integration);
                $guestCount = count($containers);
                $this->alertEvaluator->evaluateDockerContainers($integration, $containers);
            } catch (\Throwable $e) {
                $metricError = $e->getMessage();
            }
        }

        if ($collectMetrics && $integration->type === 'nvr' && $result['success']) {
            try {
                $channels = $this->nvrMonitoringService->capture($integration);
                $guestCount = count($channels);
            } catch (\Throwable $e) {
                $metricError = $e->getMessage();
            }
        }

        if ($collectMetrics && $integration->type === 'nas' && $result['success']) {
            try {
                $snapshot = $this->nasMonitoringService->capture($integration);
                $guestCount = count($snapshot['volumes'] ?? []) + count($snapshot['disks'] ?? []);
            } catch (\Throwable $e) {
                $metricError = $e->getMessage();
            }
        }

        return [
            ...$result,
            'metric_error' => $metricError,
            'guest_count' => $guestCount,
        ];
    }
}
