<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    availableTypes: { type: Object, required: true },
    sites: { type: Array, default: () => [] },
    vaultEntries: { type: Array, default: () => [] },
    inventoryAssets: { type: Array, default: () => [] },
});

const form = useForm({
    site_id: '',
    type: 'proxmox',
    name: '',
    base_url: '',
    vault_entry_id: '',
    config: {
        verify_ssl: true,
        auth_mode: 'none',
        health_path: '/health',
        health_method: 'GET',
        health_expected_status: 200,
        host_asset_id: '',
        username: '',
    },
});

const selectedVaultEntry = computed(() => (
    props.vaultEntries.find((entry) => entry.id === form.vault_entry_id) ?? null
));

const isProxmox = computed(() => form.type === 'proxmox');
const isDocker = computed(() => form.type === 'docker');
const isNvr = computed(() => form.type === 'nvr');
const isHeadscale = computed(() => form.type === 'headscale');
const requiresVaultEntry = computed(() => (
    isProxmox.value || isNvr.value || isHeadscale.value || form.config.auth_mode === 'bearer'
));

const hostAssetOptions = computed(() => (
    props.inventoryAssets.filter((asset) => asset.site_id === form.site_id)
));

const pageTitle = computed(() => (
    isProxmox.value
        ? 'Add Integration'
        : (isDocker.value
            ? 'Add Docker Host'
            : (isNvr.value
                ? 'Add Hikvision NVR'
                : (isHeadscale.value ? 'Add Headscale' : 'Add Custom API')))
));

const pageSubtitle = computed(() => (
    isProxmox.value
        ? 'Register a Proxmox endpoint and attach it to an internal vault secret instead of typing credentials inline.'
        : (isDocker.value
            ? 'Register a Docker Engine endpoint for read-only health checks, container inventory, and lightweight runtime metrics.'
            : (isNvr.value
                ? 'Register a Hikvision NVR endpoint to monitor camera channels, recording status, and generate RTSP stream links.'
                : (isHeadscale.value
                    ? 'Register a Headscale control server with its domain and API key so operators can validate reachability from the control room.'
                    : 'Register any API-based system, define its health endpoint, and optionally attach a bearer token from vault.')))
));

