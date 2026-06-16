<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AlertRuleForm from './Form.vue';

defineProps({
    sites: { type: Array, default: () => [] },
    availableRules: { type: Object, required: true },
});
</script>

<template>
    <Head title="Add Alert Rule" />

    <AppLayout>
        <PageHeader
            title="Add Alert Rule"
            subtitle="Buat override threshold atau behavior untuk site tertentu tanpa mengganggu default global."
            eyebrow="Rule Engine"
        >
            <template #actions>
                <Link :href="route('alert-rules.index')" class="btn btn-ghost btn-sm">Back</Link>
            </template>
        </PageHeader>

        <AlertRuleForm
            :sites="sites"
            :available-rules="availableRules"
            :submit-url="route('alert-rules.store')"
            submit-label="Create Rule"
        />
    </AppLayout>
</template>
