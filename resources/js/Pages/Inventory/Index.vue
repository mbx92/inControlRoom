<script setup>
import { computed, reactive, watch } from 'vue';
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
    () => {
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
                                    <div class="flex items-start gap-3">
                                        <div class="inventory-category-icon">
                                            <svg v-if="asset.category_icon === 'server'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <rect x="3" y="4" width="18" height="6" rx="2" stroke-width="1.5" />
                                                <rect x="3" y="14" width="18" height="6" rx="2" stroke-width="1.5" />
                                                <path d="M7 7h.01M7 17h.01M11 7h6M11 17h6" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'hypervisor'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <rect x="4" y="5" width="16" height="10" rx="2" stroke-width="1.5" />
                                                <path d="M8 19h8M10 15v4M14 15v4" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'storage'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <ellipse cx="12" cy="6" rx="7" ry="3" stroke-width="1.5" />
                                                <path d="M5 6v8c0 1.66 3.13 3 7 3s7-1.34 7-3V6M5 10c0 1.66 3.13 3 7 3s7-1.34 7-3" stroke-width="1.5" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'firewall'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 3l8 4v5c0 4.5-3 7.5-8 9-5-1.5-8-4.5-8-9V7l8-4Z" stroke-width="1.5" />
                                                <path d="M9 12h6M12 9v6" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'switch'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <rect x="3" y="7" width="18" height="10" rx="2" stroke-width="1.5" />
                                                <path d="M7 12h.01M10 12h.01M13 12h.01M16 12h.01" stroke-width="2" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'router'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path d="M5 15h14l-2 4H7l-2-4Z" stroke-width="1.5" />
                                                <path d="M8 11a4 4 0 0 1 8 0M5 8a7 7 0 0 1 14 0" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'ap'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <circle cx="12" cy="16" r="1.5" stroke-width="1.5" />
                                                <path d="M8.5 12.5a5 5 0 0 1 7 0M6 10a8.5 8.5 0 0 1 12 0M3.5 7.5a12 12 0 0 1 17 0" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'printer'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path d="M7 8V4h10v4M6 17H5a2 2 0 0 1-2-2v-4a3 3 0 0 1 3-3h12a3 3 0 0 1 3 3v4a2 2 0 0 1-2 2h-1" stroke-width="1.5" />
                                                <path d="M7 14h10v6H7z" stroke-width="1.5" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'ups'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <rect x="7" y="3" width="10" height="18" rx="2" stroke-width="1.5" />
                                                <path d="M10 8h4M10 12h4M11 16h2" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'pc'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <rect x="4" y="5" width="16" height="10" rx="2" stroke-width="1.5" />
                                                <path d="M9 19h6M12 15v4" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'laptop'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <rect x="6" y="5" width="12" height="8" rx="1.5" stroke-width="1.5" />
                                                <path d="M3 17h18l-1 2H4l-1-2Z" stroke-width="1.5" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'monitor'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <rect x="3" y="4" width="18" height="12" rx="2" stroke-width="1.5" />
                                                <path d="M9 20h6M12 16v4" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'medical'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 4v16M4 12h16" stroke-width="1.5" stroke-linecap="round" />
                                                <rect x="5" y="5" width="14" height="14" rx="2" stroke-width="1.5" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'license'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path d="M7 4h8l4 4v12H7z" stroke-width="1.5" />
                                                <path d="M15 4v4h4M9 13h6M9 17h4" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                            <svg v-else-if="asset.category_icon === 'spare'" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path d="M14.5 6.5a3.5 3.5 0 1 0-5 5L16 18l2-2-6.5-6.5a3.5 3.5 0 0 0 3-3Z" stroke-width="1.5" />
                                            </svg>
                                            <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <rect x="4" y="5" width="16" height="14" rx="2" stroke-width="1.5" />
                                                <path d="M8 9h8M8 13h5" stroke-width="1.5" stroke-linecap="round" />
                                            </svg>
                                        </div>

                                        <div class="min-w-0">
                                            <Link
                                                :href="route('inventory.show', asset.id)"
                                                class="text-body-sm text-body font-medium hover:text-primary"
                                                @click.stop
                                            >
                                                {{ asset.name }}
                                            </Link>
                                            <div class="mt-1 max-w-[280px] text-caption text-muted">
                                                {{ [asset.category, asset.manufacturer, asset.model].filter(Boolean).join(' / ') || 'Type, brand, and model not documented yet' }}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="align-top text-body-sm text-muted whitespace-nowrap">
                                    {{ asset.scope_label }}
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

<style scoped>
.inventory-category-icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    flex-shrink: 0;
    border: 1px solid var(--color-hairline, #2B3139);
    border-radius: 0.75rem;
    background: color-mix(in oklab, var(--color-base-300, #2B3139) 88%, white 12%);
    color: var(--color-muted, #9CA3AF);
}
</style>
