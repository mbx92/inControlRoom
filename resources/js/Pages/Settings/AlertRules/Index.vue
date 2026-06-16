<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    rules: { type: Array, required: true },
});
</script>

<template>
    <Head title="Alert Rules" />

    <AppLayout>
        <PageHeader
            title="Alert Rules"
            subtitle="Atur default global dan override per-site untuk health failure, guest stopped, dan threshold resource."
            eyebrow="Rule Engine"
        >
            <template #actions>
                <Link :href="route('alert-rules.create')" class="btn btn-primary">Add Rule Override</Link>
            </template>
        </PageHeader>

        <div v-if="rules.length === 0" class="panel-card p-12 text-center text-body-sm text-muted">
            No alert rules found.
        </div>

        <div v-else class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <article v-for="rule in rules" :key="rule.id" class="panel-card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="status-chip">{{ rule.scope_label }}</span>
                            <span class="status-chip">{{ rule.rule_key }}</span>
                        </div>
                        <h2 class="text-title-sm text-body mt-3">{{ rule.name }}</h2>
                    </div>

                    <span class="status-chip">{{ rule.is_active ? 'Active' : 'Inactive' }}</span>
                </div>

                <div class="mt-4 text-body-sm text-muted">
                    <span v-if="rule.warning_threshold !== null">Warn {{ rule.warning_threshold }}%</span>
                    <span v-if="rule.warning_threshold !== null && rule.critical_threshold !== null"> · </span>
                    <span v-if="rule.critical_threshold !== null">Critical {{ rule.critical_threshold }}%</span>
                    <span v-if="rule.warning_threshold === null && rule.critical_threshold === null">
                        Fixed severity {{ rule.default_severity || 'n/a' }}
                    </span>
                </div>

                <div class="mt-5">
                    <Link :href="route('alert-rules.edit', rule.id)" class="btn btn-secondary btn-sm">Edit</Link>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
