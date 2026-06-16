<script setup>
import { computed } from 'vue';
import { useTheme } from '@/composables/useTheme';

const props = defineProps({
    compact: { type: Boolean, default: false },
    framed: { type: Boolean, default: true },
    showHeader: { type: Boolean, default: true },
});

const { currentTheme, themeOptions, setTheme } = useTheme();

const activeTheme = computed(() => (
    themeOptions.find((theme) => theme.id === currentTheme.value) ?? themeOptions[0]
));
</script>

<template>
    <section
        class="theme-switcher"
        :class="{
            'theme-switcher--compact': compact,
            'theme-switcher--bare': !framed,
        }"
    >
        <div v-if="showHeader" class="theme-switcher__header">
            <div>
                <div class="eyebrow">Theme</div>
                <div v-if="!compact" class="text-body-sm text-muted mt-2">
                    Switch the room language without losing context.
                </div>
            </div>

            <div v-if="compact" class="theme-switcher__active text-caption text-muted">
                {{ activeTheme.shortName }}
            </div>
        </div>

        <div class="theme-switcher__list">
            <button
                v-for="theme in themeOptions"
                :key="theme.id"
                type="button"
                class="theme-switcher__button"
                :class="{ 'theme-switcher__button--active': currentTheme === theme.id }"
                @click="setTheme(theme.id)"
            >
                <span class="theme-switcher__swatch" :class="`theme-switcher__swatch--${theme.id}`" />

                <span class="min-w-0">
                    <span class="theme-switcher__name">
                        {{ compact ? theme.shortName : theme.name }}
                    </span>
                    <span v-if="!compact" class="theme-switcher__description">
                        {{ theme.description }}
                    </span>
                </span>
            </button>
        </div>
    </section>
</template>
