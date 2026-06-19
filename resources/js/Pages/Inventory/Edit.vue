<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    asset: { type: Object, required: true },
    sites: { type: Array, default: () => [] },
    statusOptions: { type: Array, default: () => [] },
    categorySuggestions: { type: Array, default: () => [] },
    siteAssets: { type: Array, default: () => [] },
});

const form = useForm({
    site_id: props.asset.site_id ?? '',
    name: props.asset.name,
    category: props.asset.category,
    status: props.asset.status,
    asset_tag: props.asset.asset_tag ?? '',
    serial_number: props.asset.serial_number ?? '',
    manufacturer: props.asset.manufacturer ?? '',
    model: props.asset.model ?? '',
    primary_ip: props.asset.primary_ip ?? '',
    location_label: props.asset.location_label ?? '',
    uplink_asset_id: props.asset.uplink_asset_id ?? '',
    owner_name: props.asset.owner_name ?? '',
    acquired_at: props.asset.acquired_at ?? '',
    warranty_expires_at: props.asset.warranty_expires_at ?? '',
    custom_fields_text: props.asset.custom_fields_text ?? '',
    notes: props.asset.notes ?? '',
});

const uplinkOptions = computed(() => (
    props.siteAssets.filter((asset) => asset.site_id === form.site_id && asset.id !== props.asset.id)
));

function submit() {
    form.put(route('inventory.update', props.asset.id));
}
</script>

