<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    channels: { type: Array, required: true },
});
</script>

<template>
    <Head title="Notification Channels" />

    <AppLayout>
        <PageHeader
            title="Notification Channels"
            subtitle="Kelola target Telegram untuk alert global maupun per-site."
            eyebrow="Delivery Mesh"
        >
            <template #actions>
                <Link :href="route('notification-channels.create')" class="btn btn-primary">Add Channel</Link>
            </template>
        </PageHeader>

        <div v-if="channels.length === 0" class="panel-card p-12 text-center text-body-sm text-muted">
            No channels configured yet.
        </div>

        <div v-else class="grid grid-cols-1 gap-4 xl:grid-cols-2">
            <article v-for="channel in channels" :key="channel.id" class="panel-card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="status-chip">{{ channel.type }}</span>
                            <span class="status-chip">{{ channel.scope_label }}</span>
                        </div>
                        <h2 class="text-title-sm text-body mt-3">{{ channel.name }}</h2>
                        <p class="text-caption text-muted mt-2">Chat ID {{ channel.chat_id || '—' }}</p>
                    </div>

                    <span class="status-chip">{{ channel.is_active ? 'Active' : 'Inactive' }}</span>
                </div>

                <div class="mt-5">
                    <Link :href="route('notification-channels.edit', channel.id)" class="btn btn-secondary btn-sm">
                        Edit
                    </Link>
                </div>
            </article>
        </div>
    </AppLayout>
</template>
