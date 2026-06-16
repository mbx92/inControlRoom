<script setup>
import { Handle, Position } from '@vue-flow/core';

defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});
</script>

<template>
    <div class="node-category">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="bottom" type="source" :position="Position.Bottom" />
        <div class="node-category__label">{{ data.label }}</div>
        <div class="node-category__count">{{ data.subtitle }}</div>
    </div>
</template>

<style scoped>
.node-category {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    min-width: 150px;
    padding: 6px 10px;
    background: var(--color-elevated, #2B3139);
    border: 1px solid var(--color-hairline, #2B3139);
    border-radius: 6px;
}

.node-category__label {
    font-family: var(--font-display);
    font-size: 12px;
    font-weight: 600;
    color: var(--color-body, #EAECEF);
}

.node-category__count {
    font-family: var(--font-mono);
    font-size: 10px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
}
</style>
