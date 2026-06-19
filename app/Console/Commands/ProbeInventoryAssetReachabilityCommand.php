<?php

namespace App\Console\Commands;

use App\Models\InventoryAsset;
use App\Services\Inventory\AssetReachabilityService;
use Illuminate\Console\Command;

class ProbeInventoryAssetReachabilityCommand extends Command
{
    protected $signature = 'inventory:probe-reachability
        {--asset= : Probe only one asset by UUID}
        {--site= : Probe only assets from one site UUID}
        {--limit=0 : Stop after N assets}';

    protected $description = 'Probe inventory assets with primary IPs and store cached reachability status.';

    public function handle(AssetReachabilityService $reachabilityService): int
    {
        $query = InventoryAsset::query()
            ->where('monitoring_enabled', true)
            ->whereNotNull('primary_ip')
            ->where('primary_ip', '!=', '')
            ->orderBy('name');

        $assetId = trim((string) $this->option('asset'));
        $siteId = trim((string) $this->option('site'));
        $limit = max(0, (int) $this->option('limit'));

        if ($assetId !== '') {
            $query->where('id', $assetId);
        }

        if ($siteId !== '') {
            $query->where('site_id', $siteId);
        }

        $processed = 0;
        $online = 0;
        $offline = 0;
        $unknown = 0;

        $query->chunk(100, function ($assets) use (
            $reachabilityService,
            $limit,
            $assetId,
            &$processed,
            &$online,
            &$offline,
            &$unknown,
        ) {
            foreach ($assets as $asset) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $asset = $reachabilityService->checkAndStore($asset);
                $processed++;

                match ($asset->reachability_status) {
                    InventoryAsset::REACHABILITY_ONLINE => $online++,
                    InventoryAsset::REACHABILITY_OFFLINE => $offline++,
                    default => $unknown++,
                };

                if ($assetId !== '' || $this->output->isVerbose()) {
                    $this->line("[{$asset->reachability_status}] {$asset->name} ({$asset->primary_ip})");
                }
            }
        });

        $this->info("Processed {$processed} asset(s).");
        $this->line("Online: {$online}");
        $this->line("Offline: {$offline}");
        $this->line("Unknown: {$unknown}");

        return self::SUCCESS;
    }
}
