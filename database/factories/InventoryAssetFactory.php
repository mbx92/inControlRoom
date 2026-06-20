<?php

namespace Database\Factories;

use App\Models\InventoryAsset;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryAsset>
 */
class InventoryAssetFactory extends Factory
{
    protected $model = InventoryAsset::class;

    public function definition(): array
    {
        $categoryProfiles = [
            'Server' => ['Dell', 'HPE', 'Lenovo', 'Supermicro'],
            'Hypervisor' => ['Dell', 'HPE', 'Lenovo'],
            'Storage' => ['Synology', 'QNAP', 'Dell', 'HPE'],
            'Firewall' => ['Fortinet', 'MikroTik', 'pfSense'],
            'Switch' => ['Cisco', 'Aruba', 'Ruijie', 'MikroTik'],
            'Switch PoE' => ['Cisco', 'Aruba', 'Ruijie', 'MikroTik', 'Ubiquiti'],
            'Access Point' => ['Ubiquiti', 'Ruijie', 'Aruba'],
            'Router' => ['MikroTik', 'Cisco', 'Juniper'],
            'IP Camera' => ['Hikvision', 'Dahua', 'Axis', 'Hikvision'],
            'NVR' => ['Hikvision', 'Dahua', 'Provision'],
            'Printer' => ['HP', 'Brother', 'Epson'],
            'UPS' => ['APC', 'Eaton', 'Vertiv'],
            'PC' => ['Dell', 'HP', 'Lenovo'],
            'Mini PC' => ['Intel', 'Beelink', 'ASUS'],
            'Laptop' => ['Dell', 'HP', 'Lenovo'],
            'Monitor' => ['Dell', 'LG', 'Samsung'],
            'NAS' => ['Synology', 'QNAP'],
            'Endpoint' => ['Dell', 'HP', 'Lenovo'],
            'Medical Device' => ['Philips', 'GE', 'Mindray'],
        ];

        $category = fake()->randomElement(array_keys($categoryProfiles));
        $manufacturer = fake()->randomElement($categoryProfiles[$category]);
        $status = fake()->randomElement(array_keys(InventoryAsset::STATUSES));
        $siteCode = strtoupper(fake()->lexify('LOC'));

        return [
            'site_id' => Site::factory(),
            'name' => $category.' '.fake()->unique()->bothify('##??'),
            'category' => $category,
            'status' => $status,
            'asset_tag' => strtoupper(fake()->unique()->bothify('AST-####')),
            'serial_number' => strtoupper(fake()->bothify('SN-####-????')),
            'manufacturer' => $manufacturer,
            'model' => strtoupper(fake()->bothify(fake()->randomElement([
                'MX-###',
                'PRO-##',
                'RACK-##',
                'EDGE-###',
                'MINI-##',
            ]))),
            'primary_ip' => fake()->optional(0.8)->localIpv4(),
            'location_label' => $siteCode.' / Rack '.fake()->randomElement(['A', 'B', 'C']).' / U'.fake()->numberBetween(1, 42),
            'owner_name' => fake()->randomElement([
                'Infra Team',
                'Network Team',
                'IT Support',
                'Biomedical Team',
            ]),
            'acquired_at' => fake()->dateTimeBetween('-5 years', '-3 months'),
            'warranty_expires_at' => fake()->dateTimeBetween('+1 months', '+4 years'),
            'custom_fields' => fake()->optional(0.6)->randomElement([
                ['rack_role' => 'core', 'priority' => 'high'],
                ['support_vendor' => 'Local Partner', 'maintenance_window' => 'Sunday 01:00'],
                ['power_source' => 'UPS-A', 'monitoring' => 'enabled'],
            ]),
            'notes' => fake()->optional(0.5)->sentence(),
        ];
    }
}
