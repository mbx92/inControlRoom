<script setup>
import { Handle, Position } from '@vue-flow/core';

defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});
</script>

<template>
    <div class="node-docker-int" :style="{ '--site-color': data.siteColor }">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="left" type="target" :position="Position.Left" />
        <Handle id="right" type="source" :position="Position.Right" />
        <Handle id="bottom" type="source" :position="Position.Bottom" />
        <div class="node-docker-int__icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 8h3v3H9zM13 8h3v3h-3zM5 8h3v3H5zM9 4h3v3H9z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 14h16a4 4 0 01-4 4H9a5 5 0 01-5-5v-1h4z" />
            </svg>
        </div>
        <div class="node-docker-int__body">
            <div class="node-docker-int__label">{{ data.label }}</div>
            <div class="node-docker-int__sub">{{ data.subtitle }}</div>
            <div v-if="data.hostAssetName" class="node-docker-int__host">on {{ data.hostAssetName }}</div>
        </div>
    </div>
</template>

<style scoped>
.node-docker-int {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 228px;
    padding: 10px 14px;
    background: color-mix(in oklab, #2496ED 8%, var(--color-card, #1E2329));
    border: 1px solid color-mix(in oklab, #2496ED 40%, transparent);
    border-radius: 10px;
}

.node-docker-int__body {
    min-width: 0;
}

.node-docker-int__icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: color-mix(in oklab, #2496ED 16%, transparent);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #4FA8F8;
    flex-shrink: 0;
}

.node-docker-int__label {
    font-family: var(--font-display);
    font-size: 13px;
    font-weight: 600;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.node-docker-int__sub {
    font-family: var(--font-mono);
    font-size: 10px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-top: 1px;
}

.node-docker-int__host {
    font-family: var(--font-display);
    font-size: 10px;
    color: #4FA8F8;
    margin-top: 2px;
}
</style>
