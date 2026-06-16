<script setup>
import { Handle, Position } from '@vue-flow/core';
import { computed } from 'vue';

const props = defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});

const statusDot = computed(() => {
    if (props.data.status === 'online') return '#0ECB81';
    if (props.data.status === 'offline') return '#F6465D';
    return '#707A8A';
});
</script>

<template>
    <div class="node-cctv">
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle id="left" type="target" :position="Position.Left" />
        <div class="node-cctv__indicator" :style="{ background: statusDot }" />
        <div class="node-cctv__icon">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 11.5 18 7l2 4.5-14 4.5z" />
                <path stroke-linecap="round" d="M6 16.5 4.5 20M14.5 13.5 16 17" />
            </svg>
        </div>
        <div class="node-cctv__body">
            <div class="node-cctv__label">{{ data.label }}</div>
            <div class="node-cctv__meta">
                <span>CH {{ data.channel_id ?? '—' }}</span>
                <span class="node-cctv__dot">·</span>
                <span>{{ data.video_codec ?? data.subtitle ?? 'stream' }}</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.node-cctv {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 206px;
    padding: 6px 10px;
    background: color-mix(in oklab, #D97706 5%, var(--color-elevated, #2B3139));
    border: 1px solid color-mix(in oklab, #D97706 24%, var(--color-hairline, #2B3139));
    border-radius: 8px;
    position: relative;
}

.node-cctv__indicator {
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    flex-shrink: 0;
}

.node-cctv__icon {
    color: #F59E0B;
    flex-shrink: 0;
}

.node-cctv__body {
    min-width: 0;
}

.node-cctv__label {
    font-family: var(--font-display);
    font-size: 12px;
    font-weight: 500;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.node-cctv__meta {
    font-family: var(--font-mono);
    font-size: 10px;
    color: var(--color-muted, #707A8A);
    margin-top: 1px;
    display: flex;
    gap: 4px;
    overflow: hidden;
    white-space: nowrap;
}

.node-cctv__dot {
    color: var(--color-hairline, #2B3139);
}
</style>
