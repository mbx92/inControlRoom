<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class InventoryAssetImportRowsImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    public function collection(Collection $collection): void
    {
        // Rows are returned directly by Excel::toCollection(), so nothing else is needed here.
    }
}
