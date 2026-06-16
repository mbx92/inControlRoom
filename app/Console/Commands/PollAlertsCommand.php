<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\Alerting\IntegrationMonitoringService;
use Illuminate\Console\Command;

class PollAlertsCommand extends Command
{
    protected $signature = 'alerts:poll {--integration=}';

    protected $description = 'Poll active integrations, update metrics, and evaluate alert rules.';

    public function handle(IntegrationMonitoringService $monitoringService): int
    {
        $query = Integration::query()
            ->with(['site', 'vaultEntry'])
            ->where('is_active', true)
            ->orderBy('name');

        $integrationId = trim((string) $this->option('integration'));
        if ($integrationId !== '') {
            $query->where('id', $integrationId);
        }

        $integrations = $query->get();

        foreach ($integrations as $integration) {
            $result = $monitoringService->run($integration);
            $status = $result['success'] ? 'ok' : 'fail';
            $message = $result['metric_error']
                ? "{$result['message']} (metric warning: {$result['metric_error']})"
                : $result['message'];

            $this->line("[{$status}] {$integration->name}: {$message}");
        }

        $this->info("Processed {$integrations->count()} integration(s).");

        return self::SUCCESS;
    }
}
