<?php

namespace App\Services\Alerting\Nas;

use App\Models\Integration;

interface NasVendorAdapter
{
    public function supports(string $vendor): bool;

    public function label(): string;

    public function check(Integration $integration): array;

    public function capture(Integration $integration): array;

    public function summarize(array $snapshot): array;
}
