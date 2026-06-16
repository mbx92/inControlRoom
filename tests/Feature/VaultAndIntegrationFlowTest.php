<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\InventoryAsset;
use App\Models\Site;
use App\Models\User;
use App\Models\VaultEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VaultAndIntegrationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_and_reveal_a_vault_entry(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('vault.store'), [
                'name' => 'Production Proxmox Token',
                'kind' => 'proxmox_api_token',
                'secret' => 'root@pam!infra=super-secret-token',
                'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGnMZ8J4Y5g9jvQ1Y8W2x8M7i3t0M4wqJ2k0gJ6s7J8w ops@example',
                'is_active' => true,
            ])
            ->assertRedirect(route('vault.index'));

        $entry = VaultEntry::query()->firstOrFail();

        $this->assertNotSame('root@pam!infra=super-secret-token', $entry->getRawOriginal('ciphertext'));
        $this->assertSame('root@pam!infra=super-secret-token', $entry->revealSecret());
        $this->assertNotNull($entry->fingerprint);
        $this->assertStringStartsWith('SHA256:', $entry->fingerprint);
        $this->assertDatabaseHas('vault_entry_access_logs', [
            'vault_entry_id' => $entry->id,
            'action' => 'create',
        ]);

        $this->actingAs($user)
            ->post(route('vault.reveal', $entry))
            ->assertRedirect(route('vault.show', $entry))
            ->assertSessionHas('revealed_secret', 'root@pam!infra=super-secret-token');

        $this->assertDatabaseHas('vault_entry_access_logs', [
            'vault_entry_id' => $entry->id,
            'action' => 'reveal',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'vault.reveal',
            'target_type' => 'vault_entry',
            'target_id' => $entry->id,
        ]);
    }

    public function test_admin_can_create_a_proxmox_integration_backed_by_vault(): void
    {
        $user = User::factory()->admin()->create();
        $vaultEntry = VaultEntry::create([
            'name' => 'Primary Proxmox Token',
            'kind' => 'proxmox_api_token',
            'ciphertext' => 'root@pam!infra=super-secret-token',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('integrations.store'), [
                'type' => 'proxmox',
                'name' => 'Primary Cluster',
                'base_url' => 'https://proxmox.example.com:8006',
                'vault_entry_id' => $vaultEntry->id,
                'config' => [
                    'verify_ssl' => true,
                ],
            ])
            ->assertRedirect(route('integrations.index'));

        $integration = Integration::query()->firstOrFail();

        $this->assertSame('proxmox', $integration->type);
        $this->assertSame($vaultEntry->id, $integration->vault_entry_id);
        $this->assertSame('[]', $integration->credentials);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.create',
            'target_type' => 'integration',
            'target_id' => $integration->id,
        ]);
    }

    public function test_api_health_check_stores_latest_proxmox_api_metadata(): void
    {
        $user = User::factory()->operator()->create();
        $vaultEntry = VaultEntry::create([
            'name' => 'Health Check Token',
            'kind' => 'proxmox_api_token',
            'ciphertext' => 'root@pam!infra=health-token',
            'is_active' => true,
        ]);

        $integration = Integration::create([
            'type' => 'proxmox',
            'name' => 'Health Cluster',
            'base_url' => 'https://proxmox.example.com:8006',
            'vault_entry_id' => $vaultEntry->id,
            'credentials' => json_encode([]),
            'config' => [
                'verify_ssl' => true,
            ],
            'is_active' => true,
        ]);

        Http::fake([
            'https://proxmox.example.com:8006/api2/json/version' => Http::response([
                'data' => [
                    'version' => '8.4.1',
                    'release' => '1',
                    'repoid' => 'abc123',
                ],
            ], 200),
            'https://proxmox.example.com:8006/api2/json/nodes' => Http::response([
                'data' => [
                    ['node' => 'pve01'],
                ],
            ], 200),
            'https://proxmox.example.com:8006/api2/json/nodes/pve01/qemu' => Http::response([
                'data' => [],
            ], 200),
            'https://proxmox.example.com:8006/api2/json/nodes/pve01/lxc' => Http::response([
                'data' => [],
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('integrations.test', $integration))
            ->assertRedirect();

        $integration->refresh();

        $this->assertSame('success', $integration->last_test_status);
        $this->assertSame('8.4.1', $integration->last_test_meta['version']);
        $this->assertSame('valid', $integration->last_test_meta['auth_status']);
        $this->assertTrue($integration->last_test_meta['api_reachable']);
        $this->assertSame(
            'https://proxmox.example.com:8006/api2/json/version',
            $integration->last_test_meta['health_endpoint']
        );
        $this->assertIsInt($integration->last_test_meta['latency_ms']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'integration.test',
            'target_type' => 'integration',
            'target_id' => $integration->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_admin_can_create_a_custom_api_integration_without_vault_secret(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('integrations.store'), [
                'type' => 'custom_api',
                'name' => 'Node Service API',
                'base_url' => 'https://node-api.example.com',
                'config' => [
                    'verify_ssl' => true,
                    'auth_mode' => 'none',
                    'health_path' => '/health',
                    'health_method' => 'GET',
                    'health_expected_status' => 200,
                ],
            ])
            ->assertRedirect(route('integrations.index'));

        $integration = Integration::query()->firstOrFail();

        $this->assertSame('custom_api', $integration->type);
        $this->assertNull($integration->vault_entry_id);
        $this->assertSame('/health', $integration->config['health_path']);
        $this->assertSame('GET', $integration->config['health_method']);
        $this->assertSame(200, $integration->config['health_expected_status']);
    }

    public function test_api_health_check_stores_custom_api_metadata(): void
    {
        $user = User::factory()->operator()->create();

        $integration = Integration::create([
            'type' => 'custom_api',
            'name' => 'Node Health API',
            'base_url' => 'https://node-api.example.com',
            'credentials' => json_encode([]),
            'config' => [
                'verify_ssl' => true,
                'auth_mode' => 'none',
                'health_path' => '/health',
                'health_method' => 'GET',
                'health_expected_status' => 200,
            ],
            'is_active' => true,
        ]);

        Http::fake([
            'https://node-api.example.com/health' => Http::response([
                'ok' => true,
            ], 200),
        ]);

        $this->actingAs($user)
            ->post(route('integrations.test', $integration))
            ->assertRedirect();

        $integration->refresh();

        $this->assertSame('success', $integration->last_test_status);
        $this->assertSame('not_required', $integration->last_test_meta['auth_status']);
        $this->assertSame(200, $integration->last_test_meta['http_status']);
        $this->assertSame('/health', parse_url($integration->last_test_meta['health_endpoint'], PHP_URL_PATH));
        $this->assertSame('GET', $integration->last_test_meta['health_method']);
        $this->assertSame(200, $integration->last_test_meta['expected_status']);
    }

    public function test_admin_can_filter_vault_entries_and_integrations_by_site_scope(): void
    {
        $admin = User::factory()->admin()->create();

        $siteA = \App\Models\Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);
        $siteB = \App\Models\Site::create([
            'name' => 'Branch Clinic',
            'code' => 'MKS-02',
            'business_type' => 'Clinic',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        VaultEntry::create([
            'site_id' => $siteA->id,
            'name' => 'Site Secret',
            'kind' => 'api_key',
            'ciphertext' => 'secret-a',
            'is_active' => true,
        ]);
        VaultEntry::create([
            'site_id' => null,
            'name' => 'Global Secret',
            'kind' => 'api_key',
            'ciphertext' => 'secret-global',
            'is_active' => true,
        ]);

        Integration::create([
            'site_id' => $siteB->id,
            'type' => 'custom_api',
            'name' => 'Site B API',
            'base_url' => 'https://site-b.example.com',
            'credentials' => json_encode([]),
            'config' => ['verify_ssl' => true],
            'is_active' => true,
        ]);
        Integration::create([
            'site_id' => null,
            'type' => 'custom_api',
            'name' => 'Global API',
            'base_url' => 'https://global.example.com',
            'credentials' => json_encode([]),
            'config' => ['verify_ssl' => true],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('vault.index', ['site' => $siteA->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site', $siteA->id)
                ->has('entries', 1)
                ->where('entries.0.name', 'Site Secret')
            );

        $this->actingAs($admin)
            ->get(route('vault.index', ['site' => 'global']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site', 'global')
                ->has('entries', 1)
                ->where('entries.0.name', 'Global Secret')
            );

        $this->actingAs($admin)
            ->get(route('integrations.index', ['site' => $siteB->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site', $siteB->id)
                ->has('integrations', 1)
                ->where('integrations.0.name', 'Site B API')
            );

        $this->actingAs($admin)
            ->get(route('integrations.index', ['site' => 'global']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site', 'global')
                ->has('integrations', 1)
                ->where('integrations.0.name', 'Global API')
            );
    }

    public function test_scoped_operator_cannot_access_vault_or_integration_from_another_site(): void
    {
        $allowedSite = \App\Models\Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);
        $otherSite = \App\Models\Site::create([
            'name' => 'Branch Clinic',
            'code' => 'MKS-02',
            'business_type' => 'Clinic',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $user = User::factory()->operator()->create();
        $user->sites()->attach($allowedSite);

        $vaultEntry = VaultEntry::create([
            'site_id' => $otherSite->id,
            'name' => 'Other Site Secret',
            'kind' => 'api_key',
            'ciphertext' => 'secret-b',
            'is_active' => true,
        ]);
        $integration = Integration::create([
            'site_id' => $otherSite->id,
            'type' => 'custom_api',
            'name' => 'Other Site API',
            'base_url' => 'https://other-site.example.com',
            'credentials' => json_encode([]),
            'config' => ['verify_ssl' => true],
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get(route('vault.show', $vaultEntry))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('integrations.show', $integration))
            ->assertForbidden();
    }

    public function test_admin_cannot_assign_a_proxmox_host_asset_from_another_site(): void
    {
        $user = User::factory()->admin()->create();
        $siteA = $this->createSite('Main Hospital', 'MKS-01');
        $siteB = $this->createSite('Branch Clinic', 'MKS-02');
        $vaultEntry = VaultEntry::create([
            'site_id' => $siteA->id,
            'name' => 'Primary Proxmox Token',
            'kind' => 'proxmox_api_token',
            'ciphertext' => 'root@pam!infra=super-secret-token',
            'is_active' => true,
        ]);
        $hostAsset = InventoryAsset::factory()->for($siteB)->create([
            'name' => 'Wrong Site Hypervisor',
        ]);

        $this->actingAs($user)
            ->from(route('integrations.create'))
            ->post(route('integrations.store'), [
                'site_id' => $siteA->id,
                'type' => 'proxmox',
                'name' => 'Primary Cluster',
                'base_url' => 'https://proxmox.example.com:8006',
                'vault_entry_id' => $vaultEntry->id,
                'config' => [
                    'verify_ssl' => true,
                    'host_asset_id' => $hostAsset->id,
                ],
            ])
            ->assertRedirect(route('integrations.create'))
            ->assertSessionHasErrors('config.host_asset_id');

        $this->assertDatabaseCount('integrations', 0);
    }

    public function test_admin_cannot_assign_a_host_asset_to_a_non_proxmox_integration(): void
    {
        $user = User::factory()->admin()->create();
        $site = $this->createSite('Main Hospital', 'MKS-01');
        $hostAsset = InventoryAsset::factory()->for($site)->create([
            'name' => 'API Host',
        ]);

        $this->actingAs($user)
            ->from(route('integrations.create'))
            ->post(route('integrations.store'), [
                'site_id' => $site->id,
                'type' => 'custom_api',
                'name' => 'Node Service API',
                'base_url' => 'https://node-api.example.com',
                'config' => [
                    'verify_ssl' => true,
                    'auth_mode' => 'none',
                    'health_path' => '/health',
                    'health_method' => 'GET',
                    'health_expected_status' => 200,
                    'host_asset_id' => $hostAsset->id,
                ],
            ])
            ->assertRedirect(route('integrations.create'))
            ->assertSessionHasErrors('config.host_asset_id');

        $this->assertDatabaseCount('integrations', 0);
    }

    private function createSite(string $name, string $code): Site
    {
        return Site::create([
            'name' => $name,
            'code' => $code,
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);
    }
}
