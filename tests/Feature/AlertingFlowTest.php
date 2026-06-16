<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Integration;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\User;
use App\Models\VaultEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AlertingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_poll_command_opens_and_resolves_a_health_alert_with_telegram_delivery(): void
    {
        $site = Site::create([
            'name' => 'Main DC',
            'code' => 'MDC',
            'business_type' => 'Data Center',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        NotificationChannel::create([
            'type' => 'telegram',
            'name' => 'Main DC Telegram',
            'site_id' => $site->id,
            'config' => [
                'bot_token' => 'bot-token',
                'chat_id' => '-100123',
            ],
            'is_active' => true,
        ]);

        $integration = Integration::create([
            'site_id' => $site->id,
            'type' => 'custom_api',
            'name' => 'Room API',
            'base_url' => 'https://room-api.example.com',
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
            'https://room-api.example.com/*' => Http::sequence()
                ->push(['ok' => false], 500)
                ->push(['ok' => true], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('alerts:poll')->assertSuccessful();

        $alert = Event::query()->where('integration_id', $integration->id)->firstOrFail();

        $this->assertSame(Event::STATUS_OPEN, $alert->status);
        $this->assertSame(Event::SEVERITY_CRITICAL, $alert->severity);
        $this->assertSame('integration_health_failure', $alert->rule_key);
        $this->assertSame('failure', $integration->fresh()->last_test_status);
        $this->assertDatabaseCount('alert_notifications', 1);

        $this->artisan('alerts:poll')->assertSuccessful();

        $alert->refresh();

        $this->assertSame(Event::STATUS_RESOLVED, $alert->status);
        $this->assertNull($alert->active_fingerprint);
        $this->assertSame('success', $integration->fresh()->last_test_status);
        $this->assertDatabaseCount('alert_notifications', 2);
    }

    public function test_poll_command_creates_proxmox_guest_alerts_and_metric_snapshots(): void
    {
        $site = Site::create([
            'name' => 'Compute Site',
            'code' => 'CMP',
            'business_type' => 'Hospital',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $vaultEntry = VaultEntry::create([
            'site_id' => $site->id,
            'name' => 'Proxmox Token',
            'kind' => 'proxmox_api_token',
            'ciphertext' => 'root@pam!alerts=test-token',
            'is_active' => true,
        ]);

        $integration = Integration::create([
            'site_id' => $site->id,
            'type' => 'proxmox',
            'name' => 'Cluster A',
            'base_url' => 'https://proxmox.example.com:8006',
            'vault_entry_id' => $vaultEntry->id,
            'credentials' => json_encode([]),
            'config' => ['verify_ssl' => true],
            'is_active' => true,
        ]);

        Http::fake([
            'https://proxmox.example.com:8006/api2/json/version' => Http::response([
                'data' => ['version' => '8.4.1', 'release' => '1', 'repoid' => 'abc'],
            ], 200),
            'https://proxmox.example.com:8006/api2/json/nodes' => Http::response([
                'data' => [['node' => 'pve01']],
            ], 200),
            'https://proxmox.example.com:8006/api2/json/nodes/pve01/qemu' => Http::response(['data' => []], 200),
            'https://proxmox.example.com:8006/api2/json/nodes/pve01/lxc' => Http::response(['data' => []], 200),
            'https://proxmox.example.com:8006/api2/json/cluster/resources*' => Http::response([
                'data' => [
                    [
                        'id' => 'qemu/101',
                        'type' => 'qemu',
                        'vmid' => 101,
                        'name' => 'ICU-VM',
                        'node' => 'pve01',
                        'status' => 'stopped',
                        'cpu' => 0.92,
                        'mem' => 8.5,
                        'maxmem' => 10,
                        'disk' => 95,
                        'maxdisk' => 100,
                    ],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('alerts:poll')->assertSuccessful();

        $this->assertDatabaseHas('events', [
            'integration_id' => $integration->id,
            'rule_key' => 'proxmox_guest_stopped',
            'status' => Event::STATUS_OPEN,
        ]);
        $this->assertDatabaseHas('events', [
            'integration_id' => $integration->id,
            'rule_key' => 'proxmox_guest_cpu_usage_percent',
            'severity' => Event::SEVERITY_CRITICAL,
        ]);
        $this->assertDatabaseHas('events', [
            'integration_id' => $integration->id,
            'rule_key' => 'proxmox_guest_memory_usage_percent',
            'severity' => Event::SEVERITY_WARNING,
        ]);
        $this->assertDatabaseHas('events', [
            'integration_id' => $integration->id,
            'rule_key' => 'proxmox_guest_disk_usage_percent',
            'severity' => Event::SEVERITY_CRITICAL,
        ]);
        $this->assertDatabaseHas('metrics', [
            'integration_id' => $integration->id,
            'key' => 'guest.status',
            'value' => 'stopped',
        ]);
    }

    public function test_operator_can_acknowledge_alert_but_viewer_cannot(): void
    {
        $site = Site::create([
            'name' => 'Ops Site',
            'code' => 'OPS',
            'business_type' => 'Clinic',
            'timezone' => 'Asia/Makassar',
            'is_active' => true,
        ]);

        $operator = User::factory()->operator()->create();
        $viewer = User::factory()->viewer()->create();
        $operator->sites()->attach($site->id);
        $viewer->sites()->attach($site->id);

        $integration = Integration::create([
            'site_id' => $site->id,
            'type' => 'custom_api',
            'name' => 'Ops API',
            'base_url' => 'https://ops.example.com',
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

        $alert = Event::create([
            'integration_id' => $integration->id,
            'site_id' => $site->id,
            'rule_key' => 'integration_health_failure',
            'fingerprint' => 'integration:test:health_failure',
            'active_fingerprint' => 'integration:test:health_failure',
            'severity' => Event::SEVERITY_CRITICAL,
            'title' => 'API health degraded: Ops API',
            'message' => 'API health check expected HTTP 200 but received 500',
            'context' => [],
            'status' => Event::STATUS_OPEN,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $this->actingAs($operator)
            ->put(route('alerts.acknowledge', $alert), ['comment' => 'Taking ownership'])
            ->assertRedirect(route('alerts.show', $alert));

        $alert->refresh();

        $this->assertSame(Event::STATUS_ACKNOWLEDGED, $alert->status);
        $this->assertSame('Taking ownership', $alert->acknowledge_comment);

        $this->actingAs($viewer)
            ->put(route('alerts.acknowledge', $alert), ['comment' => 'Nope'])
            ->assertForbidden();
    }
}
