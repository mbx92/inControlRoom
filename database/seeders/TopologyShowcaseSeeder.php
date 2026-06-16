<?php

namespace Database\Seeders;

use App\Models\AssetLink;
use App\Models\Integration;
use App\Models\InventoryAsset;
use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\TopologyLayout;
use App\Models\VaultEntry;
use Illuminate\Database\Seeder;

class TopologyShowcaseSeeder extends Seeder
{
    public const SOHO_SITE_CODE = 'SOHO-MKS-01';
    public const HQ_SITE_CODE = 'ENT-HQ-MKS-01';

    public function run(): void
    {
        $sohoSite = $this->resetSite(
            code: self::SOHO_SITE_CODE,
            attributes: [
                'name' => 'Makassar Soho Branch',
                'business_type' => 'Clinic',
                'address' => 'Jl. Pengayoman No. 21, Makassar',
                'timezone' => 'Asia/Makassar',
                'notes' => 'Small branch topology with a compact all-in-one edge stack.',
                'is_active' => true,
            ],
        );

        $hqSite = $this->resetSite(
            code: self::HQ_SITE_CODE,
            attributes: [
                'name' => 'Makassar Enterprise HQ',
                'business_type' => 'Head Office',
                'address' => 'Jl. AP Pettarani No. 88, Makassar',
                'timezone' => 'Asia/Makassar',
                'notes' => 'Enterprise office sample with server room, security, and floor access layers.',
                'is_active' => true,
            ],
        );

        $sohoAssets = $this->seedSohoBranch($sohoSite);
        $hqAssets = $this->seedEnterpriseHq($hqSite);

        $this->seedSohoIntegrations($sohoSite);
        $this->seedEnterpriseIntegrations($hqSite, $hqAssets['hq_proxmox_hypervisor']);
    }

    private function resetSite(string $code, array $attributes): Site
    {
        $site = Site::query()->updateOrCreate(
            ['code' => $code],
            $attributes,
        );

        Integration::query()->where('site_id', $site->id)->delete();
        NotificationChannel::query()->where('site_id', $site->id)->delete();
        VaultEntry::query()->where('site_id', $site->id)->delete();
        TopologyLayout::query()->where('site_id', $site->id)->delete();
        InventoryAsset::query()->where('site_id', $site->id)->delete();

        return $site->fresh();
    }

