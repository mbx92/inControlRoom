<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\InventoryAsset;
use App\Models\Site;
use App\Models\User;
use App\Models\VaultEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopologyHostAssetResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_proxmox_topology_does_not_fallback_to_unrelated_server_when_ips_differ(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        InventoryAsset::factory()->for($site)->create([
            'name' => 'Server Proxmox Utama',
            'category' => 'Server',
            'primary_ip' => '10.0.0.244',
        ]);

        $vaultEntry = VaultEntry::create([
            'name' => 'Proxmox Token',
            'kind' => 'api_token',
            'ciphertext' => 'token-value',
            'is_active' => true,
        ]);

        Integration::create([
            'site_id' => $site->id,
            'type' => 'proxmox',
            'name' => 'Proxmox VE - pve2',
            'base_url' => 'https://10.0.0.174:8006',
            'vault_entry_id' => $vaultEntry->id,
            'credentials' => json_encode([]),
            'config' => [
                'verify_ssl' => false,
                'host_asset_id' => null,
            ],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('topology.index', ['site' => $site->id, 'mode' => 'proxmox']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('topologyGraph.meta.hostAssetName', null)
            );
    }

    public function test_proxmox_topology_auto_detects_host_by_integration_ip(): void
    {
        $admin = User::factory()->admin()->create();
        $site = Site::factory()->create();

        InventoryAsset::factory()->for($site)->create([
            'name' => 'Server Proxmox Utama',
            'category' => 'Server',
            'primary_ip' => '10.0.0.244',
        ]);

        InventoryAsset::factory()->for($site)->create([
            'name' => 'PVE Node 2',
            'category' => 'Hypervisor',
            'primary_ip' => '10.0.0.174',
        ]);

        $vaultEntry = VaultEntry::create([
            'name' => 'Proxmox Token',
            'kind' => 'api_token',
            'ciphertext' => 'token-value',
            'is_active' => true,
        ]);

        Integration::create([
            'site_id' => $site->id,
            'type' => 'proxmox',
            'name' => 'Proxmox VE - pve2',
            'base_url' => '10.0.0.174:8006',
            'vault_entry_id' => $vaultEntry->id,
            'credentials' => json_encode([]),
            'config' => [
                'verify_ssl' => false,
                'host_asset_id' => null,
            ],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('topology.index', ['site' => $site->id, 'mode' => 'proxmox']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('topologyGraph.meta.hostAssetName', 'PVE Node 2')
            );
    }
}
