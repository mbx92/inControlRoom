<?php

namespace App\Services\Inventory;

use App\Models\AuditLog;
use App\Models\InventoryAsset;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class InventoryAssetImportService
{
    /**
     * @return array{
     *     total_rows:int,
     *     created:int,
     *     updated:int,
     *     failed:int,
     *     errors:array<int, string>
     * }
     */
    public function import(Collection $rows, User $user, ?string $ipAddress = null): array
    {
        $sites = Site::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        $sitesByCode = $sites
            ->filter(fn (Site $site) => filled($site->code))
            ->keyBy(fn (Site $site) => Str::lower(trim((string) $site->code)));

        $sitesByName = $sites->keyBy(fn (Site $site) => Str::lower(trim((string) $site->name)));

        $assetsByTag = InventoryAsset::query()
            ->whereNotNull('asset_tag')
            ->get()
            ->keyBy(fn (InventoryAsset $asset) => Str::lower(trim((string) $asset->asset_tag)));

        $result = [
            'total_rows' => 0,
            'created' => 0,
            'updated' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($rows->values() as $index => $row) {
            $rowNumber = $index + 2;
            $rowData = $row instanceof Collection ? $row->toArray() : (array) $row;

            if ($this->isEffectivelyEmptyRow($rowData)) {
                continue;
            }

            $result['total_rows']++;

            try {
                $normalized = $this->normalizeRow($rowData);
                $site = $this->resolveSite($normalized['site_code'], $normalized['site_name'], $sitesByCode, $sitesByName);
                $status = $this->normalizeStatus($normalized['status']);
                $customFields = $this->parseCustomFields($normalized['custom_fields']);

                $payload = [
                    'site_id' => $site?->id,
                    'name' => $normalized['name'],
                    'category' => $normalized['category'],
                    'status' => $status ?? 'active',
                    'asset_tag' => $normalized['asset_tag'],
                    'serial_number' => $normalized['serial_number'],
                    'manufacturer' => $normalized['manufacturer'],
                    'model' => $normalized['model'],
                    'primary_ip' => $normalized['primary_ip'],
                    'location_label' => $normalized['location_label'],
                    'owner_name' => $normalized['owner_name'],
                    'acquired_at' => $this->normalizeDate($normalized['acquired_at'], 'acquired_at'),
                    'warranty_expires_at' => $this->normalizeDate($normalized['warranty_expires_at'], 'warranty_expires_at'),
                    'custom_fields' => $customFields,
                    'notes' => $normalized['notes'],
                ];

                Validator::make($payload, [
                    'site_id' => ['nullable', 'exists:sites,id'],
                    'name' => ['required', 'string', 'max:255'],
                    'category' => ['required', 'string', 'max:100'],
                    'status' => ['required', 'string', 'in:'.implode(',', array_keys(InventoryAsset::STATUSES))],
                    'asset_tag' => ['nullable', 'string', 'max:100'],
                    'serial_number' => ['nullable', 'string', 'max:150'],
                    'manufacturer' => ['nullable', 'string', 'max:255'],
                    'model' => ['nullable', 'string', 'max:255'],
                    'primary_ip' => ['nullable', 'string', 'max:255'],
                    'location_label' => ['nullable', 'string', 'max:255'],
                    'owner_name' => ['nullable', 'string', 'max:255'],
                    'acquired_at' => ['nullable', 'date'],
                    'warranty_expires_at' => ['nullable', 'date'],
                    'notes' => ['nullable', 'string'],
                ])->validate();

                $normalizedAssetTag = $payload['asset_tag']
                    ? Str::lower(trim((string) $payload['asset_tag']))
                    : null;

                $existingAsset = $normalizedAssetTag
                    ? $assetsByTag->get($normalizedAssetTag)
                    : null;

                DB::transaction(function () use (
                    $existingAsset,
                    $payload,
                    $user,
                    $ipAddress,
                    $rowNumber,
                    &$result,
                    $assetsByTag,
                    $normalizedAssetTag,
                ): void {
                    if ($existingAsset) {
                        $existingAsset->fill($payload);
                        $existingAsset->save();

                        $result['updated']++;

                        AuditLog::record(
                            userId: $user->id,
                            action: 'inventory_asset.import_update',
                            targetType: 'inventory_asset',
                            targetId: $existingAsset->id,
                            payload: [
                                'row' => $rowNumber,
                                'name' => $existingAsset->name,
                                'asset_tag' => $existingAsset->asset_tag,
                                'site_id' => $existingAsset->site_id,
                            ],
                            ipAddress: $ipAddress,
                            siteId: $existingAsset->site_id,
                        );

                        return;
                    }

                    $asset = InventoryAsset::create($payload);

                    if ($normalizedAssetTag) {
                        $assetsByTag->put($normalizedAssetTag, $asset);
                    }

                    $result['created']++;

                    AuditLog::record(
                        userId: $user->id,
                        action: 'inventory_asset.import_create',
                        targetType: 'inventory_asset',
                        targetId: $asset->id,
                        payload: [
                            'row' => $rowNumber,
                            'name' => $asset->name,
                            'asset_tag' => $asset->asset_tag,
                            'site_id' => $asset->site_id,
                        ],
                        ipAddress: $ipAddress,
                        siteId: $asset->site_id,
                    );
                });
            } catch (ValidationException $exception) {
                $result['failed']++;
                $result['errors'][] = 'Baris '.$rowNumber.': '.$this->flattenValidationErrors($exception);
            } catch (Throwable $exception) {
                report($exception);

                $result['failed']++;
                $result['errors'][] = 'Baris '.$rowNumber.': '.$exception->getMessage();
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, ?string>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'site_code' => $this->normalizeCell($row['site_code'] ?? null),
            'site_name' => $this->normalizeCell($row['site_name'] ?? null),
            'name' => $this->normalizeCell($row['name'] ?? null),
            'category' => $this->normalizeCell($row['category'] ?? null),
            'status' => $this->normalizeCell($row['status'] ?? null),
            'asset_tag' => $this->normalizeCell($row['asset_tag'] ?? null),
            'serial_number' => $this->normalizeCell($row['serial_number'] ?? null),
            'manufacturer' => $this->normalizeCell($row['manufacturer'] ?? null),
            'model' => $this->normalizeCell($row['model'] ?? null),
            'primary_ip' => $this->normalizeCell($row['primary_ip'] ?? null),
            'location_label' => $this->normalizeCell($row['location_label'] ?? null),
            'owner_name' => $this->normalizeCell($row['owner_name'] ?? null),
            'acquired_at' => $this->normalizeCell($row['acquired_at'] ?? null),
            'warranty_expires_at' => $this->normalizeCell($row['warranty_expires_at'] ?? null),
            'custom_fields' => $this->normalizeCell($row['custom_fields'] ?? null),
            'notes' => $this->normalizeCell($row['notes'] ?? null),
        ];
    }

    private function resolveSite(?string $siteCode, ?string $siteName, Collection $sitesByCode, Collection $sitesByName): ?Site
    {
        if ($siteCode === null && $siteName === null) {
            return null;
        }

        if ($siteCode !== null) {
            $site = $sitesByCode->get(Str::lower($siteCode));

            if (! $site) {
                throw ValidationException::withMessages([
                    'site_code' => 'Site code "'.$siteCode.'" tidak ditemukan.',
                ]);
            }

            return $site;
        }

        $site = $sitesByName->get(Str::lower($siteName));

        if (! $site) {
            throw ValidationException::withMessages([
                'site_name' => 'Site name "'.$siteName.'" tidak ditemukan.',
            ]);
        }

        return $site;
    }

    private function normalizeStatus(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::lower(trim($value));

        foreach (InventoryAsset::STATUSES as $status => $label) {
            if ($normalized === Str::lower($status) || $normalized === Str::lower($label)) {
                return $status;
            }
        }

        throw ValidationException::withMessages([
            'status' => 'Status "'.$value.'" tidak valid.',
        ]);
    }

    /**
     * @return array<string, string>|null
     */
    private function parseCustomFields(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $items = preg_split('/\r\n|\r|\n|\s*\|\s*/', $value) ?: [];
        $fields = [];

        foreach ($items as $item) {
            $entry = trim($item);

            if ($entry === '') {
                continue;
            }

            $parts = explode(':', $entry, 2);

            if (count($parts) !== 2) {
                throw ValidationException::withMessages([
                    'custom_fields' => 'Format custom_fields harus "key: value" dan bisa dipisah dengan "|".',
                ]);
            }

            $key = trim($parts[0]);
            $fieldValue = trim($parts[1]);

            if ($key === '') {
                throw ValidationException::withMessages([
                    'custom_fields' => 'Nama key pada custom_fields tidak boleh kosong.',
                ]);
            }

            $fields[$key] = $fieldValue;
        }

        return $fields === [] ? null : $fields;
    }

    private function normalizeDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))
                    ->startOfDay()
                    ->format('Y-m-d');
            }

            return Carbon::parse((string) $value)->startOfDay()->format('Y-m-d');
        } catch (Throwable) {
            throw ValidationException::withMessages([
                $field => 'Format tanggal untuk '.$field.' harus YYYY-MM-DD atau tanggal Excel yang valid.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function isEffectivelyEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($this->normalizeCell($value) !== null) {
                return false;
            }
        }

        return true;
    }

    private function normalizeCell(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function flattenValidationErrors(ValidationException $exception): string
    {
        return collect($exception->errors())
            ->flatten()
            ->implode(' ');
    }
}
