<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const page = usePage();
const permissions = page.props.auth.permissions ?? {};

const props = defineProps({
    entry: { type: Object, required: true },
    revealedSecret: { type: String, default: null },
});

const isRevealed = ref(props.revealedSecret !== null);

const displayedSecret = computed(() => (
    isRevealed.value && props.revealedSecret !== null
        ? props.revealedSecret
        : props.entry.masked_preview
));

function toggleSecret() {
    if (isRevealed.value) {
        isRevealed.value = false;
        return;
    }

    if (props.revealedSecret !== null) {
        isRevealed.value = true;
        return;
    }

    router.post(route('vault.reveal', props.entry.id), {}, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="entry.name" />

    <AppLayout>
        <PageHeader
            :title="entry.name"
            :subtitle="entry.kind_label"
            eyebrow="Vault Detail"
        >
            <template #meta>
                <span class="status-chip">
                    <span :class="entry.is_active ? 'signal-dot signal-dot--live' : 'signal-dot signal-dot--warning'" />
                    {{ entry.is_active ? 'Active' : 'Archived' }}
                </span>
                <span class="status-chip">{{ entry.scope_kind === 'global' ? 'Global' : entry.scope_label }}</span>
            </template>

            <template #actions>
                <Link :href="route('vault.index')" class="btn btn-ghost">
                    Back
                </Link>
                <button v-if="permissions.can_execute" type="button" class="btn btn-secondary" @click="toggleSecret">
                    {{ isRevealed ? 'Hide Secret' : 'Reveal Secret' }}
                </button>
                <Link v-if="permissions.is_admin" :href="route('vault.edit', entry.id)" class="btn btn-primary">
                    Edit Entry
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <section class="panel-card p-4 sm:p-5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Scope</div>
                        <div class="text-title-sm text-body mt-3">{{ entry.scope_kind === 'global' ? 'Global' : entry.scope_label }}</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Kind</div>
                        <div class="text-title-sm text-body mt-3">{{ entry.kind_label }}</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Last Rotated</div>
                        <div class="text-title-sm text-body mt-3">{{ entry.last_rotated_human ?? 'Not tracked' }}</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Linked Integrations</div>
                        <div class="text-number-sm text-body mt-3">{{ entry.integrations_count }}</div>
                    </article>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.3fr)_360px]">
                <section class="space-y-6">
                    <section class="panel-card p-6">
                        <div class="eyebrow">Encrypted Value</div>
                        <h2 class="text-title-md text-body mt-2">Reveal On Demand</h2>

                        <div class="mt-5 rounded-xl border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Stored Value</div>
                            <pre class="mt-3 whitespace-pre-wrap break-all font-mono-num text-body-sm text-body">{{ displayedSecret }}</pre>
                        </div>

                        <div class="mt-4 text-body-sm text-muted">
                            Plaintext only appears after an explicit reveal and every reveal is written to the audit trail.
                        </div>
                    </section>

                    <section v-if="entry.notes" class="panel-card p-6">
                        <div class="eyebrow">Notes</div>
                        <div class="text-body-sm text-body mt-4 whitespace-pre-wrap">{{ entry.notes }}</div>
                    </section>

                    <section v-if="entry.public_key || entry.fingerprint" class="panel-card p-6">
                        <div class="eyebrow">Public Material</div>
                        <h2 class="text-title-md text-body mt-2">Pairing Reference</h2>

                        <div v-if="entry.fingerprint" class="mt-5 rounded-xl border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Fingerprint</div>
                            <div class="mt-3 break-all font-mono-num text-body-sm text-body">
                                {{ entry.fingerprint }}
                            </div>
                        </div>

                        <div v-if="entry.public_key" class="mt-5 rounded-xl border border-hairline bg-base-300 px-4 py-4">
                            <div class="text-caption text-muted">Public Key</div>
                            <pre class="mt-3 whitespace-pre-wrap break-all font-mono-num text-body-sm text-body">{{ entry.public_key }}</pre>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Access Trail</div>
                                <h2 class="text-title-md text-body mt-2">Recent Vault Actions</h2>
                            </div>
                            <div class="status-chip">{{ entry.access_logs.length }} entries</div>
                        </div>

                        <div v-if="entry.access_logs.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No access activity recorded yet.
                        </div>

                        <div v-else class="mt-6 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Time</th>
                                        <th>Action</th>
                                        <th>Actor</th>
                                        <th>IP</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="log in entry.access_logs"
                                        :key="log.id"
                                        class="border-hairline"
                                    >
                                        <td class="text-caption text-muted">{{ log.created_at_full ?? log.created_at }}</td>
                                        <td class="text-body-sm text-body">{{ log.action }}</td>
                                        <td class="text-body-sm text-body">{{ log.user_name }}</td>
                                        <td class="text-caption text-muted font-mono-num">{{ log.ip_address ?? '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <aside class="space-y-6 xl:self-start">
                    <section class="panel-subtle p-5 xl:sticky xl:top-28">
                        <div class="eyebrow">Linked Systems</div>
                        <div class="mt-4 text-title-sm text-body">{{ entry.integrations_count }} integrations</div>

                        <div v-if="entry.integrations.length === 0" class="mt-4 text-body-sm text-muted">
                            This secret is not attached to any live integration yet.
                        </div>

                        <div v-else class="space-y-3 mt-5">
                            <div
                                v-for="integration in entry.integrations"
                                :key="integration.id"
                                class="rounded-lg border border-hairline bg-base-300 px-4 py-4"
                            >
                                <div class="text-body-sm text-body">{{ integration.name }}</div>
                                <div class="text-caption text-muted mt-2">
                                    {{ integration.type_name }} · {{ integration.scope_label }}
                                </div>
                                <Link :href="route('integrations.show', integration.id)" class="btn btn-ghost btn-xs mt-3">
                                    View integration
                                </Link>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
