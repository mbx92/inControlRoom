<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import 'xterm/css/xterm.css';

const page = usePage();

const props = defineProps({
    integration: { type: Object, required: true },
    sshVaultEntries: { type: Array, default: () => [] },
    initialNode: { type: Object, default: () => ({ name: '', host: '' }) },
});

const terminalHostRef = ref(null);
const terminalPanelRef = ref(null);
const terminalStatus = ref('Idle');
const terminalError = ref('');
const terminalConnected = ref(false);
const authModalVisible = ref(false);
const terminalFullscreen = ref(false);

const terminalForm = reactive({
    host: props.initialNode.host ?? '',
    node_name: props.initialNode.name ?? '',
    username: 'root',
    port: 22,
    vault_entry_id: '',
    auth_type: 'private_key',
});

let terminal = null;
let fitAddon = null;
let socket = null;

const selectedVaultEntry = computed(() => (
    props.sshVaultEntries.find((entry) => entry.id === terminalForm.vault_entry_id) ?? null
));

watch(selectedVaultEntry, (entry) => {
    if (entry?.suggested_auth_type) {
        terminalForm.auth_type = entry.suggested_auth_type;
    }
});

async function ensureTerminal() {
    if (terminal && fitAddon) {
        return;
    }

    const [{ Terminal }, { FitAddon }] = await Promise.all([
        import('xterm'),
        import('xterm-addon-fit'),
    ]);

    terminal = new Terminal({
        cursorBlink: true,
        fontFamily: 'Consolas, "Courier New", monospace',
        fontSize: 13,
        rows: 24,
        theme: {
            background: '#0b1220',
            foreground: '#d7e3f4',
            cursor: '#fcd535',
            black: '#0b1220',
            red: '#ef4444',
            green: '#22c55e',
            yellow: '#f59e0b',
            blue: '#38bdf8',
            magenta: '#a855f7',
            cyan: '#14b8a6',
            white: '#e5eefb',
        },
    });

    fitAddon = new FitAddon();
    terminal.loadAddon(fitAddon);
    terminal.open(terminalHostRef.value);
    fitAddon.fit();

    terminal.onData((data) => {
        if (!socket || socket.readyState !== WebSocket.OPEN) {
            return;
        }

        socket.send(JSON.stringify({
            type: 'input',
            data,
        }));
    });
}

async function startTerminalSession() {
    terminalError.value = '';
    terminalStatus.value = 'Requesting session';

    if (!terminalForm.host || !terminalForm.username || !terminalForm.vault_entry_id) {
        terminalError.value = 'Host, SSH username, and Vault secret are required.';
        return false;
    }

    await ensureTerminal();
    terminal?.clear();
    terminal?.writeln(`Connecting to ${terminalForm.username}@${terminalForm.host}:${terminalForm.port} ...`);

    try {
        const response = await fetch(route('headscale.terminal.create', props.integration.id), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': decodeURIComponent(readCookie('XSRF-TOKEN') ?? ''),
            },
            body: JSON.stringify({
                host: terminalForm.host,
                node_name: terminalForm.node_name,
                username: terminalForm.username,
                port: Number(terminalForm.port || 22),
                vault_entry_id: terminalForm.vault_entry_id,
                auth_type: terminalForm.auth_type,
            }),
        });

        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.message ?? 'Unable to create terminal session.');
        }

        connectSocket(payload.proxy_websocket_url, payload.proxy_resolve_url);
        return true;
    } catch (error) {
        terminalStatus.value = 'Failed';
        terminalError.value = error instanceof Error ? error.message : 'Unable to create terminal session.';
        terminal?.writeln(`\r\n[error] ${terminalError.value}`);
        return false;
    }
}

async function submitAuthentication() {
    const connected = await startTerminalSession();

    if (connected) {
        authModalVisible.value = false;
    }
}

