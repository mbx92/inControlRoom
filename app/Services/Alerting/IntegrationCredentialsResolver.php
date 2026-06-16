<?php

namespace App\Services\Alerting;

use App\Models\Integration;

class IntegrationCredentialsResolver
{
    public function resolve(Integration $integration): array
    {
        $vaultEntry = $integration->relationLoaded('vaultEntry')
            ? $integration->vaultEntry
            : $integration->vaultEntry()->first();

        if ($vaultEntry) {
            return [
                'token' => $vaultEntry->revealSecret(),
            ];
        }

        $credentials = $integration->credentials;

        if (is_array($credentials)) {
            return $credentials;
        }

        if (is_string($credentials) && $credentials !== '') {
            return json_decode($credentials, true) ?: [];
        }

        return [];
    }
}
