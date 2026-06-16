<?php

namespace Tests\Feature;

use App\Models\InventoryAsset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryAssetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_inventory_asset_with_extra_attributes(): void
    {
        $user = $this->createAdminUser();
        $site = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('inventory.store'), [
                'site_id' => $site->id,
                'name' => 'Core Switch ICU',
                'category' => 'Switch',
                'status' => 'active',
                'asset_tag' => 'INV-SW-001',
                'serial_number' => 'SER-001',
                'manufacturer' => 'Cisco',
                'model' => 'Catalyst 9300',
                'primary_ip' => '10.10.10.2',
                'location_label' => 'MDF Rack A / U12',
                'owner_name' => 'Infra Team',
                'custom_fields_text' => "rack_unit: 12\nmaintenance_window: Sunday 01:00",
                'notes' => 'Primary switch for ICU floor.',
            ])
            ->assertRedirect(route('inventory.index'));

        $asset = InventoryAsset::query()->firstOrFail();

        $this->assertSame($site->id, $asset->site_id);
        $this->assertSame('Core Switch ICU', $asset->name);
        $this->assertSame('Switch', $asset->category);
        $this->assertSame('active', $asset->status);
        $this->assertSame([
            'rack_unit' => '12',
            'maintenance_window' => 'Sunday 01:00',
        ], $asset->custom_fields);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory_asset.create',
            'target_type' => 'inventory_asset',
            'target_id' => $asset->id,
            'user_id' => $user->id,
            'site_id' => $site->id,
        ]);
    }

    public function test_admin_can_update_inventory_asset(): void
    {
        $user = $this->createAdminUser();
        $site = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $asset = InventoryAsset::create([
            'site_id' => $site->id,
            'name' => 'WAN Router',
            'category' => 'Router',
            'status' => 'planned',
            'asset_tag' => 'INV-RT-002',
        ]);

        $this->actingAs($user)
            ->put(route('inventory.update', $asset), [
                'site_id' => $site->id,
                'name' => 'WAN Router',
                'category' => 'Router',
                'status' => 'active',
                'asset_tag' => 'INV-RT-002',
                'serial_number' => 'ROUTER-998',
                'manufacturer' => 'MikroTik',
                'model' => 'CCR2004',
                'primary_ip' => '172.16.0.1',
                'location_label' => 'POP Rack / U04',
                'owner_name' => 'Network Team',
                'custom_fields_text' => "uplink: ISP-A\nha_partner: WAN Router B",
                'notes' => 'Activated after cutover.',
            ])
            ->assertRedirect(route('inventory.show', $asset));

        $asset->refresh();

        $this->assertSame('active', $asset->status);
        $this->assertSame('MikroTik', $asset->manufacturer);
        $this->assertSame([
            'uplink' => 'ISP-A',
            'ha_partner' => 'WAN Router B',
        ], $asset->custom_fields);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory_asset.update',
            'target_type' => 'inventory_asset',
            'target_id' => $asset->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_inventory_index_uses_paginated_server_side_results(): void
    {
        $user = User::factory()->operator()->create();
        $site = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        InventoryAsset::factory()->count(30)->for($site)->create();

        $response = $this->actingAs($user)->get(route('inventory.index', [
            'search' => 'AST-',
        ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Index')
            ->where('filters.search', 'AST-')
            ->has('assets.data', 25)
            ->where('assets.total', 30)
            ->has('assets.links')
        );
    }

    public function test_user_can_view_inventory_asset_detail_page(): void
    {
        $user = User::factory()->operator()->create();
        $site = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $asset = InventoryAsset::create([
            'site_id' => $site->id,
            'name' => 'Core Router',
            'category' => 'Router',
            'status' => 'active',
            'asset_tag' => 'INV-RT-100',
            'primary_ip' => '10.10.20.1',
            'custom_fields' => [
                'uplink' => 'ISP-A',
            ],
        ]);

        $this->actingAs($user)
            ->get(route('inventory.show', $asset))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/Show')
                ->where('asset.id', $asset->id)
                ->where('asset.name', 'Core Router')
                ->where('asset.primary_ip', '10.10.20.1')
                ->where('asset.custom_fields.0.key', 'uplink')
                ->where('asset.custom_fields.0.value', 'ISP-A')
            );
    }

    public function test_admin_can_filter_inventory_by_site_and_unassigned_state(): void
    {
        $admin = $this->createAdminUser();
        $siteA = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);
        $siteB = Site::create([
            'name' => 'Branch Clinic',
            'code' => 'MKS-02',
            'business_type' => 'Clinic',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        InventoryAsset::factory()->for($siteA)->create(['name' => 'Asset A']);
        InventoryAsset::factory()->for($siteB)->create(['name' => 'Asset B']);
        InventoryAsset::factory()->create(['site_id' => null, 'name' => 'Unassigned Asset']);

        $this->actingAs($admin)
            ->get(route('inventory.index', ['site' => $siteA->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site', $siteA->id)
                ->where('assets.total', 1)
                ->where('assets.data.0.name', 'Asset A')
            );

        $this->actingAs($admin)
            ->get(route('inventory.index', ['site' => 'unassigned']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.site', 'unassigned')
                ->where('assets.total', 1)
                ->where('assets.data.0.name', 'Unassigned Asset')
            );
    }

    public function test_scoped_operator_cannot_view_inventory_asset_from_another_site(): void
    {
        $allowedSite = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);
        $otherSite = Site::create([
            'name' => 'Branch Clinic',
            'code' => 'MKS-02',
            'business_type' => 'Clinic',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $user = User::factory()->operator()->create();
        $user->sites()->attach($allowedSite);

        $asset = InventoryAsset::factory()->for($otherSite)->create();

        $this->actingAs($user)
            ->get(route('inventory.show', $asset))
            ->assertForbidden();
    }

    private function createAdminUser(): User
    {
        return User::factory()->admin()->create();
    }
}
