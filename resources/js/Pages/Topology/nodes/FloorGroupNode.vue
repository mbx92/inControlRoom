<script setup>
import { Handle, Position } from '@vue-flow/core';

defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});
</script>

<template>
    <div class="node-floor" :style="{ '--site-color': data.siteColor }">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="bottom" type="source" :position="Position.Bottom" />
        <div class="node-floor__icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
        </div>
        <div class="node-floor__body">
            <div class="node-floor__label">{{ data.label }}</div>
            <div class="node-floor__sub">{{ data.subtitle }}</div>
        </div>
    </div>
</template>

<style scoped>
.node-floor {
    display: flex;
    align-items: center;
    gap: 10px;
    box-sizing: border-box;
    width: 240px;
    padding: 10px 14px;
    background: var(--color-card, #1E2329);
    border: 2px solid #3B82F6;
    border-radius: 10px;
    box-shadow: 0 0 0 4px color-mix(in oklab, #3B82F6 10%, transparent);
}

.node-floor__icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: color-mix(in oklab, #3B82F6 14%, transparent);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #3B82F6;
    flex-shrink: 0;
}

.node-floor__label {
    font-family: var(--font-display);
    font-size: 14px;
    font-weight: 600;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
}

.node-floor__sub {
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
    margin-top: 1px;
}
</style>
