<?php

namespace Database\Seeders;

use App\Models\InventoryAsset;
use App\Models\Site;
use Illuminate\Database\Seeder;

class DummyInventorySeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::query()->firstOrCreate(
            ['code' => 'DUMMY-HQ-01'],
            [
                'name' => 'Dummy Operations HQ',
                'business_type' => 'Hospital',
                'address' => 'Jl. Demo Infrastruktur No. 1',
                'timezone' => 'Asia/Makassar',
                'notes' => 'Site dummy untuk preview halaman inventory.',
                'is_active' => true,
            ]
        );

        $site->inventoryAssets()->delete();

        InventoryAsset::factory()
            ->count(50)
            ->for($site)
            ->sequence(fn ($sequence) => [
                'name' => fake()->randomElement([
                    'Core Switch',
                    'Access Switch',
                    'Backup Server',
                    'Hypervisor Node',
                    'Reception PC',
                    'Nurse Station Mini PC',
                    'Pharmacy Printer',
                    'CCTV NVR',
                    'Lab Router',
                    'Radiology Workstation',
                ]).' '.str_pad((string) ($sequence->index + 1), 2, '0', STR_PAD_LEFT),
                'asset_tag' => 'DUMMY-'.str_pad((string) ($sequence->index + 1), 4, '0', STR_PAD_LEFT),
            ])
            ->create();
    }
}
