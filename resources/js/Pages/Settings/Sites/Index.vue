<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';

const props = defineProps({
    sites: { type: Array, required: true },
});

const activeCount = computed(() => props.sites.filter((site) => site.is_active).length);
const inactiveCount = computed(() => props.sites.length - activeCount.value);
const integrationCount = computed(() => props.sites.reduce((total, site) => total + site.integrations_count, 0));
</script>

<template>
    <Head title="Sites" />

    <AppLayout>
        <PageHeader
            title="Site Registry"
            subtitle="Define operational locations and keep scope boundaries explicit across the room."
            eyebrow="Sites"
        >
            <template #meta>
                <span class="status-chip">
                    <span class="signal-dot signal-dot--live" />
                    {{ activeCount }} active
                </span>
                <span class="status-chip">{{ inactiveCount }} inactive</span>
            </template>

            <template #actions>
                <Link :href="route('sites.create')" class="btn btn-primary">
                    Add Site
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard
                    label="Registered Sites"
                    :value="sites.length"
                    accent="brand"
                    description="Operational locations modeled in this control room."
                />
                <StatCard
                    label="Active Sites"
                    :value="activeCount"
                    :accent="activeCount > 0 ? 'success' : 'warning'"
                    description="Locations currently enabled for live operations."
                />
                <StatCard
                    label="Scoped Integrations"
                    :value="integrationCount"
                    accent="warning"
                    description="Integrations already attached to explicit site context."
                />
            </div>

            <div v-if="sites.length === 0" class="panel-card p-12 text-center">
                <p class="text-title-md text-body">No sites registered yet.</p>
                <p class="text-body-sm text-muted mt-3">
                    Add the first site so integrations can be scoped cleanly instead of blending into one global pool.
                </p>
                <Link :href="route('sites.create')" class="btn btn-primary mt-6">
                    Create first site
                </Link>
            </div>

            <div v-else class="grid grid-cols-1 gap-4">
                <article
                    v-for="site in sites"
                    :key="site.id"
                    class="panel-card p-6 transition-default hover:border-primary/30"
                >
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="eyebrow">{{ site.code }}</p>
                                <span class="status-chip">
                                    <span
                                        class="signal-dot"
                                        :class="site.is_active ? 'signal-dot--live' : 'signal-dot--warning'"
                                    />
                                    {{ site.is_active ? 'Active' : 'Inactive' }}
                                </span>
                            </div>
                            <h2 class="text-title-md text-body mt-3">{{ site.name }}</h2>
                            <p class="text-body-sm text-muted mt-2">{{ site.business_type }}</p>
                        </div>

                        <Link :href="route('sites.edit', site.id)" class="btn btn-secondary btn-sm">
                            Edit Site
                        </Link>
                    </div>

                    <div class="mt-6 grid grid-cols-1 gap-4 border-t border-hairline pt-5 sm:grid-cols-2 xl:grid-cols-4">
                        <div>
                            <div class="text-caption text-muted">Timezone</div>
                            <div class="text-body-sm text-body mt-2">{{ site.timezone }}</div>
                        </div>

                        <div>
                            <div class="text-caption text-muted">Integrations</div>
                            <div class="text-number-sm text-body mt-2">{{ site.integrations_count }}</div>
                        </div>

                        <div>
                            <div class="text-caption text-muted">Active Integrations</div>
                            <div class="text-number-sm text-body mt-2">{{ site.active_integrations_count }}</div>
                        </div>

                        <div>
                            <div class="text-caption text-muted">Open Alerts</div>
                            <div class="text-number-sm text-body mt-2">{{ site.open_alerts_count }}</div>
                        </div>
                    </div>

                    <div v-if="site.notes" class="mt-4">
                        <div class="rounded-lg border border-hairline bg-base-300 px-4 py-3">
                            <div class="text-caption text-muted">Notes</div>
                            <p class="text-body-sm text-body mt-2">{{ site.notes }}</p>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </AppLayout>
</template>
