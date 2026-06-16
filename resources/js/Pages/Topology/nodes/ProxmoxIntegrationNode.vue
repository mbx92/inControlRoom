<script setup>
import { Handle, Position } from '@vue-flow/core';

defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});
</script>

<template>
    <div class="node-proxmox-int node-proxmox-int--virtual" :style="{ '--site-color': data.siteColor }">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="left" type="target" :position="Position.Left" />
        <Handle id="right" type="source" :position="Position.Right" />
        <Handle id="bottom" type="source" :position="Position.Bottom" />
        <div class="node-proxmox-int__icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="2" y="2" width="20" height="20" rx="3" />
                <path stroke-linecap="round" d="M7 16l5-8 3 5 2-4" />
            </svg>
        </div>
        <div class="node-proxmox-int__body">
            <div class="node-proxmox-int__label">{{ data.label }}</div>
            <div class="node-proxmox-int__sub">{{ data.subtitle }}</div>
            <div v-if="data.hostAssetName" class="node-proxmox-int__host">on {{ data.hostAssetName }}</div>
        </div>
    </div>
</template>

<style scoped>
.node-proxmox-int {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 228px;
    padding: 10px 14px;
    background: var(--color-card, #1E2329);
    border: 1px solid color-mix(in oklab, var(--site-color, #FCD535) 50%, transparent);
    border-radius: 10px;
}

.node-proxmox-int__body {
    min-width: 0;
}

.node-proxmox-int--virtual {
    border-color: color-mix(in oklab, #F0B90B 55%, transparent);
    background: color-mix(in oklab, #F0B90B 6%, var(--color-card, #1E2329));
}

.node-proxmox-int__icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: color-mix(in oklab, #F0B90B 14%, transparent);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #FCD535;
    flex-shrink: 0;
}

.node-proxmox-int__label {
    font-family: var(--font-display);
    font-size: 13px;
    font-weight: 600;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.node-proxmox-int__sub {
    font-family: var(--font-mono);
    font-size: 10px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-top: 1px;
}

.node-proxmox-int__host {
    font-family: var(--font-display);
    font-size: 10px;
    color: #F0B90B;
    margin-top: 2px;
}
</style>
