<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Agent;
use App\Models\InventoryAsset;

trait PresentsAgentInventoryLink
{
    protected function presentInventoryAssetLink(?InventoryAsset $asset): ?array
    {
        if ($asset === null) {
            return null;
        }

        return [
            'id' => $asset->id,
            'name' => $asset->name,
            'asset_tag' => $asset->asset_tag,
            'category' => $asset->category,
            'primary_ip' => $asset->primary_ip,
            'status_label' => $asset->status_label,
        ];
    }

    protected function presentLinkedAgent(?Agent $agent): ?array
    {
        if ($agent === null) {
            return null;
        }

        $lastSeenAt = $agent->last_seen_at;
        $status = 'Never Seen';

        if ($lastSeenAt !== null) {
            $status = $lastSeenAt->gt(now()->subMinutes(5)) ? 'Online' : 'Idle';
        }

        return [
            'id' => $agent->id,
            'hostname' => $agent->hostname,
            'device_id' => $agent->device_id,
            'primary_ip' => $agent->primary_ip,
            'agent_version' => $agent->agent_version,
            'status' => $status,
            'last_seen_at' => optional($agent->last_seen_at)?->toIso8601String(),
        ];
    }

    /**
     * @return list<array{id: string, site_id: string|null, name: string, asset_tag: string|null, category: string|null, primary_ip: string|null, linked_agent_id: string|null}>
     */
    protected function inventoryAssetLinkOptions(?string $siteId, ?string $currentAgentId = null): array
    {
        if ($siteId === null) {
            return [];
        }

        $linkedAssetIds = Agent::query()
            ->whereNotNull('inventory_asset_id')
            ->when($currentAgentId !== null, fn ($query) => $query->where('id', '!=', $currentAgentId))
            ->pluck('inventory_asset_id');

        return InventoryAsset::query()
            ->where('site_id', $siteId)
            ->whereNotIn('id', $linkedAssetIds)
            ->orderBy('name')
            ->get(['id', 'site_id', 'name', 'asset_tag', 'category', 'primary_ip'])
            ->map(fn (InventoryAsset $asset) => [
                'id' => $asset->id,
                'site_id' => $asset->site_id,
                'name' => $asset->name,
                'asset_tag' => $asset->asset_tag,
                'category' => $asset->category,
                'primary_ip' => $asset->primary_ip,
                'linked_agent_id' => null,
            ])
            ->all();
    }

    /**
     * @return list<array{id: string, hostname: string, device_id: string, primary_ip: string|null, agent_version: string|null, status: string}>
     */
    protected function agentLinkOptions(InventoryAsset $asset): array
    {
        if ($asset->site_id === null) {
            return [];
        }

        $currentAgentId = $asset->relationLoaded('agent') ? $asset->agent?->id : null;

        return Agent::query()
            ->where('site_id', $asset->site_id)
            ->where(function ($query) use ($currentAgentId) {
                $query->whereNull('inventory_asset_id');

                if ($currentAgentId !== null) {
                    $query->orWhere('id', $currentAgentId);
                }
            })
            ->orderBy('hostname')
            ->get(['id', 'hostname', 'device_id', 'primary_ip', 'agent_version', 'last_seen_at'])
            ->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'hostname' => $agent->hostname,
                'device_id' => $agent->device_id,
                'primary_ip' => $agent->primary_ip,
                'agent_version' => $agent->agent_version,
                'status' => $this->presentAgentLinkStatus($agent),
            ])
            ->all();
    }

    protected function presentAgentLinkStatus(Agent $agent): string
    {
        if ($agent->last_seen_at === null) {
            return 'Never Seen';
        }

        return $agent->last_seen_at->gt(now()->subMinutes(5)) ? 'Online' : 'Idle';
    }
}
