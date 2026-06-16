<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import NotificationChannelForm from './Form.vue';

defineProps({
    channel: { type: Object, required: true },
    sites: { type: Array, default: () => [] },
    types: { type: Object, required: true },
});
</script>

<template>
    <Head title="Edit Notification Channel" />

    <AppLayout>
        <PageHeader
            title="Edit Notification Channel"
            subtitle="Perbarui target Telegram untuk routing alert site atau fallback global."
            eyebrow="Delivery Mesh"
        >
            <template #actions>
                <Link :href="route('notification-channels.index')" class="btn btn-ghost btn-sm">Back</Link>
            </template>
        </PageHeader>

        <NotificationChannelForm
            :channel="channel"
            :sites="sites"
            :types="types"
            :submit-url="route('notification-channels.update', channel.id)"
            method="put"
            submit-label="Update Channel"
        />
    </AppLayout>
</template>
