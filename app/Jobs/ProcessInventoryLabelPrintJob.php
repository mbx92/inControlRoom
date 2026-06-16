<?php

namespace App\Jobs;

use App\Models\InventoryLabelPrintJob;
use App\Services\LabelPrinting\InventoryLabelPrintService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInventoryLabelPrintJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $printJobId,
    ) {
    }

    public function handle(InventoryLabelPrintService $printService): void
    {
        $job = InventoryLabelPrintJob::query()->with(['asset', 'printer', 'requestedBy'])->find($this->printJobId);

        if (! $job) {
            return;
        }

        $printService->processQueuedJob($job);
    }
}
