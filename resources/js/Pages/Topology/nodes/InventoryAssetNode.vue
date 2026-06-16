<script setup>
import { Handle, Position } from '@vue-flow/core';
import { computed } from 'vue';

const props = defineProps({
    id: { type: String, required: true },
    data: { type: Object, required: true },
});

const statusDot = computed(() => {
    switch (props.data.status) {
        case 'active': return '#0ECB81';
        case 'standby': return '#3B82F6';
        case 'repair': return '#F0B90B';
        case 'retired': return '#707A8A';
        default: return '#707A8A';
    }
});

function openAsset() {
    if (props.data.href) {
        window.open(props.data.href, '_self');
    }
}
</script>

<template>
    <div
        class="node-asset"
        :class="{ 'node-asset--host': data.hostsVirtual }"
        @click="openAsset"
        role="button"
        tabindex="0"
    >
        <Handle id="top" type="target" :position="Position.Top" />
        <Handle v-if="data.hostsVirtual" id="right" type="source" :position="Position.Right" />
        <Handle v-if="data.hostsVirtual" id="bottom" type="source" :position="Position.Bottom" />
        <div class="node-asset__dot" :style="{ background: statusDot }" />
        <div class="node-asset__body">
            <div class="node-asset__label">{{ data.label }}</div>
            <div v-if="data.subtitle" class="node-asset__sub">{{ data.subtitle }}</div>
            <div v-if="data.hostsVirtual" class="node-asset__host-badge">
                Hosts {{ data.hostLabel }}
            </div>
        </div>
    </div>
</template>

<style scoped>
.node-asset {
    display: flex;
    align-items: center;
    gap: 8px;
    box-sizing: border-box;
    width: 260px;
    padding: 5px 10px;
    background: var(--color-elevated, #2B3139);
    border: 1px solid var(--color-hairline, #2B3139);
    border-radius: 6px;
    cursor: pointer;
    transition: border-color 0.15s ease;
}

.node-asset--host {
    width: 280px;
    border-color: color-mix(in oklab, #707A8A 60%, #F0B90B 40%);
    box-shadow: 0 0 0 2px color-mix(in oklab, #F0B90B 10%, transparent);
}

.node-asset__body {
    min-width: 0;
}

.node-asset:hover {
    border-color: color-mix(in oklab, var(--color-primary, #FCD535) 40%, transparent);
}

.node-asset__dot {
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    flex-shrink: 0;
}

.node-asset__label {
    font-family: var(--font-display);
    font-size: 11px;
    font-weight: 500;
    color: var(--color-body, #EAECEF);
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.node-asset__sub {
    font-family: var(--font-mono);
    font-size: 10px;
    color: var(--color-muted, #707A8A);
    margin-top: 1px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.node-asset__host-badge {
    font-family: var(--font-mono);
    font-size: 9px;
    font-weight: 500;
    color: #F0B90B;
    margin-top: 3px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}
</style>
