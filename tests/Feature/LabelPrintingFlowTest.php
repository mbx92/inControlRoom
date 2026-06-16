<?php

namespace Tests\Feature;

use App\Jobs\ProcessInventoryLabelPrintJob;
use App\Models\AuditLog;
use App\Models\InventoryAsset;
use App\Models\InventoryLabelPrintJob;
use App\Models\LabelPrinter;
use App\Models\Site;
use App\Models\User;
use App\Services\LabelPrinting\LabelPrintTransportResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class LabelPrintingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_open_print_smb_settings(): void
    {
        $user = User::factory()->operator()->create();

        $this->actingAs($user)
            ->get(route('print-smb.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/PrintSmb/Index')
                ->has('printers', 0)
            );
    }

    public function test_admin_can_save_global_print_smb_printer_configuration(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('print-smb.store'), [
                'display_name' => 'Thermal Queue',
                'enabled' => true,
                'connection_mode' => 'smb',
                'smb_host' => 'PRINT-SRV-01',
                'share_name' => 'ZEBRA-ZD421',
                'lan_port' => 9100,
                'username' => 'infra-print',
                'password' => 'super-secret',
                'domain' => 'WORKGROUP',
                'driver_language' => 'zpl',
            ])
            ->assertRedirect(route('print-smb.index'));

        $printer = LabelPrinter::query()->firstOrFail();

        $this->assertSame('Thermal Queue', $printer->display_name);
        $this->assertSame('smb', $printer->connection_mode);
        $this->assertSame('super-secret', $printer->password);
        $this->assertNotSame(
            'super-secret',
            DB::table('label_printers')->value('password'),
        );
    }

    public function test_admin_can_save_lan_raw_tcp_tspl_printer_without_credentials(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('print-smb.store'), [
                'display_name' => 'TSC LAN',
                'enabled' => true,
                'connection_mode' => 'raw_tcp',
                'smb_host' => '192.168.1.50',
                'share_name' => '',
                'lan_port' => 9100,
                'username' => '',
                'password' => '',
                'domain' => '',
                'driver_language' => 'tspl',
            ])
            ->assertRedirect(route('print-smb.index'));

        $printer = LabelPrinter::query()->firstOrFail();

        $this->assertSame('raw_tcp', $printer->connection_mode);
        $this->assertSame('192.168.1.50', $printer->smb_host);
        $this->assertSame(9100, $printer->lan_port);
        $this->assertSame('tspl', $printer->driver_language);
        $this->assertNull($printer->username);
        $this->assertNull($printer->password);
    }

    public function test_signed_asset_scan_page_is_accessible_without_login(): void
    {
        $site = $this->createSite();
        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Edge Firewall',
        ]);

        $signedUrl = URL::signedRoute('inventory.scan', ['asset' => $asset->id]);

        $this->get($signedUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/Scan')
                ->where('asset.id', $asset->id)
                ->where('asset.name', 'Edge Firewall')
            );
    }

    public function test_invalid_signed_asset_scan_url_is_rejected(): void
    {
        $site = $this->createSite();
        $asset = InventoryAsset::factory()->for($site)->create();

        $this->get(route('inventory.scan', ['asset' => $asset->id]))
            ->assertForbidden();
    }

    public function test_asset_history_only_shows_logs_for_the_current_asset(): void
    {
        $site = $this->createSite();
        $asset = InventoryAsset::factory()->for($site)->create(['name' => 'Router A']);
        $otherAsset = InventoryAsset::factory()->for($site)->create(['name' => 'Router B']);
        $user = User::factory()->create();

        AuditLog::record($user->id, 'inventory_asset.update', 'inventory_asset', $asset->id, ['name' => 'Router A']);
        AuditLog::record($user->id, 'inventory_asset.update', 'inventory_asset', $otherAsset->id, ['name' => 'Router B']);

        $signedUrl = URL::signedRoute('inventory.scan', ['asset' => $asset->id]);

        $this->get($signedUrl)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('history', 1)
                ->where('history.0.payload.name', 'Router A')
            );
    }

    public function test_asset_label_print_request_queues_a_job(): void
    {
        Queue::fake();

        $user = User::factory()->operator()->create();
        $site = $this->createSite();
        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Mini PC Hypervisor',
            'asset_tag' => 'AST-9001',
        ]);

        LabelPrinter::create([
            'display_name' => 'Thermal Queue',
            'enabled' => true,
            'connection_mode' => 'smb',
            'smb_host' => 'PRINT-SRV-01',
            'share_name' => 'ZEBRA-ZD421',
            'lan_port' => 9100,
            'username' => 'infra-print',
            'password' => 'super-secret',
            'domain' => 'WORKGROUP',
            'driver_language' => 'zpl',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->post(route('inventory.print-label', $asset))
            ->assertRedirect(route('inventory.show', $asset));

        $this->assertDatabaseHas('inventory_label_print_jobs', [
            'asset_id' => $asset->id,
            'status' => 'queued',
            'is_test' => false,
        ]);

        Queue::assertPushed(ProcessInventoryLabelPrintJob::class);
    }

    public function test_asset_label_print_is_processed_immediately_in_local_environment(): void
    {
        config(['inventory.label_print_process_immediately' => true]);

        $transport = Mockery::mock(LabelPrintTransportResolver::class);
        $transport->shouldReceive('connectionTarget')->andReturn('//PRINT-SRV-01/ZEBRA-ZD421');
        $transport->shouldReceive('print')->once();
        $this->app->instance(LabelPrintTransportResolver::class, $transport);

        $user = User::factory()->operator()->create();
        $site = $this->createSite();
        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Mini PC Hypervisor',
            'asset_tag' => 'AST-9002',
        ]);

        LabelPrinter::create([
            'display_name' => 'Thermal Queue',
            'enabled' => true,
            'connection_mode' => 'smb',
            'smb_host' => 'PRINT-SRV-01',
            'share_name' => 'ZEBRA-ZD421',
            'lan_port' => 9100,
            'username' => 'infra-print',
            'password' => 'super-secret',
            'domain' => 'WORKGROUP',
            'driver_language' => 'zpl',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->post(route('inventory.print-label', $asset))
            ->assertRedirect(route('inventory.show', $asset))
            ->assertSessionHas('success', 'Label sent to Thermal Queue.');

        $this->assertDatabaseHas('inventory_label_print_jobs', [
            'asset_id' => $asset->id,
            'status' => 'success',
            'is_test' => false,
        ]);
    }

    public function test_queued_asset_label_print_uses_the_original_snapshot_when_processed(): void
    {
        Queue::fake();

        $transport = Mockery::mock(LabelPrintTransportResolver::class);
        $transport->shouldReceive('connectionTarget')->andReturn('//PRINT-SRV-01/ZEBRA-ZD421');
        $transport->shouldReceive('print')
            ->once()
            ->withArgs(function (LabelPrinter $printer, string $rawContent) {
                return $printer->display_name === 'Thermal Queue'
                    && str_contains($rawContent, 'Original Mini PC')
                    && ! str_contains($rawContent, 'Renamed Mini PC');
            });
        $this->app->instance(LabelPrintTransportResolver::class, $transport);

        $user = User::factory()->operator()->create();
        $site = $this->createSite();
        $asset = InventoryAsset::factory()->for($site)->create([
            'name' => 'Original Mini PC',
            'asset_tag' => 'AST-9003',
        ]);

        LabelPrinter::create([
            'display_name' => 'Thermal Queue',
            'enabled' => true,
            'connection_mode' => 'smb',
            'smb_host' => 'PRINT-SRV-01',
            'share_name' => 'ZEBRA-ZD421',
            'lan_port' => 9100,
            'username' => 'infra-print',
            'password' => 'super-secret',
            'domain' => 'WORKGROUP',
            'driver_language' => 'zpl',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->post(route('inventory.print-label', $asset))
            ->assertRedirect(route('inventory.show', $asset));

        $job = InventoryLabelPrintJob::query()->latest()->firstOrFail();

        $this->assertStringContainsString('Original Mini PC', $job->raw_content);
        $this->assertSame('Original Mini PC', $job->meta['asset_name']);

        $asset->update(['name' => 'Renamed Mini PC']);

        app(\App\Services\LabelPrinting\InventoryLabelPrintService::class)
            ->processQueuedJob($job->fresh());

        $job->refresh();

        $this->assertSame(InventoryLabelPrintJob::STATUS_SUCCESS, $job->status);
        $this->assertSame('Original Mini PC', $job->meta['asset_name']);
        $this->assertStringContainsString('Original Mini PC', $job->raw_content);
        $this->assertStringNotContainsString('Renamed Mini PC', $job->raw_content);
    }

    private function createSite(): Site
    {
        return Site::create([
            'name' => 'Main Hospital',
            'code' => 'MKS-01',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);
    }
}
