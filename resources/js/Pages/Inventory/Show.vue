<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const page = usePage();
const permissions = page.props.auth.permissions ?? {};

const props = defineProps({
    asset: { type: Object, required: true },
    history: { type: Array, default: () => [] },
    labelPrint: { type: Object, default: () => ({}) },
});

function printLabel() {
    router.post(route('inventory.print-label', props.asset.id), {}, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="asset.name" />

    <AppLayout>
        <PageHeader
            :title="asset.name"
            :subtitle="[asset.category, asset.manufacturer, asset.model].filter(Boolean).join(' · ') || 'Inventory detail'"
            eyebrow="Asset Detail"
        >
            <template #meta>
                <span class="status-chip">{{ asset.scope_label }}</span>
                <span class="status-chip">{{ asset.asset_tag || 'No asset tag' }}</span>
            </template>

            <template #actions>
                <Link :href="route('inventory.index')" class="btn btn-ghost">
                    Back
                </Link>
                <button
                    v-if="permissions.can_execute"
                    type="button"
                    class="btn btn-secondary"
                    :disabled="!labelPrint.available"
                    @click="printLabel"
                >
                    Print Label
                </button>
                <Link v-if="permissions.is_admin" :href="route('inventory.edit', asset.id)" class="btn btn-primary">
                    Edit Asset
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="panel-card p-4 sm:p-5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Status</div>
                        <div class="text-title-sm text-body mt-3">{{ asset.status_label }}</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Primary IP</div>
                        <div class="text-title-sm text-body mt-3 font-mono-num">{{ asset.primary_ip || '—' }}</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Location</div>
                        <div class="text-title-sm text-body mt-3">{{ asset.location_label || '—' }}</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Owner</div>
                        <div class="text-title-sm text-body mt-3">{{ asset.owner_name || 'Unassigned' }}</div>
                    </article>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_360px]">
                <section class="space-y-6">
                    <section class="panel-card p-6">
                        <div class="eyebrow">Identity</div>
                        <h2 class="text-title-md text-body mt-2">Core Metadata</h2>

                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">Category</div>
                                <div class="text-body-sm text-body mt-2">{{ asset.category }}</div>
                            </div>
                            <div class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">Scope</div>
                                <div class="text-body-sm text-body mt-2">{{ asset.scope_label }}</div>
                            </div>
                            <div class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">Asset Tag</div>
                                <div class="text-body-sm text-body mt-2 font-mono-num">{{ asset.asset_tag || '—' }}</div>
                            </div>
                            <div class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">Serial Number</div>
                                <div class="text-body-sm text-body mt-2 font-mono-num">{{ asset.serial_number || '—' }}</div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="eyebrow">Procurement & Lifecycle</div>
                        <h2 class="text-title-md text-body mt-2">Ownership Window</h2>

                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">Acquired At</div>
                                <div class="text-body-sm text-body mt-2">{{ asset.acquired_at || '—' }}</div>
                            </div>
                            <div class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">Warranty Expires</div>
                                <div class="text-body-sm text-body mt-2">{{ asset.warranty_expires_at || '—' }}</div>
                            </div>
                        </div>
                    </section>

                    <section v-if="asset.notes" class="panel-card p-6">
                        <div class="eyebrow">Notes</div>
                        <div class="text-body-sm text-body mt-4 whitespace-pre-wrap">{{ asset.notes }}</div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">History</div>
                                <h2 class="text-title-md text-body mt-2">Asset Audit Trail</h2>
                            </div>
                            <span class="status-chip">{{ history.length }} recent events</span>
                        </div>

                        <div v-if="history.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No audit events recorded for this asset yet.
                        </div>

                        <div v-else class="data-list mt-6">
                            <div v-for="entry in history" :key="entry.id" class="data-list__row">
                                <div class="w-full">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="status-chip">{{ entry.result }}</span>
                                        <span class="text-caption text-muted">{{ entry.created_at_human }}</span>
                                        <span class="text-caption text-muted">{{ entry.user_name }}</span>
                                    </div>
                                    <p class="text-body-sm text-body mt-2">{{ entry.action }}</p>
                                    <p v-if="entry.error_message" class="text-caption text-error mt-1">{{ entry.error_message }}</p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Extra Attributes</div>
                                <h2 class="text-title-md text-body mt-2">Flexible Metadata</h2>
                            </div>
                            <div class="status-chip">{{ asset.custom_fields_count }} fields</div>
                        </div>

                        <div v-if="asset.custom_fields.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No extra attributes stored for this asset.
                        </div>

                        <div v-else class="mt-6 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Key</th>
                                        <th>Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="field in asset.custom_fields"
                                        :key="field.key"
                                        class="border-hairline"
                                    >
                                        <td class="font-mono-num text-body-sm text-body">{{ field.key }}</td>
                                        <td class="text-body-sm text-muted">{{ field.value }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <aside class="space-y-6 xl:self-start">
                    <section class="panel-subtle p-5">
                        <div class="eyebrow">Record Info</div>
                        <div class="data-list mt-5">
                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Created</div>
                                    <p class="text-body-sm text-body mt-2">{{ asset.created_at || '—' }}</p>
                                    <p class="text-caption text-muted mt-1">{{ asset.created_at_human || '' }}</p>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Last Updated</div>
                                    <p class="text-body-sm text-body mt-2">{{ asset.updated_at || '—' }}</p>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Manufacturer / Model</div>
                                    <p class="text-body-sm text-body mt-2">
                                        {{ [asset.manufacturer, asset.model].filter(Boolean).join(' / ') || 'Not documented' }}
                                    </p>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Signed Scan URL</div>
                                    <p class="text-body-sm text-body mt-2 break-all font-mono-num">
                                        {{ asset.scan_url }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-subtle p-5">
                        <div class="eyebrow">Label Print</div>
                        <div class="data-list mt-5">
                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Printer Status</div>
                                    <p class="text-body-sm text-body mt-2">
                                        {{ labelPrint.available ? (labelPrint.printer_name || 'Default printer active') : 'No enabled printer configured' }}
                                    </p>
                                </div>
                            </div>

                            <div v-if="labelPrint.last_job" class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Last Print Job</div>
                                    <p class="text-body-sm text-body mt-2">
                                        {{ labelPrint.last_job.status }} · {{ labelPrint.last_job.created_at || 'just now' }}
                                    </p>
                                    <p v-if="labelPrint.last_job.error_message" class="text-caption text-error mt-1">
                                        {{ labelPrint.last_job.error_message }}
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
