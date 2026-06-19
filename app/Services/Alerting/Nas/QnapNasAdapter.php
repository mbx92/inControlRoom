<?php

namespace App\Services\Alerting\Nas;

use App\Models\Integration;

class QnapNasAdapter extends AbstractNasAdapter
{
    public function supports(string $vendor): bool
    {
        return $vendor === 'qnap';
    }

    public function label(): string
    {
        return 'QNAP QTS';
    }

    public function check(Integration $integration): array
    {
        return $this->genericHealthCheck($integration, '/cgi-bin/');
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
