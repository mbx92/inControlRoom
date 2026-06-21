<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    maintenance: {
        type: Object,
        default: () => ({
            enabled: true,
            message: null,
            enabled_at: null,
            enabled_by_name: null,
        }),
    },
});

const defaultMessage = 'InfraControl sedang dalam maintenance. Operator dan viewer sementara tidak dapat mengakses sistem.';

const displayMessage = computed(() => props.maintenance.message || defaultMessage);

const enabledAtLabel = computed(() => {
    if (! props.maintenance.enabled_at) {
        return null;
    }

    return new Date(props.maintenance.enabled_at).toLocaleString();
});
</script>

<template>
    <Head title="Under Maintenance" />

    <div class="status-page">
        <div class="status-shell">
            <section class="status-card panel-subtle">
                <div class="status-icon status-icon--warning">
                    <svg class="h-10 w-10" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="1.5"
                            d="M12 9v4m0 4h.01M10.29 3.86l-7.5 13A1 1 0 003.65 18h16.7a1 1 0 00.86-1.5l-7.5-13a1 1 0 00-1.72 0z"
                        />
                    </svg>
                </div>

                <div class="eyebrow">System Status</div>
                <h1 class="text-title-lg text-body mt-4">Under Maintenance</h1>
                <p class="text-body-sm text-muted mt-3">
                    {{ displayMessage }}
                </p>

                <div v-if="enabledAtLabel || maintenance.enabled_by_name" class="status-meta">
                    <div v-if="enabledAtLabel">
                        <div class="text-caption text-muted">Started</div>
                        <div class="text-body-sm text-body mt-1">{{ enabledAtLabel }}</div>
                    </div>
                    <div v-if="maintenance.enabled_by_name">
                        <div class="text-caption text-muted">Enabled By</div>
                        <div class="text-body-sm text-body mt-1">{{ maintenance.enabled_by_name }}</div>
                    </div>
                </div>

                <p class="text-caption text-muted mt-6">
                    Jika Anda administrator, sign out lalu masuk kembali dengan akun admin untuk melanjutkan pekerjaan maintenance.
                </p>

                <div class="status-actions">
                    <Link :href="route('logout')" method="post" as="button" class="btn btn-secondary">
                        Sign Out
                    </Link>
                </div>
            </section>
        </div>
    </div>
</template>
