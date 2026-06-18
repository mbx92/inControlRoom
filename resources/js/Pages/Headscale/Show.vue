<script setup>
import { computed } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const page = usePage();

const props = defineProps({
    integration: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    nodes: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    apiError: { type: String, default: null },
});

const permissions = computed(() => page.props.auth.permissions ?? {});

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

function formatDate(value) {
    if (!value) {
        return '—';
    }

    return String(value).replace('T', ' ').replace('Z', '');
}

function terminalUrl(node) {
    return route('headscale.terminal.page', {
        integration: props.integration.id,
        node_name: node.name,
        host: node.ips?.[0] ?? '',
    });
}
</script>

<template>
    <Head :title="integration.name" />

    <AppLayout>
        <PageHeader
            :title="integration.name"
            subtitle="Headscale Manager"
            eyebrow="Mesh Control"
        >
            <template #meta>
                <span class="status-chip">
                    <span class="signal-dot" :class="connectionHealth.dotClass" />
                    {{ connectionHealth.label }}
                </span>
                <span class="status-chip">{{ integration.scope_kind === 'global' ? 'Global' : integration.scope_label }}</span>
            </template>

            <template #actions>
                <button type="button" class="btn btn-secondary" @click="testConnection">
                    Check API
                </button>
                <Link :href="route('integrations.edit', integration.id)" class="btn btn-primary">
                    Edit Source
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="panel-card p-4 sm:p-5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Domain</div>
                        <div class="text-title-sm text-body mt-3 break-all">{{ integration.base_host }}</div>
                        <div class="text-caption text-muted mt-2">server endpoint</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Nodes</div>
                        <div class="text-number-sm text-body mt-3">{{ stats.node_total }}</div>
                        <div class="text-caption text-muted mt-2">{{ stats.online_total }} online</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Users</div>
                        <div class="text-number-sm text-body mt-3">{{ stats.user_total }}</div>
                        <div class="text-caption text-muted mt-2">identity records</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Tagged Nodes</div>
                        <div class="text-number-sm text-body mt-3">{{ stats.tagged_total }}</div>
                        <div class="text-caption text-muted mt-2">nodes with tags</div>
                    </article>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_360px]">
                <section class="space-y-6">
                    <section class="panel-card p-6">
                        <div class="eyebrow">Control Plane</div>
                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <div>
                                <div class="text-caption text-muted">Server URL</div>
                                <div class="text-body-sm text-body mt-2 break-all font-mono-num">{{ integration.base_url }}</div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Management Endpoint</div>
                                <div class="text-body-sm text-body mt-2 break-all font-mono-num">{{ integration.api_health.endpoint }}</div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Secret Source</div>
                                <div class="text-body-sm text-body mt-2">{{ integration.secret_source_label }}</div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">SSL Verification</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health.verify_ssl === false ? 'Disabled' : 'Enabled' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Last Check</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.last_tested_at ?? 'Never' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Last Result</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.last_test_message ?? 'No check recorded yet.' }}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Users</div>
                                <h2 class="text-title-md text-body mt-2">Headscale Users</h2>
                            </div>
                            <div class="status-chip">{{ users.length }} users</div>
                        </div>

                        <div v-if="apiError" class="mt-6 rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                            {{ apiError }}
                        </div>

                        <div v-else-if="users.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No users returned by Headscale.
                        </div>

                        <div v-else class="mt-6 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Provider</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="user in users" :key="user.id" class="border-hairline transition-default hover:bg-elevated/30">
                                        <td>
                                            <div class="text-body-sm text-body">{{ user.name }}</div>
                                            <div v-if="user.display_name && user.display_name !== user.name" class="text-caption text-muted mt-1">
                                                {{ user.display_name }}
                                            </div>
                                        </td>
                                        <td class="text-body-sm text-body">{{ user.email ?? '—' }}</td>
                                        <td class="text-body-sm text-body">{{ user.provider ?? 'local' }}</td>
                                        <td class="text-caption text-muted">{{ formatDate(user.created_at) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Nodes</div>
                                <h2 class="text-title-md text-body mt-2">Managed Nodes</h2>
                            </div>
                            <div class="status-chip">{{ nodes.length }} nodes</div>
                        </div>

                        <div v-if="apiError" class="mt-6 rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                            {{ apiError }}
                        </div>

                        <div v-else-if="nodes.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No nodes returned by Headscale.
                        </div>

                        <div v-else class="mt-6 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Node</th>
                                        <th>User</th>
                                        <th>Status</th>
                                        <th>IPs</th>
                                        <th>Tags</th>
                                        <th>Last Seen</th>
                                        <th v-if="permissions.can_execute">Access</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="node in nodes" :key="node.id" class="border-hairline transition-default hover:bg-elevated/30">
                                        <td>
                                            <div class="text-body-sm text-body">{{ node.name }}</div>
                                            <div v-if="node.given_name && node.given_name !== node.name" class="text-caption text-muted mt-1">
                                                {{ node.given_name }}
                                            </div>
                                        </td>
                                        <td class="text-body-sm text-body">{{ node.user_name }}</td>
                                        <td>
                                            <span class="status-chip">
                                                <span class="signal-dot" :class="node.is_online ? 'signal-dot--live' : 'signal-dot--warning'" />
                                                {{ node.is_online ? 'Online' : 'Offline' }}
                                            </span>
                                        </td>
                                        <td class="text-caption text-muted font-mono-num">
                                            {{ node.ips.length ? node.ips.join(', ') : '—' }}
                                        </td>
                                        <td class="text-caption text-muted">
                                            {{ node.tags.length ? node.tags.join(', ') : '—' }}
                                        </td>
                                        <td class="text-caption text-muted">{{ formatDate(node.last_seen) }}</td>
                                        <td v-if="permissions.can_execute">
                                            <a
                                                :href="terminalUrl(node)"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="btn btn-secondary btn-sm"
                                                :class="{ 'pointer-events-none opacity-50': !node.ips.length }"
                                            >
                                                Open Terminal
                                            </a>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <aside class="space-y-6">
                    <section class="panel-subtle p-5">
                        <div class="eyebrow">API Health</div>
                        <div class="data-list mt-5">
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
                                    <div class="text-caption text-muted">Auth Status</div>
                                    <div class="text-body-sm text-body mt-2">{{ integration.api_health.auth_status }}</div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Latency</div>
                                    <div class="text-body-sm text-body mt-2">
                                        {{ integration.api_health.latency_ms !== null ? `${integration.api_health.latency_ms} ms` : '—' }}
                                    </div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">HTTP Status</div>
                                    <div class="text-body-sm text-body mt-2">{{ integration.api_health.http_status ?? '—' }}</div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Expected</div>
                                    <div class="text-body-sm text-body mt-2">{{ integration.api_health.method }} {{ integration.api_health.expected_status }}</div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-subtle p-5">
                        <div class="eyebrow">Terminal Notes</div>
                        <div class="mt-4 space-y-3 text-body-sm text-muted">
                            <p>Terminal akan dibuka di tab baru agar sesi SSH tidak mengganggu halaman manajemen Headscale utama.</p>
                            <p>Server app atau proxy SSH harus punya jalur ke tailnet yang sama agar koneksi benar-benar bisa terbuka.</p>
                            <p>Simpan SSH key atau password di Vault, lalu pilih saat membuka sesi terminal agar credential tidak ditulis langsung di halaman ini.</p>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