    private function seedSohoBranch(Site $site): array
    {
        $assets = [];

        $assets['soho_edge_router'] = $this->asset($site, [
            'name' => 'SOHO Edge Router',
            'category' => 'Router',
            'status' => 'active',
            'asset_tag' => 'SOHO-RT-001',
            'serial_number' => 'SOHO-CCR1009-001',
            'manufacturer' => 'MikroTik',
            'model' => 'CCR1009-7G-1C-1S+',
            'primary_ip' => '192.168.10.1',
            'location_label' => 'Ground Floor / Network Rack',
            'owner_name' => 'Network Team',
            'custom_fields' => [
                'role' => 'edge-router',
                'wan_provider' => 'Metro-E + LTE backup',
            ],
            'notes' => 'Primary WAN edge for branch clinic.',
        ]);

        $assets['soho_access_switch'] = $this->asset($site, [
            'name' => 'SOHO Access Switch PoE',
            'category' => 'Switch',
            'status' => 'active',
            'asset_tag' => 'SOHO-SW-001',
            'serial_number' => 'SOHO-CBS350-001',
            'manufacturer' => 'Cisco',
            'model' => 'CBS350-24P-4G',
            'primary_ip' => '192.168.10.2',
            'location_label' => 'Ground Floor / Network Rack',
            'owner_name' => 'Network Team',
            'custom_fields' => [
                'role' => 'access-switch',
                'poe_budget' => '195W',
            ],
            'notes' => 'Feeds AP, CCTV, printer, and office endpoints.',
        ]);

        $assets['soho_access_point'] = $this->asset($site, [
            'name' => 'SOHO Office Access Point',
            'category' => 'Access Point',
            'status' => 'active',
            'asset_tag' => 'SOHO-AP-001',
            'serial_number' => 'SOHO-U6-001',
            'manufacturer' => 'Ubiquiti',
            'model' => 'UniFi U6 Pro',
            'primary_ip' => '192.168.10.3',
            'location_label' => 'Ground Floor / Ceiling Zone A',
            'owner_name' => 'Network Team',
            'custom_fields' => [
                'ssid' => 'Branch-Staff',
            ],
        ]);

        $assets['soho_nas'] = $this->asset($site, [
            'name' => 'SOHO Backup NAS',
            'category' => 'NAS',
            'status' => 'active',
            'asset_tag' => 'SOHO-NAS-001',
            'serial_number' => 'SOHO-DS923-001',
            'manufacturer' => 'Synology',
            'model' => 'DS923+',
            'primary_ip' => '192.168.10.10',
            'location_label' => 'Ground Floor / Network Rack',
            'owner_name' => 'Infra Team',
            'custom_fields' => [
                'purpose' => 'local-backup',
            ],
        ]);

        $assets['soho_nvr'] = $this->asset($site, [
            'name' => 'SOHO CCTV NVR',
            'category' => 'NVR',
            'status' => 'active',
            'asset_tag' => 'SOHO-NVR-001',
            'serial_number' => 'SOHO-HIK-001',
            'manufacturer' => 'Hikvision',
            'model' => 'DS-7608NI-K2',
            'primary_ip' => '192.168.10.20',
            'location_label' => 'Ground Floor / Security Shelf',
            'owner_name' => 'Security Team',
        ]);

        $assets['soho_cctv_lobby'] = $this->asset($site, [
            'name' => 'SOHO CCTV Camera Lobby',
            'category' => 'CCTV',
            'status' => 'active',
            'asset_tag' => 'SOHO-CCTV-001',
            'serial_number' => 'SOHO-CAM-001',
            'manufacturer' => 'Hikvision',
            'model' => 'DS-2CD2143G0-I',
            'primary_ip' => '192.168.10.30',
            'location_label' => 'Ground Floor / Reception',
            'owner_name' => 'Security Team',
        ]);

        $assets['soho_access_door'] = $this->asset($site, [
            'name' => 'SOHO Main Door Access Controller',
            'category' => 'Access Door',
            'status' => 'active',
            'asset_tag' => 'SOHO-ACS-001',
            'serial_number' => 'SOHO-ACS-001',
            'manufacturer' => 'ZKTeco',
            'model' => 'C3-100',
            'primary_ip' => '192.168.10.40',
            'location_label' => 'Ground Floor / Entrance',
            'owner_name' => 'Security Team',
        ]);

        $assets['soho_pc'] = $this->asset($site, [
            'name' => 'Reception PC',
            'category' => 'PC',
            'status' => 'active',
            'asset_tag' => 'SOHO-PC-001',
            'serial_number' => 'SOHO-OPT-001',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex 7010',
            'primary_ip' => '192.168.10.101',
            'location_label' => 'Ground Floor / Reception',
            'owner_name' => 'Front Office',
        ]);

        $assets['soho_laptop'] = $this->asset($site, [
            'name' => 'Branch Manager Laptop Dock',
            'category' => 'Laptop',
            'status' => 'active',
            'asset_tag' => 'SOHO-LT-001',
            'serial_number' => 'SOHO-LAT-001',
            'manufacturer' => 'Lenovo',
            'model' => 'ThinkPad T14',
            'primary_ip' => '192.168.10.111',
            'location_label' => 'Ground Floor / Manager Room',
            'owner_name' => 'Branch Manager',
        ]);

        $assets['soho_printer'] = $this->asset($site, [
            'name' => 'Front Desk Laser Printer',
            'category' => 'Printer',
            'status' => 'active',
            'asset_tag' => 'SOHO-PR-001',
            'serial_number' => 'SOHO-HP-001',
            'manufacturer' => 'HP',
            'model' => 'LaserJet Pro 4003dn',
            'primary_ip' => '192.168.10.120',
            'location_label' => 'Ground Floor / Reception',
            'owner_name' => 'Front Office',
        ]);

        $this->link($assets['soho_access_switch'], $assets['soho_edge_router'], 'Uplink to edge router');
        $this->link($assets['soho_access_point'], $assets['soho_access_switch'], 'PoE to access switch');
        $this->link($assets['soho_nas'], $assets['soho_access_switch'], 'LAN to access switch');
        $this->link($assets['soho_nvr'], $assets['soho_access_switch'], 'LAN to access switch');
        $this->link($assets['soho_cctv_lobby'], $assets['soho_access_switch'], 'PoE camera uplink');
        $this->link($assets['soho_access_door'], $assets['soho_access_switch'], 'LAN to access switch');
        $this->link($assets['soho_pc'], $assets['soho_access_switch'], 'Desk LAN');
        $this->link($assets['soho_laptop'], $assets['soho_access_switch'], 'Dock LAN');
        $this->link($assets['soho_printer'], $assets['soho_access_switch'], 'Printer LAN');

        return $assets;
    }

