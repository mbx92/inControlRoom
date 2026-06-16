<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TopologyCanvas from './TopologyCanvas.vue';

const props = defineProps({
    sites: { type: Array, required: true },
    selectedSite: { type: String, default: null },
    topologyMode: { type: String, default: 'infrastructure' },
    topologyGraph: { type: Object, required: true },
    topologyLayout: { type: Object, default: null },
});

const canvasFullscreen = ref(false);

function selectSite(siteId) {
    router.get(route('topology.index'), { site: siteId, mode: props.topologyMode }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}
</script>

<template>
    <Head title="Topology" />

    <AppLayout>
        <PageHeader
            v-show="!canvasFullscreen"
            :title="topologyMode === 'proxmox'
                ? 'Proxmox Topology'
                : topologyMode === 'network'
                    ? 'Network Topology'
                    : 'Infrastructure Topology'"
            :subtitle="topologyMode === 'network'
                ? 'Network map by floor and room with uplink connections between devices.'
                : topologyMode === 'proxmox'
                    ? 'Proxmox hypervisor integration and VM/CT workloads for this site.'
                    : 'Physical inventory assets mapped by site and location.'"
            eyebrow="Topology View"
        >
            <template #meta>
                <span class="status-chip">{{ topologyGraph.nodes.length }} nodes</span>
                <span class="status-chip">{{ topologyGraph.edges.length }} edges</span>
                <span
                    v-if="topologyMode === 'network' && topologyGraph.meta?.floorCount"
                    class="status-chip"
                >
                    {{ topologyGraph.meta.floorCount }} floors
                </span>
                <span
                    v-else-if="topologyMode === 'proxmox' && topologyGraph.meta?.guestCount != null"
                    class="status-chip"
                >
                    {{ topologyGraph.meta.guestCount }} workloads
                </span>
                <span
                    v-else-if="topologyMode === 'infrastructure' && topologyGraph.meta?.locationCount"
                    class="status-chip"
                >
                    {{ topologyGraph.meta.locationCount }} locations
                </span>
            </template>

            <template #actions>
                <Link :href="route('inventory.index')" class="btn btn-ghost btn-sm">
                    Inventory
                </Link>
            </template>
        </PageHeader>

        <div v-show="!canvasFullscreen" class="topology-sites-bar">
            <button
                v-for="site in sites"
                :key="site.id"
                type="button"
                class="topology-sites-bar__tab"
                :class="{ 'topology-sites-bar__tab--active': site.id === selectedSite }"
                :style="site.id === selectedSite ? { '--site-color': site.color } : {}"
                @click="selectSite(site.id)"
            >
                <span class="topology-sites-bar__dot" :style="{ background: site.color }" />
                <span class="topology-sites-bar__name">{{ site.name }}</span>
                <span class="topology-sites-bar__count">{{ site.assets_count }} assets</span>
            </button>
        </div>

        <TopologyCanvas
            v-model:fullscreen="canvasFullscreen"
            :mode="topologyMode"
            :site-id="selectedSite"
            :initial-nodes="topologyGraph.nodes"
            :initial-edges="topologyGraph.edges"
            :graph-meta="topologyGraph.meta ?? {}"
            :topology-layout="topologyLayout"
        />
    </AppLayout>
</template>

<style scoped>
.topology-sites-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding: 0 16px 12px;
}

.topology-sites-bar__tab {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: var(--color-elevated, #2B3139);
    border: 1px solid var(--color-hairline, #2B3139);
    border-radius: 9999px;
    cursor: pointer;
    transition: border-color 0.15s ease, background 0.15s ease;
    font-family: var(--font-display);
    font-size: 12px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
}

.topology-sites-bar__tab:hover {
    background: var(--color-card, #1E2329);
    color: var(--color-body, #EAECEF);
}

.topology-sites-bar__tab--active {
    background: var(--color-card, #1E2329);
    border-color: var(--site-color, #FCD535);
    color: var(--color-body, #EAECEF);
    box-shadow: 0 0 0 2px color-mix(in oklab, var(--site-color, #FCD535) 16%, transparent);
}

.topology-sites-bar__dot {
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    flex-shrink: 0;
}

.topology-sites-bar__name {
    white-space: nowrap;
}

.topology-sites-bar__count {
    font-family: var(--font-mono, monospace);
    font-size: 10px;
    color: var(--color-muted, #707A8A);
    padding-left: 4px;
    border-left: 1px solid var(--color-hairline, #2B3139);
}
</style>
