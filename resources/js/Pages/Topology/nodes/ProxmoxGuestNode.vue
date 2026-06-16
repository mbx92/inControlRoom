<script setup>
import { Handle, Position } from '@vue-flow/core';
import { computed } from 'vue';

const props = defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});

const statusDot = computed(() => {
    if (props.data.status === 'running') return '#0ECB81';
    if (props.data.status === 'stopped') return '#F6465D';
    return '#707A8A';
});

const isRunning = computed(() => props.data.status === 'running');
</script>

<template>
    <div class="node-guest node-guest--virtual" :class="{ 'node-guest--running': isRunning }">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="left" type="target" :position="Position.Left" />
        <div class="node-guest__indicator" :style="{ background: statusDot }" />
        <div class="node-guest__icon">
            <svg v-if="data.icon === 'vm'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="3" y="4" width="18" height="13" rx="2" />
                <path stroke-linecap="round" d="M7 20h10M12 17v3" />
            </svg>
            <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <rect x="4" y="3" width="16" height="18" rx="2" />
                <path stroke-linecap="round" d="M9 9h6M9 13h6M9 17h4" />
            </svg>
        </div>
        <div class="node-guest__body">
            <div class="node-guest__label">{{ data.label }}</div>
            <div class="node-guest__meta">
                <span>{{ data.subtitle }}</span>
                <span class="node-guest__dot">·</span>
                <span>ID {{ data.vmid }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.node-guest {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 186px;
    padding: 6px 10px;
    background: var(--color-elevated, #2B3139);
    border: 1px solid var(--color-hairline, #2B3139);
    border-radius: 8px;
    position: relative;
}

.node-guest__body {
    min-width: 0;
}

.node-guest--virtual {
    border-color: color-mix(in oklab, #F0B90B 25%, var(--color-hairline, #2B3139));
    background: color-mix(in oklab, #F0B90B 4%, var(--color-elevated, #2B3139));
}

.node-guest--running {
    border-color: color-mix(in oklab, #0ECB81 30%, transparent);
}

.node-guest__indicator {
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    flex-shrink: 0;
}

.node-guest__icon {
    color: var(--color-muted, #707A8A);
    flex-shrink: 0;
}

.node-guest__label {
    font-family: var(--font-display);
    font-size: 12px;
    font-weight: 500;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.node-guest__meta {
    font-family: var(--font-mono);
    font-size: 10px;
    color: var(--color-muted, #707A8A);
    margin-top: 1px;
    display: flex;
    gap: 4px;
}

.node-guest__dot {
    color: var(--color-hairline, #2B3139);
}
</style>
