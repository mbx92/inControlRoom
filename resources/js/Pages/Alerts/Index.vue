<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const page = usePage();
const isAdmin = computed(() => page.props.auth.permissions?.is_admin ?? false);

const props = defineProps({
    alerts: { type: Array, required: true },
    filters: { type: Object, default: () => ({}) },
    sites: { type: Array, default: () => [] },
    integrations: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    severityOptions: { type: Array, default: () => [] },
});

const filterForm = reactive({
    site: props.filters.site ?? '',
    status: props.filters.status ?? '',
    severity: props.filters.severity ?? '',
    integration: props.filters.integration ?? '',
});

const openCount = computed(() => props.alerts.filter((alert) => alert.status === 'open').length);
const acknowledgedCount = computed(() => props.alerts.filter((alert) => alert.status === 'acknowledged').length);
const criticalCount = computed(() => props.alerts.filter((alert) => alert.severity === 'critical').length);

function applyFilters() {
    router.get(route('alerts.index'), filterForm, {
        preserveState: true,
        replace: true,
    });
}

function clearFilters() {
    filterForm.site = '';
    filterForm.status = '';
    filterForm.severity = '';
    filterForm.integration = '';
    applyFilters();
}

function severityBadge(severity) {
    return {
        critical: 'badge-error',
        warning: 'badge-warning',
        info: 'badge-info',
    }[severity] ?? 'badge-ghost';
}
</script>

<template>
    <Head title="Alerts" />

    <AppLayout>
        <PageHeader
            title="Alert Queue"
            subtitle="Satu antrean untuk health degradation, guest down, dan pressure threshold yang sedang aktif atau baru pulih."
            eyebrow="Signal Queue"
        >
            <template #meta>
                <span class="status-chip">
                    <span class="signal-dot" :class="criticalCount > 0 ? 'signal-dot--critical' : 'signal-dot--live'" />
                    {{ alerts.length }} tracked
                </span>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="panel-subtle p-5">
                <div class="eyebrow">Filter</div>
                <form class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4" @submit.prevent="applyFilters">
                    <div v-if="isAdmin">
                        <label class="form-label" for="filter-site">Site</label>
                        <select id="filter-site" v-model="filterForm.site" class="select mt-2 w-full">
                            <option value="">All scopes</option>
                            <option value="global">Global only</option>
                            <option v-for="site in sites" :key="site.id" :value="site.id">
                                {{ site.name }} ({{ site.code }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" for="filter-status">Status</label>
                        <select id="filter-status" v-model="filterForm.status" class="select mt-2 w-full">
                            <option value="">All statuses</option>
                            <option v-for="status in statusOptions" :key="status" :value="status">{{ status }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" for="filter-severity">Severity</label>
                        <select id="filter-severity" v-model="filterForm.severity" class="select mt-2 w-full">
                            <option value="">All severities</option>
                            <option v-for="severity in severityOptions" :key="severity" :value="severity">{{ severity }}</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" for="filter-integration">Integration</label>
                        <select id="filter-integration" v-model="filterForm.integration" class="select mt-2 w-full">
                            <option value="">All integrations</option>
                            <option v-for="integration in integrations" :key="integration.id" :value="integration.id">
                                {{ integration.name }} · {{ integration.scope_label }}
                            </option>
                        </select>
                    </div>

                    <div class="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-4">
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="clearFilters">Reset</button>
                    </div>
                </form>
            </section>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Tracked Alerts" :value="alerts.length" accent="brand" description="Semua alert yang lolos filter aktif." />
                <StatCard label="Open" :value="openCount" :accent="openCount > 0 ? 'critical' : 'success'" description="Butuh perhatian operator sekarang." />
                <StatCard label="Acknowledged" :value="acknowledgedCount" accent="warning" description="Sudah dipegang operator tapi belum pulih." />
                <StatCard label="Critical" :value="criticalCount" accent="critical" description="Impact tertinggi di antrean saat ini." />
            </div>

            <section class="panel-card overflow-hidden">
                <div class="border-b border-hairline px-5 py-4">
                    <div class="eyebrow">Live Queue</div>
                    <h2 class="text-title-md text-body mt-2">Alerts</h2>
                </div>

                <div v-if="alerts.length === 0" class="px-6 py-10 text-center text-body-sm text-muted">
                    No alerts match the current filters.
                </div>

                <ul v-else class="divide-y divide-[var(--color-hairline)]">
                    <li v-for="alert in alerts" :key="alert.id" class="px-5 py-4 transition-default hover:bg-elevated/40">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="badge badge-sm" :class="severityBadge(alert.severity)">{{ alert.severity }}</span>
                                    <span class="status-chip">{{ alert.status }}</span>
                                    <span class="status-chip">{{ alert.site_label }}</span>
                                </div>

                                <div class="text-body-md text-body mt-3">{{ alert.title }}</div>
                                <div v-if="alert.message" class="text-body-sm text-muted mt-2">{{ alert.message }}</div>

                                <div class="mt-3 flex flex-wrap items-center gap-3 text-caption text-muted">
                                    <span>{{ alert.integration_name }}</span>
                                    <span>First seen {{ alert.first_seen_at }}</span>
                                    <span v-if="alert.last_seen_at">Last seen {{ alert.last_seen_at }}</span>
                                    <span v-if="alert.acknowledged_by_name">Ack by {{ alert.acknowledged_by_name }}</span>
                                </div>
                            </div>

                            <Link :href="route('alerts.show', alert.id)" class="btn btn-secondary btn-sm shrink-0">
                                Details
                            </Link>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
