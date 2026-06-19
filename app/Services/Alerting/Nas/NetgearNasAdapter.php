<?php

namespace App\Services\Alerting\Nas;

use App\Models\Integration;

class NetgearNasAdapter extends AbstractNasAdapter
{
    public function supports(string $vendor): bool
    {
        return $vendor === 'netgear';
    }

    public function label(): string
    {
        return 'NETGEAR ReadyNAS';
    }

    public function check(Integration $integration): array
    {
        return $this->genericHealthCheck($integration, '/');
    }

    public function capture(Integration $integration): array
    {
        return $this->defaultSnapshot();
    }

    public function summarize(array $snapshot): array
    {
        return $this->summarizeDefault($snapshot);
    }
}
