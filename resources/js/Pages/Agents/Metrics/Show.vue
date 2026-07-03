<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AgentMetricsCharts from '@/Components/AgentMetricsCharts.vue';
import AgentInventoryLinkDialog from '@/Components/AgentInventoryLinkDialog.vue';
import AgentInventoryLinkStatus from '@/Components/AgentInventoryLinkStatus.vue';

const props = defineProps({
    agent: { type: Object, required: true },
    inventoryAssets: { type: Array, default: () => [] },
    canManageInventoryLink: { type: Boolean, default: false },
});

const linkDialogRef = ref(null);

let refreshTimer = null;

onMounted(() => {
    refreshTimer = window.setInterval(() => {
        router.reload({ only: ['agent'], preserveScroll: true });
    }, 30_000);
});

onBeforeUnmount(() => {
    if (refreshTimer) {
        window.clearInterval(refreshTimer);
    }
});

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Date(value).toLocaleString();
}

function formatBytes(bytes) {
    const value = Number(bytes ?? 0);

    if (!value) {
        return '-';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(Math.floor(Math.log(value) / Math.log(1024)), units.length - 1);
    const scaled = value / 1024 ** exponent;

    return `${scaled.toFixed(scaled >= 10 || exponent === 0 ? 0 : 1)} ${units[exponent]}`;
}

function formatPercent(value) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '-';
    }

    return `${Number(value).toFixed(1)}%`;
}

function operstateClass(state) {
    return String(state).toLowerCase() === 'up' ? 'badge-success' : 'badge-ghost';
}

function openInventoryLink() {
    linkDialogRef.value?.open(props.agent);
}

function serviceStatusClass(status) {
    const normalized = String(status).toLowerCase();

    if (normalized === 'running') {
        return 'badge-success';
    }

    if (normalized === 'stopped') {
        return 'badge-error';
    }

    return 'badge-warning';
}
</script>

