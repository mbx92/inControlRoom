<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const page = usePage();
const userRole = page.props.auth.user?.role;
const permissions = page.props.auth.permissions ?? {};

const props = defineProps({
    integrations: { type: Array, required: true },
    availableTypes: { type: Object, required: true },
    sites: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
});

const filterForm = reactive({
    site: props.filters.site ?? '',
});

const viewMode = ref('card');

const activeCount = computed(() => props.integrations.filter((integration) => integration.is_active).length);
const configuredCount = computed(() => props.integrations.length);
const healthyCount = computed(() => props.integrations.filter((integration) => integration.last_test_status === 'success').length);
const supportedTypes = computed(() => Object.keys(props.availableTypes).length);

function applyFilters() {
    router.get(route('integrations.index'), filterForm, {
        preserveState: true,
        replace: true,
    });
}

function clearFilters() {
    filterForm.site = '';
    applyFilters();
}

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

function apiHealthToneClass(integration) {
    return {
        success: 'badge-success',
        critical: 'badge-error',
        warning: 'badge-warning',
    }[integration.api_health?.tone ?? 'warning'] ?? 'badge-warning';
}

function testConnection(integrationId) {
    router.post(route('integrations.test', integrationId), {}, {
        preserveScroll: true,
    });
}

function deleteIntegration(integrationId, name) {
    if (confirm(`Delete integration "${name}"? This cannot be undone.`)) {
        router.delete(route('integrations.destroy', integrationId));
    }
}
</script>

