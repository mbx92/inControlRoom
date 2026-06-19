<?php

namespace Tests\Feature;

use App\Models\InventoryAsset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_admin_can_import_inventory_assets_from_spreadsheet(): void
    {
        $admin = $this->createAdminUser();
        $site = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $existing = InventoryAsset::create([
            'site_id' => $site->id,
            'name' => 'Old Switch',
            'category' => 'Switch',
            'status' => 'planned',
            'asset_tag' => 'INV-SW-001',
        ]);

        $csv = implode("\n", [
            'site_code,site_name,name,category,status,asset_tag,serial_number,manufacturer,model,primary_ip,location_label,owner_name,acquired_at,warranty_expires_at,custom_fields,notes',
            'MKS-01,Main Hospital,Core Switch ICU,Switch,active,INV-SW-001,SER-001,Cisco,Catalyst 9300,10.10.10.2,"Lantai 1 / ICU",Infra Team,2026-01-15,2029-01-15,"rack_unit: 12 | maintenance_window: Minggu 01:00","Updated lewat import"',
            'MKS-01,Main Hospital,UPS Front Office,UPS,standby,,UPS-2026-44,APC,Smart-UPS 2200,,"Lantai 1 / Front Office",General Affairs,,,\"capacity_va: 2200\",\"Asset baru dari import\"',
        ]);

        $file = UploadedFile::fake()->createWithContent('inventory-import.csv', $csv);

        $this->actingAs($admin)
            ->post(route('inventory.import'), [
                'file' => $file,
            ])
            ->assertRedirect(route('settings.index'));

        $existing->refresh();

        $this->assertSame('Core Switch ICU', $existing->name);
        $this->assertSame('active', $existing->status);
        $this->assertSame([
            'rack_unit' => '12',
            'maintenance_window' => 'Minggu 01:00',
        ], $existing->custom_fields);

        $this->assertDatabaseHas('inventory_assets', [
            'site_id' => $site->id,
            'name' => 'UPS Front Office',
            'category' => 'UPS',
            'status' => 'standby',
            'serial_number' => 'UPS-2026-44',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory_asset.import_update',
            'target_id' => $existing->id,
            'user_id' => $admin->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory_asset.import_create',
            'user_id' => $admin->id,
        ]);
    }

    public function test_import_reports_invalid_rows_without_creating_assets(): void
    {
        $admin = $this->createAdminUser();

        $csv = implode("\n", [
            'site_code,site_name,name,category,status,asset_tag',
            'UNKNOWN,,Broken Asset,Switch,invalid-status,INV-ERR-001',
        ]);

        $file = UploadedFile::fake()->createWithContent('inventory-invalid.csv', $csv);

        $response = $this->actingAs($admin)
            ->post(route('inventory.import'), [
                'file' => $file,
            ]);

        $response->assertRedirect(route('settings.index'));
        $response->assertSessionHas('inventory_import_report');

        $report = $response->getSession()->get('inventory_import_report');

        $this->assertSame(1, $report['total_rows']);
        $this->assertSame(0, $report['created']);
        $this->assertSame(0, $report['updated']);
        $this->assertSame(1, $report['failed']);
        $this->assertNotEmpty($report['errors']);
        $this->assertDatabaseMissing('inventory_assets', [
            'asset_tag' => 'INV-ERR-001',
        ]);
    }

    public function test_admin_can_download_inventory_import_template(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get(route('inventory.import-template'));

        $response->assertOk();
        $response->assertHeader(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $response->assertHeader(
            'content-disposition',
            'attachment; filename=inventory-asset-import-template.xlsx',
        );

        $content = $response->streamedContent();

        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('PK', $content);
    }

    private function createAdminUser(): User
    {
        return User::factory()->admin()->create();
    }
}
