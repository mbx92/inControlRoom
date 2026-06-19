<?php

namespace Tests\Feature;

use App\Models\InventoryAsset;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\AssetReachabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AssetMonitoringFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_asset_monitoring_page(): void
    {
        $user = User::factory()->operator()->create();
        $site = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Core Switch ICU',
            'primary_ip' => '10.10.10.2',
            'reachability_status' => InventoryAsset::REACHABILITY_ONLINE,
            'reachability_fail_count' => 0,
            'reachability_message' => 'Ping responded successfully.',
            'reachability_latency_ms' => 2,
            'reachability_checked_at' => now(),
            'reachability_last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('asset-monitoring.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Monitoring')
            ->where('assets.data.0.id', $asset->id)
            ->where('assets.data.0.reachability_status', InventoryAsset::REACHABILITY_ONLINE)
            ->where('stats.total', 1)
            ->where('stats.online', 1)
        );
    }

    public function test_scoped_operator_only_sees_allowed_site_assets_in_monitoring(): void
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

        InventoryAsset::factory()->for($allowedSite)->create([
            'name' => 'Allowed Asset',
            'primary_ip' => '10.10.10.2',
        ]);
        InventoryAsset::factory()->for($otherSite)->create([
            'name' => 'Blocked Asset',
            'primary_ip' => '10.10.10.3',
        ]);

        $this->actingAs($user)
            ->get(route('asset-monitoring.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total', 1)
                ->where('assets.data.0.name', 'Allowed Asset')
            );
    }

    public function test_operator_can_trigger_manual_reachability_check(): void
    {
        $site = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);
        $user = User::factory()->operator()->create();
        $user->sites()->attach($site);

        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Core Router',
            'primary_ip' => '10.10.20.1',
            'reachability_status' => InventoryAsset::REACHABILITY_UNKNOWN,
        ]);

        $mock = Mockery::mock(AssetReachabilityService::class);
        $mock->shouldReceive('checkAndStore')
            ->once()
            ->withArgs(fn (InventoryAsset $checkedAsset) => $checkedAsset->is($asset))
            ->andReturnUsing(function (InventoryAsset $checkedAsset) {
                $checkedAsset->forceFill([
                    'reachability_status' => InventoryAsset::REACHABILITY_ONLINE,
                    'reachability_checked_at' => now(),
                    'reachability_last_seen_at' => now(),
                    'reachability_latency_ms' => 3,
                    'reachability_fail_count' => 0,
                    'reachability_message' => 'Ping responded successfully.',
                ])->save();

                return $checkedAsset->fresh(['site']);
            });

        $this->app->instance(AssetReachabilityService::class, $mock);

        $this->actingAs($user)
            ->post(route('asset-monitoring.check', $asset))
            ->assertRedirect();

        $asset->refresh();

        $this->assertSame(InventoryAsset::REACHABILITY_ONLINE, $asset->reachability_status);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory_asset.reachability_check',
            'target_id' => $asset->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_reachability_probe_command_updates_assets(): void
    {
        $site = Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Probe Target',
            'primary_ip' => '10.10.30.1',
            'reachability_status' => InventoryAsset::REACHABILITY_UNKNOWN,
        ]);

        $mock = Mockery::mock(AssetReachabilityService::class);
        $mock->shouldReceive('checkAndStore')
            ->once()
            ->withArgs(fn (InventoryAsset $checkedAsset) => $checkedAsset->is($asset))
            ->andReturnUsing(function (InventoryAsset $checkedAsset) {
                $checkedAsset->forceFill([
                    'reachability_status' => InventoryAsset::REACHABILITY_OFFLINE,
                    'reachability_checked_at' => now(),
                    'reachability_latency_ms' => null,
                    'reachability_fail_count' => 1,
                    'reachability_message' => 'Host did not respond to ping.',
                ])->save();

                return $checkedAsset;
            });

        $this->app->instance(AssetReachabilityService::class, $mock);

        $this->artisan('inventory:probe-reachability', ['--asset' => $asset->id])
            ->expectsOutput('Processed 1 asset(s).')
            ->expectsOutput('Online: 0')
            ->expectsOutput('Offline: 1')
            ->expectsOutput('Unknown: 0')
            ->assertExitCode(0);

        $asset->refresh();

        $this->assertSame(InventoryAsset::REACHABILITY_OFFLINE, $asset->reachability_status);
        $this->assertSame(1, $asset->reachability_fail_count);
    }
}
