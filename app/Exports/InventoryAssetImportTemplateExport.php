<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class InventoryAssetImportTemplateExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $sites,
        private readonly array $statuses,
        private readonly array $categories,
    ) {}

    public function sheets(): array
    {
        return [
            new InventoryAssetImportTemplateSheet,
            new InventoryAssetImportReferenceSheet($this->sites, $this->statuses, $this->categories),
        ];
    }
}