function submit() {
    form.transform((data) => ({
        ...data,
        site_id: data.site_id || null,
        vault_entry_id: data.vault_entry_id || null,
        config: {
            verify_ssl: data.config.verify_ssl,
            auth_mode: data.config.auth_mode,
            health_path: isDocker.value || isNvr.value ? (isDocker.value ? '/_ping' : '/ISAPI/System/status') : data.config.health_path,
            health_method: isDocker.value || isNvr.value ? 'GET' : data.config.health_method,
            health_expected_status: isDocker.value || isNvr.value ? 200 : Number(data.config.health_expected_status || 200),
            host_asset_id: data.config.host_asset_id || null,
            username: isNvr.value ? data.config.username : '',
        },
    })).post(route('integrations.store'));
}
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout>
        <PageHeader
            :title="pageTitle"
            :subtitle="pageSubtitle"
            eyebrow="Source Mesh"
        >
            <template #meta>
                <span class="status-chip">{{ Object.keys(availableTypes).length }} integration types</span>
                <span class="status-chip">{{ vaultEntries.length }} vault entries available</span>
            </template>
        </PageHeader>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]">
            <section class="panel-card p-6">
                <form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <label class="form-label" for="integration-type">Type</label>
                        <select
                            id="integration-type"
                            v-model="form.type"
                            class="select mt-2 w-full"
                            :class="{ 'select-error': form.errors.type }"
                            required
                        >
                            <option v-for="(label, key) in availableTypes" :key="key" :value="key">
                                {{ label }}
                            </option>
                        </select>
                        <p v-if="form.errors.type" class="form-error">{{ form.errors.type }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="integration-name">Name</label>
                        <input
                            id="integration-name"
                            v-model="form.name"
                            type="text"
                            :placeholder="isProxmox ? 'Production Proxmox' : (isDocker ? 'Docker Host Production' : (isNvr ? 'NVR Lobby Utama' : (isHeadscale ? 'Headscale Primary' : 'Node API Production')))"
                            class="input mt-2 w-full"
                            :class="{ 'input-error': form.errors.name }"
                            required
                        />
                        <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="integration-site">Scope</label>
                        <select
                            id="integration-site"
                            v-model="form.site_id"
                            class="select mt-2 w-full"
                            :class="{ 'select-error': form.errors.site_id }"
                        >
                            <option value="">Global</option>
                            <option v-for="site in sites" :key="site.id" :value="site.id">
                                {{ site.name }} ({{ site.code }})
                            </option>
                        </select>
                        <p class="text-body-sm text-muted mt-2">
                            Use a site scope when the service belongs to a specific operational location.
                        </p>
                        <p v-if="form.errors.site_id" class="form-error">{{ form.errors.site_id }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="integration-base-url">{{ isHeadscale ? 'Domain / Server URL' : 'Base URL' }}</label>
                        <input
                            id="integration-base-url"
                            v-model="form.base_url"
                            type="url"
                            :placeholder="isProxmox ? 'https://proxmox.example.com:8006' : (isDocker ? 'https://docker.example.com:2376' : (isNvr ? 'http://192.168.1.64' : (isHeadscale ? 'https://headscale.example.com' : 'https://api.example.com')))"
                            class="input mt-2 w-full font-mono-num"
                            :class="{ 'input-error': form.errors.base_url }"
                            required
                        />
                        <p v-if="isHeadscale" class="text-body-sm text-muted mt-2">
                            Masukkan domain Headscale. Sistem akan memakai endpoint <span class="font-mono-num">/api/v1/node</span> untuk validasi.
                        </p>
                        <p v-if="form.errors.base_url" class="form-error">{{ form.errors.base_url }}</p>
                    </div>

                    <div v-if="isProxmox || isNvr">
                        <label class="form-label" for="host-asset">Host Machine (Inventory)</label>
                        <select
                            id="host-asset"
                            v-model="form.config.host_asset_id"
                            class="select mt-2 w-full"
                            :class="{ 'select-error': form.errors['config.host_asset_id'] }"
                        >
                            <option value="">Auto-detect from Base URL IP</option>
                            <option v-for="asset in hostAssetOptions" :key="asset.id" :value="asset.id">
                                {{ asset.label }}
                            </option>
                        </select>
                        <p class="text-body-sm text-muted mt-2">
                            {{ isProxmox ? 'Link this Proxmox instance to the physical machine it runs on (e.g. Mini PC). Used in topology view.' : 'Link this NVR to its physical device record in inventory. Used in topology view.' }}
                        </p>
                        <p v-if="form.errors['config.host_asset_id']" class="form-error">{{ form.errors['config.host_asset_id'] }}</p>
                    </div>

                    <!-- NVR credentials -->
                    <div v-if="isNvr" class="divider text-caption">NVR Access</div>

                    <div v-if="isNvr">
                        <label class="form-label" for="nvr-username">Camera Username</label>
                        <input
                            id="nvr-username"
                            v-model="form.config.username"
                            type="text"
                            placeholder="admin"
                            class="input mt-2 w-full"
                            :class="{ 'input-error': form.errors['config.username'] }"
                            required
                        />
                        <p class="text-body-sm text-muted mt-2">
                            Hikvision ISAPI login username. The password must be stored in a vault entry.
                        </p>
                        <p v-if="form.errors['config.username']" class="form-error">{{ form.errors['config.username'] }}</p>
                    </div>

                    <div v-if="!isProxmox && !isNvr && !isHeadscale" class="divider text-caption">{{ isDocker ? 'Docker Access' : 'API Health' }}</div>

                    <div v-if="isHeadscale" class="divider text-caption">Headscale Access</div>

                    <div v-if="!isProxmox && !isDocker && !isNvr && !isHeadscale" class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="health-path">Health Path</label>
                            <input
                                id="health-path"
                                v-model="form.config.health_path"
                                type="text"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors['config.health_path'] }"
                                placeholder="/health"
                                required
                            />
                            <p v-if="form.errors['config.health_path']" class="form-error">{{ form.errors['config.health_path'] }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="health-method">Method</label>
                            <select id="health-method" v-model="form.config.health_method" class="select mt-2 w-full">
                                <option value="GET">GET</option>
                                <option value="POST">POST</option>
                                <option value="HEAD">HEAD</option>
                            </select>
                        </div>

                        <div>
                            <label class="form-label" for="expected-status">Expected HTTP Status</label>
                            <input
                                id="expected-status"
                                v-model="form.config.health_expected_status"
                                type="number"
                                min="100"
                                max="599"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors['config.health_expected_status'] }"
                            />
                            <p v-if="form.errors['config.health_expected_status']" class="form-error">{{ form.errors['config.health_expected_status'] }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="auth-mode">Auth Mode</label>
                            <select id="auth-mode" v-model="form.config.auth_mode" class="select mt-2 w-full">
                                <option value="none">None</option>
                                <option value="bearer">Bearer Token</option>
                            </select>
                        </div>
                    </div>

                    <div v-if="isDocker" class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="auth-mode">Auth Mode</label>
                            <select id="auth-mode" v-model="form.config.auth_mode" class="select mt-2 w-full">
                                <option value="none">None</option>
                                <option value="bearer">Bearer Token</option>
                            </select>
                        </div>

                        <div class="rounded-xl border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                            Docker MVP reads only <span class="text-body font-mono-num">/_ping</span>, <span class="text-body font-mono-num">/containers/json</span>, <span class="text-body font-mono-num">/containers/{id}/json</span>, and <span class="text-body font-mono-num">/containers/{id}/stats?stream=false</span>.
                        </div>
                    </div>

                    <div v-if="isHeadscale" class="rounded-xl border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                        Headscale memakai Bearer API key. Simpan key di Vault lalu pilih di field bawah. Health check default mengarah ke <span class="text-body font-mono-num">/api/v1/node</span>.
                    </div>

                    <div>
                        <label class="form-label" for="integration-vault-entry">{{ isHeadscale ? 'Headscale API Key' : 'Vault Secret' }}</label>
                        <select
                            id="integration-vault-entry"
                            v-model="form.vault_entry_id"
                            class="select mt-2 w-full"
                            :class="{ 'select-error': form.errors.vault_entry_id }"
                            :required="requiresVaultEntry"
                        >
                            <option value="">No vault secret</option>
                            <option v-for="entry in vaultEntries" :key="entry.id" :value="entry.id">
                                {{ entry.name }} · {{ entry.kind_label }} · {{ entry.scope_label }}
                            </option>
                        </select>
                        <p class="text-body-sm text-muted mt-2">
                            {{ isProxmox
                                ? 'Proxmox always requires a vault entry containing its API token.'
                                : (isNvr
                                    ? 'Hikvision NVR requires a vault entry containing the camera password (stored as generic secret).'
                                    : (isHeadscale
                                        ? 'Headscale requires a vault entry containing the API key. The key is not entered directly here so it stays encrypted.'
                                        : (isDocker
                                            ? 'Optional for open Docker endpoints. Required only when your proxy expects a bearer token.'
                                            : 'Optional for open health endpoints. Required only when auth mode uses a bearer token.'))) }}
                        </p>
                        <p v-if="form.errors.vault_entry_id" class="form-error">{{ form.errors.vault_entry_id }}</p>
                    </div>

                    <label class="mt-2 flex items-center gap-3 cursor-pointer">
                        <input v-model="form.config.verify_ssl" type="checkbox" class="checkbox checkbox-primary" />
                        <span class="text-body-sm text-muted">
                            {{ isProxmox ? 'Verify SSL certificate during Proxmox API checks' : (isDocker ? 'Verify SSL certificate during Docker API checks' : (isNvr ? 'Verify SSL certificate during NVR API checks' : (isHeadscale ? 'Verify SSL certificate during Headscale API checks' : 'Verify SSL certificate during API health checks'))) }}
                        </span>
                    </label>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :class="{ loading: form.processing }"
                            :disabled="form.processing"
                        >
                            Create Integration
                        </button>
                        <Link :href="route('integrations.index')" class="btn btn-ghost">Cancel</Link>
                        <Link :href="route('vault.create')" class="btn btn-secondary">Add Vault Entry</Link>
                    </div>
                </form>
            </section>

            <aside class="space-y-6 xl:self-start">
                <section class="panel-subtle p-5 xl:sticky xl:top-28">
                    <div class="eyebrow">Provisioning Notes</div>
                    <div class="mt-4 text-title-sm text-body">{{ availableTypes[form.type] }}</div>
                    <p class="text-body-sm text-muted mt-3">
                        {{ isProxmox
                            ? 'Choose a vault-backed API token and InfraControl will use it for inventory plus API health checks.'
                            : (isNvr
                                ? 'Enter the camera username and attach a vault entry with the password. InfraControl will use Hikvision ISAPI for health checks, channel inventory, and recording status.'
                                : (isHeadscale
                                    ? 'Use the Headscale base domain and attach a vault entry that contains the API key. InfraControl validates the control server through its REST API.'
                                    : (isDocker
                                        ? 'Use Docker for a single host endpoint when the main need is container health, runtime inventory, and basic CPU/memory telemetry.'
                                        : 'Use Custom API for Node services or other internal systems where the main need is endpoint reachability and authentication health.'))) }}
                    </p>

                    <div v-if="selectedVaultEntry" class="mt-5 rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Selected Vault Entry</div>
                        <div class="text-body-sm text-body mt-2">{{ selectedVaultEntry.name }}</div>
                        <div class="text-caption text-muted mt-2">
                            {{ selectedVaultEntry.kind_label }} · {{ selectedVaultEntry.scope_label }}
                        </div>
                    </div>

                    <div class="data-list mt-5">
                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Health Strategy</div>
                                <p class="text-body-sm text-muted mt-2">
                                    {{ isDocker
                                        ? 'Point this integration to a read-only Docker API or proxy endpoint. The built-in health probe always targets /_ping.'
                                        : (isNvr
                                            ? 'Point this integration to your Hikvision NVR IP address (e.g., http://192.168.1.64). Health probe uses /ISAPI/System/status.'
                                            : (isHeadscale
                                                ? 'Point this integration to your Headscale domain. InfraControl appends /api/v1/node and sends the vault-backed API key as Bearer auth.'
                                                : 'Point health checks to a stable endpoint such as /health or /api/health.')) }}
                                </p>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Scope Match</div>
                                <p class="text-body-sm text-muted mt-2">
                                    Site-scoped integrations can only attach to global vault entries or vault entries from the same site.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
