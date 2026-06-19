<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    integration: { type: Object, required: true },
    availableTypes: { type: Object, required: true },
    nasVendors: { type: Object, default: () => ({}) },
    sites: { type: Array, default: () => [] },
    vaultEntries: { type: Array, default: () => [] },
    inventoryAssets: { type: Array, default: () => [] },
});

const form = useForm({
    site_id: props.integration.site_id ?? '',
    name: props.integration.name,
    base_url: props.integration.base_url,
    vault_entry_id: props.integration.vault_entry_id ?? '',
    config: {
        verify_ssl: props.integration.config?.verify_ssl ?? true,
        auth_mode: props.integration.config?.auth_mode ?? 'none',
        health_path: props.integration.config?.health_path ?? '/health',
        health_method: props.integration.config?.health_method ?? 'GET',
        health_expected_status: props.integration.config?.health_expected_status ?? 200,
        host_asset_id: props.integration.config?.host_asset_id ?? '',
        username: props.integration.config?.username ?? '',
        vendor: props.integration.config?.vendor ?? 'synology',
    },
    is_active: props.integration.is_active,
});

const railStatus = computed(() => (
    form.is_active ? 'Participating in live orchestration' : 'Configured but held in standby'
));

const connectionHealth = computed(() => {
    if (props.integration.last_test_status === 'success') {
        return {
            label: 'Healthy',
            dotClass: 'signal-dot--live',
        };
    }

    if (props.integration.last_test_status === 'failure') {
        return {
            label: 'Needs attention',
            dotClass: 'signal-dot--critical',
        };
    }

    return {
        label: 'Not tested',
        dotClass: 'signal-dot--warning',
    };
});

const selectedVaultEntry = computed(() => (
    props.vaultEntries.find((entry) => entry.id === form.vault_entry_id) ?? null
));

const isProxmox = computed(() => props.integration.type === 'proxmox');
const isDocker = computed(() => props.integration.type === 'docker');
const isNvr = computed(() => props.integration.type === 'nvr');
const isNas = computed(() => props.integration.type === 'nas');
const isHeadscale = computed(() => props.integration.type === 'headscale');
const requiresVaultEntry = computed(() => (
    isProxmox.value || isNvr.value || isNas.value || isHeadscale.value || form.config.auth_mode === 'bearer'
));

const hostAssetOptions = computed(() => (
    props.inventoryAssets.filter((asset) => asset.site_id === form.site_id)
));

function submit() {
    form.transform((data) => ({
        site_id: data.site_id || null,
        name: data.name,
        base_url: data.base_url,
        vault_entry_id: data.vault_entry_id || null,
        config: {
            verify_ssl: data.config.verify_ssl,
            auth_mode: data.config.auth_mode,
            health_path: isDocker.value || isNvr.value || isNas.value ? (isDocker.value ? '/_ping' : (isNvr.value ? '/ISAPI/System/status' : '/')) : data.config.health_path,
            health_method: isDocker.value || isNvr.value || isNas.value ? 'GET' : data.config.health_method,
            health_expected_status: isDocker.value || isNvr.value || isNas.value ? 200 : Number(data.config.health_expected_status || 200),
            host_asset_id: data.config.host_asset_id || null,
            username: isNvr.value || isNas.value ? data.config.username : '',
            vendor: isNas.value ? data.config.vendor : null,
        },
        is_active: data.is_active,
    })).put(route('integrations.update', props.integration.id));
}
</script>