<template>
    <Head :title="`Edit ${asset.name}`" />

    <AppLayout>
        <PageHeader
            :title="`Edit ${asset.name}`"
            subtitle="Rapikan metadata inventaris tanpa memaksa schema berat yang belum diperlukan."
            eyebrow="Asset Register"
        >
            <template #meta>
                <span class="status-chip">{{ asset.status_label }}</span>
                <span class="status-chip">{{ asset.scope_label }}</span>
            </template>

            <template #actions>
                <Link :href="route('inventory.show', asset.id)" class="btn btn-ghost btn-sm">
                    Back
                </Link>
            </template>
        </PageHeader>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]">
            <section class="panel-card p-6">
                <form class="space-y-5" @submit.prevent="submit">
                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="form-label" for="asset-site">Site</label>
                            <select
                                id="asset-site"
                                v-model="form.site_id"
                                class="select mt-2 w-full"
                                :class="{ 'select-error': form.errors.site_id }"
                            >
                                <option value="">Unassigned</option>
                                <option v-for="site in sites" :key="site.id" :value="site.id">
                                    {{ site.name }} ({{ site.code }})
                                </option>
                            </select>
                            <p v-if="form.errors.site_id" class="form-error">{{ form.errors.site_id }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="asset-status">Status</label>
                            <select
                                id="asset-status"
                                v-model="form.status"
                                class="select mt-2 w-full"
                                :class="{ 'select-error': form.errors.status }"
                            >
                                <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                            <p v-if="form.errors.status" class="form-error">{{ form.errors.status }}</p>
                        </div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="form-label" for="asset-name">Name</label>
                            <input
                                id="asset-name"
                                v-model="form.name"
                                type="text"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.name }"
                                required
                            />
                            <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="asset-category">Category</label>
                            <select
                                id="asset-category"
                                v-model="form.category"
                                class="select mt-2 w-full"
                                :class="{ 'select-error': form.errors.category }"
                                required
                            >
                                <option value="" disabled>Select category</option>
                                <option v-for="category in categorySuggestions" :key="category" :value="category">
                                    {{ category }}
                                </option>
                            </select>
                            <p v-if="form.errors.category" class="form-error">{{ form.errors.category }}</p>
                        </div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label class="form-label" for="asset-tag">Asset Tag</label>
                            <input
                                id="asset-tag"
                                v-model="form.asset_tag"
                                type="text"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors.asset_tag }"
                            />
                            <p v-if="form.errors.asset_tag" class="form-error">{{ form.errors.asset_tag }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="asset-serial">Serial Number</label>
                            <input
                                id="asset-serial"
                                v-model="form.serial_number"
                                type="text"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.serial_number }"
                            />
                            <p v-if="form.errors.serial_number" class="form-error">{{ form.errors.serial_number }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="asset-ip">Primary IP</label>
                            <input
                                id="asset-ip"
                                v-model="form.primary_ip"
                                type="text"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors.primary_ip }"
                            />
                            <p v-if="form.errors.primary_ip" class="form-error">{{ form.errors.primary_ip }}</p>
                        </div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label class="form-label" for="asset-manufacturer">Manufacturer</label>
                            <input
                                id="asset-manufacturer"
                                v-model="form.manufacturer"
                                type="text"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.manufacturer }"
                            />
                            <p v-if="form.errors.manufacturer" class="form-error">{{ form.errors.manufacturer }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="asset-model">Model</label>
                            <input
                                id="asset-model"
                                v-model="form.model"
                                type="text"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.model }"
                            />
                            <p v-if="form.errors.model" class="form-error">{{ form.errors.model }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="asset-owner">Owner</label>
                            <input
                                id="asset-owner"
                                v-model="form.owner_name"
                                type="text"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.owner_name }"
                            />
                            <p v-if="form.errors.owner_name" class="form-error">{{ form.errors.owner_name }}</p>
                        </div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <label class="form-label" for="asset-location">Location Label</label>
                            <input
                                id="asset-location"
                                v-model="form.location_label"
                                type="text"
                                placeholder="Lantai 1 / Ruang A"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.location_label }"
                            />
                            <p class="text-body-sm text-muted mt-2">Format: Lantai / Ruang — dipakai di topology Network.</p>
                            <p v-if="form.errors.location_label" class="form-error">{{ form.errors.location_label }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="asset-uplink">Uplink To</label>
                            <select
                                id="asset-uplink"
                                v-model="form.uplink_asset_id"
                                class="select mt-2 w-full"
                                :class="{ 'select-error': form.errors.uplink_asset_id }"
                                :disabled="!form.site_id"
                            >
                                <option value="">No uplink</option>
                                <option v-for="option in uplinkOptions" :key="option.id" :value="option.id">
                                    {{ option.label }}
                                </option>
                            </select>
                            <p class="text-body-sm text-muted mt-2">Switch/port upstream untuk topology network.</p>
                            <p v-if="form.errors.uplink_asset_id" class="form-error">{{ form.errors.uplink_asset_id }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="asset-acquired-at">Acquired At</label>
                            <input
                                id="asset-acquired-at"
                                v-model="form.acquired_at"
                                type="date"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.acquired_at }"
                            />
                            <p v-if="form.errors.acquired_at" class="form-error">{{ form.errors.acquired_at }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="asset-warranty-expires-at">Warranty Expires</label>
                            <input
                                id="asset-warranty-expires-at"
                                v-model="form.warranty_expires_at"
                                type="date"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.warranty_expires_at }"
                            />
                            <p v-if="form.errors.warranty_expires_at" class="form-error">{{ form.errors.warranty_expires_at }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="asset-custom-fields">Extra Attributes</label>
                        <textarea
                            id="asset-custom-fields"
                            v-model="form.custom_fields_text"
                            rows="5"
                            class="textarea mt-2 w-full font-mono-num"
                            :class="{ 'textarea-error': form.errors.custom_fields_text }"
                        />
                        <p class="text-caption text-muted mt-2">
                            Tetap gunakan format <span class="font-mono-num">key: value</span> satu baris per atribut.
                        </p>
                        <p v-if="form.errors.custom_fields_text" class="form-error">{{ form.errors.custom_fields_text }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="asset-notes">Notes</label>
                        <textarea
                            id="asset-notes"
                            v-model="form.notes"
                            rows="4"
                            class="textarea mt-2 w-full"
                            :class="{ 'textarea-error': form.errors.notes }"
                        />
                        <p v-if="form.errors.notes" class="form-error">{{ form.errors.notes }}</p>
                    </div>

                    <div class="flex flex-wrap gap-3 pt-2">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing" class="loading loading-spinner loading-xs"></span>
                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                        <Link :href="route('inventory.show', asset.id)" class="btn btn-ghost">Cancel</Link>
                    </div>
                </form>
            </section>

            <aside class="space-y-6 xl:self-start">
                <section class="panel-subtle p-5 xl:sticky xl:top-28">
                    <div class="eyebrow">Current snapshot</div>
                    <div class="data-list mt-5">
                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Site Scope</div>
                                <p class="text-body-sm text-body mt-2">{{ asset.scope_label }}</p>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Extra Attributes</div>
                                <p class="text-body-sm text-body mt-2">{{ asset.custom_fields_count }}</p>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Last Updated</div>
                                <p class="text-body-sm text-body mt-2">{{ asset.updated_at || 'Just now' }}</p>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
