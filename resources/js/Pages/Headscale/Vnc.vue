<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    integration: { type: Object, required: true },
    vncVaultEntries: { type: Array, default: () => [] },
    initialTarget: { type: Object, default: () => ({ name: '', host: '', port: 5900 }) },
});

const desktopHostRef = ref(null);
const desktopPanelRef = ref(null);
const connectionStatus = ref('Idle');
const connectionError = ref('');
const connected = ref(false);
const authModalVisible = ref(false);
const desktopFullscreen = ref(false);

const vncForm = reactive({
    host: props.initialTarget.host ?? '',
    node_name: props.initialTarget.name ?? '',
    port: props.initialTarget.port ?? 5900,
    vault_entry_id: '',
    view_only: false,
});

const selectedVaultEntry = computed(() => (
    props.vncVaultEntries.find((entry) => entry.id === vncForm.vault_entry_id) ?? null
));

let rfb = null;
let RFBModule = null;

function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? match[1] : null;
}

async function ensureRfb() {
    if (RFBModule) {
        return;
    }

    const module = await import('@novnc/novnc');
    RFBModule = module.default ?? module;
}

async function resolveVncPayload(resolveUrl) {
    const response = await fetch(resolveUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(readCookie('XSRF-TOKEN') ?? ''),
        },
    });

    const payload = await response.json();

    if (!response.ok) {
        throw new Error(payload.message ?? 'Unable to resolve VNC target.');
    }

    return payload;
}

function destroyRfb() {
    if (!rfb) {
        return;
    }

    rfb.disconnect();
    rfb = null;
}

async function startVncSession() {
    connectionError.value = '';
    connectionStatus.value = 'Requesting session';

    if (!vncForm.host || !vncForm.vault_entry_id) {
        connectionError.value = 'Host and VNC password secret are required.';
        return false;
    }

    await ensureRfb();
    destroyRfb();

    try {
        const response = await fetch(route('headscale.vnc.create', props.integration.id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(readCookie('XSRF-TOKEN') ?? ''),
            },
            body: JSON.stringify({
                host: vncForm.host,
                node_name: vncForm.node_name,
                port: Number(vncForm.port || 5900),
                vault_entry_id: vncForm.vault_entry_id,
                view_only: vncForm.view_only,
            }),
        });

        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message ?? 'Unable to create VNC session.');
        }

        const resolved = await resolveVncPayload(payload.proxy_resolve_url);
        const wsUrl = new URL(payload.proxy_websocket_url);
        wsUrl.searchParams.set('resolve', payload.proxy_resolve_url);

        rfb = new RFBModule(desktopHostRef.value, wsUrl.toString(), {
            credentials: { password: resolved.password ?? '' },
        });
        rfb.viewOnly = Boolean(vncForm.view_only);
        rfb.scaleViewport = true;
        rfb.resizeSession = true;
        rfb.background = '#020617';
        rfb.clipViewport = false;
        rfb.qualityLevel = 6;
        rfb.compressionLevel = 2;
        rfb.focusOnClick = true;

        rfb.addEventListener('connect', () => {
            connected.value = true;
            connectionStatus.value = 'Connected';
        });

        rfb.addEventListener('disconnect', (event) => {
            connected.value = false;
            connectionStatus.value = event.detail?.clean ? 'Closed' : 'Disconnected';
        });

        rfb.addEventListener('credentialsrequired', () => {
            connectionStatus.value = 'Credentials required';
            if (resolved.password) {
                rfb.sendCredentials({ password: resolved.password });
            }
        });

        rfb.addEventListener('securityfailure', (event) => {
            connected.value = false;
            connectionStatus.value = 'Security failure';
            connectionError.value = event.detail?.reason ?? 'VNC security handshake failed.';
        });

        connectionStatus.value = 'Connecting';
        return true;
    } catch (error) {
        connected.value = false;
        connectionStatus.value = 'Failed';
        connectionError.value = error instanceof Error ? error.message : 'Unable to start VNC session.';
        return false;
    }
}

async function submitAuthentication() {
    const ok = await startVncSession();

    if (ok) {
        authModalVisible.value = false;
    }
}

async function toggleDesktopFullscreen() {
    if (!desktopPanelRef.value) {
        return;
    }

    if (document.fullscreenElement === desktopPanelRef.value) {
        await document.exitFullscreen();
        return;
    }

    await desktopPanelRef.value.requestFullscreen();
}

function syncDesktopFullscreenState() {
    desktopFullscreen.value = document.fullscreenElement === desktopPanelRef.value;
}

function reconnectDesktop() {
    startVncSession();
}

watch(selectedVaultEntry, () => {
    connectionError.value = '';
});

onMounted(async () => {
    await nextTick();
    authModalVisible.value = true;
    document.addEventListener('fullscreenchange', syncDesktopFullscreenState);
});

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', syncDesktopFullscreenState);
    destroyRfb();
});
</script>

