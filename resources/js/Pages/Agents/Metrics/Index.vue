<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const page = usePage();
const userRole = page.props.auth.user?.role;

const props = defineProps({
    agents: { type: Array, default: () => [] },
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
});

const filterForm = reactive({
    search: props.filters.search ?? '',
    site: props.filters.site ?? '',
    status: props.filters.status ?? '',
});

let debounceTimer = null;

watch(
    () => filterForm.search,
    () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            applyFilters();
        }, 300);
    },
);

function applyFilters() {
    router.get(route('agents.metrics.index'), filterForm, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clearFilters() {
    filterForm.search = '';
    filterForm.site = '';
    filterForm.status = '';
    applyFilters();
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Date(value).toLocaleString();
}

function formatPercent(value) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '-';
    }

    return `${Number(value).toFixed(1)}%`;
}

function formatBytes(bytes) {
    const value = Number(bytes ?? 0);

    if (!value) {
        return '-';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
    const scaled = value / 1024 ** exponent;

    return `${scaled.toFixed(scaled >= 10 || exponent === 0 ? 0 : 1)} ${units[exponent]}`;
}

function formatMemoryCell(agent) {
    if (!agent.has_metrics) {
        return '-';
    }

    const total = formatBytes(agent.memory_total_bytes);
    const percent = formatPercent(agent.memory_used_percent);

    return total === '-' ? percent : `${total} · ${percent}`;
}

function formatStorageCell(agent) {
    if (!agent.has_metrics) {
        return '-';
    }

    const total = formatBytes(agent.storage_total_bytes || agent.physical_storage_total_bytes);
    const percent = agent.storage_total_bytes
        ? formatPercent(agent.storage_used_percent)
        : formatPercent(agent.worst_disk_used_percent);

    return total === '-' ? percent : `${total} · ${percent}`;
}

function usageTone(value) {
    if (value === null || value === undefined) {
        return 'text-muted';
    }

    if (value >= 90) {
        return 'text-error';
    }

    if (value >= 75) {
        return 'text-warning';
    }

    return 'text-success';
}

function statusClass(status) {
    return status === 'Online' ? 'status-chip' : 'status-chip opacity-80';
}
</script>

<template>
    <Head title="Agent Metrics" />

    <AppLayout>
        <PageHeader
            title="Agent Metrics"
            subtitle="Live CPU, memory, disk, and service inventory from enrolled Windows agents."
            eyebrow="InfraControl Agent"
        >
            <template #meta>
                <span class="status-chip">{{ stats.online ?? 0 }} online</span>
                <span class="status-chip">{{ stats.total ?? 0 }} devices</span>
            </template>

            <template #actions>
                <Link
                    v-if="page.props.auth.permissions?.is_admin"
                    :href="route('settings.agents.index')"
                    class="btn btn-ghost btn-sm"
                >
                    Agent Enrollment
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Registered Devices"
                    :value="stats.total ?? 0"
                    accent="brand"
                    description="Agents that have completed enrollment."
                />
                <StatCard
                    label="Online"
                    :value="stats.online ?? 0"
                    accent="success"
                    description="Heartbeat received within the last 5 minutes."
                />
                <StatCard
                    label="Reporting Metrics"
                    :value="stats.with_metrics ?? 0"
                    accent="default"
                    description="Devices with a stored metrics payload."
                />
                <StatCard
                    label="High CPU"
                    :value="stats.high_cpu ?? 0"
                    :accent="(stats.high_cpu ?? 0) > 0 ? 'warning' : 'default'"
                    description="CPU usage at or above 80%."
                />
            </div>

            <section class="panel-subtle p-5">
                <div class="eyebrow">Filter Devices</div>
                <form class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_240px_220px_auto]" @submit.prevent="applyFilters">
                    <div>
                        <label class="form-label" for="agent-metrics-search">Search</label>
                        <input
                            id="agent-metrics-search"
                            v-model="filterForm.search"
                            type="text"
                            class="input mt-2 w-full"
                            placeholder="Hostname, IP, device ID"
                        />
                    </div>

                    <div v-if="userRole === 'admin'">
                        <label class="form-label" for="agent-metrics-site">Site</label>
                        <select id="agent-metrics-site" v-model="filterForm.site" class="select mt-2 w-full">
                            <option value="">All sites</option>
                            <option v-for="site in sites" :key="site.id" :value="site.id">
                                {{ site.name }} ({{ site.code }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" for="agent-metrics-status">Status</label>
                        <select id="agent-metrics-status" v-model="filterForm.status" class="select mt-2 w-full">
                            <option value="">All statuses</option>
                            <option value="online">Online</option>
                            <option value="idle">Idle</option>
                            <option value="never_seen">Never seen</option>
                        </select>
                    </div>

                    <div class="flex items-end gap-2">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <button type="button" class="btn btn-ghost" @click="clearFilters">Reset</button>
                    </div>
                </form>
            </section>

            <section class="panel-card p-5">
                <div v-if="agents.length === 0" class="text-body-sm text-muted">
                    No enrolled agents match the current filters.
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Hostname</th>
                                <th>Site</th>
                                <th>Status</th>
                                <th>CPU</th>
                                <th>Memory</th>
                                <th>Storage</th>
                                <th>Services</th>
                                <th>Last Seen</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="agent in agents" :key="agent.id">
                                <td>
                                    <div class="font-medium text-body">{{ agent.hostname }}</div>
                                    <div class="text-caption text-muted font-mono-num">{{ agent.primary_ip || agent.device_id }}</div>
                                </td>
                                <td>{{ agent.site_name }}</td>
                                <td><span :class="statusClass(agent.status)">{{ agent.status }}</span></td>
                                <td :class="usageTone(agent.cpu_usage_percent)">
                                    {{ agent.has_metrics ? formatPercent(agent.cpu_usage_percent) : '-' }}
                                </td>
                                <td :class="usageTone(agent.memory_used_percent)">
                                    {{ formatMemoryCell(agent) }}
                                </td>
                                <td :class="usageTone(agent.storage_used_percent ?? agent.worst_disk_used_percent)">
                                    {{ formatStorageCell(agent) }}
                                </td>
                                <td>{{ agent.services_count }}</td>
                                <td>{{ formatDate(agent.last_seen_at) }}</td>
                                <td class="text-right">
                                    <Link :href="route('agents.metrics.show', agent.id)" class="btn btn-ghost btn-sm">
                                        Details
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
