<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ThemeSwitcher from '@/Components/ThemeSwitcher.vue';

const page = usePage();
const isAdmin = computed(() => page.props.auth.permissions?.is_admin ?? false);
const props = defineProps({
    runtimeServices: { type: Object, default: () => ({}) },
    maintenance: { type: Object, default: () => ({ enabled: false, message: null }) },
    inventoryImportReport: { type: Object, default: null },
    glitchtip: { type: Object, default: () => ({}) },
});

const proxyService = ref(props.runtimeServices.ssh_terminal_proxy ?? null);
const proxyServiceManagedExternally = computed(() => Boolean(proxyService.value?.managed_externally));
const runtimeBusyAction = ref('');
const runtimeFlash = ref('');
const runtimeError = ref('');
const glitchtipForm = useForm({});
const maintenanceForm = useForm({
    enabled: props.maintenance?.enabled ?? false,
    message: props.maintenance?.message ?? '',
});
const importForm = useForm({
    file: null,
});
const importErrorPreview = computed(() => (props.inventoryImportReport?.errors ?? []).slice(0, 6));
const proxyActions = [
    { id: 'start', label: 'Start Proxy', buttonClass: 'btn-primary' },
    { id: 'restart', label: 'Restart Proxy', buttonClass: 'btn-secondary' },
    { id: 'stop', label: 'Stop Proxy', buttonClass: 'btn-ghost' },
    { id: 'refresh', label: 'Refresh Status', buttonClass: 'btn-ghost' },
];
const glitchtipDeploymentChecklist = [
    'Isi DSN backend dan frontend production di environment server.',
    'Set SENTRY_RELEASE ke build atau commit yang sedang dirilis.',
    'Aktifkan source map build lalu upload dengan auth token GlitchTip.',
    'Pastikan security endpoint CSP bisa diakses browser production.',
    'Kirim backend test event, frontend test error, dan CSP test setelah deploy.',
];

function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? match[1] : null;
}

async function parseRuntimeResponse(response) {
    const contentType = response.headers.get('content-type') ?? '';
    const body = await response.text();

    if (contentType.includes('application/json')) {
        return body ? JSON.parse(body) : {};
    }

    const trimmedBody = body.trim();

    if (trimmedBody.startsWith('{') || trimmedBody.startsWith('[')) {
        return JSON.parse(trimmedBody);
    }

    if (trimmedBody.startsWith('<!DOCTYPE') || trimmedBody.startsWith('<html')) {
        throw new Error(`Request failed with HTTP ${response.status}. Server returned an HTML error page.`);
    }

    throw new Error(trimmedBody || `Request failed with HTTP ${response.status}.`);
}

async function runtimeRequest(url) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(readCookie('XSRF-TOKEN') ?? ''),
        },
    });

    const payload = await parseRuntimeResponse(response);

    if (!response.ok) {
        throw new Error(payload.message ?? `Request failed with HTTP ${response.status}.`);
    }

    return payload;
}

async function refreshProxyStatus() {
    runtimeBusyAction.value = 'refresh';
    runtimeError.value = '';

    try {
        const response = await fetch(route('settings.runtime-services.status', 'ssh-terminal-proxy'), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload = await parseRuntimeResponse(response);

        if (!response.ok) {
            throw new Error(payload.message ?? `Request failed with HTTP ${response.status}.`);
        }

        proxyService.value = payload;
    } catch (error) {
        runtimeError.value = error instanceof Error ? error.message : 'Unable to refresh proxy status.';
    } finally {
        runtimeBusyAction.value = '';
    }
}

async function controlProxy(action) {
    runtimeBusyAction.value = action;
    runtimeFlash.value = '';
    runtimeError.value = '';

    try {
        const payload = await runtimeRequest(route(`settings.runtime-services.${action}`, 'ssh-terminal-proxy'));
        proxyService.value = payload.service ?? proxyService.value;
        runtimeFlash.value = payload.message ?? 'Action completed.';
    } catch (error) {
        runtimeError.value = error instanceof Error ? error.message : 'Unable to control SSH proxy.';
    } finally {
        runtimeBusyAction.value = '';
    }
}

function handleImportFileChange(event) {
    importForm.file = event.target.files?.[0] ?? null;
}

function submitImport() {
    importForm.post(route('inventory.import'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            importForm.reset();
            importForm.clearErrors();
        },
    });
}

