<?php

namespace App\Services\Alerting;

use App\Models\AlertRule;
use App\Models\Event;
use App\Models\Integration;

class AlertEvaluator
{
    public function __construct(
        private readonly AlertNotifier $notifier,
    ) {
    }

    public function syncIntegrationHealth(Integration $integration, array $result): void
    {
        $rule = $this->resolveRule($integration->site_id, AlertRule::RULE_INTEGRATION_HEALTH_FAILURE);
        $fingerprint = "integration:{$integration->id}:health_failure";

        if (! $rule['is_active']) {
            $this->resolveIfActive($fingerprint, ['reason' => 'rule_disabled']);

            return;
        }

        if ($result['success']) {
            $this->resolveIfActive($fingerprint, [
                'recovery_message' => $result['message'],
                'meta' => $result['meta'] ?? [],
            ]);

            return;
        }

        $this->activateAlert(
            integration: $integration,
            ruleKey: AlertRule::RULE_INTEGRATION_HEALTH_FAILURE,
            fingerprint: $fingerprint,
            severity: $rule['default_severity'] ?? Event::SEVERITY_CRITICAL,
            title: "API health degraded: {$integration->name}",
            message: $result['message'],
            context: [
                'meta' => $result['meta'] ?? [],
            ],
        );
    }

    public function evaluateProxmoxGuests(Integration $integration, array $guests): void
    {
        $seenFingerprints = [];
        $stoppedRule = $this->resolveRule($integration->site_id, AlertRule::RULE_PROXMOX_GUEST_STOPPED);
        $metricRules = [
            AlertRule::RULE_PROXMOX_GUEST_CPU_USAGE => $this->resolveRule($integration->site_id, AlertRule::RULE_PROXMOX_GUEST_CPU_USAGE),
            AlertRule::RULE_PROXMOX_GUEST_MEMORY_USAGE => $this->resolveRule($integration->site_id, AlertRule::RULE_PROXMOX_GUEST_MEMORY_USAGE),
            AlertRule::RULE_PROXMOX_GUEST_DISK_USAGE => $this->resolveRule($integration->site_id, AlertRule::RULE_PROXMOX_GUEST_DISK_USAGE),
        ];

        foreach ($guests as $guest) {
            $guestLabel = trim(($guest['name'] ?? 'Guest').' #'.($guest['vmid'] ?? '?'));
            $guestIdentity = ($guest['type'] ?? 'guest').':'.($guest['vmid'] ?? 'unknown');

            $stoppedFingerprint = "integration:{$integration->id}:guest:{$guestIdentity}:stopped";
            if ($stoppedRule['is_active']) {
                $seenFingerprints[] = $stoppedFingerprint;

                if (($guest['status'] ?? 'unknown') !== 'running') {
                    $this->activateAlert(
                        integration: $integration,
                        ruleKey: AlertRule::RULE_PROXMOX_GUEST_STOPPED,
                        fingerprint: $stoppedFingerprint,
                        severity: $stoppedRule['default_severity'] ?? Event::SEVERITY_CRITICAL,
                        title: "Proxmox guest stopped: {$guestLabel}",
                        message: "{$guestLabel} is currently {$guest['status']}.",
                        context: ['guest' => $guest],
                    );
                } else {
                    $this->resolveIfActive($stoppedFingerprint, ['guest' => $guest]);
                }
            } else {
                $this->resolveIfActive($stoppedFingerprint, ['reason' => 'rule_disabled']);
            }

            $this->evaluateMetricRule(
                integration: $integration,
                guest: $guest,
                guestLabel: $guestLabel,
                guestIdentity: $guestIdentity,
                ruleKey: AlertRule::RULE_PROXMOX_GUEST_CPU_USAGE,
                valueKey: 'cpu_usage_percent',
                metricLabel: 'CPU',
                rule: $metricRules[AlertRule::RULE_PROXMOX_GUEST_CPU_USAGE],
                seenFingerprints: $seenFingerprints,
            );
            $this->evaluateMetricRule(
                integration: $integration,
                guest: $guest,
                guestLabel: $guestLabel,
                guestIdentity: $guestIdentity,
                ruleKey: AlertRule::RULE_PROXMOX_GUEST_MEMORY_USAGE,
                valueKey: 'memory_usage_percent',
                metricLabel: 'memory',
                rule: $metricRules[AlertRule::RULE_PROXMOX_GUEST_MEMORY_USAGE],
                seenFingerprints: $seenFingerprints,
            );
            $this->evaluateMetricRule(
                integration: $integration,
                guest: $guest,
                guestLabel: $guestLabel,
                guestIdentity: $guestIdentity,
                ruleKey: AlertRule::RULE_PROXMOX_GUEST_DISK_USAGE,
                valueKey: 'disk_usage_percent',
                metricLabel: 'disk',
                rule: $metricRules[AlertRule::RULE_PROXMOX_GUEST_DISK_USAGE],
                seenFingerprints: $seenFingerprints,
            );
        }

        $activeGuestAlerts = Event::query()
            ->where('integration_id', $integration->id)
            ->whereIn('rule_key', [
                AlertRule::RULE_PROXMOX_GUEST_STOPPED,
                AlertRule::RULE_PROXMOX_GUEST_CPU_USAGE,
                AlertRule::RULE_PROXMOX_GUEST_MEMORY_USAGE,
                AlertRule::RULE_PROXMOX_GUEST_DISK_USAGE,
            ])
            ->whereNotNull('active_fingerprint')
            ->get();

        foreach ($activeGuestAlerts as $event) {
            if (! in_array($event->active_fingerprint, $seenFingerprints, true)) {
                $event->resolve([
                    'reason' => 'guest_missing_or_recovered',
                    'previous_context' => $event->context,
                ]);

                $this->notifier->notify($event->fresh(['integration', 'site']), 'resolved');
            }
        }
    }

