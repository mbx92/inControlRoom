<script setup>
import { router } from '@inertiajs/vue3';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const page = usePage();
const permissions = page.props.auth.permissions ?? {};

const props = defineProps({
    printers: { type: Array, required: true },
    transport: { type: Object, required: true },
    recentJobs: { type: Array, required: true },
});

const connectionBadge = {
    smb: 'SMB Share',
    raw_tcp: 'LAN TCP',
};

function testPrint(printer) {
    router.post(route('print-smb.test', printer.id), {}, {
        preserveScroll: true,
    });
}

function setDefault(printer) {
    router.put(route('print-smb.set-default', printer.id), {}, {
        preserveScroll: true,
    });
}

function removePrinter(printer) {
    if (!confirm(`Hapus printer "${printer.display_name}"? Job print sebelumnya tetap tersimpan.`)) {
        return;
    }

    router.delete(route('print-smb.destroy', printer.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Label Printers" />

    <AppLayout>
        <PageHeader
            title="Label Printers"
            subtitle="Kelola printer thermal untuk cetak label asset 50×15 mm dengan QR code."
            eyebrow="Settings"
        >
            <template #meta>
                <span class="status-chip">50×15mm</span>
                <span class="status-chip">{{ printers.length }} printer(s)</span>
            </template>
        </PageHeader>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
            <div class="space-y-6">
                <div class="flex items-center justify-between">
                    <div class="text-body-sm text-muted">
                        Printer default akan otomatis dipakai saat cetak label dari halaman Inventory.
                    </div>

                    <Link v-if="permissions.is_admin" :href="route('print-smb.create')" class="btn btn-primary btn-sm">
                        Add Printer
                    </Link>
                </div>

                <section v-if="printers.length === 0" class="panel-card p-8 text-center">
                    <div class="text-body-sm text-muted">
                        {{ permissions.is_admin
                            ? 'Belum ada printer terdaftar. Klik Add Printer untuk mulai.'
                            : 'Belum ada printer terdaftar untuk environment ini.'
                        }}
                    </div>
                </section>

                <section v-else class="panel-card overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="table w-full">
                            <thead>
                                <tr>
                                    <th>Printer</th>
                                    <th>Connection</th>
                                    <th>Driver</th>
                                    <th>Target</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <tr v-for="printer in printers" :key="printer.id">
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <span class="text-body-sm font-semibold text-body">
                                                {{ printer.display_name }}
                                            </span>
                                            <span
                                                v-if="printer.is_default"
                                                class="status-chip"
                                            >Default</span>
                                            <span
                                                v-if="!printer.enabled"
                                                class="status-chip opacity-60"
                                            >Disabled</span>
                                            <span
                                                v-else
                                                class="signal-dot signal-dot--live"
                                            />
                                        </div>
                                    </td>

                                    <td>
                                        <span class="status-chip">
                                            {{ connectionBadge[printer.connection_mode] || printer.connection_mode }}
                                        </span>
                                    </td>

                                    <td>
                                        <span class="text-body-sm text-body font-mono-num">
                                            {{ printer.driver_language.toUpperCase() }}
                                        </span>
                                    </td>

                                    <td>
                                        <span class="text-caption text-muted font-mono-num">
                                            {{ printer.connection_target || '—' }}
                                        </span>
                                    </td>

                                    <td>
                                        <div class="flex items-center justify-end gap-1">
                                            <button
                                                v-if="permissions.is_admin && !printer.is_default"
                                                type="button"
                                                class="btn btn-ghost btn-xs"
                                                title="Set as default"
                                                @click="setDefault(printer)"
                                            >
                                                Default
                                            </button>

                                            <Link
                                                v-if="permissions.is_admin"
                                                :href="route('print-smb.edit', printer.id)"
                                                class="btn btn-ghost btn-xs"
                                            >
                                                Edit
                                            </Link>

                                            <button
                                                v-if="permissions.can_execute && printer.enabled"
                                                type="button"
                                                class="btn btn-secondary btn-xs"
                                                @click="testPrint(printer)"
                                            >
                                                Test
                                            </button>

                                            <button
                                                v-if="permissions.is_admin"
                                                type="button"
                                                class="btn btn-ghost btn-xs text-error"
                                                @click="removePrinter(printer)"
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section v-if="recentJobs.length > 0" class="panel-card p-5">
                    <div class="eyebrow">Recent Activity</div>
                    <div class="data-list mt-4">
                        <div v-for="job in recentJobs" :key="job.id" class="data-list__row">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="status-chip">{{ job.result }}</span>
                                    <span class="text-caption text-muted">{{ job.created_at }}</span>
                                </div>
                                <p class="text-body-sm text-body mt-2">
                                    {{ job.payload?.printer_name || 'Test Print' }}
                                </p>
                                <p v-if="job.error_message" class="text-caption text-error mt-1">
                                    {{ job.error_message }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </div>

            <aside class="space-y-6 xl:self-start">
                <section class="panel-subtle p-5 xl:sticky xl:top-28">
                    <div class="eyebrow">Transport Status</div>
                    <div class="data-list mt-5">
                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">SMB (smbclient)</div>
                                <p
                                    class="text-body-sm mt-2"
                                    :class="transport.smbclientAvailable ? 'text-body' : 'text-error'"
                                >
                                    {{ transport.smbclientAvailable ? 'Available' : 'Not installed' }}
                                </p>
                            </div>
                        </div>
                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">LAN Raw TCP</div>
                                <p
                                    class="text-body-sm mt-2"
                                    :class="transport.rawTcpAvailable ? 'text-body' : 'text-error'"
                                >
                                    {{ transport.rawTcpAvailable ? 'Available' : 'Unavailable' }}
                                </p>
                            </div>
                        </div>
                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Template</div>
                                <p class="text-body-sm text-body mt-2">
                                    Thermal 50×15mm · Name + metadata + QR
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
