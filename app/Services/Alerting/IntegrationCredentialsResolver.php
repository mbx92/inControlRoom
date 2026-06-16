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
            return $this->normalizeVaultSecret($vaultEntry->revealSecret());
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

    private function normalizeVaultSecret(string $secret): array
    {
        $trimmed = trim($secret);

        if ($trimmed === '') {
            return [];
        }

        $decoded = json_decode($trimmed, true);
        if (! is_array($decoded)) {
            return ['token' => $secret];
        }

        $credentials = [];

        foreach (['token', 'secret', 'password', 'username', 'console_username', 'console_password'] as $key) {
            if (! array_key_exists($key, $decoded) || ! is_scalar($decoded[$key])) {
                continue;
            }

            $value = trim((string) $decoded[$key]);
            if ($value === '') {
                continue;
            }

            if ($key === 'secret') {
                $credentials['token'] = $value;
                continue;
            }

            $credentials[$key] = $value;
        }

        return $credentials === [] ? ['token' => $secret] : $credentials;
    }
}
