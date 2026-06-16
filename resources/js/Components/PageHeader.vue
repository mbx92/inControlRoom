<script setup>
defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    eyebrow: { type: String, default: 'Operations Surface' },
});
</script>

<template>
    <section class="page-header">
        <div class="panel-card page-header__band">
            <div class="min-w-0">
                <p class="eyebrow">{{ eyebrow }}</p>
                <h1 class="page-header__title text-display-sm text-body">{{ title }}</h1>
                <p v-if="subtitle" class="text-body-md text-muted mt-3 max-w-2xl">
                    {{ subtitle }}
                </p>
            </div>

            <div v-if="$slots.meta || $slots.actions" class="page-header__meta">
                <div v-if="$slots.meta" class="page-header__meta-row">
                    <slot name="meta" />
                </div>

                <div v-if="$slots.actions" class="page-header__meta-row">
                    <slot name="actions" />
                </div>
            </div>
        </div>
    </section>
</template>