<template>
    <Head title="Integrations" />

    <AppLayout>
        <PageHeader
            title="API Integrations"
            subtitle="Manage Proxmox clusters and custom API-based systems that feed health, telemetry, and infrastructure posture into the room."
            eyebrow="Source Mesh"
        >
            <template #meta>
                <span class="status-chip">
                    <span class="signal-dot signal-dot--live" />
                    {{ activeCount }} active
                </span>
                <span class="status-chip">{{ supportedTypes }} supported types</span>
            </template>

            <template #actions>
                <Link v-if="permissions.is_admin" :href="route('integrations.create')" class="btn btn-primary">
                    Add Integration
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section v-if="userRole === 'admin'" class="panel-subtle p-5">
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
                    label="Configured Sources"
                    :value="configuredCount"
                    accent="brand"
                    description="All infrastructure and API systems currently modeled inside InfraControl."
                />
                <StatCard
                    label="Active Links"
                    :value="activeCount"
                    :accent="activeCount > 0 ? 'success' : 'warning'"
                    description="Connections expected to participate in the live room."
                />
                <StatCard
                    label="Supported Types"
                    :value="supportedTypes"
                    accent="warning"
                    description="Proxmox plus custom API health endpoints."
                />
                <StatCard
                    label="Healthy Sources"
                    :value="healthyCount"
                    :accent="healthyCount > 0 ? 'success' : 'warning'"
                    description="Sources with a successful connection check on record."
                />
            </div>

            <div v-if="integrations.length === 0" class="panel-card p-12 text-center">
                <p class="text-title-md text-body">No integrations configured.</p>
                <p class="text-body-sm text-muted mt-3">
                    {{ permissions.is_admin
                        ? 'Start by connecting the first system, whether it is Proxmox or a custom API service such as a Node app.'
                        : 'No integrations are available yet for your current environment.'
                    }}
                </p>
                <Link v-if="permissions.is_admin" :href="route('integrations.create')" class="btn btn-primary mt-6">
                    Connect your first integration
                </Link>
            </div>

            <template v-else>
                <div class="flex items-center justify-between">
                    <span class="text-body-sm text-muted">{{ integrations.length }} integration{{ integrations.length !== 1 ? 's' : '' }}</span>
                    <div class="tabs">
                        <button
                            class="tab tab-bordered" :class="{ 'tab-active': viewMode === 'card' }"
                            @click="viewMode = 'card'"
                        >
                            Card
                        </button>
                        <button
                            class="tab tab-bordered" :class="{ 'tab-active': viewMode === 'table' }"
                            @click="viewMode = 'table'"
                        >
                            Table
                        </button>
                    </div>
                </div>

                <!-- Card View -->
                <div v-if="viewMode === 'card'" class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                    <article
                        v-for="integration in integrations"
                        :key="integration.id"
                        class="panel-card p-5 transition-default hover:border-primary/30"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="eyebrow">{{ integration.type_name }}</p>
                                    <span class="status-chip">
                                        {{ integration.scope_kind === 'global' ? 'Global' : integration.scope_label }}
                                    </span>
                                </div>
                                <h2 class="text-title-sm text-body mt-3">{{ integration.name }}</h2>
                                <p class="text-caption text-muted mt-2 break-all font-mono-num">{{ integration.base_url }}</p>
                            </div>

                            <span class="status-chip shrink-0">
                                <span
                                    class="signal-dot"
                                    :class="integration.is_active ? 'signal-dot--live' : 'signal-dot--warning'"
                                />
                                {{ integration.is_active ? 'Active' : 'Standby' }}
                            </span>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-hairline pt-4">
                            <span class="status-chip">
                                <span class="signal-dot" :class="healthDotClass(integration)" />
                                {{ healthLabel(integration) }}
                            </span>
                            <span class="status-chip">
                                {{ integration.scope_kind === 'global' ? 'Global' : integration.scope_label }}
                            </span>
                            <span class="status-chip">
                                Checked {{ integration.last_tested_at ?? 'never' }}
                            </span>
                            <span class="badge badge-sm" :class="apiHealthToneClass(integration)">
                                {{ integration.api_health?.label ?? 'Not tested' }}
                            </span>
                        </div>

                        <div v-if="integration.last_test_message" class="mt-4 text-body-sm text-muted">
                            {{ integration.last_test_message }}
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-3 text-caption text-muted">
                            <span>Auth {{ integration.api_health?.auth_status ?? 'unknown' }}</span>
                            <span v-if="integration.api_health?.latency_ms !== null">
                                {{ integration.api_health.latency_ms }} ms
                            </span>
                            <span v-if="integration.api_health?.version">
                                v{{ integration.api_health.version }}
                            </span>
                        </div>

                        <div class="mt-5 flex flex-wrap gap-2">
                            <Link
                                :href="route('integrations.show', integration.id)"
                                class="btn btn-primary btn-sm"
                            >
                                Details
                            </Link>
                            <button
                                v-if="permissions.can_execute"
                                type="button"
                                class="btn btn-secondary btn-sm"
                                @click="testConnection(integration.id)"
                            >
                                Check API Health
                            </button>
                            <Link
                                v-if="permissions.is_admin"
                                :href="route('integrations.edit', integration.id)"
                                class="btn btn-ghost btn-sm"
                            >
                                Edit
                            </Link>
                            <button
                                v-if="permissions.is_admin"
                                type="button"
                                class="btn btn-ghost btn-sm text-error"
                                @click="deleteIntegration(integration.id, integration.name)"
                            >
                                Delete
                            </button>
                        </div>
                    </article>
                </div>

                <!-- Table View -->
                <div v-else class="panel-card overflow-x-auto">
                    <table class="table w-full">
                        <thead>
                            <tr class="border-b border-hairline">
                                <th class="text-caption text-muted font-normal px-4 py-3 text-left">Name</th>
                                <th class="text-caption text-muted font-normal px-4 py-3 text-left">Type</th>
                                <th class="text-caption text-muted font-normal px-4 py-3 text-left">Scope</th>
                                <th class="text-caption text-muted font-normal px-4 py-3 text-left">Status</th>
                                <th class="text-caption text-muted font-normal px-4 py-3 text-left">Health</th>
                                <th class="text-caption text-muted font-normal px-4 py-3 text-left hidden md:table-cell">Base URL</th>
                                <th class="text-caption text-muted font-normal px-4 py-3 text-left hidden lg:table-cell">Last Checked</th>
                                <th class="text-caption text-muted font-normal px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="integration in integrations"
                                :key="integration.id"
                                class="border-b border-hairline hover:bg-base-300/50 transition-default"
                            >
                                <td class="px-4 py-3">
                                    <Link
                                        :href="route('integrations.show', integration.id)"
                                        class="text-body-sm font-medium text-body hover:text-primary transition-default"
                                    >
                                        {{ integration.name }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-body-sm text-body">{{ integration.type_name }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="status-chip text-caption">
                                        {{ integration.scope_kind === 'global' ? 'Global' : integration.scope_label }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="status-chip text-caption">
                                        <span
                                            class="signal-dot"
                                            :class="integration.is_active ? 'signal-dot--live' : 'signal-dot--warning'"
                                        />
                                        {{ integration.is_active ? 'Active' : 'Standby' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="status-chip text-caption">
                                            <span class="signal-dot" :class="healthDotClass(integration)" />
                                            {{ healthLabel(integration) }}
                                        </span>
                                        <span
                                            v-if="integration.api_health?.latency_ms !== null"
                                            class="text-caption text-muted"
                                        >{{ integration.api_health.latency_ms }} ms</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 max-w-[200px] hidden md:table-cell">
                                    <span class="text-caption text-muted font-mono-num truncate block" :title="integration.base_url">
                                        {{ integration.base_url }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 hidden lg:table-cell">
                                    <span class="text-caption text-muted">{{ integration.last_tested_at ?? '—' }}</span>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <Link
                                            :href="route('integrations.show', integration.id)"
                                            class="btn btn-ghost btn-xs"
                                        >
                                            View
                                        </Link>
                                        <button
                                            v-if="permissions.can_execute"
                                            type="button"
                                            class="btn btn-ghost btn-xs"
                                            @click="testConnection(integration.id)"
                                        >
                                            Test
                                        </button>
                                        <Link
                                            v-if="permissions.is_admin"
                                            :href="route('integrations.edit', integration.id)"
                                            class="btn btn-ghost btn-xs"
                                        >
                                            Edit
                                        </Link>
                                        <button
                                            v-if="permissions.is_admin"
                                            type="button"
                                            class="btn btn-ghost btn-xs text-error"
                                            @click="deleteIntegration(integration.id, integration.name)"
                                        >
                                            Del
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
