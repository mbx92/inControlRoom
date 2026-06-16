<script setup>
import { computed } from 'vue';
import { Handle, Position } from '@vue-flow/core';

const props = defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});

const visibleLocations = computed(() => (props.data.locations ?? props.data.floors ?? []).slice(0, 4));
const hiddenLocationCount = computed(() => Math.max(0, (props.data.locations ?? props.data.floors ?? []).length - 4));
</script>

<template>
    <div class="node-site-group" :style="{ '--site-color': data.siteColor }">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="bottom" type="source" :position="Position.Bottom" />
        <Handle id="right" type="source" :position="Position.Right" />
        <div class="node-site-group__icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
        </div>
        <div class="node-site-group__body">
            <div class="node-site-group__label">{{ data.label }}</div>
            <div class="node-site-group__sub">{{ data.subtitle }}</div>
            <div v-if="visibleLocations.length" class="node-site-group__floors">
                <span
                    v-for="location in visibleLocations"
                    :key="location.name"
                    class="node-site-group__floor-chip"
                >
                    {{ location.name }}
                    <span class="node-site-group__floor-count">{{ location.count }}</span>
                </span>
                <span v-if="hiddenLocationCount" class="node-site-group__floor-more">
                    +{{ hiddenLocationCount }}
                </span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.node-site-group {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    box-sizing: border-box;
    width: 280px;
    padding: 10px 14px;
    background: var(--color-card, #1E2329);
    border: 2px solid var(--site-color, #FCD535);
    border-radius: 10px;
    box-shadow: 0 0 0 4px color-mix(in oklab, var(--site-color, #FCD535) 8%, transparent);
}

.node-site-group__icon {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    background: color-mix(in oklab, var(--site-color, #FCD535) 14%, transparent);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--site-color, #FCD535);
    flex-shrink: 0;
    margin-top: 2px;
}

.node-site-group__label {
    font-family: var(--font-display);
    font-size: 14px;
    font-weight: 600;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
}

.node-site-group__sub {
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 500;
    color: var(--color-muted, #707A8A);
    margin-top: 2px;
}

.node-site-group__floors {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-top: 8px;
}

.node-site-group__floor-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 6px;
    border-radius: 4px;
    background: color-mix(in oklab, var(--site-color, #FCD535) 10%, var(--color-elevated, #2B3139));
    border: 1px solid color-mix(in oklab, var(--site-color, #FCD535) 22%, transparent);
    font-family: var(--font-display);
    font-size: 10px;
    font-weight: 500;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
}

.node-site-group__floor-count {
    font-family: var(--font-mono, monospace);
    font-size: 9px;
    color: var(--color-muted, #707A8A);
}

.node-site-group__floor-more {
    font-family: var(--font-mono, monospace);
    font-size: 10px;
    color: var(--color-muted, #707A8A);
    padding: 2px 4px;
}
</style>
