<script setup>
import { Handle, Position } from '@vue-flow/core';
import { computed } from 'vue';

const props = defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});

const statusDot = computed(() => {
    if (props.data.status === 'running') return '#0ECB81';
    if (props.data.status === 'exited' || props.data.status === 'dead') return '#F6465D';
    return '#707A8A';
});

const isRunning = computed(() => props.data.status === 'running');
</script>

<template>
    <div class="node-docker-ct" :class="{ 'node-docker-ct--running': isRunning }">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="left" type="target" :position="Position.Left" />
        <div class="node-docker-ct__indicator" :style="{ background: statusDot }" />
        <div class="node-docker-ct__icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 8h3v3H9zM13 8h3v3h-3zM5 8h3v3H5zM9 4h3v3H9z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 14h16a4 4 0 01-4 4H9a5 5 0 01-5-5v-1h4z" />
            </svg>
        </div>
        <div class="node-docker-ct__body">
            <div class="node-docker-ct__label">{{ data.label }}</div>
            <div class="node-docker-ct__meta">
                <span>{{ data.subtitle }}</span>
                <span class="node-docker-ct__dot">·</span>
                <span>{{ data.image ?? 'image' }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.node-docker-ct {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 206px;
    padding: 6px 10px;
    background: color-mix(in oklab, #2496ED 4%, var(--color-elevated, #2B3139));
    border: 1px solid color-mix(in oklab, #2496ED 22%, var(--color-hairline, #2B3139));
    border-radius: 8px;
    position: relative;
}

.node-docker-ct--running {
    border-color: color-mix(in oklab, #0ECB81 30%, transparent);
}

.node-docker-ct__body {
    min-width: 0;
}

.node-docker-ct__indicator {
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    flex-shrink: 0;
}

.node-docker-ct__icon {
    color: #4FA8F8;
    flex-shrink: 0;
}

.node-docker-ct__label {
    font-family: var(--font-display);
    font-size: 12px;
    font-weight: 500;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.node-docker-ct__meta {
    font-family: var(--font-mono);
    font-size: 10px;
    color: var(--color-muted, #707A8A);
    margin-top: 1px;
    display: flex;
    gap: 4px;
    overflow: hidden;
    white-space: nowrap;
}

.node-docker-ct__dot {
    color: var(--color-hairline, #2B3139);
}
</style>