    private function seedEnterpriseHq(Site $site): array
    {
        $assets = [];

        $assets['hq_edge_router'] = $this->asset($site, [
            'name' => 'HQ Edge Router',
            'category' => 'Router',
            'status' => 'active',
            'asset_tag' => 'HQ-RT-001',
            'serial_number' => 'HQ-ISR-001',
            'manufacturer' => 'Cisco',
            'model' => 'ISR 4331',
            'primary_ip' => '10.20.0.1',
            'location_label' => 'Floor 1 / Server Room',
            'owner_name' => 'Network Team',
            'custom_fields' => [
                'role' => 'edge-router',
                'wan_provider' => 'Primary MPLS + DIA backup',
            ],
        ]);

        $assets['hq_core_switch'] = $this->asset($site, [
            'name' => 'HQ Core Switch',
            'category' => 'Switch',
            'status' => 'active',
            'asset_tag' => 'HQ-CSW-001',
            'serial_number' => 'HQ-C9300-001',
            'manufacturer' => 'Cisco',
            'model' => 'Catalyst 9300-48X',
            'primary_ip' => '10.20.0.2',
            'location_label' => 'Floor 1 / Server Room',
            'owner_name' => 'Network Team',
            'custom_fields' => [
                'role' => 'core-switch',
            ],
        ]);

        $assets['hq_distribution_floor1'] = $this->asset($site, [
            'name' => 'HQ Distribution Switch Floor 1',
            'category' => 'Switch',
            'status' => 'active',
            'asset_tag' => 'HQ-DSW-001',
            'serial_number' => 'HQ-C9200-001',
            'manufacturer' => 'Cisco',
            'model' => 'Catalyst 9200-24P',
            'primary_ip' => '10.20.1.2',
            'location_label' => 'Floor 1 / Server Room',
            'owner_name' => 'Network Team',
            'custom_fields' => [
                'role' => 'distribution-switch',
            ],
        ]);

        $assets['hq_distribution_floor2'] = $this->asset($site, [
            'name' => 'HQ Distribution Switch Floor 2',
            'category' => 'Switch',
            'status' => 'active',
            'asset_tag' => 'HQ-DSW-002',
            'serial_number' => 'HQ-C9200-002',
            'manufacturer' => 'Cisco',
            'model' => 'Catalyst 9200-24P',
            'primary_ip' => '10.20.2.2',
            'location_label' => 'Floor 2 / IDF Closet',
            'owner_name' => 'Network Team',
            'custom_fields' => [
                'role' => 'distribution-switch',
            ],
        ]);

        $assets['hq_access_east'] = $this->asset($site, [
            'name' => 'HQ Access Switch East',
            'category' => 'Switch',
            'status' => 'active',
            'asset_tag' => 'HQ-ASW-001',
            'serial_number' => 'HQ-CBS-001',
            'manufacturer' => 'Cisco',
            'model' => 'CBS350-24P-4G',
            'primary_ip' => '10.20.2.10',
            'location_label' => 'Floor 2 / Open Office East',
            'owner_name' => 'Network Team',
            'custom_fields' => [
                'role' => 'access-switch',
            ],
        ]);

        $assets['hq_access_west'] = $this->asset($site, [
            'name' => 'HQ Access Switch West',
            'category' => 'Switch',
            'status' => 'active',
            'asset_tag' => 'HQ-ASW-002',
            'serial_number' => 'HQ-CBS-002',
            'manufacturer' => 'Cisco',
            'model' => 'CBS350-24P-4G',
            'primary_ip' => '10.20.2.11',
            'location_label' => 'Floor 2 / Open Office West',
            'owner_name' => 'Network Team',
            'custom_fields' => [
                'role' => 'access-switch',
            ],
        ]);

        $assets['hq_proxmox_hypervisor'] = $this->asset($site, [
            'name' => 'HQ Proxmox Hypervisor',
            'category' => 'Hypervisor',
            'status' => 'active',
            'asset_tag' => 'HQ-HYP-001',
            'serial_number' => 'HQ-DL380-001',
            'manufacturer' => 'HPE',
            'model' => 'ProLiant DL380 Gen10',
            'primary_ip' => '10.20.10.10',
            'location_label' => 'Floor 1 / Server Room',
            'owner_name' => 'Infra Team',
            'custom_fields' => [
                'hostname' => 'pve-hq-01',
                'fqdn' => 'pve-hq.demo.local',
                'rack' => 'Rack A / U18',
            ],
            'notes' => 'Mapped as host asset for demo Proxmox integration.',
        ]);

        $assets['hq_app_server'] = $this->asset($site, [
            'name' => 'HQ App Server',
            'category' => 'Server',
            'status' => 'active',
            'asset_tag' => 'HQ-SRV-001',
            'serial_number' => 'HQ-R740-001',
            'manufacturer' => 'Dell',
            'model' => 'PowerEdge R740',
            'primary_ip' => '10.20.10.20',
            'location_label' => 'Floor 1 / Server Room',
            'owner_name' => 'Infra Team',
        ]);

        $assets['hq_backup_nas'] = $this->asset($site, [
            'name' => 'HQ Backup NAS',
            'category' => 'NAS',
            'status' => 'active',
            'asset_tag' => 'HQ-NAS-001',
            'serial_number' => 'HQ-TRUENAS-001',
            'manufacturer' => 'iXsystems',
            'model' => 'TrueNAS Mini X+',
            'primary_ip' => '10.20.10.30',
            'location_label' => 'Floor 1 / Server Room',
            'owner_name' => 'Infra Team',
        ]);

        $assets['hq_security_nvr'] = $this->asset($site, [
            'name' => 'HQ Security NVR',
            'category' => 'NVR',
            'status' => 'active',
            'asset_tag' => 'HQ-NVR-001',
            'serial_number' => 'HQ-NVR-001',
            'manufacturer' => 'Hikvision',
            'model' => 'DS-7716NXI-I4',
            'primary_ip' => '10.20.10.40',
            'location_label' => 'Floor 1 / Security Desk',
            'owner_name' => 'Security Team',
        ]);

        $assets['hq_ap_a'] = $this->asset($site, [
            'name' => 'HQ Floor 2 Access Point A',
            'category' => 'Access Point',
            'status' => 'active',
            'asset_tag' => 'HQ-AP-001',
            'serial_number' => 'HQ-U6-001',
            'manufacturer' => 'Ubiquiti',
            'model' => 'UniFi U6 Enterprise',
            'primary_ip' => '10.20.20.10',
            'location_label' => 'Floor 2 / Ceiling Corridor',
            'owner_name' => 'Network Team',
        ]);

        $assets['hq_ap_b'] = $this->asset($site, [
            'name' => 'HQ Meeting Room Access Point',
            'category' => 'Access Point',
            'status' => 'active',
            'asset_tag' => 'HQ-AP-002',
            'serial_number' => 'HQ-U6-002',
            'manufacturer' => 'Ubiquiti',
            'model' => 'UniFi U6 Pro',
            'primary_ip' => '10.20.20.11',
            'location_label' => 'Floor 2 / Meeting Room',
            'owner_name' => 'Network Team',
        ]);

        $assets['hq_finance_pc'] = $this->asset($site, [
            'name' => 'Finance PC 01',
            'category' => 'PC',
            'status' => 'active',
            'asset_tag' => 'HQ-PC-001',
            'serial_number' => 'HQ-OPT-001',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex 7010',
            'primary_ip' => '10.20.21.101',
            'location_label' => 'Floor 2 / Open Office East',
            'owner_name' => 'Finance Team',
        ]);

        $assets['hq_ops_laptop'] = $this->asset($site, [
            'name' => 'Ops Laptop 01',
            'category' => 'Laptop',
            'status' => 'active',
            'asset_tag' => 'HQ-LT-001',
            'serial_number' => 'HQ-LAT-001',
            'manufacturer' => 'Lenovo',
            'model' => 'ThinkPad X1 Carbon',
            'primary_ip' => '10.20.21.121',
            'location_label' => 'Floor 2 / Open Office West',
            'owner_name' => 'Ops Team',
        ]);

        $assets['hq_printer'] = $this->asset($site, [
            'name' => 'Office Laser Printer',
            'category' => 'Printer',
            'status' => 'active',
            'asset_tag' => 'HQ-PR-001',
            'serial_number' => 'HQ-PR-001',
            'manufacturer' => 'HP',
            'model' => 'LaserJet Enterprise M507dn',
            'primary_ip' => '10.20.21.130',
            'location_label' => 'Floor 2 / Open Office East',
            'owner_name' => 'General Affairs',
        ]);

        $assets['hq_cctv_lobby'] = $this->asset($site, [
            'name' => 'Lobby CCTV Camera',
            'category' => 'CCTV',
            'status' => 'active',
            'asset_tag' => 'HQ-CCTV-001',
            'serial_number' => 'HQ-CAM-001',
            'manufacturer' => 'Hikvision',
            'model' => 'DS-2CD2387G2',
            'primary_ip' => '10.20.30.10',
            'location_label' => 'Floor 1 / Lobby',
            'owner_name' => 'Security Team',
        ]);

        $assets['hq_door_access'] = $this->asset($site, [
            'name' => 'Main Door Access Controller',
            'category' => 'Access Door',
            'status' => 'active',
            'asset_tag' => 'HQ-ACS-001',
            'serial_number' => 'HQ-ACS-001',
            'manufacturer' => 'ZKTeco',
            'model' => 'C3-400',
            'primary_ip' => '10.20.30.20',
            'location_label' => 'Floor 1 / Lobby',
            'owner_name' => 'Security Team',
        ]);

        $this->link($assets['hq_core_switch'], $assets['hq_edge_router'], 'Northbound uplink');
        $this->link($assets['hq_distribution_floor1'], $assets['hq_core_switch'], 'Server room aggregation');
        $this->link($assets['hq_distribution_floor2'], $assets['hq_core_switch'], 'Floor 2 aggregation');
        $this->link($assets['hq_access_east'], $assets['hq_distribution_floor2'], 'East office access');
        $this->link($assets['hq_access_west'], $assets['hq_distribution_floor2'], 'West office access');
        $this->link($assets['hq_proxmox_hypervisor'], $assets['hq_distribution_floor1'], '10G uplink');
        $this->link($assets['hq_app_server'], $assets['hq_distribution_floor1'], 'Server VLAN');
        $this->link($assets['hq_backup_nas'], $assets['hq_distribution_floor1'], 'Storage VLAN');
        $this->link($assets['hq_security_nvr'], $assets['hq_distribution_floor1'], 'Security VLAN');
        $this->link($assets['hq_cctv_lobby'], $assets['hq_distribution_floor1'], 'PoE security uplink');
        $this->link($assets['hq_door_access'], $assets['hq_distribution_floor1'], 'Access control uplink');
        $this->link($assets['hq_ap_a'], $assets['hq_access_east'], 'PoE AP uplink');
        $this->link($assets['hq_ap_b'], $assets['hq_access_west'], 'PoE AP uplink');
        $this->link($assets['hq_finance_pc'], $assets['hq_access_east'], 'Desk LAN');
        $this->link($assets['hq_ops_laptop'], $assets['hq_access_west'], 'Dock LAN');
        $this->link($assets['hq_printer'], $assets['hq_access_east'], 'Printer LAN');

        return $assets;
    }

