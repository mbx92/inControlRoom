<?php

use App\Models\AuditLog;
use App\Models\Integration;
use App\Models\User;
use App\Models\VaultEntry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('vault:migrate-inline-credentials {--dry-run} {--user=}', function () {
    $isDryRun = (bool) $this->option('dry-run');
    $userOption = $this->option('user');

    $actingUser = null;

    if (is_string($userOption) && trim($userOption) !== '') {
        $actingUser = is_numeric($userOption)
            ? User::query()->find((int) $userOption)
            : User::query()->where('email', $userOption)->first();

        if (! $actingUser) {
            $this->error("Operator user \"{$userOption}\" was not found.");

            return self::FAILURE;
        }
    } else {
        $actingUser = User::query()->orderBy('created_at')->first();

        if (! $actingUser) {
            $this->error('No user is available to attribute the migration audit log. Pass --user=<id|email>.');

            return self::FAILURE;
        }

        $this->warn("No --user supplied. Using {$actingUser->email} for audit attribution.");
    }

    $integrations = Integration::query()
        ->whereNull('vault_entry_id')
        ->orderBy('name')
        ->get();

    $created = 0;
    $skipped = 0;

    foreach ($integrations as $integration) {
        if ($integration->type !== 'proxmox') {
            $this->line("Skipping {$integration->name}: unsupported type {$integration->type}.");
            $skipped++;

            continue;
        }

        $credentials = [];

        if (is_array($integration->credentials)) {
            $credentials = $integration->credentials;
        } elseif (is_string($integration->credentials) && $integration->credentials !== '') {
            $credentials = json_decode($integration->credentials, true) ?: [];
        }

        $token = trim((string) ($credentials['token'] ?? ''));

        if ($token === '') {
            $this->line("Skipping {$integration->name}: no inline Proxmox token found.");
            $skipped++;

            continue;
        }

        $vaultName = "{$integration->name} API Token";
        $suffix = 2;

        while (VaultEntry::query()->where('name', $vaultName)->exists()) {
            $vaultName = "{$integration->name} API Token {$suffix}";
            $suffix++;
        }

        if ($isDryRun) {
            $this->info("[dry-run] Would migrate {$integration->name} -> {$vaultName}");
            $created++;

            continue;
        }

        DB::transaction(function () use ($integration, $token, $vaultName, $actingUser): void {
            $vaultEntry = VaultEntry::create([
                'site_id' => $integration->site_id,
                'name' => $vaultName,
                'kind' => 'proxmox_api_token',
                'ciphertext' => $token,
                'notes' => 'Migrated automatically from legacy inline integration credentials.',
                'last_rotated_at' => $integration->updated_at ?? now(),
                'is_active' => true,
            ]);

            $integration->forceFill([
                'vault_entry_id' => $vaultEntry->id,
                'credentials' => json_encode([]),
            ])->save();

            AuditLog::record(
                userId: $actingUser->id,
                action: 'vault.migrate_inline_credentials',
                targetType: 'vault_entry',
                targetId: $vaultEntry->id,
                payload: [
                    'name' => $vaultEntry->name,
                    'integration_id' => $integration->id,
                    'integration_name' => $integration->name,
                ],
                ipAddress: null,
                siteId: $integration->site_id,
            );

            AuditLog::record(
                userId: $actingUser->id,
                action: 'integration.migrate_inline_credentials',
                targetType: 'integration',
                targetId: $integration->id,
                payload: [
                    'name' => $integration->name,
                    'vault_entry_id' => $vaultEntry->id,
                    'vault_entry_name' => $vaultEntry->name,
                ],
                ipAddress: null,
                siteId: $integration->site_id,
            );
        });

        $this->info("Migrated {$integration->name} -> {$vaultName}");
        $created++;
    }

    $this->newLine();
    $this->info("Processed {$integrations->count()} integrations.");
    $this->info("Migrated: {$created}");
    $this->info("Skipped: {$skipped}");

    if ($isDryRun) {
        $this->warn('Dry run only. No database changes were written.');
    }

    return self::SUCCESS;
})->purpose('Move legacy inline integration credentials into the internal vault');
