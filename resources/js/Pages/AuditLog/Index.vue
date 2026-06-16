<script setup>
import { computed, reactive } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const page = usePage();
const userRole = page.props.auth.user?.role;

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    sites: { type: Array, default: () => [] },
});

const filterForm = reactive({
    action: props.filters.action ?? '',
    user_id: props.filters.user_id ?? '',
    site: props.filters.site ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const successCount = computed(() => props.logs.data.filter((log) => log.result === 'success').length);
const failureCount = computed(() => props.logs.data.filter((log) => ['failure', 'error'].includes(log.result)).length);

function applyFilters() {
    router.get(route('audit-logs.index'), filterForm, {
        preserveState: true,
        replace: true,
    });
}

function clearFilters() {
    filterForm.action = '';
    filterForm.user_id = '';
    filterForm.site = '';
    filterForm.from = '';
    filterForm.to = '';
    applyFilters();
}

const resultBadge = {
    success: 'badge-success',
    failure: 'badge-error',
    error: 'badge-error',
};
</script>

<template>
    <Head title="Audit Log" />

    <AppLayout>
        <PageHeader
            title="Audit Ledger"
            subtitle="Immutable evidence for every operator action, source mutation, and execution result."
            eyebrow="Evidence Ledger"
        >
            <template #meta>
                <span class="status-chip">{{ logs.total }} total records</span>
                <span class="status-chip">
                    <span class="signal-dot signal-dot--live" />
                    {{ successCount }} success
                </span>
                <span class="status-chip" v-if="failureCount > 0">
                    <span class="signal-dot signal-dot--critical" />
                    {{ failureCount }} failed
                </span>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="panel-subtle p-5">
                <div class="eyebrow">Query Controls</div>
                <form class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-5" @submit.prevent="applyFilters">
                    <div>
                        <label class="form-label" for="filter-action">Action</label>
                        <input
                            id="filter-action"
                            v-model="filterForm.action"
                            type="text"
                            placeholder="integration.create"
                            class="input input-sm mt-2 w-full"
                        />
                    </div>

                    <div>
                        <label class="form-label" for="filter-user-id">User ID</label>
                        <input
                            id="filter-user-id"
                            v-model="filterForm.user_id"
                            type="text"
                            class="input input-sm mt-2 w-full"
                        />
                    </div>

                    <div v-if="userRole === 'admin'">
                        <label class="form-label" for="filter-site">Site</label>
                        <select id="filter-site" v-model="filterForm.site" class="select select-sm mt-2 w-full">
                            <option value="">All scopes</option>
                            <option value="global">Global</option>
                            <option v-for="site in sites" :key="site.id" :value="site.id">
                                {{ site.name }} ({{ site.code }})
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" for="filter-from">From</label>
                        <input
                            id="filter-from"
                            v-model="filterForm.from"
                            type="date"
                            class="input input-sm mt-2 w-full"
                        />
                    </div>

                    <div>
                        <label class="form-label" for="filter-to">To</label>
                        <input
                            id="filter-to"
                            v-model="filterForm.to"
                            type="date"
                            class="input input-sm mt-2 w-full"
                        />
                    </div>

                    <div class="flex flex-wrap gap-2 md:col-span-2 xl:col-span-5">
                        <button type="submit" class="btn btn-primary btn-sm">Run Filter</button>
                        <button type="button" class="btn btn-ghost btn-sm" @click="clearFilters">Clear</button>
                    </div>
                </form>
            </section>

            <div>
                <section class="panel-card table-shell">
                    <div class="flex items-center justify-between gap-4 border-b border-hairline px-5 py-4">
                        <div>
                            <div class="eyebrow">Trace Stream</div>
                            <h2 class="text-title-md text-body mt-2">Recorded Actions</h2>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" @click="clearFilters">
                            Reset Query
                        </button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr class="border-hairline">
                                    <th>Time</th>
                                    <th>Action</th>
                                    <th>User</th>
                                    <th>Scope</th>
                                    <th>Target</th>
                                    <th>Result</th>
                                    <th>IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="logs.data.length === 0">
                                    <td colspan="7" class="py-12 text-center text-body-sm text-muted">
                                        No audit logs found for the current query.
                                    </td>
                                </tr>

                                <tr
                                    v-for="log in logs.data"
                                    :key="log.id"
                                    class="border-hairline transition-default hover:bg-elevated/40"
                                >
                                    <td class="whitespace-nowrap text-caption text-muted">
                                        <div>{{ log.created_at }}</div>
                                        <div class="mt-1">{{ log.created_at_human }}</div>
                                    </td>
                                    <td class="text-body-sm text-brand font-mono-num">{{ log.action }}</td>
                                    <td class="text-body-sm text-body">{{ log.user_name }}</td>
                                    <td class="text-body-sm text-muted">
                                        {{ log.site_name }}
                                        <span v-if="log.site_code" class="font-mono-num"> / {{ log.site_code }}</span>
                                    </td>
                                    <td class="text-body-sm text-muted">
                                        <span v-if="log.target_type">{{ log.target_type }}</span>
                                        <span v-if="log.target_id" class="font-mono-num"> / {{ log.target_id }}</span>
                                        <span v-if="!log.target_type">—</span>
                                    </td>
                                    <td>
                                        <span class="badge badge-sm" :class="resultBadge[log.result] ?? 'badge-ghost'">
                                            {{ log.result }}
                                        </span>
                                    </td>
                                    <td class="text-caption text-muted font-mono-num">{{ log.ip_address ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="logs.links.length > 3" class="border-t border-hairline px-5 py-4">
                        <div class="flex flex-wrap gap-1">
                            <template v-for="link in logs.links" :key="link.label">
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
        </div>
    </AppLayout>
</template>
