<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const page = usePage();
const userRole = page.props.auth.user?.role;
const isAdmin = page.props.auth.permissions?.is_admin ?? false;

const props = defineProps({
    entries: { type: Array, required: true },
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const filterForm = reactive({
    site: props.filters.site ?? '',
    status: props.filters.status ?? '',
});

const activeCount = computed(() => props.entries.filter((entry) => entry.is_active).length);
const globalCount = computed(() => props.entries.filter((entry) => entry.scope_kind === 'global').length);
const rotationDueCount = computed(() => props.entries.filter((entry) => {
    if (!entry.rotation_interval_days || !entry.last_rotated_at) {
        return false;
    }

    const lastRotated = new Date(entry.last_rotated_at);
    if (Number.isNaN(lastRotated.getTime())) {
        return false;
    }

    const dueAt = new Date(lastRotated);
    dueAt.setDate(dueAt.getDate() + entry.rotation_interval_days);

    return dueAt < new Date();
}).length);

function applyFilters() {
    router.get(route('vault.index'), filterForm, {
        preserveState: true,
        replace: true,
    });
}

function clearFilters() {
    filterForm.site = '';
    filterForm.status = '';
    applyFilters();
}
</script>

<template>
    <Head title="Vault" />

    <AppLayout>
        <PageHeader
            title="Internal Vault"
            subtitle="Encrypted operational secrets with scope-aware ownership and explicit reveal audit."
            eyebrow="Vault Grid"
        >
            <template #meta>
                <span class="status-chip">{{ activeCount }} active</span>
                <span class="status-chip">{{ entries.length }} total entries</span>
            </template>

            <template #actions>
                <Link v-if="isAdmin" :href="route('vault.create')" class="btn btn-primary">
                    Add Secret
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section v-if="userRole === 'admin'" class="panel-subtle p-5">
                <div class="eyebrow">Scope Filter</div>
                <form class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,240px)_minmax(0,180px)_auto]" @submit.prevent="applyFilters">
                    <div>
                        <label class="form-label" for="vault-filter-site">Site Scope</label>
                        <select id="vault-filter-site" v-model="filterForm.site" class="select mt-2 w-full">
                            <option value="">All scopes</option>
                            <option value="global">Global only</option>
                            <option v-for="site in sites" :key="site.id" :value="site.id">
                                {{ site.name }} ({{ site.code }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" for="vault-filter-status">Status</label>
                        <select id="vault-filter-status" v-model="filterForm.status" class="select mt-2 w-full">
                            <option value="">All entries</option>
                            <option value="active">Active only</option>
                            <option value="inactive">Inactive only</option>
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
                    label="Vault Entries"
                    :value="entries.length"
                    accent="brand"
                    description="Secrets currently modeled inside the internal vault."
                />
                <StatCard
                    label="Active Secrets"
                    :value="activeCount"
                    :accent="activeCount > 0 ? 'success' : 'warning'"
                    description="Entries still allowed to back integrations or manual reveal."
                />
                <StatCard
                    label="Global Scope"
                    :value="globalCount"
                    accent="warning"
                    description="Secrets shared across sites and reusable by global services."
                />
                <StatCard
                    label="Rotation Due"
                    :value="rotationDueCount"
                    :accent="rotationDueCount > 0 ? 'critical' : 'success'"
                    description="Entries already past their declared rotation interval."
                />
            </div>

            <div v-if="entries.length === 0" class="panel-card p-12 text-center">
                <p class="text-title-md text-body">No vault entries yet.</p>
                <p class="text-body-sm text-muted mt-3">
                    Add the first secret before wiring a Proxmox integration to this control room.
                </p>
                <Link v-if="isAdmin" :href="route('vault.create')" class="btn btn-primary mt-6">
                    Create first secret
                </Link>
            </div>

            <div v-else class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                <article
                    v-for="entry in entries"
                    :key="entry.id"
                    class="panel-card p-5 transition-default hover:border-primary/30"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="eyebrow">{{ entry.kind_label }}</p>
                                <span class="status-chip">{{ entry.scope_kind === 'global' ? 'Global' : entry.scope_label }}</span>
                            </div>
                            <h2 class="text-title-sm text-body mt-3">{{ entry.name }}</h2>
                            <p class="text-body-sm text-muted mt-2">{{ entry.masked_preview }}</p>
                        </div>

                        <span class="status-chip shrink-0">
                            <span
                                class="signal-dot"
                                :class="entry.is_active ? 'signal-dot--live' : 'signal-dot--warning'"
                            />
                            {{ entry.is_active ? 'Active' : 'Archived' }}
                        </span>
                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-3 border-t border-hairline pt-4">
                        <div>
                            <div class="text-caption text-muted">Used By</div>
                            <div class="text-body-sm text-body mt-1">{{ entry.integrations_count }} integrations</div>
                        </div>
                        <div>
                            <div class="text-caption text-muted">Last Rotated</div>
                            <div class="text-body-sm text-body mt-1">{{ entry.last_rotated_human ?? 'Not tracked' }}</div>
                        </div>
                    </div>

                    <div v-if="entry.notes" class="mt-4 text-body-sm text-muted line-clamp-2">
                        {{ entry.notes }}
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        <Link :href="route('vault.show', entry.id)" class="btn btn-primary btn-sm">
                            Details
                        </Link>
                        <Link v-if="isAdmin" :href="route('vault.edit', entry.id)" class="btn btn-ghost btn-sm">
                            Edit
                        </Link>
                    </div>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
