<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\InventoryAsset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentInventoryLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_link_agent_to_inventory_asset(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $admin = User::factory()->admin()->create();

        $agent = Agent::create([
            'site_id' => $site->id,
            'hostname' => 'CLIENT-01',
            'device_id' => 'device-001',
            'agent_token_hash' => hash('sha256', 'agent-token'),
            'is_active' => true,
        ]);

        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Nurse Station PC',
            'asset_tag' => 'PC-001',
        ]);

        InventoryAsset::factory()->for($otherSite)->create([
            'name' => 'Other Site PC',
        ]);

        $this->actingAs($admin)
            ->from(route('settings.agents.index'))
            ->put(route('settings.agents.inventory-link.update', $agent), [
                'inventory_asset_id' => $asset->id,
            ])
            ->assertRedirect(route('settings.agents.index'))
            ->assertSessionHas('success');

        $agent->refresh();

        $this->assertSame($asset->id, $agent->inventory_asset_id);
    }

    public function test_admin_can_unlink_agent_from_inventory_asset(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->admin()->create();
        $asset = InventoryAsset::factory()->for($site)->create();

        $agent = Agent::create([
            'site_id' => $site->id,
            'hostname' => 'CLIENT-02',
            'device_id' => 'device-002',
            'agent_token_hash' => hash('sha256', 'agent-token-2'),
            'inventory_asset_id' => $asset->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->put(route('settings.agents.inventory-link.update', $agent), [
                'inventory_asset_id' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $agent->refresh();

        $this->assertNull($agent->inventory_asset_id);
    }

    public function test_link_rejects_asset_from_different_site(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $admin = User::factory()->admin()->create();

        $agent = Agent::create([
            'site_id' => $site->id,
            'hostname' => 'CLIENT-03',
            'device_id' => 'device-003',
            'agent_token_hash' => hash('sha256', 'agent-token-3'),
            'is_active' => true,
        ]);

        $asset = InventoryAsset::factory()->for($otherSite)->create();

        $this->actingAs($admin)
            ->put(route('settings.agents.inventory-link.update', $agent), [
                'inventory_asset_id' => $asset->id,
            ])
            ->assertSessionHasErrors('inventory_asset_id');
    }

    public function test_inventory_asset_detail_shows_linked_agent(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->admin()->create();
        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Reception PC',
        ]);

        $agent = Agent::create([
            'site_id' => $site->id,
            'hostname' => 'RECEPTION-PC',
            'device_id' => 'device-004',
            'agent_token_hash' => hash('sha256', 'agent-token-4'),
            'inventory_asset_id' => $asset->id,
            'primary_ip' => '10.20.30.50',
            'agent_version' => '1.0.0',
            'last_seen_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('inventory.show', $asset))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Show')
                ->where('linkedAgent.id', $agent->id)
                ->where('linkedAgent.hostname', 'RECEPTION-PC')
            );
    }

    public function test_admin_can_link_inventory_asset_to_agent_from_inventory_page(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->admin()->create();

        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Front Desk PC',
        ]);

        $agent = Agent::create([
            'site_id' => $site->id,
            'hostname' => 'FRONTDESK-PC',
            'device_id' => 'device-006',
            'agent_token_hash' => hash('sha256', 'agent-token-6'),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->from(route('inventory.show', $asset))
            ->put(route('inventory.agent-link.update', $asset), [
                'agent_id' => $agent->id,
            ])
            ->assertRedirect(route('inventory.show', $asset))
            ->assertSessionHas('success');

        $agent->refresh();

        $this->assertSame($asset->id, $agent->inventory_asset_id);
    }

    public function test_inventory_detail_includes_available_agents_for_admin(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->admin()->create();
        $asset = InventoryAsset::factory()->for($site)->create();

        Agent::create([
            'site_id' => $site->id,
            'hostname' => 'AVAILABLE-PC',
            'device_id' => 'device-007',
            'agent_token_hash' => hash('sha256', 'agent-token-7'),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('inventory.show', $asset))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Inventory/Show')
                ->where('canManageAgentLink', true)
                ->has('availableAgents', 1)
                ->where('availableAgents.0.hostname', 'AVAILABLE-PC')
            );
    }

    public function test_agent_metrics_detail_includes_inventory_asset(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->admin()->create();
        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Lab PC',
            'asset_tag' => 'PC-LAB-01',
        ]);

        $agent = Agent::create([
            'site_id' => $site->id,
            'hostname' => 'LAB-PC',
            'device_id' => 'device-005',
            'agent_token_hash' => hash('sha256', 'agent-token-5'),
            'inventory_asset_id' => $asset->id,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('agents.metrics.show', $agent))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/Metrics/Show')
                ->where('agent.inventory_asset.id', $asset->id)
                ->where('agent.inventory_asset.name', 'Lab PC')
                ->where('canManageInventoryLink', true)
            );
    }
}
