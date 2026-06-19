<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    sites: { type: Array, default: () => [] },
    kindOptions: { type: Object, required: true },
});

const form = useForm({
    site_id: '',
    name: '',
    kind: 'proxmox_api_token',
    secret: '',
    public_key: '',
    fingerprint: '',
    notes: '',
    rotation_interval_days: '',
    last_rotated_at: '',
    is_active: true,
});

const selectedKindLabel = computed(() => props.kindOptions[form.kind] ?? 'Secret');

function submit() {
    form.post(route('vault.store'));
}
</script>

<template>
    <Head title="Add Vault Entry" />

    <AppLayout>
        <PageHeader
            title="Add Vault Entry"
            subtitle="Store an encrypted secret before it gets attached to a Proxmox integration or manual ops flow."
            eyebrow="Vault Grid"
        />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]">
            <section class="panel-card p-6">
                <form class="space-y-5" @submit.prevent="submit">
                    <div>
                        <label class="form-label" for="vault-name">Name</label>
                        <input
                            id="vault-name"
                            v-model="form.name"
                            type="text"
                            placeholder="Proxmox Production Token"
                            class="input mt-2 w-full"
                            :class="{ 'input-error': form.errors.name }"
                            required
                        />
                        <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="vault-kind">Kind</label>
                        <select
                            id="vault-kind"
                            v-model="form.kind"
                            class="select mt-2 w-full"
                            :class="{ 'select-error': form.errors.kind }"
                            required
                        >
                            <option v-for="(label, key) in kindOptions" :key="key" :value="key">
                                {{ label }}
                            </option>
                        </select>
                        <p v-if="form.errors.kind" class="form-error">{{ form.errors.kind }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="vault-site">Scope</label>
                        <select
                            id="vault-site"
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
                            Keep secret scope global only when multiple sites should reuse the same credential.
                        </p>
                        <p v-if="form.errors.site_id" class="form-error">{{ form.errors.site_id }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="vault-secret">Secret Value</label>
                        <textarea
                            id="vault-secret"
                            v-model="form.secret"
                            rows="6"
                            class="textarea mt-2 w-full font-mono-num"
                            :class="{ 'textarea-error': form.errors.secret }"
                            placeholder="Paste token, password, private key, or recovery value"
                            required
                        />
                        <p v-if="form.errors.secret" class="form-error">{{ form.errors.secret }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="vault-notes">Notes</label>
                        <textarea
                            id="vault-notes"
                            v-model="form.notes"
                            rows="4"
                            class="textarea mt-2 w-full"
                            :class="{ 'textarea-error': form.errors.notes }"
                            placeholder="Rotation owner, system context, or operational caveats"
                        />
                        <p v-if="form.errors.notes" class="form-error">{{ form.errors.notes }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="vault-public-key">Public Key</label>
                        <textarea
                            id="vault-public-key"
                            v-model="form.public_key"
                            rows="4"
                            class="textarea mt-2 w-full font-mono-num"
                            :class="{ 'textarea-error': form.errors.public_key }"
                            placeholder="Optional public key pair, for example ssh-ed25519 AAAA..."
                        />
                        <p class="text-body-sm text-muted mt-2">
                            Not secret, but useful for inventory and fingerprint generation.
                        </p>
                        <p v-if="form.errors.public_key" class="form-error">{{ form.errors.public_key }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="vault-fingerprint">Fingerprint</label>
                        <input
                            id="vault-fingerprint"
                            v-model="form.fingerprint"
                            type="text"
                            class="input mt-2 w-full font-mono-num"
                            :class="{ 'input-error': form.errors.fingerprint }"
                            placeholder="Optional if you want to override or store a manual fingerprint"
                        />
                        <p class="text-body-sm text-muted mt-2">
                            If the public key is valid OpenSSH format, fingerprint will be generated automatically.
                        </p>
                        <p v-if="form.errors.fingerprint" class="form-error">{{ form.errors.fingerprint }}</p>
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label class="form-label" for="vault-rotation-interval">Rotation Interval (days)</label>
                            <input
                                id="vault-rotation-interval"
                                v-model="form.rotation_interval_days"
                                type="number"
                                min="1"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.rotation_interval_days }"
                            />
                            <p v-if="form.errors.rotation_interval_days" class="form-error">{{ form.errors.rotation_interval_days }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="vault-last-rotated">Last Rotated</label>
                            <input
                                id="vault-last-rotated"
                                v-model="form.last_rotated_at"
                                type="date"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.last_rotated_at }"
                            />
                            <p v-if="form.errors.last_rotated_at" class="form-error">{{ form.errors.last_rotated_at }}</p>
                        </div>
                    </div>

                    <label class="mt-2 flex items-center gap-3 cursor-pointer">
                        <input v-model="form.is_active" type="checkbox" class="toggle toggle-primary" />
                        <span class="text-body-sm text-muted">Allow this secret to back live integrations</span>
                    </label>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing" class="loading loading-spinner loading-xs"></span>
                            {{ form.processing ? 'Saving...' : 'Create Vault Entry' }}
                        </button>
                        <Link :href="route('vault.index')" class="btn btn-ghost">Cancel</Link>
                    </div>
                </form>
            </section>

            <aside class="space-y-6 xl:self-start">
                <section class="panel-subtle p-5 xl:sticky xl:top-28">
                    <div class="eyebrow">Entry Notes</div>
                    <div class="mt-4 text-title-sm text-body">{{ selectedKindLabel }}</div>
                    <p class="text-body-sm text-muted mt-3">
                        List pages never receive plaintext. Reveal only happens from the detail view and every reveal is logged.
                    </p>

                    <div class="data-list mt-5">
                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Proxmox Bias</div>
                                <p class="text-body-sm text-muted mt-2">
                                    Use <span class="text-body">Proxmox API Token</span> for cluster connectors so integration forms stay secret-free.
                                </p>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Rotation</div>
                                <p class="text-body-sm text-muted mt-2">
                                    Set a cadence only when the team has a real owner for rotating this credential.
                                </p>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">SSH Pairing</div>
                                <p class="text-body-sm text-muted mt-2">
                                    For SSH material, keep the private key in <span class="text-body">Secret Value</span> and place the public half here for audit-friendly pairing.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
