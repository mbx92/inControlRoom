<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    integrations: { type: Array, required: true },
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, required: true },
});

const filterForm = reactive({
    site: props.filters.site ?? '',
});

const connectedCount = computed(() => props.integrations.filter((item) => item.last_test_status === 'success').length);

function applyFilters() {
    router.get(route('headscale.index'), filterForm, {
        preserveState: true,
        replace: true,
    });
}

function clearFilters() {
    filterForm.site = '';
    applyFilters();
}

function testConnection(integrationId) {
    router.post(route('integrations.test', integrationId), {}, {
        preserveScroll: true,
    });
}

function healthToneClass(integration) {
    return {
        success: 'badge-success',
        failure: 'badge-error',
        unknown: 'badge-warning',
    }[integration.last_test_status ?? 'unknown'] ?? 'badge-warning';
}
</script>

<template>
    <Head title="Headscale" />

    <AppLayout>
        <PageHeader
            title="Headscale Manager"
            subtitle="Modul khusus untuk mengelola koneksi control plane Headscale, memantau status API, dan membuka ringkasan node serta user."
            eyebrow="Mesh Control"
        >
            <template #meta>
                <span class="status-chip">
                    <span class="signal-dot signal-dot--live" />
                    {{ connectedCount }} connected
                </span>
                <span class="status-chip">{{ stats.server_total }} servers</span>
            </template>

            <template #actions>
                <Link :href="route('integrations.create')" class="btn btn-primary">
                    Add Headscale Server
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="panel-subtle p-5">
                <div class="eyebrow">Scope Filter</div>
                <form class="mt-5 grid gap-4 md:grid-cols-[minmax(0,280px)_auto]" @submit.prevent="applyFilters">
                    <div>
                        <label class="form-label" for="filter-site">Site Scope</label>
                        <select id="filter-site" v-model="filterForm.site" class="select mt-2 w-full">
                            <option value="">All scopes</option>
                            <option value="global">Global only</option>
                            <option v-for="site in sites" :key="site.id" :value="site.id">
                                {{ site.name }} ({{ site.code }})
                            </option>
                        </select>
                    </div>

                    <div class="flex flex-wrap items-end gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="clearFilters">Reset</button>
                    </div>
                </form>
            </section>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Headscale Servers"
                    :value="stats.server_total"
                    accent="brand"
                    description="Server control plane yang sudah terdaftar di modul ini."
                />
                <StatCard
                    label="Healthy Links"
                    :value="stats.healthy_total"
                    :accent="stats.healthy_total > 0 ? 'success' : 'warning'"
                    description="Server yang terakhir lolos check API."
                />
                <StatCard
                    label="Tracked Nodes"
                    :value="stats.node_total"
                    accent="warning"
                    description="Akumulasi node yang terakhir terbaca dari Headscale API."
                />
                <StatCard
                    label="Known Users"
                    :value="stats.user_total"
                    accent="success"
                    description="Akumulasi user yang terakhir terbaca dari Headscale API."
                />
            </div>

            <div v-if="integrations.length === 0" class="panel-card p-12 text-center">
                <p class="text-title-md text-body">No Headscale server configured.</p>
                <p class="text-body-sm text-muted mt-3">
                    Tambahkan integrasi Headscale lebih dulu agar module ini bisa dipakai untuk monitoring dan operasional.
                </p>
                <Link :href="route('integrations.create')" class="btn btn-primary mt-6">
                    Create Headscale Integration
                </Link>
            </div>

            <div v-else class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <article
                    v-for="integration in integrations"
                    :key="integration.id"
                    class="panel-card p-5 transition-default hover:border-primary/30"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="eyebrow">Headscale Control Plane</p>
                                <span class="status-chip">
                                    {{ integration.scope_kind === 'global' ? 'Global' : integration.scope_label }}
                                </span>
                            </div>
                            <h2 class="text-title-sm text-body mt-3">{{ integration.name }}</h2>
                            <p class="text-caption text-muted mt-2 break-all font-mono-num">{{ integration.base_url }}</p>
                        </div>

                        <span class="badge badge-sm" :class="healthToneClass(integration)">
                            {{ integration.api_health.label }}
                        </span>
                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-3">
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Nodes</div>
                            <div class="text-number-sm text-body mt-2">
                                {{ integration.source_summary.node_count ?? '—' }}
                            </div>
                        </div>

                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Users</div>
                            <div class="text-number-sm text-body mt-2">
                                {{ integration.source_summary.user_count ?? '—' }}
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2 text-caption text-muted">
                        <span>Auth {{ integration.api_health.auth_status }}</span>
                        <span v-if="integration.api_health.latency_ms !== null">{{ integration.api_health.latency_ms }} ms</span>
                        <span>{{ integration.secret_source_label }}</span>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <Link :href="route('headscale.show', integration.id)" class="btn btn-primary btn-sm">
                            Open Module
                        </Link>
                        <button type="button" class="btn btn-secondary btn-sm" @click="testConnection(integration.id)">
                            Check API
                        </button>
                        <Link :href="route('integrations.edit', integration.id)" class="btn btn-ghost btn-sm">
                            Edit Source
                        </Link>
                    </div>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
