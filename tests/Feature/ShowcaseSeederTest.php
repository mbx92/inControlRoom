<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Integration;
use App\Models\InventoryAsset;
use App\Models\Site;
use Database\Seeders\AlertShowcaseSeeder;
use Database\Seeders\TopologyShowcaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShowcaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_topology_showcase_seeder_creates_soho_and_enterprise_sites_with_valid_links(): void
    {
        $this->seed(TopologyShowcaseSeeder::class);

        $soho = Site::query()->where('code', TopologyShowcaseSeeder::SOHO_SITE_CODE)->firstOrFail();
        $hq = Site::query()->where('code', TopologyShowcaseSeeder::HQ_SITE_CODE)->firstOrFail();

        $this->assertGreaterThanOrEqual(10, InventoryAsset::query()->where('site_id', $soho->id)->count());
        $this->assertGreaterThanOrEqual(15, InventoryAsset::query()->where('site_id', $hq->id)->count());

        $this->assertDatabaseHas('inventory_assets', [
            'site_id' => $soho->id,
            'name' => 'SOHO Edge Router',
            'category' => 'Router',
            'location_label' => 'Ground Floor / Network Rack',
        ]);
        $this->assertDatabaseHas('inventory_assets', [
            'site_id' => $hq->id,
            'name' => 'HQ Core Switch',
            'category' => 'Switch',
            'location_label' => 'Floor 1 / Server Room',
        ]);

        $accessSwitch = InventoryAsset::query()->where('name', 'SOHO Access Switch PoE')->firstOrFail();
        $edgeRouter = InventoryAsset::query()->where('name', 'SOHO Edge Router')->firstOrFail();

        $this->assertDatabaseHas('asset_links', [
            'source_asset_id' => $accessSwitch->id,
            'target_asset_id' => $edgeRouter->id,
            'link_type' => 'uplink',
        ]);

        $proxmoxIntegration = Integration::query()->where('name', 'HQ Proxmox Cluster')->firstOrFail();

        $this->assertSame('proxmox', $proxmoxIntegration->type);
        $this->assertNotNull($proxmoxIntegration->vault_entry_id);
        $this->assertSame('failure', $proxmoxIntegration->last_test_status);
        $this->assertSame('10.20.10.10', InventoryAsset::query()->findOrFail($proxmoxIntegration->config['host_asset_id'])->primary_ip);
    }

    public function test_alert_showcase_seeder_creates_open_acknowledged_and_resolved_alerts(): void
    {
        $this->seed([
            TopologyShowcaseSeeder::class,
            AlertShowcaseSeeder::class,
        ]);

        $this->assertDatabaseHas('events', [
            'status' => Event::STATUS_OPEN,
            'title' => 'Door access API latency elevated',
        ]);
        $this->assertDatabaseHas('events', [
            'status' => Event::STATUS_ACKNOWLEDGED,
            'title' => 'High disk usage: File Server VM #204',
        ]);
        $this->assertDatabaseHas('events', [
            'status' => Event::STATUS_RESOLVED,
            'title' => 'CCTV monitoring sync recovered',
        ]);

        $this->assertSame(4, Event::query()->count());
    }
}
