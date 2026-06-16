<script setup>
import { Handle, Position } from '@vue-flow/core';

defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});
</script>

<template>
    <div class="node-room">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="bottom" type="source" :position="Position.Bottom" />
        <div class="node-room__label">{{ data.label }}</div>
        <div class="node-room__count">{{ data.subtitle }}</div>
    </div>
</template>

<style scoped>
.node-room {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-width: 150px;
    padding: 6px 10px;
    background: color-mix(in oklab, #3B82F6 8%, var(--color-elevated, #2B3139));
    border: 1px solid color-mix(in oklab, #3B82F6 35%, var(--color-hairline, #2B3139));
    border-radius: 6px;
}

.node-room__label {
    font-family: var(--font-display);
    font-size: 12px;
    font-weight: 600;
    color: var(--color-body, #EAECEF);
}

.node-room__count {
    font-family: var(--font-mono);
    font-size: 10px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
}
</style>