    public function evaluateDockerContainers(Integration $integration, array $containers): void
    {
        $seenFingerprints = [];
        $rule = $this->resolveRule($integration->site_id, AlertRule::RULE_DOCKER_CONTAINER_STOPPED);

        foreach ($containers as $container) {
            $containerLabel = trim(($container['name'] ?? 'Container').' '.substr((string) ($container['id'] ?? 'unknown'), 0, 12));
            $containerId = (string) ($container['id'] ?? 'unknown');
            $fingerprint = "integration:{$integration->id}:container:{$containerId}:stopped";

            if (! $rule['is_active']) {
                $this->resolveIfActive($fingerprint, ['reason' => 'rule_disabled']);

                continue;
            }

            $seenFingerprints[] = $fingerprint;

            if (($container['state'] ?? 'unknown') !== 'running') {
                $this->activateAlert(
                    integration: $integration,
                    ruleKey: AlertRule::RULE_DOCKER_CONTAINER_STOPPED,
                    fingerprint: $fingerprint,
                    severity: $rule['default_severity'] ?? Event::SEVERITY_CRITICAL,
                    title: "Docker container stopped: {$containerLabel}",
                    message: "{$containerLabel} is currently {$container['state']}.",
                    context: ['container' => $container],
                );
            } else {
                $this->resolveIfActive($fingerprint, ['container' => $container]);
            }
        }

        $activeContainerAlerts = Event::query()
            ->where('integration_id', $integration->id)
            ->where('rule_key', AlertRule::RULE_DOCKER_CONTAINER_STOPPED)
            ->whereNotNull('active_fingerprint')
            ->get();

        foreach ($activeContainerAlerts as $event) {
            if (! in_array($event->active_fingerprint, $seenFingerprints, true)) {
                $event->resolve([
                    'reason' => 'container_missing_or_recovered',
                    'previous_context' => $event->context,
                ]);

                $this->notifier->notify($event->fresh(['integration', 'site']), 'resolved');
            }
        }
    }

    private function evaluateMetricRule(
        Integration $integration,
        array $guest,
        string $guestLabel,
        string $guestIdentity,
        string $ruleKey,
        string $valueKey,
        string $metricLabel,
        array $rule,
        array &$seenFingerprints,
    ): void {
        $fingerprint = "integration:{$integration->id}:guest:{$guestIdentity}:{$valueKey}";

        if (! $rule['is_active']) {
            $this->resolveIfActive($fingerprint, ['reason' => 'rule_disabled']);

            return;
        }

        $seenFingerprints[] = $fingerprint;
        $value = $guest[$valueKey] ?? null;
        $severity = $this->thresholdSeverity($value, $rule);

        if ($severity === null) {
            $this->resolveIfActive($fingerprint, ['guest' => $guest]);

            return;
        }

        $this->activateAlert(
            integration: $integration,
            ruleKey: $ruleKey,
            fingerprint: $fingerprint,
            severity: $severity,
            title: "High {$metricLabel} usage: {$guestLabel}",
            message: "{$guestLabel} {$metricLabel} usage is at {$value}%.",
            context: [
                'guest' => $guest,
                'observed_value' => $value,
                'warning_threshold' => $rule['warning_threshold'],
                'critical_threshold' => $rule['critical_threshold'],
            ],
        );
    }

