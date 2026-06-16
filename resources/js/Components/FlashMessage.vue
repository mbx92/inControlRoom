<script setup>
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const AUTO_DISMISS_MS = 3500;
const visible = ref(false);
const message = ref('');
const type = ref('success');
const dismissTimer = ref(null);

const flash = computed(() => page.props.flash);

watch(flash, (value) => {
    if (dismissTimer.value) {
        clearTimeout(dismissTimer.value);
        dismissTimer.value = null;
    }

    if (value.success) {
        message.value = value.success;
        type.value = 'success';
        visible.value = true;
    } else if (value.error) {
        message.value = value.error;
        type.value = 'error';
        visible.value = true;
    }

    if (visible.value) {
        dismissTimer.value = setTimeout(() => {
            visible.value = false;
            dismissTimer.value = null;
        }, AUTO_DISMISS_MS);
    }
}, { immediate: true, deep: true });
</script>

<template>
    <Transition name="flash">
        <div
            v-if="visible && message"
            class="flash-toast-shell animate-fade-up"
        >
            <div
                class="flash-toast"
                :class="type === 'success' ? 'flash-toast--success' : 'flash-toast--error'"
            >
                <div class="flash-toast__icon" aria-hidden="true">
                    <svg
                        v-if="type === 'success'"
                        class="h-4 w-4"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <svg
                        v-else
                        class="h-4 w-4"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M10.29 3.86l-7.5 13A1 1 0 003.67 18h16.66a1 1 0 00.88-1.49l-7.5-13a1 1 0 00-1.74 0z" />
                    </svg>
                </div>

                <div class="flash-toast__content">
                    <div class="flash-toast__label">
                        {{ type === 'success' ? 'Success' : 'Attention' }}
                    </div>
                    <p class="flash-toast__message">{{ message }}</p>
                </div>

                <button type="button" class="flash-toast__close" aria-label="Dismiss notification" @click="visible = false">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.flash-toast-shell {
    position: fixed;
    top: 1rem;
    right: 1rem;
    z-index: 50;
    width: min(26rem, calc(100vw - 2rem));
}

.flash-toast {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem 0.875rem 0.875rem 1rem;
    border: 1px solid var(--color-hairline);
    border-left-width: 3px;
    border-radius: var(--radius-xl);
    background: var(--color-card);
    box-shadow: var(--theme-panel-shadow), 0 14px 32px rgba(0, 0, 0, 0.24);
    color: var(--color-body);
}

.flash-toast--success {
    border-left-color: var(--color-trading-up);
}

.flash-toast--error {
    border-left-color: var(--color-trading-down);
}

.flash-toast__icon {
    display: inline-flex;
    width: 2rem;
    height: 2rem;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
}

.flash-toast--success .flash-toast__icon {
    color: var(--color-trading-up);
    background: color-mix(in srgb, var(--color-trading-up) 16%, transparent);
}

.flash-toast--error .flash-toast__icon {
    color: var(--color-trading-down);
    background: color-mix(in srgb, var(--color-trading-down) 16%, transparent);
}

.flash-toast__content {
    min-width: 0;
    flex: 1 1 auto;
}

.flash-toast__label {
    font-family: var(--font-mono);
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--color-muted);
}

.flash-toast__message {
    margin: 0.375rem 0 0;
    font-family: var(--font-display);
    font-size: 0.875rem;
    line-height: 1.5;
    color: var(--color-body);
    word-break: break-word;
}

.flash-toast__close {
    display: inline-flex;
    width: 2rem;
    height: 2rem;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: var(--color-muted);
    transition: background-color 0.15s ease, color 0.15s ease;
}

.flash-toast__close:hover {
    background: var(--theme-hover-bg);
    color: var(--color-body);
}

.flash-enter-active,
.flash-leave-active {
    transition: opacity 0.2s ease, transform 0.2s ease;
}

.flash-enter-from,
.flash-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

@media (max-width: 640px) {
    .flash-toast-shell {
        top: 0.75rem;
        right: 0.75rem;
        left: 0.75rem;
        width: auto;
    }
}
</style>
