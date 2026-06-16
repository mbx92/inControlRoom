<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    accent: { type: String, default: 'default' },
    description: { type: String, default: '' },
});

const accentClasses = {
    default: 'metric-card',
    brand: 'metric-card metric-card--brand',
    critical: 'metric-card metric-card--critical',
    warning: 'metric-card metric-card--warning',
    success: 'metric-card metric-card--success',
};

const toneDots = {
    default: 'bg-muted',
    brand: 'bg-primary',
    critical: 'bg-trading-down',
    warning: 'bg-primary',
    success: 'bg-trading-up',
};
</script>

<template>
    <article class="panel-card h-full" :class="accentClasses[accent] ?? accentClasses.default">
        <div class="metric-card__label">
            <span class="text-caption uppercase tracking-[0.14em]">{{ label }}</span>
            <span class="h-2 w-2 rounded-full" :class="toneDots[accent] ?? toneDots.default" />
        </div>

        <div class="metric-card__value text-number-display">
            {{ value }}
        </div>

        <p v-if="description" class="metric-card__description text-body-sm">
            {{ description }}
        </p>
    </article>
</template>