<template>
    <Head :title="`${agent.hostname} Metrics`" />

    <AppLayout>
        <PageHeader
            :title="agent.hostname"
            :subtitle="[agent.site_name, agent.primary_ip, agent.os, agent.os_version].filter(Boolean).join(' · ')"
            eyebrow="Agent Metrics"
        >
            <template #meta>
                <span class="status-chip">{{ agent.status }}</span>
                <span class="status-chip">v{{ agent.agent_version || 'unknown' }}</span>
            </template>

            <template #actions>
                <Link :href="route('agents.metrics.index')" class="btn btn-ghost">
                    Back to Metrics
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section v-if="agent.metrics?.has_metrics" class="panel-card p-4 sm:p-5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Total RAM</div>
                        <div class="text-title-sm text-body mt-3 font-mono-num">{{ formatBytes(agent.metrics.memory_total_bytes) }}</div>
                        <p class="text-caption text-muted mt-2">
                            {{ formatBytes(agent.metrics.memory_used_bytes) }} used · {{ formatPercent(agent.metrics.memory_used_percent) }}
                        </p>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Storage Capacity</div>
                        <div class="text-title-sm text-body mt-3 font-mono-num">
                            {{ formatBytes(agent.metrics.storage_total_bytes || agent.metrics.physical_storage_total_bytes) }}
                        </div>
                        <p class="text-caption text-muted mt-2">
                            <span v-if="agent.metrics.storage_total_bytes">
                                Volumes {{ formatBytes(agent.metrics.storage_used_bytes) }} used
                            </span>
                            <span v-if="agent.metrics.physical_storage_total_bytes">
                                <span v-if="agent.metrics.storage_total_bytes"> · </span>
                                Physical {{ formatBytes(agent.metrics.physical_storage_total_bytes) }}
                            </span>
                        </p>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">CPU Load</div>
                        <div class="text-title-sm text-body mt-3">{{ formatPercent(agent.metrics.cpu_usage_percent) }}</div>
                        <p class="text-caption text-muted mt-2 truncate">{{ agent.metrics.cpu_brand || 'Processor' }}</p>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">RAM Modules</div>
                        <div class="text-title-sm text-body mt-3">{{ agent.metrics.memory_slots_used || 0 }} slots</div>
                        <p class="text-caption text-muted mt-2 font-mono-num">
                            Installed {{ formatBytes(agent.metrics.memory_installed_bytes) }}
                        </p>
                    </article>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="panel-card p-5">
                    <div class="eyebrow">Processor</div>
                    <div class="mt-3 text-body-sm space-y-2">
                        <p class="font-medium text-body">{{ agent.metrics?.cpu_brand || 'Unknown CPU' }}</p>
                        <p><span class="text-muted">Vendor:</span> {{ agent.metrics?.cpu_manufacturer || '-' }}</p>
                        <p><span class="text-muted">Cores:</span> {{ agent.metrics?.cpu_physical_cores || '-' }} physical / {{ agent.metrics?.cpu_cores || '-' }} logical</p>
                    </div>
                </article>

                <article class="panel-card p-5">
                    <div class="eyebrow">Device</div>
                    <div class="mt-3 text-body-sm space-y-2">
                        <p><span class="text-muted">Device ID:</span> <span class="font-mono-num">{{ agent.device_id }}</span></p>
                        <p><span class="text-muted">Architecture:</span> {{ agent.arch || '-' }}</p>
                        <p><span class="text-muted">Last heartbeat:</span> {{ formatDate(agent.last_heartbeat_at) }}</p>
                    </div>
                </article>

                <article class="panel-card p-5">
                    <div class="eyebrow">Network</div>
                    <div class="mt-3 text-body-sm space-y-2">
                        <p><span class="text-muted">Primary IP:</span> {{ agent.primary_ip || '-' }}</p>
                        <p><span class="text-muted">Last source IP:</span> {{ agent.last_ip_address || '-' }}</p>
                        <p><span class="text-muted">Enrolled:</span> {{ formatDate(agent.enrolled_at) }}</p>
                    </div>
                </article>

                <article class="panel-card p-5">
                    <div class="eyebrow">Inventory</div>
                    <div class="mt-3 space-y-3">
                        <AgentInventoryLinkStatus :agent="agent" />
                        <button
                            v-if="canManageInventoryLink"
                            type="button"
                            class="btn btn-secondary btn-sm"
                            @click="openInventoryLink"
                        >
                            {{ agent.inventory_asset ? 'Change Link' : 'Link Inventory' }}
                        </button>
                    </div>
                </article>

                <article class="panel-card p-5">
                    <div class="eyebrow">Enrollment</div>
                    <div class="mt-3 text-body-sm space-y-2">
                        <p><span class="text-muted">Token label:</span> {{ agent.token_name || 'Direct enroll' }}</p>
                    </div>
                </article>

                <article class="panel-card p-5">
                    <div class="eyebrow">Labels</div>
                    <div v-if="agent.labels?.length" class="mt-3 flex flex-wrap gap-2">
                        <span v-for="label in agent.labels" :key="label" class="status-chip">{{ label }}</span>
                    </div>
                    <p v-else class="mt-3 text-body-sm text-muted">No labels reported.</p>
                </article>
            </section>

            <section v-if="!agent.metrics?.has_metrics" class="panel-card p-5">
                <div class="eyebrow">Metrics</div>
                <p class="mt-3 text-body-sm text-muted">
                    This agent has not reported metrics yet. Confirm the Windows service is running and heartbeats are reaching the server.
                </p>
            </section>

            <AgentMetricsCharts v-else :agent="agent" />

            <section v-if="agent.metrics?.memory_slots?.length" class="panel-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Memory Hardware</div>
                        <h2 class="mt-3 text-title-md text-body">RAM Slots</h2>
                        <p class="mt-1 text-body-sm text-muted font-mono-num">
                            Total installed {{ formatBytes(agent.metrics.memory_installed_bytes) }}
                        </p>
                    </div>
                    <div class="status-chip">{{ agent.metrics.memory_slots_used }} used</div>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Slot</th>
                                <th>Capacity</th>
                                <th>Type</th>
                                <th>Speed</th>
                                <th>Manufacturer</th>
                                <th>Part Number</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="slot in agent.metrics.memory_slots" :key="slot.slot">
                                <td>{{ slot.slot }}</td>
                                <td>{{ formatBytes(slot.size_bytes) }}</td>
                                <td>{{ slot.type || '-' }}</td>
                                <td>{{ slot.speed_mhz ? `${slot.speed_mhz} MHz` : '-' }}</td>
                                <td>{{ slot.manufacturer || '-' }}</td>
                                <td class="font-mono-num text-caption">{{ slot.part_number || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="agent.metrics?.storage_devices?.length" class="panel-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Storage Hardware</div>
                        <h2 class="mt-3 text-title-md text-body">Physical Drives</h2>
                        <p class="mt-1 text-body-sm text-muted font-mono-num">
                            Total capacity {{ formatBytes(agent.metrics.physical_storage_total_bytes) }}
                        </p>
                    </div>
                    <div class="status-chip">{{ agent.metrics.storage_devices.length }} devices</div>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Capacity</th>
                                <th>Interface</th>
                                <th>Vendor</th>
                                <th>SMART</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="drive in agent.metrics.storage_devices" :key="drive.device || drive.name">
                                <td>
                                    <div class="font-medium text-body">{{ drive.name }}</div>
                                    <div class="text-caption text-muted font-mono-num">{{ drive.serial_number || drive.device }}</div>
                                </td>
                                <td>{{ drive.type || '-' }}</td>
                                <td>{{ formatBytes(drive.size_bytes) }}</td>
                                <td>{{ drive.interface_type || '-' }}</td>
                                <td>{{ drive.vendor || '-' }}</td>
                                <td>{{ drive.smart_status || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="agent.metrics?.network?.length" class="panel-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Network</div>
                        <h2 class="mt-3 text-title-md text-body">Interface Metrics</h2>
                    </div>
                    <div class="status-chip">{{ agent.metrics.network.length }} interfaces</div>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Interface</th>
                                <th>State</th>
                                <th>IPv4</th>
                                <th>MAC</th>
                                <th>RX</th>
                                <th>TX</th>
                                <th>Errors</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entry in agent.metrics.network" :key="entry.iface">
                                <td class="font-medium text-body">{{ entry.iface }}</td>
                                <td><span class="badge badge-sm" :class="operstateClass(entry.operstate)">{{ entry.operstate }}</span></td>
                                <td class="font-mono-num">{{ entry.ipv4 || '-' }}</td>
                                <td class="font-mono-num text-caption">{{ entry.mac || '-' }}</td>
                                <td>{{ formatBytes(entry.rx_bytes) }}</td>
                                <td>{{ formatBytes(entry.tx_bytes) }}</td>
                                <td>{{ entry.rx_errors + entry.tx_errors }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section v-if="agent.metrics?.usb_devices?.length" class="panel-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">USB</div>
                        <h2 class="mt-3 text-title-md text-body">Connected USB Devices</h2>
                    </div>
                    <div class="status-chip">{{ agent.metrics.usb_devices.length }} devices</div>
                </div>

                <div class="mt-5 overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Manufacturer</th>
                                <th>Device ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="device in agent.metrics.usb_devices" :key="device.device_id || device.name">
                                <td>{{ device.name }}</td>
                                <td>{{ device.type || '-' }}</td>
                                <td>{{ device.manufacturer || device.vendor || '-' }}</td>
                                <td class="font-mono-num text-caption">{{ device.device_id || '-' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Services</div>
                        <h2 class="mt-3 text-title-md text-body">Important Windows Services</h2>
                        <p class="mt-2 text-body-sm text-muted">
                            Dhcp, Dnscache, EventLog, LanmanServer, Spooler, TermService, W32Time, and Winmgmt. EventLog is always queried first.
                        </p>
                    </div>
                    <div class="status-chip">{{ agent.services.length }} tracked</div>
                </div>

                <div v-if="agent.services.length === 0" class="mt-5 rounded-2xl border border-hairline bg-base-300 p-4 text-body-sm text-muted">
                    No service inventory reported in the latest heartbeat. Rebuild and reinstall the agent if this device was enrolled before the service inventory fix.
                </div>

                <div v-else class="mt-5 overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Display Name</th>
                                <th>Status</th>
                                <th>Start Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="service in agent.services" :key="service.name">
                                <td class="font-mono-num">{{ service.name }}</td>
                                <td>{{ service.display_name }}</td>
                                <td><span class="badge badge-sm" :class="serviceStatusClass(service.status)">{{ service.status }}</span></td>
                                <td>{{ service.start_mode }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <AgentInventoryLinkDialog
            v-if="canManageInventoryLink"
            ref="linkDialogRef"
            :inventory-assets="inventoryAssets"
        />
    </AppLayout>
</template>
