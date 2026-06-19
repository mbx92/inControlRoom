<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventoryAssetImportTemplateSheet implements FromArray, ShouldAutoSize, WithTitle
{
    public function array(): array
    {
        return [
            [
                'site_code',
                'site_name',
                'name',
                'category',
                'status',
                'asset_tag',
                'serial_number',
                'manufacturer',
                'model',
                'primary_ip',
                'location_label',
                'owner_name',
                'acquired_at',
                'warranty_expires_at',
                'custom_fields',
                'notes',
            ],
            [
                'MKS-01',
                'Main Hospital',
                'Core Switch ICU',
                'Switch',
                'active',
                'INV-SW-001',
                'SER-001',
                'Cisco',
                'Catalyst 9300',
                '10.10.10.2',
                'Lantai 1 / Ruang ICU',
                'Infra Team',
                '2026-01-15',
                '2029-01-15',
                'rack_unit: 12 | maintenance_window: Minggu 01:00',
                'Core switch untuk lantai ICU.',
            ],
            [
                '',
                '',
                'UPS Front Office',
                'UPS',
                'standby',
                '',
                'UPS-2026-44',
                'APC',
                'Smart-UPS 2200',
                '',
                'Lantai 1 / Front Office',
                'General Affairs',
                '',
                '',
                'capacity_va: 2200',
                'Kosongkan asset_tag jika ingin selalu membuat asset baru.',
            ],
        ];
    }

    public function title(): string
    {
        return 'Template';
    }
}