<template>
    <Head :title="`Edit ${integration.name}`" />

    <AppLayout>
        <PageHeader
            :title="`Tune ${integration.name}`"
            :subtitle="integration.type_name"
            eyebrow="Source Mesh"
        >
            <template #meta>
                <span class="status-chip">
                    <span :class="form.is_active ? 'signal-dot signal-dot--live' : 'signal-dot signal-dot--warning'" />
                    {{ form.is_active ? 'Active' : 'Standby' }}
                </span>
                <span class="status-chip">
                    <span class="signal-dot" :class="connectionHealth.dotClass" />
                    {{ connectionHealth.label }}
                </span>
            </template>
        </PageHeader>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]">
            <section class="panel-card p-6">
                <form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <label class="form-label" for="integration-type">Type</label>
                        <input
                            id="integration-type"
                            type="text"
                            :value="availableTypes[integration.type] ?? integration.type_name"
                            class="input mt-2 w-full"
                            disabled
                        />
                    </div>

                    <div>
                        <label class="form-label" for="integration-name">Name</label>
                        <input
                            id="integration-name"
                            v-model="form.name"
                            type="text"
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
                            Move this integration only when service ownership really changes.
                        </p>
                        <p v-if="form.errors.site_id" class="form-error">{{ form.errors.site_id }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="integration-base-url">{{ isHeadscale ? 'Domain / Server URL' : 'Base URL' }}</label>
                        <input
                            id="integration-base-url"
                            v-model="form.base_url"
                            type="url"
                            class="input mt-2 w-full font-mono-num"
                            :class="{ 'input-error': form.errors.base_url }"
                            required
                        />
                        <p v-if="isHeadscale" class="text-body-sm text-muted mt-2">
                            Gunakan domain Headscale. InfraControl akan memeriksa endpoint <span class="font-mono-num">/api/v1/node</span>.
                        </p>
                        <p v-if="form.errors.base_url" class="form-error">{{ form.errors.base_url }}</p>
                    </div>

                    <div v-if="isProxmox || isNvr || isNas">
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
                            {{ isProxmox
                                ? 'Link this Proxmox instance to the physical machine it runs on (e.g. Mini PC). Used in topology view.'
                                : (isNvr
                                    ? 'Link this NVR to its physical device record in inventory. Used in topology view.'
                                    : 'Link this NAS appliance to its inventory record so storage systems stay anchored to real hardware. Used in topology view.') }}
                        </p>
                        <p v-if="form.errors['config.host_asset_id']" class="form-error">{{ form.errors['config.host_asset_id'] }}</p>
                    </div>

                    <!-- NVR credentials -->
                    <div v-if="isNvr" class="divider text-caption">NVR Access</div>
                    <div v-if="isNas" class="divider text-caption">NAS Access</div>

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

                    <div v-if="isNas" class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="nas-vendor">NAS Vendor</label>
                            <select
                                id="nas-vendor"
                                v-model="form.config.vendor"
                                class="select mt-2 w-full"
                                :class="{ 'select-error': form.errors['config.vendor'] }"
                                required
                            >
                                <option v-for="(label, key) in nasVendors" :key="key" :value="key">
                                    {{ label }}
                                </option>
                            </select>
                            <p v-if="form.errors['config.vendor']" class="form-error">{{ form.errors['config.vendor'] }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="nas-username">Admin Username</label>
                            <input
                                id="nas-username"
                                v-model="form.config.username"
                                type="text"
                                placeholder="admin"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors['config.username'] }"
                                required
                            />
                            <p class="text-body-sm text-muted mt-2">
                                Rotate passwords from Vault, not from this form.
                            </p>
                            <p v-if="form.errors['config.username']" class="form-error">{{ form.errors['config.username'] }}</p>
                        </div>
                    </div>

                    <div v-if="!isProxmox && !isNvr && !isNas && !isHeadscale" class="divider text-caption">{{ isDocker ? 'Docker Access' : 'API Health' }}</div>

                    <div v-if="isHeadscale" class="divider text-caption">Headscale Access</div>

                    <div v-if="!isProxmox && !isDocker && !isNvr && !isNas && !isHeadscale" class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="health-path">Health Path</label>
                            <input
                                id="health-path"
                                v-model="form.config.health_path"
                                type="text"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors['config.health_path'] }"
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
                            Docker monitoring stays read-only and uses the built-in ping, container list, inspect, and stats endpoints.
                        </div>
                    </div>

                    <div v-if="isHeadscale" class="rounded-xl border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                        Headscale memakai Bearer API key yang disimpan di Vault. Endpoint health check terkunci ke <span class="text-body font-mono-num">/api/v1/node</span>.
                    </div>

                    <div v-if="isNas" class="rounded-xl border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                        NAS stays as one normalized type. The selected vendor chooses the adapter path so Synology, QNAP, and NETGEAR can grow independently without multiplying integration categories.
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
                        <p v-if="isHeadscale" class="text-body-sm text-muted mt-2">
                            Rotasi API key dilakukan dari Vault, bukan di form ini.
                        </p>
                        <p v-if="isNas" class="text-body-sm text-muted mt-2">
                            Vault entry should store the NAS password as a generic secret.
                        </p>
                        <p v-if="form.errors.vault_entry_id" class="form-error">{{ form.errors.vault_entry_id }}</p>
                    </div>

                    <label class="mt-2 flex items-center gap-3 cursor-pointer">
                        <input v-model="form.is_active" type="checkbox" class="toggle toggle-primary" />
                        <span class="text-body-sm text-muted">Integration active in the live room</span>
                    </label>

                    <label class="mt-2 flex items-center gap-3 cursor-pointer">
                        <input v-model="form.config.verify_ssl" type="checkbox" class="checkbox checkbox-primary" />
                        <span class="text-body-sm text-muted">
                            {{ isProxmox ? 'Verify SSL certificate during Proxmox API checks' : (isDocker ? 'Verify SSL certificate during Docker API checks' : (isNvr ? 'Verify SSL certificate during NVR API checks' : (isNas ? 'Verify SSL certificate during NAS API checks' : (isHeadscale ? 'Verify SSL certificate during Headscale API checks' : 'Verify SSL certificate during API health checks')))) }}
                        </span>
                    </label>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing" class="loading loading-spinner loading-xs"></span>
                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                        <Link :href="route('integrations.index')" class="btn btn-ghost">Cancel</Link>
                        <Link :href="route('vault.create')" class="btn btn-secondary">Add Vault Entry</Link>
                    </div>
                </form>
            </section>

            <aside class="space-y-6 xl:self-start">
                <section class="panel-subtle p-5 xl:sticky xl:top-28">
                    <div class="eyebrow">Live Posture</div>
                    <div class="mt-4 text-title-sm text-body">{{ railStatus }}</div>
                    <p class="text-body-sm text-muted mt-3">
                        {{ isProxmox
                            ? 'Proxmox integrations read their token from vault, so rotation should happen on the secret entry rather than in this form.'
                            : (isNvr
                                ? 'NVR integrations use HTTP Digest auth. Username is stored in this form, password comes from the attached vault entry as a generic secret.'
                                : (isNas
                                    ? 'NAS integrations keep the password in Vault and the admin username in config. Vendor-specific adapters decide how deep the check goes.'
                                    : (isHeadscale
                                        ? 'Headscale integrations keep the API key in Vault. Change the domain here, but rotate the key at the secret source.'
                                        : (isDocker
                                            ? 'Docker integrations assume one host endpoint and keep monitoring read-only. If you use auth in front of Docker, rotate the bearer token in vault.'
                                            : 'Custom API integrations let you target any service health endpoint, including Node-based apps with bearer token auth.')))) }}
                    </p>

                    <div v-if="selectedVaultEntry" class="mt-5 rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Current Secret Source</div>
                        <div class="text-body-sm text-body mt-2">{{ selectedVaultEntry.name }}</div>
                        <div class="text-caption text-muted mt-2">
                            {{ selectedVaultEntry.kind_label }} · {{ selectedVaultEntry.scope_label }}
                        </div>
                    </div>

                    <div class="data-list mt-5">
                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Connection Health</div>
                                <div class="mt-2 flex items-center gap-2 text-body-sm text-body">
                                    <span class="signal-dot" :class="connectionHealth.dotClass" />
                                    {{ connectionHealth.label }}
                                </div>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Last Checked</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.last_tested_at ?? 'Never' }}
                                </div>
                                <div v-if="integration.last_tested_at_full" class="text-caption text-muted mt-1">
                                    {{ integration.last_tested_at_full }}
                                </div>
                            </div>
                        </div>

                        <div v-if="integration.last_test_message" class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Last Result</div>
                                <p class="text-body-sm text-body mt-2">
                                    {{ integration.last_test_message }}
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
