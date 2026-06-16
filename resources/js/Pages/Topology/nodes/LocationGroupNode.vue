<script setup>
import { Handle, Position } from '@vue-flow/core';

defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});
</script>

<template>
    <div class="node-location">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="bottom" type="source" :position="Position.Bottom" />
        <div class="node-location__icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 21s7-4.35 7-11a7 7 0 10-14 0c0 6.65 7 11 7 11z" />
                <circle cx="12" cy="10" r="2.5" />
            </svg>
        </div>
        <div class="node-location__body">
            <div class="node-location__label">{{ data.label }}</div>
            <div class="node-location__sub">{{ data.subtitle }}</div>
        </div>
    </div>
</template>

<style scoped>
.node-location {
    display: flex;
    align-items: center;
    gap: 10px;
    box-sizing: border-box;
    width: 260px;
    padding: 10px 14px;
    background: color-mix(in oklab, #2DBDb6 8%, var(--color-card, #1E2329));
    border: 2px solid #2DBDb6;
    border-radius: 10px;
    box-shadow: 0 0 0 4px color-mix(in oklab, #2DBDb6 10%, transparent);
}

.node-location__icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: color-mix(in oklab, #2DBDb6 14%, transparent);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #2DBDb6;
    flex-shrink: 0;
}

.node-location__label {
    font-family: var(--font-display);
    font-size: 13px;
    font-weight: 600;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
}

.node-location__sub {
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
    margin-top: 1px;
}
</style>
