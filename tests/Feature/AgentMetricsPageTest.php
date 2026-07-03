<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AgentMetricsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_agent_metrics_index(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->admin()->create();

        Agent::create([
            'site_id' => $site->id,
            'hostname' => 'CLIENT-01',
            'device_id' => 'device-001',
            'primary_ip' => '10.20.30.40',
            'os' => 'Windows',
            'os_version' => '11 Pro',
            'arch' => 'x64',
            'agent_version' => '1.0.0',
            'agent_token_hash' => hash('sha256', 'agent-token'),
            'last_metrics' => [
                'cpu' => ['usage_percent' => 42.5],
                'memory' => [
                    'total_bytes' => 16_000_000_000,
                    'free_bytes' => 8_000_000_000,
                ],
                'disks' => [
                    [
                        'name' => 'C:\\',
                        'total_bytes' => 500_000_000_000,
                        'free_bytes' => 100_000_000_000,
                    ],
                ],
            ],
            'last_services' => [
                [
                    'name' => 'Spooler',
                    'display_name' => 'Print Spooler',
                    'status' => 'Running',
                    'start_mode' => 'Automatic',
                ],
            ],
            'last_seen_at' => now(),
            'last_heartbeat_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('agents.metrics.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/Metrics/Index')
                ->where('stats.total', 1)
                ->where('stats.with_metrics', 1)
                ->has('agents', 1)
                ->where('agents.0.hostname', 'CLIENT-01')
                ->where('agents.0.cpu_usage_percent', 42.5)
                ->where('agents.0.memory_used_percent', 50)
            );
    }

    public function test_admin_can_view_agent_metrics_detail(): void
    {
        $site = Site::factory()->create();
        $admin = User::factory()->admin()->create();

        $agent = Agent::create([
            'site_id' => $site->id,
            'hostname' => 'CLIENT-02',
            'device_id' => 'device-002',
            'primary_ip' => '10.20.30.41',
            'os' => 'Windows',
            'os_version' => '11 Pro',
            'arch' => 'x64',
            'agent_version' => '1.0.0',
            'agent_token_hash' => hash('sha256', 'agent-token-2'),
            'last_metrics' => [
                'cpu' => [
                    'usage_percent' => 18.5,
                    'brand' => 'Intel Core i7-12700',
                    'manufacturer' => 'Intel',
                    'cores' => 20,
                    'physical_cores' => 12,
                ],
                'memory' => [
                    'total_bytes' => 8_000_000_000,
                    'free_bytes' => 4_000_000_000,
                    'slots_used' => 2,
                    'slots' => [
                        [
                            'slot' => 'BANK 0',
                            'size_bytes' => 8_000_000_000,
                            'type' => 'DDR4',
                            'speed_mhz' => 3200,
                            'manufacturer' => 'Samsung',
                            'part_number' => 'M378A1K43CB2',
                        ],
                    ],
                ],
                'disks' => [
                    [
                        'name' => 'C:\\',
                        'total_bytes' => 500_000_000_000,
                        'free_bytes' => 100_000_000_000,
                    ],
                ],
                'storage_devices' => [
                    [
                        'name' => 'Samsung SSD 980',
                        'type' => 'SSD',
                        'size_bytes' => 500_000_000_000,
                        'interface_type' => 'NVMe',
                    ],
                ],
                'network' => [
                    [
                        'iface' => 'Ethernet',
                        'operstate' => 'up',
                        'ipv4' => '10.20.30.41',
                        'rx_bytes' => 1024,
                        'tx_bytes' => 2048,
                        'rx_errors' => 0,
                        'tx_errors' => 0,
                        'rx_dropped' => 0,
                        'tx_dropped' => 0,
                    ],
                ],
                'usb_devices' => [
                    [
                        'name' => 'USB Keyboard',
                        'type' => 'Keyboard',
                        'manufacturer' => 'Logitech',
                        'device_id' => 'USB\\VID_046D',
                    ],
                ],
            ],
            'last_services' => [],
            'last_seen_at' => now(),
            'last_heartbeat_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('agents.metrics.show', $agent))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Agents/Metrics/Show')
                ->where('agent.hostname', 'CLIENT-02')
                ->where('agent.metrics.cpu_usage_percent', 18.5)
                ->where('agent.metrics.cpu_brand', 'Intel Core i7-12700')
                ->where('agent.metrics.memory_used_percent', 50)
                ->where('agent.metrics.memory_total_bytes', 8_000_000_000)
                ->where('agent.metrics.physical_storage_total_bytes', 500_000_000_000)
                ->where('agent.metrics.storage_total_bytes', 500_000_000_000)
                ->where('agent.metrics.memory_slots_used', 2)
                ->has('agent.metrics.memory_slots', 1)
                ->has('agent.metrics.storage_devices', 1)
                ->has('agent.metrics.network', 1)
                ->has('agent.metrics.usb_devices', 1)
            );
    }
}
