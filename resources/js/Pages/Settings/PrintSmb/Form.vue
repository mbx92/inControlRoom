<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    printer: { type: Object, default: null },
    driverOptions: { type: Array, required: true },
    connectionModeOptions: { type: Array, required: true },
    mode: { type: String, required: true }, // 'create' | 'edit'
});

const isEdit = computed(() => props.mode === 'edit');

const form = useForm({
    display_name: props.printer?.display_name ?? '',
    enabled: props.printer?.enabled ?? true,
    is_default: props.printer?.is_default ?? false,
    connection_mode: props.printer?.connection_mode ?? 'smb',
    smb_host: props.printer?.smb_host ?? '',
    share_name: props.printer?.share_name ?? '',
    lan_port: props.printer?.lan_port ?? 9100,
    username: props.printer?.username ?? '',
    password: '',
    domain: props.printer?.domain ?? '',
    driver_language: props.printer?.driver_language ?? 'zpl',
});

const usesSmb = computed(() => form.connection_mode === 'smb');
const usesRawTcp = computed(() => form.connection_mode === 'raw_tcp');

function submit() {
    if (isEdit.value) {
        form.put(route('print-smb.update', props.printer.id));
    } else {
        form.post(route('print-smb.store'));
    }
}
</script>

<template>
    <div class="space-y-8">
        <PageHeader
            :title="isEdit ? 'Edit Printer' : 'Add Printer'"
            :subtitle="isEdit
                ? 'Ubah konfigurasi printer thermal label.'
                : 'Daftarkan printer thermal label baru untuk cetak label asset.'"
            eyebrow="Label Printer"
        >
            <template #meta>
                <span v-if="isEdit" class="status-chip">{{ printer.display_name }}</span>
                <span class="status-chip">{{ usesSmb ? 'SMB' : 'LAN TCP' }}</span>
                <span class="status-chip">{{ form.driver_language.toUpperCase() }}</span>
            </template>
        </PageHeader>

        <section class="panel-card p-6 lg:p-7 max-w-3xl">
            <form class="space-y-6" @submit.prevent="submit">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label class="form-label" for="printer-name">Printer Name</label>
                        <input
                            id="printer-name"
                            v-model="form.display_name"
                            type="text"
                            class="input mt-2 w-full"
                            :class="{ 'input-error': form.errors.display_name }"
                            placeholder="Zebra ZD421 - Warehouse"
                            required
                        />
                        <p v-if="form.errors.display_name" class="form-error">{{ form.errors.display_name }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="printer-connection">Connection Mode</label>
                        <select
                            id="printer-connection"
                            v-model="form.connection_mode"
                            class="select mt-2 w-full"
                            :class="{ 'select-error': form.errors.connection_mode }"
                            required
                        >
                            <option v-for="opt in connectionModeOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                        <p v-if="form.errors.connection_mode" class="form-error">{{ form.errors.connection_mode }}</p>
                    </div>
                </div>

                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label class="form-label" for="printer-driver">Driver Language</label>
                        <select
                            id="printer-driver"
                            v-model="form.driver_language"
                            class="select mt-2 w-full"
                            :class="{ 'select-error': form.errors.driver_language }"
                            required
                        >
                            <option v-for="opt in driverOptions" :key="opt.value" :value="opt.value">
                                {{ opt.label }}
                            </option>
                        </select>
                        <p class="text-body-sm text-muted mt-2">
                            <strong>TSPL</strong> untuk TSC / Gprinter; <strong>ZPL</strong> untuk Zebra.
                        </p>
                        <p v-if="form.errors.driver_language" class="form-error">{{ form.errors.driver_language }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="printer-host">
                            {{ usesRawTcp ? 'Printer IP / Host' : 'SMB Host' }}
                        </label>
                        <input
                            id="printer-host"
                            v-model="form.smb_host"
                            type="text"
                            class="input mt-2 w-full font-mono-num"
                            :class="{ 'input-error': form.errors.smb_host }"
                            :placeholder="usesRawTcp ? '192.168.1.50' : 'PRINT-SRV-01'"
                            required
                        />
                        <p v-if="form.errors.smb_host" class="form-error">{{ form.errors.smb_host }}</p>
                    </div>
                </div>

                <template v-if="usesSmb">
                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="form-label" for="printer-share">Share Name</label>
                            <input
                                id="printer-share"
                                v-model="form.share_name"
                                type="text"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors.share_name }"
                                placeholder="ZEBRA-ZD421"
                                required
                            />
                            <p v-if="form.errors.share_name" class="form-error">{{ form.errors.share_name }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="printer-user">Username</label>
                            <input
                                id="printer-user"
                                v-model="form.username"
                                type="text"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors.username }"
                                placeholder="svc-print"
                                required
                            />
                            <p v-if="form.errors.username" class="form-error">{{ form.errors.username }}</p>
                        </div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="form-label" for="printer-domain">Domain</label>
                            <input
                                id="printer-domain"
                                v-model="form.domain"
                                type="text"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors.domain }"
                                placeholder="WORKGROUP"
                            />
                            <p v-if="form.errors.domain" class="form-error">{{ form.errors.domain }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="printer-password">Password</label>
                            <input
                                id="printer-password"
                                v-model="form.password"
                                type="password"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors.password }"
                                :placeholder="isEdit && printer.has_saved_password
                                    ? 'Leave blank to keep current password'
                                    : 'SMB printer password'"
                            />
                            <p v-if="form.errors.password" class="form-error">{{ form.errors.password }}</p>
                        </div>
                    </div>
                </template>

                <template v-if="usesRawTcp">
                    <div>
                        <label class="form-label" for="printer-port">TCP Port</label>
                        <input
                            id="printer-port"
                            v-model.number="form.lan_port"
                            type="number"
                            min="1"
                            max="65535"
                            class="input mt-2 w-full font-mono-num"
                            :class="{ 'input-error': form.errors.lan_port }"
                            placeholder="9100"
                        />
                        <p class="text-body-sm text-muted mt-2">
                            Port standar raw printing: <strong>9100</strong>.
                        </p>
                        <p v-if="form.errors.lan_port" class="form-error">{{ form.errors.lan_port }}</p>
                    </div>
                </template>

                <div class="flex flex-col gap-4 pt-2">
                    <label class="flex cursor-pointer items-center gap-3">
                        <input v-model="form.enabled" type="checkbox" class="toggle toggle-primary" />
                        <span class="text-body-sm text-muted">Printer aktif untuk job label asset</span>
                    </label>

                    <label class="flex cursor-pointer items-center gap-3">
                        <input v-model="form.is_default" type="checkbox" class="toggle toggle-primary" />
                        <span class="text-body-sm text-muted">
                            {{ isEdit && printer.is_default
                                ? 'Printer ini adalah default saat ini'
                                : 'Jadikan sebagai printer default untuk cetak label asset' }}
                        </span>
                    </label>
                </div>

                <div class="flex flex-wrap gap-3 pt-2">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :class="{ loading: form.processing }"
                        :disabled="form.processing"
                    >
                        {{ isEdit ? 'Save Changes' : 'Add Printer' }}
                    </button>
                    <Link :href="route('print-smb.index')" class="btn btn-ghost">
                        Cancel
                    </Link>
                </div>
            </form>
        </section>
    </div>
</template>
