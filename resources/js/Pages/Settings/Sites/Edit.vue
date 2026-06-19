<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    site: { type: Object, required: true },
});

const form = useForm({
    name: props.site.name,
    code: props.site.code,
    business_type: props.site.business_type,
    timezone: props.site.timezone,
    notes: props.site.notes ?? '',
    is_active: props.site.is_active,
});

function submit() {
    form.put(route('sites.update', props.site.id));
}
</script>

<template>
    <Head :title="`Edit ${site.name}`" />

    <AppLayout>
        <PageHeader
            :title="`Edit ${site.name}`"
            subtitle="Adjust site metadata without breaking the scope attached to existing integrations."
            eyebrow="Settings"
        >
            <template #meta>
                <span class="status-chip">
                    <span :class="site.is_active ? 'signal-dot signal-dot--live' : 'signal-dot signal-dot--warning'" />
                    {{ site.is_active ? 'Active' : 'Inactive' }}
                </span>
            </template>
        </PageHeader>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_340px]">
            <section class="panel-card p-6">
                <form class="space-y-5" @submit.prevent="submit">
                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="form-label" for="site-name">Name</label>
                            <input
                                id="site-name"
                                v-model="form.name"
                                type="text"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.name }"
                                required
                            />
                            <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="site-code">Code</label>
                            <input
                                id="site-code"
                                v-model="form.code"
                                type="text"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors.code }"
                                required
                            />
                            <p v-if="form.errors.code" class="form-error">{{ form.errors.code }}</p>
                        </div>
                    </div>

                    <div class="grid gap-5 md:grid-cols-2">
                        <div>
                            <label class="form-label" for="site-business-type">Business Type</label>
                            <input
                                id="site-business-type"
                                v-model="form.business_type"
                                type="text"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': form.errors.business_type }"
                                required
                            />
                            <p v-if="form.errors.business_type" class="form-error">{{ form.errors.business_type }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="site-timezone">Timezone</label>
                            <input
                                id="site-timezone"
                                v-model="form.timezone"
                                type="text"
                                class="input mt-2 w-full font-mono-num"
                                :class="{ 'input-error': form.errors.timezone }"
                                required
                            />
                            <p v-if="form.errors.timezone" class="form-error">{{ form.errors.timezone }}</p>
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="site-notes">Notes</label>
                        <textarea
                            id="site-notes"
                            v-model="form.notes"
                            rows="4"
                            class="textarea mt-2 w-full"
                            :class="{ 'textarea-error': form.errors.notes }"
                        />
                        <p v-if="form.errors.notes" class="form-error">{{ form.errors.notes }}</p>
                    </div>

                    <label class="mt-2 flex cursor-pointer items-center gap-3">
                        <input v-model="form.is_active" type="checkbox" class="toggle toggle-primary" />
                        <span class="text-body-sm text-muted">Site participates in active operations</span>
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
                        <Link :href="route('sites.index')" class="btn btn-ghost">Cancel</Link>
                    </div>
                </form>
            </section>

            <aside class="space-y-6">
                <section class="panel-subtle p-5 xl:sticky xl:top-28">
                    <div class="eyebrow">Site Snapshot</div>
                    <div class="data-list mt-5">
                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Scoped Integrations</div>
                                <div class="text-number-sm text-body mt-2">{{ site.integrations_count }}</div>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Active Integrations</div>
                                <div class="text-number-sm text-body mt-2">{{ site.active_integrations_count }}</div>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Open Alerts</div>
                                <div class="text-number-sm text-body mt-2">{{ site.open_alerts_count }}</div>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Last Updated</div>
                                <div class="text-body-sm text-body mt-2">{{ site.updated_at ?? 'Just now' }}</div>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