    private function activateAlert(
        Integration $integration,
        string $ruleKey,
        string $fingerprint,
        string $severity,
        string $title,
        string $message,
        array $context,
    ): Event {
        $event = $this->findActiveEvent($fingerprint);

        $transition = 'opened';

        if (! $event) {
            $event = Event::create([
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
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        } else {
            $transition = null;
            $status = $event->status;

            if ($event->severity !== $severity) {
                $transition = 'severity_changed';
                $status = Event::STATUS_OPEN;
            } elseif ($event->status === Event::STATUS_ACKNOWLEDGED && $event->message !== $message) {
                $transition = 'reopened';
                $status = Event::STATUS_OPEN;
            }

            $event->forceFill([
                'integration_id' => $integration->id,
                'site_id' => $integration->site_id,
                'rule_key' => $ruleKey,
                'fingerprint' => $fingerprint,
                'active_fingerprint' => $fingerprint,
                'severity' => $severity,
                'title' => $title,
                'message' => $message,
                'context' => $context,
                'status' => $status,
                'last_seen_at' => now(),
                'resolved_at' => null,
                'acknowledged_by' => $status === Event::STATUS_OPEN ? null : $event->acknowledged_by,
                'acknowledged_at' => $status === Event::STATUS_OPEN ? null : $event->acknowledged_at,
                'acknowledge_comment' => $status === Event::STATUS_OPEN ? null : $event->acknowledge_comment,
            ])->save();
        }

        $event->loadMissing(['integration', 'site']);

        if ($transition !== null) {
            $this->notifier->notify($event, $transition);
        }

        return $event;
    }

    private function resolveIfActive(string $fingerprint, array $context = []): void
    {
        $event = $this->findActiveEvent($fingerprint);

        if (! $event) {
            return;
        }

        $event->resolve($context);
        $event->loadMissing(['integration', 'site']);
        $this->notifier->notify($event, 'resolved');
    }

    private function thresholdSeverity(mixed $value, array $rule): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        $numericValue = (float) $value;
        $critical = $rule['critical_threshold'];
        $warning = $rule['warning_threshold'];

        if ($critical !== null && $numericValue >= $critical) {
            return Event::SEVERITY_CRITICAL;
        }

        if ($warning !== null && $numericValue >= $warning) {
            return Event::SEVERITY_WARNING;
        }

        return null;
    }

    private function findActiveEvent(string $fingerprint): ?Event
    {
        return Event::query()
            ->where(function ($query) use ($fingerprint) {
                $query->where('active_fingerprint', $fingerprint)
                    ->orWhere(function ($subQuery) use ($fingerprint) {
                        $subQuery->where('fingerprint', $fingerprint)
                            ->where('status', '!=', Event::STATUS_RESOLVED);
                    });
            })
            ->orderByDesc('id')
            ->first();
    }

    private function resolveRule(?string $siteId, string $ruleKey): array
    {
        $rule = AlertRule::query()
            ->where('rule_key', $ruleKey)
            ->where(function ($query) use ($siteId) {
                if ($siteId) {
                    $query->where('site_id', $siteId)->orWhereNull('site_id');
                } else {
                    $query->whereNull('site_id');
                }
            })
            ->orderByRaw('site_id is null')
            ->first();

        if ($rule) {
            return [
                'is_active' => $rule->is_active,
                'default_severity' => $rule->default_severity,
                'warning_threshold' => $rule->warning_threshold,
                'critical_threshold' => $rule->critical_threshold,
                'config' => $rule->config ?? [],
            ];
        }

        return match ($ruleKey) {
            AlertRule::RULE_INTEGRATION_HEALTH_FAILURE,
            AlertRule::RULE_PROXMOX_GUEST_STOPPED,
            AlertRule::RULE_DOCKER_CONTAINER_STOPPED => [
                'is_active' => true,
                'default_severity' => Event::SEVERITY_CRITICAL,
                'warning_threshold' => null,
                'critical_threshold' => null,
                'config' => [],
            ],
            default => [
                'is_active' => true,
                'default_severity' => null,
                'warning_threshold' => 80.0,
                'critical_threshold' => 90.0,
                'config' => [],
            ],
        };
    }
}
