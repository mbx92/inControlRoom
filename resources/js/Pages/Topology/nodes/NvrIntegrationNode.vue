<script setup>
import { Handle, Position } from '@vue-flow/core';

defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});
</script>

<template>
    <div class="node-nvr-int">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="left" type="target" :position="Position.Left" />
        <Handle id="right" type="source" :position="Position.Right" />
        <Handle id="bottom" type="source" :position="Position.Bottom" />
        <div class="node-nvr-int__icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="3.5" y="6.5" width="17" height="11" rx="2" />
                <path stroke-linecap="round" d="M7 17.5v2M17 17.5v2M8.5 10h7M8.5 13h4" />
            </svg>
        </div>
        <div class="node-nvr-int__body">
            <div class="node-nvr-int__label">{{ data.label }}</div>
            <div class="node-nvr-int__sub">{{ data.subtitle }}</div>
            <div v-if="data.hostAssetName" class="node-nvr-int__host">on {{ data.hostAssetName }}</div>
        </div>
    </div>
</template>

<style scoped>
.node-nvr-int {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 228px;
    padding: 10px 14px;
    background: color-mix(in oklab, #D97706 10%, var(--color-card, #1E2329));
    border: 1px solid color-mix(in oklab, #D97706 44%, transparent);
    border-radius: 10px;
}

.node-nvr-int__body {
    min-width: 0;
}

.node-nvr-int__icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: color-mix(in oklab, #D97706 18%, transparent);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #F59E0B;
    flex-shrink: 0;
}

.node-nvr-int__label {
    font-family: var(--font-display);
    font-size: 13px;
    font-weight: 600;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.node-nvr-int__sub {
    font-family: var(--font-mono);
    font-size: 10px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-top: 1px;
}

.node-nvr-int__host {
    font-family: var(--font-display);
    font-size: 10px;
    color: #F59E0B;
    margin-top: 2px;
}
</style>
