<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import NotificationChannelForm from './Form.vue';

defineProps({
    sites: { type: Array, default: () => [] },
    types: { type: Object, required: true },
});
</script>

<template>
    <Head title="Add Notification Channel" />

    <AppLayout>
        <PageHeader
            title="Add Notification Channel"
            subtitle="Hubungkan Telegram bot ke site tertentu atau jadikan fallback global."
            eyebrow="Delivery Mesh"
        >
            <template #actions>
                <Link :href="route('notification-channels.index')" class="btn btn-ghost btn-sm">Back</Link>
            </template>
        </PageHeader>

        <NotificationChannelForm
            :sites="sites"
            :types="types"
            :submit-url="route('notification-channels.store')"
            submit-label="Create Channel"
        />
    </AppLayout>
</template>
