<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ThemeSwitcher from '@/Components/ThemeSwitcher.vue';

const page = usePage();
const isAdmin = computed(() => page.props.auth.permissions?.is_admin ?? false);
const props = defineProps({
    runtimeServices: { type: Object, default: () => ({}) },
});

const proxyService = ref(props.runtimeServices.ssh_terminal_proxy ?? null);
const runtimeBusyAction = ref('');
const runtimeFlash = ref('');
const runtimeError = ref('');
const proxyActions = [
    { id: 'start', label: 'Start Proxy', buttonClass: 'btn-primary' },
    { id: 'restart', label: 'Restart Proxy', buttonClass: 'btn-secondary' },
    { id: 'stop', label: 'Stop Proxy', buttonClass: 'btn-ghost' },
    { id: 'refresh', label: 'Refresh Status', buttonClass: 'btn-ghost' },
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
                <div class="eyebrow">Runtime</div>
                <h2 class="text-title-lg text-body mt-3">Proxy Control</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Jalankan atau restart `ssh-terminal-proxy` langsung dari UI admin jika perlu akses terminal Headscale.
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
                            :disabled="runtimeBusyAction !== ''"
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
