<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const page = usePage();
const userRole = page.props.auth.user?.role;
const isAdmin = page.props.auth.permissions?.is_admin ?? false;

const props = defineProps({
    assets: { type: Object, required: true },
    sites: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const filterForm = reactive({
    search: props.filters.search ?? '',
    site: props.filters.site ?? '',
    status: props.filters.status ?? '',
});

let debounceTimer = null;

watch(
    () => filterForm.search,
    (newVal) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            router.get(route('inventory.index'), filterForm, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, 300);
    },
);

const activeCount = computed(() => props.assets.data.filter((asset) => asset.status === 'active').length);
const assignedCount = computed(() => props.assets.data.filter((asset) => asset.site_id).length);
const withIpCount = computed(() => props.assets.data.filter((asset) => asset.primary_ip).length);

const statusBadgeClass = {
    active: 'badge-success',
    standby: 'badge-info',
    planned: 'badge-ghost',
    repair: 'badge-warning',
    retired: 'badge-neutral',
};

function applyFilters() {
    router.get(route('inventory.index'), filterForm, {
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
</script>

<template>
    <Head title="Inventory" />

    <AppLayout>
        <PageHeader
            title="Inventory Registry"
            subtitle="CMDB ringan yang hanya menyimpan data inventaris yang memang tim operasional butuhkan."
            eyebrow="Asset Register"
        >
            <template #meta>
                <span class="status-chip">{{ assets.total }} assets</span>
                <span class="status-chip">{{ activeCount }} active</span>
            </template>

            <template #actions>
                <Link v-if="isAdmin" :href="route('inventory.create')" class="btn btn-primary">
                    Add Asset
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Tracked Assets"
                    :value="assets.total"
                    accent="brand"
                    description="Semua aset yang sudah masuk registry internal."
                />
                <StatCard
                    label="Active Assets"
                    :value="activeCount"
                    :accent="activeCount > 0 ? 'success' : 'warning'"
                    description="Aset yang sedang dipakai operasional aktif."
                />
                <StatCard
                    label="Assigned to Site"
                    :value="assignedCount"
                    accent="warning"
                    description="Aset yang sudah punya konteks lokasi operasional."
                />
                <StatCard
                    label="With IP Address"
                    :value="withIpCount"
                    accent="brand"
                    description="Aset yang sudah punya endpoint jaringan utama."
                />
            </div>

            <section class="panel-subtle p-5">
                <div class="eyebrow">Filter Inventory</div>
                <form class="mt-5 grid gap-4 xl:grid-cols-[minmax(0,1.3fr)_240px_220px_auto]" @submit.prevent="applyFilters">
                    <div>
                        <label class="form-label" for="inventory-search">Search</label>
                        <input
                            id="inventory-search"
                            v-model="filterForm.search"
                            type="text"
                            class="input mt-2 w-full"
                            placeholder="Name, asset tag, serial, model, IP"
                        />
                    </div>

                    <div v-if="userRole === 'admin'">
                        <label class="form-label" for="inventory-site">Site</label>
                        <select id="inventory-site" v-model="filterForm.site" class="select mt-2 w-full">
                            <option value="">All sites</option>
                            <option value="unassigned">Unassigned only</option>
                            <option v-for="site in sites" :key="site.id" :value="site.id">
                                {{ site.name }} ({{ site.code }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" for="inventory-status">Status</label>
                        <select id="inventory-status" v-model="filterForm.status" class="select mt-2 w-full">
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
                <p class="text-title-md text-body">No inventory assets yet.</p>
                <p class="text-body-sm text-muted mt-3">
                    Tambahkan aset pertama supaya daftar inventaris tidak lagi tercecer di catatan manual.
                </p>
                <Link v-if="isAdmin" :href="route('inventory.create')" class="btn btn-primary mt-6">
                    Create first asset
                </Link>
            </div>

            <section v-else class="panel-card table-shell">
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-hairline px-5 py-4">
                    <div>
                        <div class="eyebrow">Asset List</div>
                        <h2 class="text-title-md text-body mt-2">Registered Inventory</h2>
                    </div>

                    <div class="status-chip">{{ assets.from ?? 0 }}-{{ assets.to ?? 0 }} of {{ assets.total }}</div>
                </div>

                <div class="overflow-x-auto">
                    <table class="table table-sm inventory-table">
                        <thead>
                            <tr class="border-hairline">
                                <th>Asset</th>
                                <th>Scope</th>
                                <th>Category</th>
                                <th>Network</th>
                                <th>Location</th>
                                <th>Owner</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="asset in assets.data"
                                :key="asset.id"
                                class="cursor-pointer border-hairline transition-default hover:bg-elevated/40"
                                tabindex="0"
                                @click="openAsset(asset.id)"
                                @keydown.enter.prevent="openAsset(asset.id)"
                                @keydown.space.prevent="openAsset(asset.id)"
                            >
                                <td class="align-top min-w-[260px]">
                                    <Link
                                        :href="route('inventory.show', asset.id)"
                                        class="text-body-sm text-body font-medium hover:text-primary"
                                        @click.stop
                                    >
                                        {{ asset.name }}
                                    </Link>
                                    <div class="mt-1 max-w-[280px] text-caption text-muted">
                                        {{ [asset.manufacturer, asset.model].filter(Boolean).join(' · ') || 'Model not documented yet' }}
                                    </div>
                                    <div class="mt-2 space-y-1 text-caption text-muted">
                                        <div>{{ asset.asset_tag || 'No asset tag' }}</div>
                                        <div v-if="asset.serial_number" class="font-mono-num">SN {{ asset.serial_number }}</div>
                                        <div v-if="asset.custom_fields_count > 0">{{ asset.custom_fields_count }} extra fields</div>
                                    </div>
                                    <div v-if="asset.notes" class="mt-2 max-w-[320px] text-caption text-muted line-clamp-1">
                                        {{ asset.notes }}
                                    </div>
                                </td>
                                <td class="align-top text-body-sm text-muted whitespace-nowrap">
                                    {{ asset.scope_label }}
                                </td>
                                <td class="align-top text-body-sm text-muted whitespace-nowrap">
                                    {{ asset.category }}
                                </td>
                                <td class="align-top whitespace-nowrap">
                                    <div class="font-mono-num text-body-sm text-body">{{ asset.primary_ip || '—' }}</div>
                                    <div class="mt-1 text-caption text-muted">{{ asset.primary_ip ? 'Primary IP' : 'No IP recorded' }}</div>
                                </td>
                                <td class="align-top text-body-sm text-muted">
                                    <div class="max-w-[180px]">
                                        {{ asset.location_label || '—' }}
                                    </div>
                                </td>
                                <td class="align-top text-body-sm text-muted whitespace-nowrap">
                                    {{ asset.owner_name || 'Unassigned' }}
                                </td>
                                <td class="align-top">
                                    <span class="badge badge-sm whitespace-nowrap" :class="statusBadgeClass[asset.status] ?? 'badge-ghost'">
                                        {{ asset.status_label }}
                                    </span>
                                </td>
                                <td class="align-top text-caption text-muted whitespace-nowrap">
                                    {{ asset.updated_at || '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="assets.links.length > 3" class="border-t border-hairline px-5 py-4">
                    <div class="flex flex-wrap gap-1">
                        <template v-for="link in assets.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="btn btn-sm"
                                :class="link.active ? 'btn-primary' : 'btn-ghost'"
                                v-html="link.label"
                                preserve-state
                            />
                            <span v-else class="btn btn-sm btn-disabled btn-ghost" v-html="link.label" />
                        </template>
                    </div>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
