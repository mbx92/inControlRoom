<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use App\Models\VaultEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrateInlineCredentialsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_migrates_legacy_inline_credentials_to_vault(): void
    {
        $user = User::factory()->create([
            'email' => 'ops@example.com',
        ]);

        $integration = Integration::create([
            'type' => 'proxmox',
            'name' => 'Legacy Cluster',
            'base_url' => 'https://legacy-proxmox.example.com:8006',
            'credentials' => json_encode([
                'token' => 'root@pam!legacy=super-secret-token',
            ]),
            'config' => [
                'verify_ssl' => true,
            ],
            'is_active' => true,
        ]);

        $this->artisan('vault:migrate-inline-credentials', [
            '--user' => $user->id,
        ])->assertExitCode(0);

        $integration->refresh();

        $this->assertNotNull($integration->vault_entry_id);
        $this->assertSame('[]', $integration->credentials);

        $vaultEntry = VaultEntry::query()->findOrFail($integration->vault_entry_id);

        $this->assertSame('Legacy Cluster API Token', $vaultEntry->name);
        $this->assertSame('root@pam!legacy=super-secret-token', $vaultEntry->revealSecret());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'vault.migrate_inline_credentials',
            'target_type' => 'vault_entry',
            'target_id' => $vaultEntry->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.migrate_inline_credentials',
            'target_type' => 'integration',
            'target_id' => $integration->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_command_dry_run_does_not_write_changes(): void
    {
        $user = User::factory()->create();

        $integration = Integration::create([
            'type' => 'proxmox',
            'name' => 'Dry Run Cluster',
            'base_url' => 'https://dry-run.example.com:8006',
            'credentials' => json_encode([
                'token' => 'root@pam!dryrun=token',
            ]),
            'config' => [
                'verify_ssl' => true,
            ],
            'is_active' => true,
        ]);

        $this->artisan('vault:migrate-inline-credentials', [
            '--user' => $user->id,
            '--dry-run' => true,
        ])->assertExitCode(0);

        $integration->refresh();

        $this->assertNull($integration->vault_entry_id);
        $this->assertCount(0, VaultEntry::query()->get());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'vault.migrate_inline_credentials',
        ]);
    }
}