<template>
    <Head :title="`Remote Desktop ${initialTarget.name || integration.name}`" />

    <AppLayout>
        <PageHeader
            :title="initialTarget.name || integration.name"
            subtitle="Remote Desktop"
            eyebrow="Mesh Control"
        >
            <template #meta>
                <span class="status-chip">
                    <span class="signal-dot" :class="connected ? 'signal-dot--live' : 'signal-dot--warning'" />
                    {{ connected ? 'Live session' : connectionStatus }}
                </span>
                <span class="status-chip font-mono-num">{{ vncForm.host || 'No host selected' }}:{{ vncForm.port }}</span>
            </template>

            <template #actions>
                <button type="button" class="btn btn-primary" @click="authModalVisible = true">
                    Connection
                </button>
                <Link :href="route('headscale.show', integration.id)" class="btn btn-ghost">
                    Back to Headscale
                </Link>
            </template>
        </PageHeader>

        <section ref="desktopPanelRef" class="panel-card p-6 vnc-shell" :class="{ 'vnc-shell--fullscreen': desktopFullscreen }">
            <div class="flex items-center justify-between gap-3">
                <div class="text-body-sm text-muted">
                    {{ connected ? 'Desktop connected' : 'Desktop not connected' }}
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn btn-ghost btn-sm" @click="toggleDesktopFullscreen">
                        {{ desktopFullscreen ? 'Exit Full Screen' : 'Full Screen' }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="reconnectDesktop">
                        Reconnect
                    </button>
                    <div class="status-chip">
                        <span class="signal-dot" :class="connected ? 'signal-dot--live' : 'signal-dot--warning'" />
                        {{ connected ? 'Live' : 'Idle' }}
                    </div>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-3 text-caption text-muted">
                <span>{{ vncForm.node_name || 'No node label' }}</span>
                <span class="font-mono-num">{{ vncForm.host || 'host' }}:{{ vncForm.port }}</span>
                <span>{{ selectedVaultEntry?.name || 'No VNC secret selected' }}</span>
                <span>{{ vncForm.view_only ? 'View only' : 'Interactive' }}</span>
            </div>

            <div v-if="connectionError" class="mt-4 rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-error">
                {{ connectionError }}
            </div>

            <div ref="desktopHostRef" class="headscale-vnc mt-4" />
        </section>

        <div v-if="authModalVisible" class="vnc-auth-modal">
            <div class="vnc-auth-modal__backdrop" @click="authModalVisible = false" />
            <div class="vnc-auth-modal__panel panel-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Connection</div>
                        <h2 class="text-title-md text-body mt-2">noVNC Session Setup</h2>
                    </div>

                    <button type="button" class="btn btn-ghost btn-sm" @click="authModalVisible = false">
                        Close
                    </button>
                </div>

                <form class="mt-5 space-y-4" @submit.prevent="submitAuthentication">
                    <div>
                        <label class="form-label" for="vnc-node-name">Label</label>
                        <input
                            id="vnc-node-name"
                            v-model="vncForm.node_name"
                            type="text"
                            class="input mt-2 w-full"
                            placeholder="Desktop target label"
                        />
                    </div>

                    <div>
                        <label class="form-label" for="vnc-host">Host</label>
                        <input
                            id="vnc-host"
                            v-model="vncForm.host"
                            type="text"
                            class="input mt-2 w-full font-mono-num"
                            placeholder="10.10.20.15"
                            required
                        />
                        <p class="text-body-sm text-muted mt-2">
                            Bisa isi IP node Tailscale atau host di subnet route Headscale.
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="vnc-port">Port</label>
                            <input
                                id="vnc-port"
                                v-model="vncForm.port"
                                type="number"
                                min="1"
                                max="65535"
                                class="input mt-2 w-full"
                                required
                            />
                        </div>

                        <label class="flex items-center gap-3 rounded-lg border border-hairline bg-base-300 px-4 py-3 self-end">
                            <input v-model="vncForm.view_only" type="checkbox" class="checkbox" />
                            <span class="text-body-sm text-body">View only</span>
                        </label>
                    </div>

                    <div>
                        <label class="form-label" for="vnc-vault">VNC Password Secret</label>
                        <select id="vnc-vault" v-model="vncForm.vault_entry_id" class="select mt-2 w-full" required>
                            <option value="">Select vault entry</option>
                            <option v-for="entry in vncVaultEntries" :key="entry.id" :value="entry.id">
                                {{ entry.name }} - {{ entry.kind_label }} - {{ entry.scope_label }}
                            </option>
                        </select>
                    </div>

                    <div v-if="selectedVaultEntry" class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Selected Secret</div>
                        <div class="text-body-sm text-body mt-2">{{ selectedVaultEntry.name }}</div>
                        <div class="text-caption text-muted mt-2">
                            {{ selectedVaultEntry.kind_label }} - {{ selectedVaultEntry.scope_label }}
                        </div>
                    </div>

                    <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                        <div class="text-caption text-muted">Route Note</div>
                        <div class="text-body-sm text-body mt-2">
                            Gunakan host dalam subnet route jika VNC server berada di belakang subnet router Headscale.
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="btn btn-primary">
                            Connect Desktop
                        </button>
                        <button type="button" class="btn btn-ghost" @click="authModalVisible = false">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.vnc-shell--fullscreen {
    width: 100%;
    height: 100%;
    max-width: none;
    margin: 0;
    border-radius: 0;
    background: #020617;
}

.headscale-vnc {
    min-height: 72vh;
    border: 1px solid color-mix(in srgb, var(--color-border) 80%, transparent);
    border-radius: 1rem;
    background: #020617;
    overflow: hidden;
}

:deep(.headscale-vnc canvas) {
    width: 100% !important;
    height: auto !important;
}

.vnc-auth-modal {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
}

.vnc-auth-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgb(2 6 23 / 0.72);
    backdrop-filter: blur(6px);
}

.vnc-auth-modal__panel {
    position: relative;
    width: min(100%, 640px);
    max-height: calc(100vh - 3rem);
    overflow: auto;
}
</style>
