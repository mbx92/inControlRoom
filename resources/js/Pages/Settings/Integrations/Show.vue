<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const page = usePage();
const permissions = page.props.auth.permissions ?? {};

const props = defineProps({
    integration: { type: Object, required: true },
    activity: { type: Object, required: true },
    activitySource: { type: String, default: 'local' },
    activityError: { type: String, default: null },
    proxmoxGuests: { type: Array, default: null },
    proxmoxSummary: { type: Object, default: null },
    proxmoxJournal: { type: Object, default: null },
});

const connectionHealth = computed(() => {
    if (props.integration.last_test_status === 'success') {
        return {
            label: 'Healthy',
            dotClass: 'signal-dot--live',
        };
    }

    if (props.integration.last_test_status === 'failure') {
        return {
            label: 'Needs attention',
            dotClass: 'signal-dot--critical',
        };
    }

    return {
        label: 'Not tested',
        dotClass: 'signal-dot--warning',
    };
});

function testConnection() {
    router.post(route('integrations.test', props.integration.id), {}, {
        preserveScroll: true,
    });
}

function formatBytes(bytes) {
    if (bytes === null || bytes === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let value = Number(bytes);
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    const digits = value >= 10 || unitIndex === 0 ? 0 : 1;
    return `${value.toFixed(digits)} ${units[unitIndex]}`;
}

function formatPercent(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${Number(value).toFixed(value >= 10 ? 0 : 1)}%`;
}

function formatUptime(seconds) {
    if (!seconds) {
        return '—';
    }

    const totalMinutes = Math.floor(seconds / 60);
    const days = Math.floor(totalMinutes / 1440);
    const hours = Math.floor((totalMinutes % 1440) / 60);
    const minutes = totalMinutes % 60;

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m`;
}

function activityBadgeClass(result) {
    if (result === 'success') {
        return 'badge-success';
    }

    if (result === 'running') {
        return 'badge-warning';
    }

    return 'badge-error';
}

function goToPage(pageParam, page) {
    router.get(route('integrations.show', props.integration.id), {
        tasks_page: pageParam === 'tasks_page' ? page : props.activity.current_page,
        journal_page: pageParam === 'journal_page' ? page : (props.proxmoxJournal?.current_page ?? 1),
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <Head :title="integration.name" />

    <AppLayout>
        <PageHeader
            :title="integration.name"
            :subtitle="integration.type_name"
            eyebrow="Integration Detail"
        >
            <template #meta>
                <span class="status-chip">
                    <span :class="integration.is_active ? 'signal-dot signal-dot--live' : 'signal-dot signal-dot--warning'" />
                    {{ integration.is_active ? 'Active' : 'Standby' }}
                </span>
                <span class="status-chip">
                    <span class="signal-dot" :class="connectionHealth.dotClass" />
                    {{ connectionHealth.label }}
                </span>
                <span class="status-chip">
                    {{ integration.scope_kind === 'global' ? 'Global' : integration.scope_label }}
                </span>
            </template>

            <template #actions>
                <button v-if="permissions.can_execute" type="button" class="btn btn-secondary" @click="testConnection">
                    Check API Health
                </button>
                <Link v-if="permissions.is_admin" :href="route('integrations.edit', integration.id)" class="btn btn-primary">
                    Edit Integration
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="panel-card p-4 sm:p-5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Host</div>
                        <div class="text-title-sm text-body mt-3 break-all">{{ integration.base_host }}</div>
                        <div class="text-caption text-muted mt-2">Endpoint target</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Last Checked</div>
                        <div class="text-title-sm text-body mt-3">{{ integration.last_tested_at ?? 'Never' }}</div>
                        <div class="mt-2 flex items-center gap-2 text-caption text-muted">
                            <span class="signal-dot" :class="connectionHealth.dotClass" />
                            {{ connectionHealth.label }}
                        </div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Events</div>
                        <div class="text-number-sm text-body mt-3">{{ integration.events_count }}</div>
                        <div class="text-caption text-muted mt-2">Tracked records</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Metrics</div>
                        <div class="text-number-sm text-body mt-3">{{ integration.metrics_count }}</div>
                        <div class="text-caption text-muted mt-2">Persisted points</div>
                    </article>
                </div>
            </section>

            <section
                v-if="integration.type === 'proxmox' && proxmoxSummary"
                class="panel-card p-4 sm:p-5"
            >
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Virtual Machines</div>
                        <div class="mt-3 flex items-end gap-2">
                            <div class="text-number-sm text-body">{{ proxmoxSummary.vm_total }}</div>
                            <div class="text-caption text-muted">{{ proxmoxSummary.vm_online }} online</div>
                        </div>
                        <div class="text-caption text-muted mt-2">{{ proxmoxSummary.vm_offline }} offline</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Containers</div>
                        <div class="mt-3 flex items-end gap-2">
                            <div class="text-number-sm text-body">{{ proxmoxSummary.ct_total }}</div>
                            <div class="text-caption text-muted">{{ proxmoxSummary.ct_online }} online</div>
                        </div>
                        <div class="text-caption text-muted mt-2">{{ proxmoxSummary.ct_offline }} offline</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Memory Footprint</div>
                        <div class="text-title-sm text-body mt-3">
                            {{ formatBytes(proxmoxSummary.memory_used_bytes) }}
                        </div>
                        <div class="text-caption text-muted mt-2">
                            of {{ formatBytes(proxmoxSummary.memory_total_bytes) }}
                        </div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Disk Footprint</div>
                        <div class="text-title-sm text-body mt-3">
                            {{ formatBytes(proxmoxSummary.disk_used_bytes) }}
                        </div>
                        <div class="text-caption text-muted mt-2">
                            of {{ formatBytes(proxmoxSummary.disk_total_bytes) }}
                        </div>
                    </article>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.3fr)_360px]">
                <section class="space-y-6">
                    <section class="panel-card p-6">
                        <div class="eyebrow">Overview</div>
                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <div>
                                <div class="text-caption text-muted">Endpoint</div>
                                <div class="text-body-sm text-body mt-2 break-all font-mono-num">
                                    {{ integration.base_url }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Scope</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.scope_kind === 'global' ? 'Global scope' : integration.scope_label }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Last Result</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.last_test_message ?? 'No connection check recorded yet.' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">SSL Verification</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.config?.verify_ssl === false ? 'Disabled' : 'Enabled' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Secret Source</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.secret_source_label }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">API Health</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health?.label ?? 'Not tested' }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="eyebrow">API Health</div>
                        <h2 class="text-title-md text-body mt-2">Latest Endpoint Check</h2>

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <div>
                                <div class="text-caption text-muted">Health Endpoint</div>
                                <div class="text-body-sm text-body mt-2 break-all font-mono-num">
                                    {{ integration.api_health?.endpoint ?? integration.base_url }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Reachability</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health?.reachable ? 'Reachable' : 'Unavailable / unknown' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Auth Status</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health?.auth_status ?? 'unknown' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Latency</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health?.latency_ms !== null ? `${integration.api_health.latency_ms} ms` : '—' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">HTTP Status</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health?.http_status ?? '—' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Method</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health?.method ?? 'GET' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Expected Status</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health?.expected_status ?? '200' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">API Version</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health?.version ?? '—' }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="integration.type === 'proxmox' && integration.source_summary"
                        class="panel-card p-6"
                    >
                        <div class="eyebrow">Platform Summary</div>
                        <div class="mt-4 text-title-sm text-body">
                            {{ integration.source_summary.headline }}
                            <span v-if="integration.source_summary.release" class="text-muted">
                                · {{ integration.source_summary.release }}
                            </span>
                        </div>

                        <div class="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
                            <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">Nodes</div>
                                <div class="text-number-sm text-body mt-2">
                                    {{ integration.source_summary.node_count ?? '—' }}
                                </div>
                            </div>

                            <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">VM</div>
                                <div class="text-number-sm text-body mt-2">
                                    {{ integration.source_summary.vm_count ?? '—' }}
                                </div>
                            </div>

                            <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">CT</div>
                                <div class="text-number-sm text-body mt-2">
                                    {{ integration.source_summary.ct_count ?? '—' }}
                                </div>
                            </div>

                            <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">Repo ID</div>
                                <div class="text-body-sm text-body mt-2 font-mono-num">
                                    {{ integration.source_summary.repoid ?? '—' }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="integration.type === 'proxmox' && proxmoxGuests"
                        class="panel-card p-6"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Guest Estate</div>
                                <h2 class="text-title-md text-body mt-2">Live VM / CT Snapshot</h2>
                            </div>

                            <div class="status-chip">{{ proxmoxGuests.length }} guests</div>
                        </div>

                        <div class="mt-6 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Guest</th>
                                        <th>Status</th>
                                        <th>CPU</th>
                                        <th>RAM</th>
                                        <th>Disk</th>
                                        <th>Uptime</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="guest in proxmoxGuests"
                                        :key="guest.id"
                                        class="border-hairline transition-default hover:bg-elevated/30"
                                    >
                                        <td>
                                            <Link
                                                :href="route('integrations.guests.show', {
                                                    integration: integration.id,
                                                    guestType: guest.type,
                                                    node: guest.node,
                                                    vmid: guest.vmid,
                                                })"
                                                class="text-body-sm text-body transition-default hover:text-brand"
                                            >
                                                {{ guest.name }}
                                            </Link>
                                            <div class="text-caption text-muted mt-1">
                                                {{ guest.type_label }} / {{ guest.vmid }} / {{ guest.node }}
                                            </div>
                                        </td>
                                        <td>
                                            <span class="status-chip">
                                                <span
                                                    class="signal-dot"
                                                    :class="guest.is_online ? 'signal-dot--live' : 'signal-dot--warning'"
                                                />
                                                {{ guest.is_online ? 'Online' : 'Offline' }}
                                            </span>
                                        </td>
                                        <td class="text-body-sm text-body">
                                            <div>{{ formatPercent(guest.cpu_usage_percent) }}</div>
                                            <div class="text-caption text-muted mt-1">
                                                {{ guest.cpu_cores ?? '—' }} cores
                                            </div>
                                        </td>
                                        <td class="text-body-sm text-body">
                                            <div>
                                                {{ formatBytes(guest.memory_used_bytes) }}
                                                <span class="text-muted">/ {{ formatBytes(guest.memory_total_bytes) }}</span>
                                            </div>
                                            <div class="text-caption text-muted mt-1">
                                                {{ formatPercent(guest.memory_usage_percent) }}
                                            </div>
                                        </td>
                                        <td class="text-body-sm text-body">
                                            <div>
                                                {{ formatBytes(guest.disk_used_bytes) }}
                                                <span class="text-muted">/ {{ formatBytes(guest.disk_total_bytes) }}</span>
                                            </div>
                                            <div class="text-caption text-muted mt-1">
                                                {{ formatPercent(guest.disk_usage_percent) }}
                                            </div>
                                        </td>
                                        <td class="text-caption text-muted">
                                            {{ formatUptime(guest.uptime) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Recent Activity</div>
                                <h2 class="text-title-md text-body mt-2">
                                    {{ activitySource === 'proxmox' ? 'Proxmox Task Log' : 'Audit Trace' }}
                                </h2>
                            </div>

                            <Link :href="route('audit-logs.index', { action: 'integration', site: integration.site_id ?? 'global' })" class="btn btn-ghost btn-sm">
                                {{ activitySource === 'proxmox' ? 'App Audit Log' : 'View Audit Log' }}
                            </Link>
                        </div>

                        <div
                            v-if="activityError"
                            class="mt-6 rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted"
                        >
                            {{ activityError }}
                        </div>

                        <div v-if="activity.total === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No activity recorded for this integration yet.
                        </div>

                        <div v-else class="mt-6">
                            <div class="overflow-x-auto">
                                <table class="table table-sm">
                                    <thead>
                                        <tr class="border-hairline">
                                            <th>Time</th>
                                            <th>Action</th>
                                            <th>Actor</th>
                                            <th>Target</th>
                                            <th>State</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="entry in activity.data"
                                            :key="entry.id"
                                            class="border-hairline transition-default hover:bg-elevated/30"
                                        >
                                            <td class="text-caption text-muted">
                                                {{ entry.created_at_full ?? entry.created_at }}
                                            </td>
                                            <td class="text-body-sm text-body font-mono-num">
                                                {{ entry.action }}
                                            </td>
                                            <td class="text-body-sm text-body">
                                                {{ entry.user_name }}
                                            </td>
                                            <td class="text-caption text-muted">
                                                <span v-if="entry.node">{{ entry.node }}</span>
                                                <span v-if="entry.target"> / {{ entry.target }}</span>
                                                <span v-if="!entry.node && !entry.target">—</span>
                                            </td>
                                            <td>
                                                <span class="badge badge-sm" :class="activityBadgeClass(entry.result)">
                                                    {{ entry.status_label ?? entry.result }}
                                                </span>
                                                <div v-if="entry.error_message" class="text-caption text-muted mt-2 max-w-xs">
                                                    {{ entry.error_message }}
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4 flex items-center justify-between gap-4">
                                <div class="text-caption text-muted">
                                    Showing {{ activity.from }}-{{ activity.to }} of {{ activity.total }}
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        :disabled="!activity.has_prev"
                                        @click="goToPage('tasks_page', activity.prev_page)"
                                    >
                                        Prev
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        :disabled="!activity.has_next"
                                        @click="goToPage('tasks_page', activity.next_page)"
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        v-if="integration.type === 'proxmox' && proxmoxJournal"
                        class="panel-card p-6"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Node Journal</div>
                                <h2 class="text-title-md text-body mt-2">Cluster Journal Feed</h2>
                            </div>

                            <div class="status-chip">
                                {{ proxmoxJournal.total }} entries
                            </div>
                        </div>

                        <div v-if="proxmoxJournal.total === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No journal entries available.
                        </div>

                        <div v-else class="mt-6">
                            <div class="overflow-x-auto">
                                <table class="table table-sm">
                                    <thead>
                                        <tr class="border-hairline">
                                            <th>Time</th>
                                            <th>Node</th>
                                            <th>Tag</th>
                                            <th>Message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="entry in proxmoxJournal.data"
                                            :key="entry.id"
                                            class="border-hairline transition-default hover:bg-elevated/30"
                                        >
                                            <td class="text-caption text-muted">
                                                {{ entry.time ?? entry.time_human ?? `Line ${entry.line_number}` }}
                                            </td>
                                            <td class="text-body-sm text-body">{{ entry.node }}</td>
                                            <td class="text-caption text-muted font-mono-num">{{ entry.tag }}</td>
                                            <td class="text-body-sm text-body">
                                                <div>{{ entry.message || entry.raw || '—' }}</div>
                                                <div
                                                    v-if="entry.raw && entry.message && entry.raw !== entry.message"
                                                    class="mt-2 text-caption text-muted break-all"
                                                >
                                                    {{ entry.raw }}
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4 flex items-center justify-between gap-4">
                                <div class="text-caption text-muted">
                                    Showing {{ proxmoxJournal.from }}-{{ proxmoxJournal.to }} of {{ proxmoxJournal.total }}
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        :disabled="!proxmoxJournal.has_prev"
                                        @click="goToPage('journal_page', proxmoxJournal.prev_page)"
                                    >
                                        Prev
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        :disabled="!proxmoxJournal.has_next"
                                        @click="goToPage('journal_page', proxmoxJournal.next_page)"
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </section>

                <aside class="space-y-6">
                    <section class="panel-subtle p-5">
                        <div class="eyebrow">Connector State</div>
                        <div class="data-list mt-5">
                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Type</div>
                                    <div class="text-body-sm text-body mt-2">{{ integration.type_name }}</div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Status</div>
                                    <div class="mt-2 flex items-center gap-2 text-body-sm text-body">
                                        <span class="signal-dot" :class="connectionHealth.dotClass" />
                                        {{ connectionHealth.label }}
                                    </div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">API Auth</div>
                                    <div class="text-body-sm text-body mt-2">
                                        {{ integration.api_health?.auth_status ?? 'unknown' }}
                                    </div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Latency</div>
                                    <div class="text-body-sm text-body mt-2">
                                        {{ integration.api_health?.latency_ms !== null ? `${integration.api_health.latency_ms} ms` : '—' }}
                                    </div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Created</div>
                                    <div class="text-body-sm text-body mt-2">{{ integration.created_at ?? '—' }}</div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Updated</div>
                                    <div class="text-body-sm text-body mt-2">{{ integration.updated_at ?? '—' }}</div>
                                </div>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