    private function seedSohoIntegrations(Site $site): void
    {
        Integration::query()->create([
            'site_id' => $site->id,
            'type' => 'custom_api',
            'name' => 'SOHO Door Access API',
            'base_url' => 'https://door-api.branch.demo.local',
            'credentials' => json_encode([]),
            'config' => [
                'verify_ssl' => true,
                'auth_mode' => 'none',
                'health_path' => '/health',
                'health_method' => 'GET',
                'health_expected_status' => 200,
            ],
            'is_active' => true,
            'last_tested_at' => now()->subMinutes(18),
            'last_test_status' => 'success',
            'last_test_message' => 'API health check succeeded with HTTP 200',
            'last_test_meta' => [
                'kind' => 'custom_api',
                'product' => 'Custom API',
                'health_endpoint' => 'https://door-api.branch.demo.local/health',
                'api_reachable' => true,
                'auth_status' => 'not_required',
                'latency_ms' => 42,
                'http_status' => 200,
                'expected_status' => 200,
                'health_method' => 'GET',
            ],
        ]);
    }

    private function seedEnterpriseIntegrations(Site $site, InventoryAsset $hostAsset): void
    {
        $vaultEntry = VaultEntry::query()->create([
            'site_id' => $site->id,
            'name' => 'HQ Proxmox API Token',
            'kind' => 'proxmox_api_token',
            'ciphertext' => 'root@pam!demo=infra-token-hq',
            'notes' => 'Demo token for seeded Proxmox integration.',
            'last_rotated_at' => now()->subDays(14),
            'is_active' => true,
        ]);

        Integration::query()->create([
            'site_id' => $site->id,
            'type' => 'proxmox',
            'name' => 'HQ Proxmox Cluster',
            'base_url' => 'https://pve-hq.demo.local:8006',
            'vault_entry_id' => $vaultEntry->id,
            'credentials' => json_encode([]),
            'config' => [
                'verify_ssl' => true,
                'host_asset_id' => $hostAsset->id,
            ],
            'is_active' => true,
            'last_tested_at' => now()->subMinutes(11),
            'last_test_status' => 'failure',
            'last_test_message' => 'Proxmox returned HTTP 500',
            'last_test_meta' => [
                'kind' => 'proxmox',
                'product' => 'Proxmox VE',
                'version' => '8.4.1',
                'release' => '1',
                'repoid' => 'demo-hq',
                'node_count' => 2,
                'vm_count' => 8,
                'ct_count' => 4,
                'verify_ssl' => true,
                'health_endpoint' => 'https://pve-hq.demo.local:8006/api2/json/version',
                'api_reachable' => true,
                'auth_status' => 'valid',
                'latency_ms' => 187,
                'http_status' => 500,
            ],
        ]);

        Integration::query()->create([
            'site_id' => $site->id,
            'type' => 'custom_api',
            'name' => 'HQ CCTV Monitoring API',
            'base_url' => 'https://cctv-api.hq.demo.local',
            'credentials' => json_encode([]),
            'config' => [
                'verify_ssl' => true,
                'auth_mode' => 'none',
                'health_path' => '/health',
                'health_method' => 'GET',
                'health_expected_status' => 200,
            ],
            'is_active' => true,
            'last_tested_at' => now()->subMinutes(6),
            'last_test_status' => 'success',
            'last_test_message' => 'API health check succeeded with HTTP 200',
            'last_test_meta' => [
                'kind' => 'custom_api',
                'product' => 'Custom API',
                'health_endpoint' => 'https://cctv-api.hq.demo.local/health',
                'api_reachable' => true,
                'auth_status' => 'not_required',
                'latency_ms' => 61,
                'http_status' => 200,
                'expected_status' => 200,
                'health_method' => 'GET',
            ],
        ]);
    }

    private function asset(Site $site, array $attributes): InventoryAsset
    {
        return InventoryAsset::query()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'owner_name' => 'Infra Team',
            'acquired_at' => now()->subMonths(18)->toDateString(),
            'warranty_expires_at' => now()->addMonths(18)->toDateString(),
            'custom_fields' => [],
            'notes' => null,
            ...$attributes,
        ]);
    }

    private function link(InventoryAsset $source, InventoryAsset $target, ?string $label = null): void
    {
        AssetLink::query()->create([
            'source_asset_id' => $source->id,
            'target_asset_id' => $target->id,
            'link_type' => AssetLink::TYPE_UPLINK,
            'label' => $label,
        ]);
    }
}
