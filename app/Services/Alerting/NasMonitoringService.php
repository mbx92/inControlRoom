<?php

namespace App\Services\Alerting;

use App\Models\Integration;
use App\Services\Alerting\Nas\NasVendorAdapter;
use App\Services\Alerting\Nas\NetgearNasAdapter;
use App\Services\Alerting\Nas\QnapNasAdapter;
use App\Services\Alerting\Nas\SynologyNasAdapter;

class NasMonitoringService
{
    /**
     * @var array<int, NasVendorAdapter>
     */
    private array $adapters;

    public function __construct(
        SynologyNasAdapter $synologyAdapter,
        QnapNasAdapter $qnapAdapter,
        NetgearNasAdapter $netgearAdapter,
    ) {
        $this->adapters = [
            $synologyAdapter,
            $qnapAdapter,
            $netgearAdapter,
        ];
    }

    public function check(Integration $integration): array
    {
        return $this->resolveAdapter($integration)->check($integration);
    }

    public function capture(Integration $integration): array
    {
        return $this->resolveAdapter($integration)->capture($integration);
    }

    public function summarize(Integration $integration, array $snapshot): array
    {
        return $this->resolveAdapter($integration)->summarize($snapshot);
    }

    public function vendorLabel(?string $vendor): string
    {
        return Integration::NAS_VENDORS[$vendor ?? ''] ?? strtoupper((string) $vendor);
    }

    private function resolveAdapter(Integration $integration): NasVendorAdapter
    {
        $vendor = (string) (($integration->config ?? [])['vendor'] ?? '');

        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($vendor)) {
                return $adapter;
            }
        }

        throw new \RuntimeException("Unsupported NAS vendor: {$vendor}");
    }
}
