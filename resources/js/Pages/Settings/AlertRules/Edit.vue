<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AlertRuleForm from './Form.vue';

defineProps({
    rule: { type: Object, required: true },
    sites: { type: Array, default: () => [] },
    availableRules: { type: Object, required: true },
});
</script>

<template>
    <Head title="Edit Alert Rule" />

    <AppLayout>
        <PageHeader
            title="Edit Alert Rule"
            subtitle="Sesuaikan severity atau threshold untuk scope yang dipilih."
            eyebrow="Rule Engine"
        >
            <template #actions>
                <Link :href="route('alert-rules.index')" class="btn btn-ghost btn-sm">Back</Link>
            </template>
        </PageHeader>

        <AlertRuleForm
            :rule="rule"
            :sites="sites"
            :available-rules="availableRules"
            :submit-url="route('alert-rules.update', rule.id)"
            method="put"
            submit-label="Update Rule"
        />
    </AppLayout>
</template>