function connectSocket(websocketUrl, resolveUrl) {
    destroySocket();

    const url = new URL(websocketUrl);
    url.searchParams.set('resolve', resolveUrl);

    terminalStatus.value = 'Opening SSH transport';
    socket = new WebSocket(url);

    socket.addEventListener('open', () => {
        terminalConnected.value = true;
        terminalStatus.value = 'Connected';
        fitAddon?.fit();
        socket?.send(JSON.stringify({
            type: 'resize',
            cols: terminal?.cols ?? 120,
            rows: terminal?.rows ?? 24,
        }));
    });

    socket.addEventListener('message', (event) => {
        try {
            const payload = JSON.parse(event.data);

            if (payload.type === 'data' && typeof payload.data === 'string') {
                terminal?.write(payload.data);
            }
        } catch {
            terminal?.write(typeof event.data === 'string' ? event.data : '');
        }
    });

    socket.addEventListener('close', () => {
        terminalConnected.value = false;
        terminalStatus.value = 'Closed';
        terminal?.writeln('\r\n[session closed]');
    });

    socket.addEventListener('error', () => {
        terminalConnected.value = false;
        terminalStatus.value = 'Error';
        terminalError.value = 'Web terminal transport failed.';
        terminal?.writeln('\r\n[error] Web terminal transport failed.');
    });
}

function destroySocket() {
    if (!socket) {
        return;
    }

    if (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING) {
        socket.close();
    }

    socket = null;
}

function handleTerminalResize() {
    fitAddon?.fit();

    if (!socket || socket.readyState !== WebSocket.OPEN) {
        return;
    }

    socket.send(JSON.stringify({
        type: 'resize',
        cols: terminal?.cols ?? 120,
        rows: terminal?.rows ?? 24,
    }));
}

async function toggleTerminalFullscreen() {
    if (!terminalPanelRef.value) {
        return;
    }

    if (document.fullscreenElement === terminalPanelRef.value) {
        await document.exitFullscreen();
        return;
    }

    await terminalPanelRef.value.requestFullscreen();
}

function syncTerminalFullscreenState() {
    terminalFullscreen.value = document.fullscreenElement === terminalPanelRef.value;
    setTimeout(() => handleTerminalResize(), 100);
}

function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? match[1] : null;
}

onMounted(async () => {
    await nextTick();
    await ensureTerminal();
    terminal?.writeln(`Terminal ready for ${props.initialNode.name || props.integration.name}.`);
    authModalVisible.value = true;
    document.addEventListener('fullscreenchange', syncTerminalFullscreenState);
});

window.addEventListener('resize', handleTerminalResize);

onBeforeUnmount(() => {
    document.removeEventListener('fullscreenchange', syncTerminalFullscreenState);
    window.removeEventListener('resize', handleTerminalResize);
    destroySocket();
    terminal?.dispose();
});
</script>

