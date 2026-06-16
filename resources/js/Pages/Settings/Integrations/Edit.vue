<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    integration: { type: Object, required: true },
    availableTypes: { type: Object, required: true },
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
const requiresVaultEntry = computed(() => (
    isProxmox.value || form.config.auth_mode === 'bearer'
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
            health_path: data.config.health_path,
            health_method: data.config.health_method,
            health_expected_status: Number(data.config.health_expected_status || 200),
            host_asset_id: data.config.host_asset_id || null,
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
                        <label class="form-label" for="integration-base-url">Base URL</label>
                        <input
                            id="integration-base-url"
                            v-model="form.base_url"
                            type="url"
                            class="input mt-2 w-full font-mono-num"
                            :class="{ 'input-error': form.errors.base_url }"
                            required
                        />
                        <p v-if="form.errors.base_url" class="form-error">{{ form.errors.base_url }}</p>
                    </div>

                    <div v-if="isProxmox">
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
                            Link this Proxmox instance to the physical machine it runs on (e.g. Mini PC). Used in topology view.
                        </p>
                        <p v-if="form.errors['config.host_asset_id']" class="form-error">{{ form.errors['config.host_asset_id'] }}</p>
                    </div>

                    <div v-if="!isProxmox" class="divider text-caption">API Health</div>

                    <div v-if="!isProxmox" class="grid gap-5 sm:grid-cols-2">
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

                    <div>
                        <label class="form-label" for="integration-vault-entry">Vault Secret</label>
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
                        <p v-if="form.errors.vault_entry_id" class="form-error">{{ form.errors.vault_entry_id }}</p>
                    </div>

                    <label class="mt-2 flex items-center gap-3 cursor-pointer">
                        <input v-model="form.is_active" type="checkbox" class="toggle toggle-primary" />
                        <span class="text-body-sm text-muted">Integration active in the live room</span>
                    </label>

                    <label class="mt-2 flex items-center gap-3 cursor-pointer">
                        <input v-model="form.config.verify_ssl" type="checkbox" class="checkbox checkbox-primary" />
                        <span class="text-body-sm text-muted">
                            {{ isProxmox ? 'Verify SSL certificate during Proxmox API checks' : 'Verify SSL certificate during API health checks' }}
                        </span>
                    </label>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :class="{ loading: form.processing }"
                            :disabled="form.processing"
                        >
                            Save Changes
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
                            : 'Custom API integrations let you target any service health endpoint, including Node-based apps with bearer token auth.' }}
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
