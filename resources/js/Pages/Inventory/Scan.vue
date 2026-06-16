<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    asset: { type: Object, required: true },
    history: { type: Array, default: () => [] },
});
</script>

<template>
    <Head :title="`${asset.name} Scan`" />

    <div class="min-h-screen bg-canvas px-4 py-8 sm:px-6">
        <div class="mx-auto max-w-5xl space-y-6">
            <section class="panel-card p-6 sm:p-7">
                <div class="eyebrow">Asset Scan</div>
                <h1 class="text-title-lg text-body mt-3">{{ asset.name }}</h1>
                <p class="text-body-sm text-muted mt-3">
                    {{ [asset.category, asset.manufacturer, asset.model].filter(Boolean).join(' · ') || 'Inventory asset' }}
                </p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <span class="status-chip">{{ asset.scope_label }}</span>
                    <span class="status-chip">{{ asset.asset_tag || asset.serial_number || 'No asset tag' }}</span>
                    <span class="status-chip">{{ asset.status_label }}</span>
                </div>
            </section>

            <section class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <article class="panel-card p-5">
                    <div class="text-caption text-muted">Primary IP</div>
                    <div class="text-body-sm text-body mt-3 font-mono-num">{{ asset.primary_ip || '—' }}</div>
                </article>
                <article class="panel-card p-5">
                    <div class="text-caption text-muted">Location</div>
                    <div class="text-body-sm text-body mt-3">{{ asset.location_label || '—' }}</div>
                </article>
                <article class="panel-card p-5">
                    <div class="text-caption text-muted">Owner</div>
                    <div class="text-body-sm text-body mt-3">{{ asset.owner_name || 'Unassigned' }}</div>
                </article>
                <article class="panel-card p-5">
                    <div class="text-caption text-muted">Serial Number</div>
                    <div class="text-body-sm text-body mt-3 font-mono-num">{{ asset.serial_number || '—' }}</div>
                </article>
            </section>

            <section class="panel-card p-6">
                <div class="eyebrow">Asset History</div>
                <h2 class="text-title-md text-body mt-2">Recent Audit Trail</h2>

                <div v-if="history.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                    No audit history recorded for this asset yet.
                </div>

                <div v-else class="data-list mt-6">
                    <div v-for="entry in history" :key="entry.id" class="data-list__row">
                        <div class="flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="status-chip">{{ entry.result }}</span>
                                <span class="text-caption text-muted">{{ entry.created_at }}</span>
                                <span class="text-caption text-muted">{{ entry.user_name }}</span>
                            </div>
                            <p class="text-body-sm text-body mt-2">{{ entry.action }}</p>
                            <p v-if="entry.error_message" class="text-caption text-error mt-1">{{ entry.error_message }}</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