<template>
    <Head :title="`Terminal ${initialNode.name || integration.name}`" />

    <AppLayout>
        <PageHeader
            :title="initialNode.name || integration.name"
            subtitle="SSH Terminal"
            eyebrow="Mesh Control"
        >
            <template #meta>
                <span class="status-chip">
                    <span class="signal-dot" :class="terminalConnected ? 'signal-dot--live' : 'signal-dot--warning'" />
                    {{ terminalConnected ? 'Live session' : terminalStatus }}
                </span>
                <span class="status-chip font-mono-num">{{ terminalForm.host || 'No host selected' }}</span>
            </template>

            <template #actions>
                <button type="button" class="btn btn-primary" @click="authModalVisible = true">
                    Authentication
                </button>
                <Link :href="route('headscale.show', integration.id)" class="btn btn-ghost">
                    Back to Headscale
                </Link>
            </template>
        </PageHeader>

        <section ref="terminalPanelRef" class="panel-card p-6 terminal-shell" :class="{ 'terminal-shell--fullscreen': terminalFullscreen }">
            <div class="flex items-center justify-between gap-3">
                <div class="text-body-sm text-muted">
                    {{ terminalConnected ? 'Terminal connected' : 'Terminal not connected' }}
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn btn-ghost btn-sm" @click="toggleTerminalFullscreen">
                        {{ terminalFullscreen ? 'Exit Full Screen' : 'Full Screen' }}
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" @click="handleTerminalResize">
                        Fit Screen
                    </button>
                    <div class="status-chip">
                        <span class="signal-dot" :class="terminalConnected ? 'signal-dot--live' : 'signal-dot--warning'" />
                        {{ terminalConnected ? 'Live' : 'Idle' }}
                    </div>
                </div>
            </div>

            <div class="mt-3 flex flex-wrap gap-3 text-caption text-muted">
                <span>{{ terminalForm.node_name || 'No node label' }}</span>
                <span class="font-mono-num">{{ terminalForm.username || 'user' }}@{{ terminalForm.host || 'host' }}:{{ terminalForm.port }}</span>
                <span>{{ selectedVaultEntry?.name || 'No secret selected' }}</span>
            </div>

            <div v-if="terminalError" class="mt-4 rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-error">
                {{ terminalError }}
            </div>

            <div ref="terminalHostRef" class="headscale-terminal mt-4" />
        </section>

        <div v-if="authModalVisible" class="terminal-auth-modal">
            <div class="terminal-auth-modal__backdrop" @click="authModalVisible = false" />
            <div class="terminal-auth-modal__panel panel-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Authentication</div>
                        <h2 class="text-title-md text-body mt-2">SSH Session Setup</h2>
                    </div>

                    <button type="button" class="btn btn-ghost btn-sm" @click="authModalVisible = false">
                        Close
                    </button>
                </div>

                <form class="mt-5 space-y-4" @submit.prevent="submitAuthentication">
                    <div>
                        <label class="form-label" for="terminal-node-name">Node Label</label>
                        <input
                            id="terminal-node-name"
                            v-model="terminalForm.node_name"
                            type="text"
                            class="input mt-2 w-full"
                            placeholder="Node display name"
                        />
                    </div>

                    <div>
                        <label class="form-label" for="terminal-host">Host</label>
                        <input
                            id="terminal-host"
                            v-model="terminalForm.host"
                            type="text"
                            class="input mt-2 w-full font-mono-num"
                            placeholder="100.x.y.z"
                            required
                        />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="terminal-port">Port</label>
                            <input
                                id="terminal-port"
                                v-model="terminalForm.port"
                                type="number"
                                min="1"
                                max="65535"
                                class="input mt-2 w-full"
                                required
                            />
                        </div>

                        <div>
                            <label class="form-label" for="terminal-username">SSH Username</label>
                            <input
                                id="terminal-username"
                                v-model="terminalForm.username"
                                type="text"
                                class="input mt-2 w-full"
                                placeholder="root"
                                required
                            />
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="terminal-auth-type">Auth Type</label>
                        <select id="terminal-auth-type" v-model="terminalForm.auth_type" class="select mt-2 w-full">
                            <option value="private_key">Private Key</option>
                            <option value="password">Password</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label" for="terminal-vault">SSH Secret</label>
                        <select id="terminal-vault" v-model="terminalForm.vault_entry_id" class="select mt-2 w-full" required>
                            <option value="">Select vault entry</option>
                            <option v-for="entry in sshVaultEntries" :key="entry.id" :value="entry.id">
                                {{ entry.name }} - {{ entry.kind_label }} - {{ entry.scope_label }}
                            </option>
                        </select>
                        <p class="text-body-sm text-muted mt-2">
                            Gunakan `ssh_private_key` untuk key-based login atau `service_password`/`generic_secret` untuk password login.
                        </p>
                    </div>

                    <div v-if="selectedVaultEntry" class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Selected Secret</div>
                        <div class="text-body-sm text-body mt-2">{{ selectedVaultEntry.name }}</div>
                        <div class="text-caption text-muted mt-2">
                            {{ selectedVaultEntry.kind_label }} - {{ selectedVaultEntry.scope_label }}
                        </div>
                    </div>

                    <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                        <div class="text-caption text-muted">Session Status</div>
                        <div class="text-body-sm text-body mt-2">{{ terminalStatus }}</div>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button type="submit" class="btn btn-primary">
                            Connect Terminal
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
.terminal-shell--fullscreen {
    width: 100%;
    height: 100%;
    max-width: none;
    margin: 0;
    border-radius: 0;
    background: #020617;
}

.headscale-terminal {
    min-height: 70vh;
    border: 1px solid color-mix(in srgb, var(--color-border) 80%, transparent);
    border-radius: 1rem;
    padding: 0.75rem;
    background: #0b1220;
    overflow: hidden;
}

.terminal-shell--fullscreen .headscale-terminal {
    min-height: calc(100vh - 10rem);
}

.terminal-auth-modal {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
}

.terminal-auth-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgb(2 6 23 / 0.72);
    backdrop-filter: blur(6px);
}

.terminal-auth-modal__panel {
    position: relative;
    width: min(100%, 640px);
    max-height: calc(100vh - 3rem);
    overflow: auto;
}
</style>
