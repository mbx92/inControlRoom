<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    integration: { type: Object, required: true },
    guest: { type: Object, required: true },
    guestTasks: { type: Object, required: true },
});

const runtimeBars = computed(() => [
    {
        label: 'CPU',
        percent: props.guest.cpu_usage_percent,
        detail: `${formatPercent(props.guest.cpu_usage_percent)} · ${props.guest.cpu_cores ?? '—'} cores`,
    },
    {
        label: 'RAM',
        percent: props.guest.memory_usage_percent,
        detail: `${formatBytes(props.guest.memory_used_bytes)} / ${formatBytes(props.guest.memory_total_bytes)}`,
    },
    {
        label: 'Disk',
        percent: props.guest.disk_usage_percent,
        detail: `${formatBytes(props.guest.disk_used_bytes)} / ${formatBytes(props.guest.disk_total_bytes)}`,
    },
]);

function formatBytes(bytes) {
    if (bytes === null || bytes === undefined) {
        return '—';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    let value = Number(bytes);
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    const digits = value >= 10 || unitIndex === 0 ? 0 : 1;
    return `${value.toFixed(digits)} ${units[unitIndex]}`;
}

function formatPercent(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    return `${Number(value).toFixed(value >= 10 ? 0 : 1)}%`;
}

function formatUptime(seconds) {
    if (!seconds) {
        return '—';
    }

    const totalMinutes = Math.floor(seconds / 60);
    const days = Math.floor(totalMinutes / 1440);
    const hours = Math.floor((totalMinutes % 1440) / 60);
    const minutes = totalMinutes % 60;

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m`;
}

function taskBadgeClass(result) {
    if (result === 'success') {
        return 'badge-success';
    }

    if (result === 'running') {
        return 'badge-warning';
    }

    return 'badge-error';
}

function goToTaskPage(page) {
    router.get(route('integrations.guests.show', {
        integration: props.integration.id,
        guestType: props.guest.type,
        node: props.guest.node,
        vmid: props.guest.vmid,
    }), {
        tasks_page: page,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <Head :title="`${guest.name} · ${integration.name}`" />

    <AppLayout>
        <PageHeader
            :title="guest.name"
            :subtitle="`${guest.type_label} / ${guest.vmid} / ${guest.node}`"
            eyebrow="Guest Detail"
        >
            <template #meta>
                <span class="status-chip">
                    <span :class="guest.is_online ? 'signal-dot signal-dot--live' : 'signal-dot signal-dot--warning'" />
                    {{ guest.is_online ? 'Online' : 'Offline' }}
                </span>
                <span class="status-chip">{{ integration.name }}</span>
            </template>

            <template #actions>
                <Link :href="route('integrations.show', integration.id)" class="btn btn-secondary">
                    Back to Integration
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="panel-card p-4 sm:p-5">
                <div class="grid grid-cols-1 gap-3 lg:grid-cols-3">
                    <article
                        v-for="bar in runtimeBars"
                        :key="bar.label"
                        class="rounded-xl border border-hairline bg-base-300 px-4 py-4"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <div class="text-caption text-muted">{{ bar.label }}</div>
                            <div class="text-caption text-body">{{ formatPercent(bar.percent) }}</div>
                        </div>
                        <div class="mt-3 h-2 overflow-hidden rounded-full bg-base-200">
                            <div
                                class="h-full rounded-full bg-primary transition-all duration-300"
                                :style="{ width: `${Math.max(0, Math.min(bar.percent ?? 0, 100))}%` }"
                            />
                        </div>
                        <div class="text-caption text-muted mt-3">{{ bar.detail }}</div>
                    </article>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.3fr)_360px]">
                <section class="space-y-6">
                    <section class="panel-card p-6">
                        <div class="eyebrow">Runtime</div>
                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <div>
                                <div class="text-caption text-muted">Status</div>
                                <div class="text-body-sm text-body mt-2">{{ guest.status }}</div>
                            </div>
                            <div>
                                <div class="text-caption text-muted">Uptime</div>
                                <div class="text-body-sm text-body mt-2">{{ formatUptime(guest.uptime) }}</div>
                            </div>
                            <div>
                                <div class="text-caption text-muted">OS Type</div>
                                <div class="text-body-sm text-body mt-2">{{ guest.os_type ?? '—' }}</div>
                            </div>
                            <div>
                                <div class="text-caption text-muted">On Boot</div>
                                <div class="text-body-sm text-body mt-2">{{ guest.onboot ? 'Enabled' : 'Disabled' }}</div>
                            </div>
                            <div>
                                <div class="text-caption text-muted">Guest Agent</div>
                                <div class="text-body-sm text-body mt-2">{{ guest.agent ?? '—' }}</div>
                            </div>
                            <div>
                                <div class="text-caption text-muted">Tags</div>
                                <div class="text-body-sm text-body mt-2">{{ guest.tags ?? '—' }}</div>
                            </div>
                        </div>
                        <div v-if="guest.description" class="mt-5 rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Description</div>
                            <div class="text-body-sm text-body mt-2">{{ guest.description }}</div>
                        </div>
                    </section>

                    <section v-if="guest.networks.length > 0" class="panel-card p-6">
                        <div class="eyebrow">Network</div>
                        <div class="mt-5 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Key</th>
                                        <th>Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="item in guest.networks"
                                        :key="item.key"
                                        class="border-hairline"
                                    >
                                        <td class="text-caption text-muted font-mono-num">{{ item.key }}</td>
                                        <td class="text-body-sm text-body break-all">{{ item.value || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section v-if="guest.storage.length > 0" class="panel-card p-6">
                        <div class="eyebrow">Storage</div>
                        <div class="mt-5 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Key</th>
                                        <th>Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="item in guest.storage"
                                        :key="item.key"
                                        class="border-hairline"
                                    >
                                        <td class="text-caption text-muted font-mono-num">{{ item.key }}</td>
                                        <td class="text-body-sm text-body break-all">{{ item.value || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Guest Activity</div>
                                <h2 class="text-title-md text-body mt-2">Recent Tasks</h2>
                            </div>
                            <div class="status-chip">{{ guestTasks.total }} tasks</div>
                        </div>

                        <div v-if="guestTasks.total === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No tasks recorded for this guest.
                        </div>

                        <div v-else class="mt-6">
                            <div class="overflow-x-auto">
                                <table class="table table-sm">
                                    <thead>
                                        <tr class="border-hairline">
                                            <th>Time</th>
                                            <th>Action</th>
                                            <th>Actor</th>
                                            <th>Target</th>
                                            <th>State</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="entry in guestTasks.data"
                                            :key="entry.id"
                                            class="border-hairline transition-default hover:bg-elevated/30"
                                        >
                                            <td class="text-caption text-muted">{{ entry.created_at_full ?? entry.created_at }}</td>
                                            <td class="text-body-sm text-body font-mono-num">{{ entry.action }}</td>
                                            <td class="text-body-sm text-body">{{ entry.user_name }}</td>
                                            <td class="text-caption text-muted font-mono-num">{{ entry.target }}</td>
                                            <td>
                                                <span class="badge badge-sm" :class="taskBadgeClass(entry.result)">
                                                    {{ entry.status_label ?? entry.result }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-4 flex items-center justify-between gap-4">
                                <div class="text-caption text-muted">
                                    Showing {{ guestTasks.from }}-{{ guestTasks.to }} of {{ guestTasks.total }}
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        :disabled="!guestTasks.has_prev"
                                        @click="goToTaskPage(guestTasks.prev_page)"
                                    >
                                        Prev
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        :disabled="!guestTasks.has_next"
                                        @click="goToTaskPage(guestTasks.next_page)"
                                    >
                                        Next
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>
                </section>

                <aside class="space-y-6">
                    <section class="panel-subtle p-5">
                        <div class="eyebrow">Full Config</div>
                        <div class="mt-5 max-h-[760px] overflow-auto rounded-lg border border-hairline bg-base-300">
                            <table class="table table-sm">
                                <tbody>
                                    <tr
                                        v-for="item in guest.config"
                                        :key="item.key"
                                        class="border-hairline"
                                    >
                                        <td class="w-36 text-caption text-muted font-mono-num">{{ item.key }}</td>
                                        <td class="text-body-sm text-body break-all">{{ item.value || '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
