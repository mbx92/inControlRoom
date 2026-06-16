<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Integration;
use Illuminate\Database\Seeder;

class AlertShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $sohoIntegration = Integration::query()
            ->where('name', 'SOHO Door Access API')
            ->first();

        $hqProxmox = Integration::query()
            ->where('name', 'HQ Proxmox Cluster')
            ->first();

        $hqCctvApi = Integration::query()
            ->where('name', 'HQ CCTV Monitoring API')
            ->first();

        if (! $sohoIntegration || ! $hqProxmox || ! $hqCctvApi) {
            $this->call(TopologyShowcaseSeeder::class);

            $sohoIntegration = Integration::query()->where('name', 'SOHO Door Access API')->first();
            $hqProxmox = Integration::query()->where('name', 'HQ Proxmox Cluster')->first();
            $hqCctvApi = Integration::query()->where('name', 'HQ CCTV Monitoring API')->first();
        }

        Event::query()
            ->whereIn('integration_id', [
                $sohoIntegration?->id,
                $hqProxmox?->id,
                $hqCctvApi?->id,
            ])
            ->delete();

        $this->openEvent(
            integration: $sohoIntegration,
            ruleKey: 'integration_health_failure',
            severity: Event::SEVERITY_WARNING,
            title: 'Door access API latency elevated',
            message: 'Door access API is reachable, but average latency has stayed above 400 ms for the last 10 minutes.',
            fingerprint: "integration:{$sohoIntegration->id}:door_access_latency",
            context: [
                'observed_latency_ms' => 428,
                'site_area' => 'Ground Floor / Entrance',
            ],
            firstSeenAt: now()->subMinutes(13),
            lastSeenAt: now()->subMinutes(2),
        );

        $this->openEvent(
            integration: $hqProxmox,
            ruleKey: 'proxmox_guest_stopped',
            severity: Event::SEVERITY_CRITICAL,
            title: 'Proxmox guest stopped: Billing VM #202',
            message: 'Billing VM 202 on node pve-hq-01 is no longer running and operator action is required.',
            fingerprint: "integration:{$hqProxmox->id}:guest:qemu:202:stopped",
            context: [
                'guest' => [
                    'type' => 'qemu',
                    'vmid' => 202,
                    'name' => 'Billing VM',
                    'node' => 'pve-hq-01',
                    'status' => 'stopped',
                ],
            ],
            firstSeenAt: now()->subMinutes(27),
            lastSeenAt: now()->subMinute(),
        );

        $this->acknowledgedEvent(
            integration: $hqProxmox,
            ruleKey: 'proxmox_guest_disk_usage_percent',
            severity: Event::SEVERITY_WARNING,
            title: 'High disk usage: File Server VM #204',
            message: 'File Server VM 204 is at 84.6% disk usage and has been assigned to the infra team for cleanup.',
            fingerprint: "integration:{$hqProxmox->id}:guest:qemu:204:disk_usage_percent",
            context: [
                'guest' => [
                    'type' => 'qemu',
                    'vmid' => 204,
                    'name' => 'File Server VM',
                    'node' => 'pve-hq-02',
                    'disk_usage_percent' => 84.6,
                ],
                'warning_threshold' => 80,
                'critical_threshold' => 90,
            ],
            firstSeenAt: now()->subHours(2),
            lastSeenAt: now()->subMinutes(9),
            acknowledgeComment: 'Cleanup scheduled after backup window.',
        );

        $this->resolvedEvent(
            integration: $hqCctvApi,
            ruleKey: 'integration_health_failure',
            severity: Event::SEVERITY_INFO,
            title: 'CCTV monitoring sync recovered',
            message: 'The CCTV monitoring API returned to normal after a brief authentication issue.',
            fingerprint: "integration:{$hqCctvApi->id}:health_failure",
            context: [
                'recovery_message' => 'Authentication token refreshed successfully.',
            ],
            firstSeenAt: now()->subHours(6),
            lastSeenAt: now()->subHours(6)->addMinutes(14),
            resolvedAt: now()->subHours(6)->addMinutes(14),
        );
    }

    private function openEvent(
        Integration $integration,
        string $ruleKey,
        string $severity,
        string $title,
        string $message,
        string $fingerprint,
        array $context,
        \Illuminate\Support\Carbon $firstSeenAt,
        \Illuminate\Support\Carbon $lastSeenAt,
    ): void {
        Event::query()->create([
            'integration_id' => $integration->id,
            'site_id' => $integration->site_id,
            'rule_key' => $ruleKey,
            'fingerprint' => $fingerprint,
            'active_fingerprint' => $fingerprint,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
            'status' => Event::STATUS_OPEN,
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => $lastSeenAt,
            'created_at' => $firstSeenAt,
            'updated_at' => $lastSeenAt,
        ]);
    }

    private function acknowledgedEvent(
        Integration $integration,
        string $ruleKey,
        string $severity,
        string $title,
        string $message,
        string $fingerprint,
        array $context,
        \Illuminate\Support\Carbon $firstSeenAt,
        \Illuminate\Support\Carbon $lastSeenAt,
        string $acknowledgeComment,
    ): void {
        Event::query()->create([
            'integration_id' => $integration->id,
            'site_id' => $integration->site_id,
            'rule_key' => $ruleKey,
            'fingerprint' => $fingerprint,
            'active_fingerprint' => $fingerprint,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
            'status' => Event::STATUS_ACKNOWLEDGED,
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => $lastSeenAt,
            'acknowledge_comment' => $acknowledgeComment,
            'acknowledged_at' => $lastSeenAt->copy()->subMinutes(5),
            'created_at' => $firstSeenAt,
            'updated_at' => $lastSeenAt,
        ]);
    }

    private function resolvedEvent(
        Integration $integration,
        string $ruleKey,
        string $severity,
        string $title,
        string $message,
        string $fingerprint,
        array $context,
        \Illuminate\Support\Carbon $firstSeenAt,
        \Illuminate\Support\Carbon $lastSeenAt,
        \Illuminate\Support\Carbon $resolvedAt,
    ): void {
        Event::query()->create([
            'integration_id' => $integration->id,
            'site_id' => $integration->site_id,
            'rule_key' => $ruleKey,
            'fingerprint' => $fingerprint,
            'active_fingerprint' => null,
            'severity' => $severity,
            'title' => $title,
            'message' => $message,
            'context' => $context,
            'status' => Event::STATUS_RESOLVED,
            'first_seen_at' => $firstSeenAt,
            'last_seen_at' => $lastSeenAt,
            'resolved_at' => $resolvedAt,
            'created_at' => $firstSeenAt,
            'updated_at' => $resolvedAt,
        ]);
    }
}