function sendBackendTestEvent() {
    glitchtipForm.post(route('settings.glitchtip.test'), {
        preserveScroll: true,
    });
}

function submitMaintenance() {
    maintenanceForm.put(route('settings.maintenance.update'), {
        preserveScroll: true,
    });
}

function triggerFrontendTestError() {
    window.setTimeout(() => {
        throw new Error(`InfraControl frontend test error at ${new Date().toISOString()}`);
    }, 0);
}
</script>

<template>
    <Head title="Settings" />

    <AppLayout>
        <PageHeader
            title="Settings"
            subtitle="Theme control dan konfigurasi printer thermal."
            eyebrow="Settings"
        />

        <div class="space-y-8">
            <section v-if="isAdmin">
                <div class="eyebrow">System</div>
                <h2 class="text-title-lg text-body mt-3">Maintenance Mode</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Blokir akses operator dan viewer saat upgrade atau pekerjaan maintenance. Admin tetap bisa masuk dan mengelola sistem.
                    Untuk Laravel <code>php artisan down</code>, gunakan <code>php artisan down --render=errors.503</code> agar halaman 503 memakai styling InfraControl.
                </p>

                <form class="panel-card p-5 space-y-5" @submit.prevent="submitMaintenance">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input
                            v-model="maintenanceForm.enabled"
                            type="checkbox"
                            class="checkbox checkbox-warning mt-1"
                        />
                        <span>
                            <span class="text-body-sm text-body block">Enable maintenance mode</span>
                            <span class="text-caption text-muted mt-1 block">
                                Non-admin users akan melihat halaman under maintenance dan tidak bisa login.
                            </span>
                        </span>
                    </label>

                    <div>
                        <label for="maintenance-message" class="form-label">Public message</label>
                        <textarea
                            id="maintenance-message"
                            v-model="maintenanceForm.message"
                            class="textarea w-full mt-2"
                            rows="3"
                            placeholder="Contoh: Upgrade database pukul 22:00–23:00 WITA. Dashboard sementara tidak tersedia untuk operator."
                            :disabled="!maintenanceForm.enabled"
                        />
                        <p v-if="maintenanceForm.errors.message" class="form-error mt-2">
                            {{ maintenanceForm.errors.message }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="submit"
                            class="btn"
                            :class="maintenanceForm.enabled ? 'btn-warning' : 'btn-primary'"
                            :disabled="maintenanceForm.processing"
                        >
                            {{ maintenanceForm.processing ? 'Saving...' : (maintenanceForm.enabled ? 'Enable Maintenance' : 'Save / Disable Maintenance') }}
                        </button>
                        <span
                            v-if="maintenance.enabled"
                            class="status-chip"
                        >
                            <span class="signal-dot signal-dot--warning" />
                            Maintenance active
                        </span>
                    </div>
                </form>
            </section>

            <hr v-if="isAdmin" class="border-border" />

            <section>
                <div class="eyebrow">Control Plane</div>
                <h2 class="text-title-lg text-body mt-3">Headscale Manager</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Modul khusus untuk domain Headscale, API key dari vault, monitoring node, dan user inventory dari control plane.
                </p>

                <div class="flex flex-wrap gap-3">
                    <Link :href="route('headscale.index')" class="btn btn-primary">
                        Open Headscale Module
                    </Link>
                    <Link :href="route('integrations.index')" class="btn btn-secondary">
                        View All Integrations
                    </Link>
                </div>
            </section>

            <hr class="border-border" />

            <section>
                <div class="eyebrow">Appearance</div>
                <h2 class="text-title-lg text-body mt-3">Theme Control</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Pilih karakter visual yang paling pas untuk ritme kerja tim.
                </p>

                <ThemeSwitcher :framed="false" :show-header="false" />
            </section>

            <hr class="border-border" />

            <section v-if="isAdmin">
                <div class="eyebrow">Observability</div>
                <h2 class="text-title-lg text-body mt-3">GlitchTip</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Error tracking backend Laravel, frontend Vue, dan CSP reporting sekarang diarahkan ke GlitchTip.
                </p>

                <div class="panel-card p-5 space-y-5">
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Backend SDK</div>
                            <div class="mt-2 text-body-sm text-body">{{ glitchtip.enabled ? 'Enabled' : 'Disabled' }}</div>
                        </div>
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Frontend SDK</div>
                            <div class="mt-2 text-body-sm text-body">{{ glitchtip.frontend_enabled ? 'Enabled' : 'Disabled' }}</div>
                        </div>
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Environment</div>
                            <div class="mt-2 text-body-sm text-body">{{ glitchtip.backend_environment ?? '-' }}</div>
                        </div>
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">CSP Mode</div>
                            <div class="mt-2 text-body-sm text-body">{{ glitchtip.csp_report_only ? 'Report Only' : 'Enforced' }}</div>
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Security Endpoint</div>
                            <div class="mt-2 break-all text-body-sm text-body font-mono-num">{{ glitchtip.security_endpoint ?? '-' }}</div>
                        </div>
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Release</div>
                            <div class="mt-2 text-body-sm text-body font-mono-num">{{ glitchtip.release ?? 'Not set' }}</div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="glitchtipForm.processing || !glitchtip.enabled"
                            @click="sendBackendTestEvent"
                        >
                            {{ glitchtipForm.processing ? 'Sending...' : 'Send Backend Test Event' }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-secondary"
                            :disabled="!glitchtip.frontend_enabled"
                            @click="triggerFrontendTestError"
                        >
                            Throw Frontend Test Error
                        </button>
                        <a
                            :href="route('settings.glitchtip.csp-test')"
                            class="btn btn-ghost"
                            target="_blank"
                            rel="noreferrer"
                            :aria-disabled="!glitchtip.security_endpoint"
                            :class="{ 'pointer-events-none opacity-50': !glitchtip.security_endpoint }"
                        >
                            Open CSP Report Test
                        </a>
                    </div>

                    <div class="grid gap-4 xl:grid-cols-[1.15fr_0.85fr]">
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Deploy Checklist</div>
                            <ul class="mt-3 space-y-2 text-body-sm text-body">
                                <li
                                    v-for="item in glitchtipDeploymentChecklist"
                                    :key="item"
                                    class="flex items-start gap-2"
                                >
                                    <span class="mt-1 h-2 w-2 rounded-full bg-primary" />
                                    <span>{{ item }}</span>
                                </li>
                            </ul>
                            <p class="mt-4 text-body-sm text-muted">
                                Detail langkah deploy ada di <code>docs/glitchtip-deployment-checklist.md</code>.
                            </p>
                        </div>

                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Source Map Commands</div>
                            <div class="mt-3 space-y-2 font-mono-num text-body-sm text-body">
                                <div><code>npm run build:sourcemaps</code></div>
                                <div><code>npm run glitchtip:sourcemaps:upload</code></div>
                                <div><code>npm run glitchtip:sourcemaps:build-upload</code></div>
                            </div>
                            <p class="mt-4 text-body-sm text-muted">
                                Isi <code>SENTRY_AUTH_TOKEN</code>, <code>SENTRY_ORG</code>, <code>SENTRY_PROJECT</code>, dan <code>SENTRY_RELEASE</code> sebelum upload.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <hr v-if="isAdmin" class="border-border" />

            <section v-if="isAdmin">
                <div class="eyebrow">Agent</div>
                <h2 class="text-title-lg text-body mt-3">Client Distribution Pack</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Generate enrollment token, pantau agent yang sudah register, dan unduh dokumen rekomendasi distribusi untuk client.
                </p>

                <div class="panel-card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="max-w-3xl">
                            <div class="text-title-sm text-body">InfraControl Agent Enrollment</div>
                            <p class="text-body-sm text-muted mt-2">
                                Kelola enrollment token per site dan pantau device yang sudah berhasil enroll dari satu tempat.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Link :href="route('agents.metrics.index')" class="btn btn-secondary">
                                Open Agent Metrics
                            </Link>
                            <Link :href="route('settings.agents.index')" class="btn btn-primary">
                                Open Agent Enrollment
                            </Link>
                        </div>
                    </div>
                </div>
            </section>

            <hr v-if="isAdmin" class="border-border" />

            <section v-if="isAdmin">
                <div class="eyebrow">Inventory</div>
                <h2 class="text-title-lg text-body mt-3">Excel Import</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Import asset massal dari Excel atau CSV. Jika `asset_tag` cocok dengan data yang sudah ada, asset akan diperbarui.
                </p>

                <div class="panel-card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="max-w-3xl">
                            <div class="text-title-sm text-body">Upload File Inventory</div>
                            <p class="text-body-sm text-muted mt-2">
                                Gunakan template supaya format kolom konsisten untuk `site_code`, status, tanggal, dan custom fields.
                            </p>
                        </div>

                        <a :href="route('inventory.import-template')" class="btn btn-ghost btn-sm">
                            Download Template
                        </a>
                    </div>

                    <form class="mt-5 grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto]" @submit.prevent="submitImport">
                        <div>
                            <label class="form-label" for="settings-inventory-import-file">File Import</label>
                            <input
                                id="settings-inventory-import-file"
                                type="file"
                                accept=".xlsx,.xls,.csv,.txt"
                                class="file-input file-input-bordered mt-2 w-full"
                                :class="{ 'file-input-error': importForm.errors.file }"
                                @change="handleImportFileChange"
                            />
                            <p class="mt-2 text-body-sm text-muted">
                                Kolom utama: `site_code`, `name`, `category`, `status`, `asset_tag`, `serial_number`, `manufacturer`, `model`, `primary_ip`, `location_label`, `owner_name`, `acquired_at`, `warranty_expires_at`, `custom_fields`, `notes`.
                            </p>
                            <p v-if="importForm.errors.file" class="form-error">{{ importForm.errors.file }}</p>
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="btn btn-primary" :disabled="importForm.processing || !importForm.file">
                                {{ importForm.processing ? 'Importing...' : 'Import Excel' }}
                            </button>
                        </div>
                    </form>
                </div>

                <div v-if="inventoryImportReport" class="panel-subtle mt-5 p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <div class="eyebrow">Import Report</div>
                            <h3 class="mt-2 text-title-md text-body">Hasil import terakhir</h3>
                        </div>

                        <div class="status-chip">{{ inventoryImportReport.total_rows }} rows</div>
                    </div>

                    <div class="mt-5 grid gap-4 md:grid-cols-4">
                        <div class="rounded-2xl border border-hairline bg-base-200/70 p-4">
                            <div class="text-body-sm text-muted">Diproses</div>
                            <div class="mt-2 text-title-md text-body">{{ inventoryImportReport.total_rows }}</div>
                        </div>
                        <div class="rounded-2xl border border-hairline bg-base-200/70 p-4">
                            <div class="text-body-sm text-muted">Dibuat</div>
                            <div class="mt-2 text-title-md text-body">{{ inventoryImportReport.created }}</div>
                        </div>
                        <div class="rounded-2xl border border-hairline bg-base-200/70 p-4">
                            <div class="text-body-sm text-muted">Diperbarui</div>
                            <div class="mt-2 text-title-md text-body">{{ inventoryImportReport.updated }}</div>
                        </div>
                        <div class="rounded-2xl border border-hairline bg-base-200/70 p-4">
                            <div class="text-body-sm text-muted">Gagal</div>
                            <div class="mt-2 text-title-md text-body">{{ inventoryImportReport.failed }}</div>
                        </div>
                    </div>

                    <div v-if="importErrorPreview.length" class="mt-5 rounded-2xl border border-rose-400/30 bg-rose-500/8 p-4">
                        <div class="text-body-sm font-medium text-body">Baris yang perlu dicek</div>
                        <ul class="mt-3 space-y-2 text-body-sm text-muted">
                            <li v-for="error in importErrorPreview" :key="error">{{ error }}</li>
                        </ul>
                        <p v-if="(inventoryImportReport.errors?.length ?? 0) > importErrorPreview.length" class="mt-3 text-body-sm text-muted">
                            Menampilkan {{ importErrorPreview.length }} dari {{ inventoryImportReport.errors.length }} error.
                        </p>
                    </div>
                </div>
            </section>

            <hr v-if="isAdmin" class="border-border" />

            <section v-if="isAdmin">
                <div class="eyebrow">Runtime</div>
                <h2 class="text-title-lg text-body mt-3">Proxy Control</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    {{
                        proxyServiceManagedExternally
                            ? 'SSH terminal proxy sedang dikelola sebagai service terpisah. Status tetap bisa dipantau dari sini.'
                            : 'Jalankan atau restart `ssh-terminal-proxy` langsung dari UI admin jika perlu akses terminal Headscale.'
                    }}
                </p>

                <div class="panel-card p-5 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <div class="text-title-sm text-body">{{ proxyService?.label ?? 'SSH Terminal Proxy' }}</div>
                            <div class="text-body-sm text-muted mt-2">{{ proxyService?.message ?? 'Status unavailable.' }}</div>
                        </div>

                        <div class="status-chip">
                            <span class="signal-dot" :class="proxyService?.healthy ? 'signal-dot--live' : 'signal-dot--warning'" />
                            {{ proxyService?.healthy ? 'Running' : 'Stopped' }}
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Health URL</div>
                            <div class="text-body-sm text-body mt-2 font-mono-num break-all">{{ proxyService?.health_url ?? '-' }}</div>
                        </div>
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">PID</div>
                            <div class="text-body-sm text-body mt-2 font-mono-num">{{ proxyService?.pid ?? '-' }}</div>
                        </div>
                    </div>

                    <div
                        v-if="proxyServiceManagedExternally"
                        class="rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-body"
                    >
                        Start, stop, dan restart dilakukan dari Coolify karena proxy berjalan sebagai container terpisah.
                    </div>

                    <div v-if="runtimeFlash" class="rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-body">
                        {{ runtimeFlash }}
                    </div>

                    <div v-if="runtimeError" class="rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-error">
                        {{ runtimeError }}
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <button
                            v-for="action in proxyActions"
                            :key="action.id"
                            type="button"
                            class="btn runtime-action-btn"
                            :class="action.buttonClass"
                            :disabled="runtimeBusyAction !== '' || (proxyServiceManagedExternally && action.id !== 'refresh')"
                            @click="action.id === 'refresh' ? refreshProxyStatus() : controlProxy(action.id)"
                        >
                            <span v-if="runtimeBusyAction === action.id" class="loading loading-spinner loading-xs shrink-0" aria-hidden="true" />
                            <span>{{ action.label }}</span>
                        </button>
                    </div>

                    <div v-if="runtimeBusyAction" class="text-body-sm text-muted">
                        Processing {{ proxyActions.find((action) => action.id === runtimeBusyAction)?.label?.toLowerCase() ?? 'request' }}...
                    </div>
                </div>
            </section>

            <hr v-if="isAdmin" class="border-border" />

            <section>
                <div class="eyebrow">Peripheral</div>
                <h2 class="text-title-lg text-body mt-3">Label Printer</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Konfigurasikan printer thermal via SMB Windows atau LAN raw TCP untuk cetak label asset 50x15mm.
                </p>

                <Link :href="route('print-smb.index')" class="btn btn-primary">
                    Configure Printer
                </Link>
            </section>

            <hr class="border-border" />

            <section v-if="isAdmin">
                <div class="eyebrow">Alerting</div>
                <h2 class="text-title-lg text-body mt-3">Delivery and Rules</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Kelola channel Telegram per-site dan threshold rules untuk health degradation, guest down, dan resource pressure.
                </p>

                <div class="flex flex-wrap gap-3">
                    <Link :href="route('notification-channels.index')" class="btn btn-primary">
                        Notification Channels
                    </Link>
                    <Link :href="route('alert-rules.index')" class="btn btn-secondary">
                        Alert Rules
                    </Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
