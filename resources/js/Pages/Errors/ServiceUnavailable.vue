<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    message: {
        type: String,
        default: 'InfraControl sedang tidak tersedia sementara. Tim IT sedang melakukan maintenance atau recovery.',
    },
    retryAfter: { type: [Number, String], default: null },
});
</script>

<template>
    <Head title="503 — Service Unavailable" />

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
                <h1 class="text-title-xl text-body mt-4">503</h1>
                <h2 class="text-title-md text-body mt-2">Service Unavailable</h2>

                <p class="text-body-sm text-muted mt-4 px-2">
                    {{ message }}
                </p>

                <div v-if="retryAfter" class="status-meta">
                    <div>
                        <div class="text-caption text-muted">Retry After</div>
                        <div class="text-body-sm text-body mt-1">{{ retryAfter }} seconds</div>
                    </div>
                </div>

                <p class="text-caption text-muted mt-6">
                    Jika Anda administrator, coba akses kembali setelah maintenance selesai atau hubungi tim operasional.
                </p>

                <div class="status-actions">
                    <button type="button" class="btn btn-primary" @click="window.location.reload()">
                        Try Again
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>
