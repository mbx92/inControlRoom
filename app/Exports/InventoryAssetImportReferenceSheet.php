<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class InventoryAssetImportReferenceSheet implements FromArray, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly array $sites,
        private readonly array $statuses,
        private readonly array $categories,
    ) {}

    public function array(): array
    {
        $rows = [
            ['Aturan Import', '', '', ''],
            ['Kolom', 'Wajib', 'Keterangan', 'Contoh'],
            ['site_code', 'opsional', 'Kode site yang sudah terdaftar. Jika diisi, diprioritaskan dibanding site_name.', 'MKS-01'],
            ['site_name', 'opsional', 'Nama site jika tidak memakai site_code.', 'Main Hospital'],
            ['name', 'ya', 'Nama asset.', 'Core Switch ICU'],
            ['category', 'ya', 'Kategori bebas, disarankan mengikuti daftar referensi di bawah.', 'Switch'],
            ['status', 'opsional', 'Boleh pakai key atau label. Default: active.', 'active'],
            ['asset_tag', 'opsional', 'Jika cocok dengan asset_tag yang sudah ada, data akan di-update.', 'INV-SW-001'],
            ['serial_number', 'opsional', 'Nomor serial asset.', 'SER-001'],
            ['manufacturer', 'opsional', 'Vendor perangkat.', 'Cisco'],
            ['model', 'opsional', 'Model perangkat.', 'Catalyst 9300'],
            ['primary_ip', 'opsional', 'IP utama asset.', '10.10.10.2'],
            ['location_label', 'opsional', 'Label lokasi.', 'Lantai 1 / Ruang ICU'],
            ['owner_name', 'opsional', 'PIC atau pemilik asset.', 'Infra Team'],
            ['acquired_at', 'opsional', 'Tanggal format YYYY-MM-DD.', '2026-01-15'],
            ['warranty_expires_at', 'opsional', 'Tanggal format YYYY-MM-DD.', '2029-01-15'],
            ['custom_fields', 'opsional', 'Format "key: value" dan beberapa item bisa dipisah dengan "|".', 'rack_unit: 12 | maintenance_window: Minggu 01:00'],
            ['notes', 'opsional', 'Catatan bebas.', 'Core switch untuk lantai ICU'],
            [],
            ['Status Valid', 'Label', '', ''],
        ];

        foreach ($this->statuses as $value => $label) {
            $rows[] = [$value, $label, '', ''];
        }

        $rows[] = [];
        $rows[] = ['Kategori Saran', '', '', ''];

        foreach ($this->categories as $category) {
            $rows[] = [$category, '', '', ''];
        }

        $rows[] = [];
        $rows[] = ['Site Tersedia', '', '', ''];
        $rows[] = ['Code', 'Name', '', ''];

        foreach (Collection::make($this->sites)->sortBy('name')->values() as $site) {
            $rows[] = [$site['code'] ?? '', $site['name'] ?? '', '', ''];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Referensi';
    }
}
