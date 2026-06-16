<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatCard from '@/Components/StatCard.vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const userRole = page.props.auth.user?.role;
const userSites = page.props.auth.sites ?? [];
const isAdmin = page.props.auth.permissions?.is_admin ?? false;

const props = defineProps({
    stats: { type: Object, required: true },
    integrations: { type: Array, required: true },
    recentActivity: { type: Array, required: true },
    recentAlerts: { type: Array, required: true },
    sites: { type: Array, default: () => [] },
});

const scopeLabel = computed(() => {
    if (userRole === 'admin') return 'All sites';

    return userSites.length > 0
        ? userSites.map(s => s.name).join(', ')
        : 'All sites';
});

const severityBadge = {
    critical: 'badge-error',
    warning: 'badge-warning',
    info: 'badge-info',
};

const criticalShare = computed(() => (
    props.stats.active_alerts > 0
        ? Math.round((props.stats.critical_alerts / props.stats.active_alerts) * 100)
        : 0
));

function healthLabel(integration) {
    if (integration.last_test_status === 'success') {
        return 'Healthy';
    }

    if (integration.last_test_status === 'failure') {
        return 'Needs attention';
    }

    return 'Not tested';
}

function healthDotClass(integration) {
    if (integration.last_test_status === 'success') {
        return 'signal-dot--live';
    }

    if (integration.last_test_status === 'failure') {
        return 'signal-dot--critical';
    }

    return 'signal-dot--warning';
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout>
        <div class="space-y-6">
            <div v-if="userRole !== 'admin'" class="panel-subtle p-4 flex items-center gap-3">
                <span class="signal-dot signal-dot--live" />
                <span class="text-body-sm text-muted">
                    Scope: <strong class="text-body">{{ scopeLabel }}</strong>
                </span>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Live Integrations"
                    :value="stats.total_integrations"
                    accent="brand"
                    description="External systems actively feeding the room."
                />
                <StatCard
                    label="Open Alerts"
                    :value="stats.active_alerts"
                    :accent="stats.active_alerts > 0 ? 'warning' : 'success'"
                    description="Signals waiting for operator attention."
                />
                <StatCard
                    label="Critical Load"
                    :value="stats.critical_alerts"
                    accent="critical"
                    description="Highest-priority incidents in the current queue."
                />
                <StatCard
                    label="Warnings"
                    :value="stats.warning_alerts"
                    accent="warning"
                    description="Non-critical anomalies still worth tracking."
                />
            </div>

            <div class="grid gap-6 2xl:grid-cols-[minmax(0,1.45fr)_380px]">
                <section class="panel-card table-shell">
                    <div class="flex items-center justify-between gap-4 border-b border-hairline px-5 py-4">
                        <div>
                            <div class="eyebrow">Live Queue</div>
                            <h2 class="text-title-md text-body mt-2">Recent Alerts</h2>
                        </div>
                        <span class="status-chip">
                            <span class="signal-dot" :class="stats.critical_alerts > 0 ? 'signal-dot--critical' : 'signal-dot--live'" />
                            {{ recentAlerts.length }} tracked
                        </span>
                    </div>

                    <div v-if="recentAlerts.length === 0" class="px-6 py-10 text-center text-body-sm text-muted">
                        No open alerts. The room is quiet for now.
                    </div>

                    <ul v-else class="divide-y divide-[var(--color-hairline)]">
                        <li
                            v-for="alert in recentAlerts"
                            :key="alert.id"
                            class="px-5 py-4 transition-default hover:bg-elevated/40"
                        >
                            <div class="flex items-start gap-3">
                                <span class="badge badge-sm mt-0.5" :class="severityBadge[alert.severity] ?? 'badge-ghost'">
                                    {{ alert.severity }}
                                </span>

                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <div class="text-body-md text-body">{{ alert.title }}</div>
                                        <span class="text-caption text-muted">{{ alert.integration_name }}</span>
                                        <span class="status-chip">{{ alert.site_label }}</span>
                                    </div>
                                    <div v-if="alert.message" class="text-body-sm text-muted mt-2 line-clamp-2">
                                        {{ alert.message }}
                                    </div>
                                    <div class="text-caption text-muted mt-3">
                                        {{ alert.integration_type }} source · {{ alert.created_at }}
                                    </div>
                                </div>
                            </div>
                        </li>
                    </ul>
                </section>

                <aside class="space-y-6">
                    <section class="panel-card overflow-hidden">
                        <div class="border-b border-hairline px-5 py-4">
                            <div class="eyebrow">Source Health</div>
                            <h2 class="text-title-md text-body mt-2">Connected Systems</h2>
                        </div>

                        <div v-if="integrations.length === 0" class="px-5 py-8 text-body-sm text-muted">
                            No active integrations available yet.
                        </div>

                        <ul v-else class="divide-y divide-[var(--color-hairline)]">
                            <li
                                v-for="integration in integrations"
                                :key="integration.id"
                                class="px-5 py-4"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="text-body-sm text-body">{{ integration.name }}</div>
                                        <div class="text-caption text-muted mt-1">
                                            {{ integration.type_name }} · {{ integration.scope_label }}
                                        </div>
                                    </div>

                                    <span class="status-chip shrink-0">
                                        <span class="signal-dot" :class="healthDotClass(integration)" />
                                        {{ healthLabel(integration) }}
                                    </span>
                                </div>

                                <div class="text-caption text-muted mt-3">
                                    Last checked: {{ integration.last_tested_at ?? 'Never' }}
                                </div>

                                <div class="mt-2 flex flex-wrap items-center gap-3 text-caption text-muted">
                                    <span>{{ integration.api_health?.label ?? 'Not tested' }}</span>
                                    <span>Auth {{ integration.api_health?.auth_status ?? 'unknown' }}</span>
                                    <span v-if="integration.api_health?.latency_ms !== null">
                                        {{ integration.api_health.latency_ms }} ms
                                    </span>
                                </div>

                                <div
                                    v-if="integration.type === 'proxmox' && integration.source_summary"
                                    class="mt-3 grid grid-cols-3 gap-2"
                                >
                                    <div class="rounded-lg border border-hairline bg-base-300/40 px-3 py-2">
                                        <div class="text-caption text-muted">Nodes</div>
                                        <div class="text-number-sm text-body mt-1">{{ integration.source_summary.node_count ?? '—' }}</div>
                                    </div>
                                    <div class="rounded-lg border border-hairline bg-base-300/40 px-3 py-2">
                                        <div class="text-caption text-muted">VM</div>
                                        <div class="text-number-sm text-body mt-1">{{ integration.source_summary.vm_count ?? '—' }}</div>
                                    </div>
                                    <div class="rounded-lg border border-hairline bg-base-300/40 px-3 py-2">
                                        <div class="text-caption text-muted">CT</div>
                                        <div class="text-number-sm text-body mt-1">{{ integration.source_summary.ct_count ?? '—' }}</div>
                                    </div>
                                </div>

                            </li>
                        </ul>
                    </section>

                    <section class="panel-subtle p-5">
                        <div class="eyebrow">Response Logic</div>
                        <div class="data-list mt-5">
                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Critical Share</div>
                                    <div class="text-title-sm text-body mt-1">
                                        {{ stats.active_alerts > 0 ? `${criticalShare}%` : '0%' }}
                                    </div>
                                </div>
                                <div class="text-number-sm" :class="stats.critical_alerts > 0 ? 'text-trading-down' : 'text-trading-up'">
                                    {{ stats.critical_alerts > 0 ? 'Escalate' : 'Stable' }}
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Recommended Action</div>
                                    <div class="text-body-sm text-body mt-1">
                                        Review unresolved critical alerts before provisioning or maintenance work.
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-card overflow-hidden">
                        <div class="border-b border-hairline px-5 py-4">
                            <div class="eyebrow">Operator Feed</div>
                            <h2 class="text-title-md text-body mt-2">Recent Activity</h2>
                        </div>

                        <div v-if="recentActivity.length === 0" class="px-5 py-8 text-body-sm text-muted">
                            No activity yet.
                        </div>

                        <ul v-else class="divide-y divide-[var(--color-hairline)]">
                            <li
                                v-for="log in recentActivity"
                                :key="log.id"
                                class="px-5 py-4 transition-default hover:bg-elevated/40"
                            >
                                <div class="text-body-sm">
                                    <span class="text-brand font-mono-num">{{ log.action }}</span>
                                    <span class="text-muted"> by </span>
                                    <span>{{ log.user_name }}</span>
                                </div>
                                <div class="text-caption text-muted mt-2">
                                    {{ log.target_type ?? 'system' }}
                                    <span v-if="log.target_id"> / {{ log.target_id }}</span>
                                    <span> · {{ log.site_label }}</span>
                                </div>
                                <div class="text-caption text-muted mt-2">{{ log.created_at }}</div>
                            </li>
                        </ul>
                    </section>
                </aside>
            </div>

            <section class="panel-card table-shell">
                <div class="flex items-center justify-between gap-4 border-b border-hairline px-5 py-4">
                    <div>
                        <div class="eyebrow">Source Mesh</div>
                        <h2 class="text-title-md text-body mt-2">Integration Fabric</h2>
                    </div>
                    <Link :href="route('integrations.index')" class="btn btn-secondary btn-sm">
                        Manage Sources
                    </Link>
                </div>

                <div v-if="integrations.length === 0" class="px-6 py-10 text-center">
                    <p class="text-body-sm text-muted">No integrations configured yet.</p>
                    <Link v-if="isAdmin" :href="route('integrations.create')" class="btn btn-primary mt-4">
                        Connect your first tool
                    </Link>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr class="border-hairline">
                                <th>Name</th>
                                <th>Type</th>
                                <th>Scope</th>
                                <th>Connection</th>
                                <th>Status</th>
                                <th>Last Sync</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="integration in integrations"
                                :key="integration.id"
                                class="border-hairline transition-default hover:bg-elevated/40"
                            >
                                <td class="text-body-sm text-body">{{ integration.name }}</td>
                                <td class="text-body-sm text-muted">{{ integration.type_name }}</td>
                                <td class="text-body-sm text-muted">{{ integration.scope_label }}</td>
                                <td>
                                    <span class="status-chip">
                                        <span class="signal-dot" :class="healthDotClass(integration)" />
                                        {{ healthLabel(integration) }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge badge-sm" :class="integration.is_active ? 'badge-success' : 'badge-ghost'">
                                        {{ integration.is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                </td>
                                <td class="text-caption text-muted">{{ integration.last_synced_at ?? 'Never' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
