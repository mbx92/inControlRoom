<?php

namespace App\Services\LabelPrinting;

use App\Models\AuditLog;
use App\Models\InventoryAsset;
use App\Models\InventoryLabelPrintJob;
use App\Models\LabelPrinter;
use App\Models\User;
use Illuminate\Support\Facades\URL;

class InventoryLabelPrintService
{
    public function __construct(
        private readonly AssetLabelTemplateRenderer $renderer,
        private readonly LabelPrintTransportResolver $transport,
    ) {
    }

    public function queueAssetLabel(InventoryAsset $asset, LabelPrinter $printer, User $user): InventoryLabelPrintJob
    {
        $rendered = $this->renderer->renderAssetLabel($printer, $asset, $this->scanUrl($asset));

        return InventoryLabelPrintJob::create([
            'asset_id' => $asset->id,
            'printer_id' => $printer->id,
            'requested_by' => $user->id,
            'status' => InventoryLabelPrintJob::STATUS_QUEUED,
            'is_test' => false,
            'printer_name' => $printer->display_name,
            'share_path' => $this->transport->connectionTarget($printer),
            'driver_language' => $printer->driver_language,
            'label_identifier' => $rendered['identifier'],
            'qr_url' => $rendered['qr_url'],
            'meta' => $rendered['meta'],
            'raw_content' => $rendered['content'],
        ]);
    }

    public function runTestPrint(LabelPrinter $printer, User $user, ?string $ipAddress = null): InventoryLabelPrintJob
    {
        $rendered = $this->renderer->renderTestLabel($printer);

        $job = InventoryLabelPrintJob::create([
            'printer_id' => $printer->id,
            'requested_by' => $user->id,
            'status' => InventoryLabelPrintJob::STATUS_PROCESSING,
            'is_test' => true,
            'printer_name' => $printer->display_name,
            'share_path' => $this->transport->connectionTarget($printer),
            'driver_language' => $printer->driver_language,
            'label_identifier' => $rendered['identifier'],
            'meta' => $rendered['meta'],
            'raw_content' => $rendered['content'],
        ]);

        $this->executePrintJob($job, $rendered['content'], $ipAddress);

        return $job->fresh();
    }

    public function processQueuedJob(InventoryLabelPrintJob $job): void
    {
        if ($job->is_test) {
            return;
        }

        $job->loadMissing('asset.site', 'printer', 'requestedBy');

        if (! $job->asset || ! $job->printer) {
            $this->markFailed($job, 'The asset or printer configuration is no longer available.');

            return;
        }

        $rawContent = $job->raw_content;

        if (! is_string($rawContent) || $rawContent === '') {
            $rendered = $this->renderer->renderAssetLabel($job->printer, $job->asset, $this->scanUrl($job->asset));
            $rawContent = $rendered['content'];

            $job->forceFill([
                'label_identifier' => $rendered['identifier'],
                'qr_url' => $rendered['qr_url'],
                'meta' => $rendered['meta'],
                'raw_content' => $rawContent,
            ])->save();
        }

        $job->forceFill([
            'status' => InventoryLabelPrintJob::STATUS_PROCESSING,
            'error_message' => null,
        ])->save();

        $this->executePrintJob($job, $rawContent, null);
    }

    private function executePrintJob(InventoryLabelPrintJob $job, string $rawContent, ?string $ipAddress): void
    {
        $job->loadMissing('asset.site', 'printer', 'requestedBy');

        try {
            $this->transport->print($job->printer, $rawContent);

            $job->forceFill([
                'status' => InventoryLabelPrintJob::STATUS_SUCCESS,
                'error_message' => null,
                'completed_at' => now(),
            ])->save();

            $this->recordAudit($job, 'success', null, $ipAddress);
        } catch (\Throwable $e) {
            $this->markFailed($job, $e->getMessage(), $ipAddress);
        }
    }

    private function markFailed(InventoryLabelPrintJob $job, string $message, ?string $ipAddress = null): void
    {
        $job->forceFill([
            'status' => InventoryLabelPrintJob::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
        ])->save();

        $this->recordAudit($job, 'failure', $message, $ipAddress);
    }

    private function recordAudit(InventoryLabelPrintJob $job, string $result, ?string $errorMessage, ?string $ipAddress): void
    {
        $job->loadMissing('asset.site', 'printer', 'requestedBy');

        $targetType = $job->is_test ? 'label_printer' : 'inventory_asset';
        $targetId = $job->is_test ? $job->printer_id : $job->asset_id;
        $siteId = $job->asset?->site_id;

        AuditLog::record(
            userId: $job->requested_by,
            action: $job->is_test ? 'label_printer.test_print' : 'inventory_asset.label_print',
            targetType: $targetType,
            targetId: $targetId,
            payload: [
                'printer_name' => $job->printer_name,
                'share_path' => $job->share_path,
                'driver_language' => $job->driver_language,
                'label_identifier' => $job->label_identifier,
                'qr_url' => $job->qr_url,
                'is_test' => $job->is_test,
            ],
            ipAddress: $ipAddress,
            result: $result,
            errorMessage: $errorMessage,
            siteId: $siteId,
        );
    }

    private function scanUrl(InventoryAsset $asset): string
    {
        return URL::signedRoute('inventory.scan', ['asset' => $asset->id]);
    }
}
