<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    entry: { type: Object, required: true },
    sites: { type: Array, default: () => [] },
    kindOptions: { type: Object, required: true },
});

const form = useForm({
    site_id: props.entry.site_id ?? '',
    name: props.entry.name,
    kind: props.entry.kind,
    secret: '',
    public_key: props.entry.public_key ?? '',
    fingerprint: props.entry.fingerprint ?? '',
    notes: props.entry.notes ?? '',
    rotation_interval_days: props.entry.rotation_interval_days ?? '',
    last_rotated_at: props.entry.last_rotated_at ?? '',
    is_active: props.entry.is_active,
});

const selectedKindLabel = computed(() => props.kindOptions[form.kind] ?? 'Secret');

function submit() {
    form.put(route('vault.update', props.entry.id));
}
</script>

<template>
    <Head :title="`Edit ${entry.name}`" />

    <AppLayout>
        <PageHeader
            :title="`Tune ${entry.name}`"
            :subtitle="entry.kind_label"
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
                        <p v-if="form.errors.site_id" class="form-error">{{ form.errors.site_id }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="vault-secret">Replace Secret Value</label>
                        <textarea
                            id="vault-secret"
                            v-model="form.secret"
                            rows="6"
                            class="textarea mt-2 w-full font-mono-num"
                            :class="{ 'textarea-error': form.errors.secret }"
                            placeholder="Leave blank to keep the existing encrypted value"
                        />
                        <p class="text-body-sm text-muted mt-2">
                            Plaintext is only used for this update request and never shown again unless you explicitly reveal it later.
                        </p>
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
                            placeholder="Optional public key pair"
                        />
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
                            placeholder="Optional manual fingerprint"
                        />
                        <p class="text-body-sm text-muted mt-2">
                            Valid OpenSSH public keys will refresh this automatically on save.
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
                        <span class="text-body-sm text-muted">Entry active for live usage</span>
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
                        <Link :href="route('vault.show', entry.id)" class="btn btn-ghost">Cancel</Link>
                    </div>
                </form>
            </section>

            <aside class="space-y-6 xl:self-start">
                <section class="panel-subtle p-5 xl:sticky xl:top-28">
                    <div class="eyebrow">Entry Posture</div>
                    <div class="mt-4 text-title-sm text-body">{{ selectedKindLabel }}</div>
                    <p class="text-body-sm text-muted mt-3">
                        This entry currently backs {{ entry.integrations_count }} integrations. Renaming is safe, but rotating the secret changes real system access.
                    </p>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
