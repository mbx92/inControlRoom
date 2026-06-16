<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
    defaultTimezone: { type: String, default: 'UTC' },
});

const form = useForm({
    name: '',
    code: '',
    business_type: '',
    timezone: props.defaultTimezone,
    notes: '',
    is_active: true,
});

function submit() {
    form.post(route('sites.store'));
}
</script>

<template>
    <Head title="Add Site" />

    <AppLayout>
        <PageHeader
            title="Add Site"
            subtitle="Register a new operational location before binding integrations to it."
            eyebrow="Settings"
        />

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
                                placeholder="Makassar Branch"
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
                                placeholder="MKS-01"
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
                                placeholder="Clinic"
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
                                placeholder="Asia/Makassar"
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
                            placeholder="Operational notes, ownership, or deployment context"
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
                            :class="{ loading: form.processing }"
                            :disabled="form.processing"
                        >
                            Create Site
                        </button>
                        <Link :href="route('sites.index')" class="btn btn-ghost">Cancel</Link>
                    </div>
                </form>
            </section>

            <aside class="space-y-6">
                <section class="panel-subtle p-5 xl:sticky xl:top-28">
                    <div class="eyebrow">Why it matters</div>
                    <div class="data-list mt-5">
                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Scope Discipline</div>
                                <p class="text-body-sm text-muted mt-2">
                                    A clean site registry keeps integrations, alerts, and audit evidence from collapsing into one flat namespace.
                                </p>
                            </div>
                        </div>

                        <div class="data-list__row">
                            <div>
                                <div class="text-caption text-muted">Code Format</div>
                                <p class="text-body-sm text-muted mt-2">
                                    Use short stable codes so badges and filters remain readable in dense operational views.
                                </p>
                            </div>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
