<script setup>
import { reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const page = usePage();
const userRole = page.props.auth.user?.role;
const canExecute = page.props.auth.permissions?.can_execute ?? false;

const props = defineProps({
    assets: { type: Object, required: true },
    sites: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
});

const filterForm = reactive({
    search: props.filters.search ?? '',
    site: props.filters.site ?? '',
    status: props.filters.status ?? '',
});

const checkingAssetId = ref('');
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

const statusBadgeClass = {
    online: 'badge-success',
    offline: 'badge-error',
    unknown: 'badge-warning',
};

function applyFilters() {
    router.get(route('asset-monitoring.index'), filterForm, {
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

function openAsset(assetId) {
    router.visit(route('inventory.show', assetId));
}

function checkNow(assetId) {
    checkingAssetId.value = assetId;

    router.post(route('asset-monitoring.check', assetId), {}, {
        preserveScroll: true,
        onFinish: () => {
            checkingAssetId.value = '';
        },
    });
}
</script>

<template>
    <Head title="Asset Monitoring" />

    <AppLayout>
        <PageHeader
            title="Asset Monitoring"
            subtitle="Status online/offline dibaca dari hasil probe background, jadi halaman tetap ringan meski asset ber-IP banyak."
            eyebrow="Monitoring"
        >
            <template #meta>
                <span class="status-chip">{{ stats.total ?? 0 }} monitored</span>
                <span class="status-chip">{{ stats.offline ?? 0 }} offline</span>
            </template>

            <template #actions>
                <Link :href="route('inventory.index')" class="btn btn-ghost btn-sm">
                    Back to Inventory
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Monitored Assets"
                    :value="stats.total ?? 0"
                    accent="brand"
                    description="Asset dengan primary IP yang masuk pemantauan reachability."
                />
                <StatCard
                    label="Online"
                    :value="stats.online ?? 0"
                    accent="success"
                    description="Merespons probe background terakhir."
                />
                <StatCard
                    label="Offline"
                    :value="stats.offline ?? 0"
                    :accent="(stats.offline ?? 0) > 0 ? 'critical' : 'warning'"
                    description="Tidak merespons probe terakhir."
                />
                <StatCard
                    label="Flapping Risk"
                    :value="stats.flapping ?? 0"
                    accent="warning"
                    description="Sudah gagal 3 kali atau lebih berturut-turut."
                />
            </div>

            <section class="panel-subtle p-5">
                <div class="eyebrow">Filter Monitoring</div>
                <form class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_240px_220px_auto]" @submit.prevent="applyFilters">
                    <div>
                        <label class="form-label" for="monitoring-search">Search</label>
                        <input
                            id="monitoring-search"
                            v-model="filterForm.search"
                            type="text"
                            class="input mt-2 w-full"
                            placeholder="Name, IP, asset tag, category"
                        />
                    </div>

                    <div v-if="userRole === 'admin'">
                        <label class="form-label" for="monitoring-site">Site</label>
                        <select id="monitoring-site" v-model="filterForm.site" class="select mt-2 w-full">
                            <option value="">All sites</option>
                            <option value="unassigned">Unassigned only</option>
                            <option v-for="site in sites" :key="site.id" :value="site.id">
                                {{ site.name }} ({{ site.code }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" for="monitoring-status">Status</label>
                        <select id="monitoring-status" v-model="filterForm.status" class="select mt-2 w-full">
                            <option value="">All statuses</option>
                            <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                    <div class="flex flex-wrap items-end gap-2">
                        <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="clearFilters">Reset</button>
                    </div>
                </form>
            </section>

            <div v-if="assets.data.length === 0" class="panel-card p-12 text-center">
                <p class="text-title-md text-body">No monitored assets found.</p>
                <p class="text-body-sm text-muted mt-3">
                    Tambahkan `primary_ip` pada asset inventory supaya status reachability bisa dipantau di sini.
                </p>
            </div>

            <section v-else class="panel-card table-shell">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-hairline px-5 py-4">
                    <div>
                        <div class="eyebrow">Reachability Cache</div>
                        <h2 class="mt-2 text-title-md text-body">Latest Probe Results</h2>
                    </div>

                    <div class="status-chip">{{ assets.from ?? 0 }}-{{ assets.to ?? 0 }} of {{ assets.total }}</div>
                </div>

                <div class="overflow-x-auto">
                    <table class="table table-sm inventory-table">
                        <thead>
                            <tr class="border-hairline">
                                <th>Asset</th>
                                <th>IP</th>
                                <th>Status</th>
                                <th>Last Checked</th>
                                <th>Last Seen</th>
                                <th>Latency</th>
                                <th>Failures</th>
                                <th v-if="canExecute">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="asset in assets.data"
                                :key="asset.id"
                                class="border-hairline transition-default hover:bg-elevated/40"
                            >
                                <td class="min-w-[260px] align-top">
                                    <div class="min-w-0">
                                        <button
                                            type="button"
                                            class="text-left text-body-sm text-body font-medium hover:text-primary"
                                            @click="openAsset(asset.id)"
                                        >
                                            {{ asset.name }}
                                        </button>
                                        <div class="mt-1 text-body-sm text-muted">
                                            {{ asset.category }}<span v-if="asset.asset_tag"> · {{ asset.asset_tag }}</span>
                                        </div>
                                        <div class="mt-1 text-caption text-muted">
                                            {{ asset.scope_label }}<span v-if="asset.location_label"> · {{ asset.location_label }}</span>
                                        </div>
                                    </div>
                                </td>

                                <td class="align-top">
                                    <div class="font-mono-num text-body-sm text-body">{{ asset.primary_ip }}</div>
                                    <div class="mt-1 text-caption text-muted">{{ asset.reachability_message ?? 'No recent probe message.' }}</div>
                                </td>

                                <td class="align-top">
                                    <span class="badge" :class="statusBadgeClass[asset.reachability_status] ?? 'badge-ghost'">
                                        {{ asset.reachability_status_label }}
                                    </span>
                                </td>

                                <td class="align-top">
                                    <div class="text-body-sm text-body">{{ asset.reachability_checked_at_human ?? 'Never' }}</div>
                                    <div class="mt-1 text-caption text-muted">{{ asset.reachability_checked_at ?? '-' }}</div>
                                </td>

                                <td class="align-top">
                                    <div class="text-body-sm text-body">{{ asset.reachability_last_seen_at_human ?? 'Never' }}</div>
                                    <div class="mt-1 text-caption text-muted">{{ asset.reachability_last_seen_at ?? '-' }}</div>
                                </td>

                                <td class="align-top">
                                    <span class="text-body-sm text-body">{{ asset.reachability_latency_ms ? `${asset.reachability_latency_ms} ms` : '-' }}</span>
                                </td>

                                <td class="align-top">
                                    <span class="text-body-sm text-body">{{ asset.reachability_fail_count }}</span>
                                </td>

                                <td v-if="canExecute" class="align-top">
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-xs"
                                        :disabled="checkingAssetId === asset.id"
                                        @click="checkNow(asset.id)"
                                    >
                                        <span v-if="checkingAssetId === asset.id" class="loading loading-spinner loading-xs shrink-0" aria-hidden="true" />
                                        <span>{{ checkingAssetId === asset.id ? 'Checking...' : 'Check Now' }}</span>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
