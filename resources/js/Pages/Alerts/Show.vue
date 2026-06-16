<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const page = usePage();
const permissions = computed(() => page.props.auth.permissions ?? {});

const props = defineProps({
    alert: { type: Object, required: true },
});

const form = useForm({
    comment: props.alert.acknowledge_comment ?? '',
});

const canAcknowledge = computed(() => permissions.value.can_execute && props.alert.status !== 'resolved');
const contextJson = computed(() => JSON.stringify(props.alert.context ?? {}, null, 2));

function acknowledge() {
    form.put(route('alerts.acknowledge', props.alert.id), {
        preserveScroll: true,
    });
}

function severityBadge(severity) {
    return {
        critical: 'badge-error',
        warning: 'badge-warning',
        info: 'badge-info',
    }[severity] ?? 'badge-ghost';
}
</script>

<template>
    <Head :title="alert.title" />

    <AppLayout>
        <PageHeader
            :title="alert.title"
            :subtitle="alert.message || 'No extra operator note attached to this alert.'"
            eyebrow="Alert Detail"
        >
            <template #meta>
                <span class="badge badge-sm" :class="severityBadge(alert.severity)">{{ alert.severity }}</span>
                <span class="status-chip">{{ alert.status }}</span>
                <span class="status-chip">{{ alert.site_label }}</span>
            </template>

            <template #actions>
                <Link :href="route('alerts.index')" class="btn btn-ghost btn-sm">Back to Alerts</Link>
            </template>
        </PageHeader>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.3fr)_380px]">
            <section class="panel-card p-5">
                <div class="eyebrow">Signal Context</div>
                <div class="data-list mt-5">
                    <div class="data-list__row">
                        <div>
                            <div class="text-caption text-muted">Integration</div>
                            <div class="text-body-sm text-body mt-1">{{ alert.integration_name }}</div>
                        </div>
                    </div>
                    <div class="data-list__row">
                        <div>
                            <div class="text-caption text-muted">Rule Key</div>
                            <div class="text-body-sm text-body mt-1 font-mono-num">{{ alert.rule_key || 'manual' }}</div>
                        </div>
                    </div>
                    <div class="data-list__row">
                        <div>
                            <div class="text-caption text-muted">First Seen</div>
                            <div class="text-body-sm text-body mt-1">{{ alert.first_seen_at_full || alert.first_seen_at }}</div>
                        </div>
                    </div>
                    <div class="data-list__row">
                        <div>
                            <div class="text-caption text-muted">Last Seen</div>
                            <div class="text-body-sm text-body mt-1">{{ alert.last_seen_at_full || alert.last_seen_at || '—' }}</div>
                        </div>
                    </div>
                    <div class="data-list__row" v-if="alert.resolved_at_full">
                        <div>
                            <div class="text-caption text-muted">Resolved</div>
                            <div class="text-body-sm text-body mt-1">{{ alert.resolved_at_full }}</div>
                        </div>
                    </div>
                </div>

                <div class="mt-6">
                    <div class="eyebrow">Raw Context</div>
                    <pre class="mt-4 overflow-x-auto rounded-2xl border border-hairline bg-base-300/30 p-4 text-caption text-body">{{ contextJson }}</pre>
                </div>
            </section>

            <aside class="space-y-6">
                <section class="panel-card p-5">
                    <div class="eyebrow">Ownership</div>
                    <div class="mt-5 space-y-4">
                        <div>
                            <div class="text-caption text-muted">Acknowledged By</div>
                            <div class="text-body-sm text-body mt-1">{{ alert.acknowledged_by_name || 'Unclaimed' }}</div>
                        </div>
                        <div>
                            <div class="text-caption text-muted">Acknowledged At</div>
                            <div class="text-body-sm text-body mt-1">{{ alert.acknowledged_at_full || '—' }}</div>
                        </div>
                        <div>
                            <div class="text-caption text-muted">Comment</div>
                            <div class="text-body-sm text-body mt-1">{{ alert.acknowledge_comment || 'No comment yet.' }}</div>
                        </div>
                    </div>
                </section>

                <section v-if="canAcknowledge" class="panel-subtle p-5">
                    <div class="eyebrow">Acknowledge</div>
                    <form class="mt-5 space-y-4" @submit.prevent="acknowledge">
                        <div>
                            <label class="form-label" for="ack-comment">Comment</label>
                            <textarea
                                id="ack-comment"
                                v-model="form.comment"
                                rows="4"
                                class="textarea mt-2 w-full"
                                :class="{ 'textarea-error': form.errors.comment }"
                                placeholder="Who owns this and what is the next action?"
                            />
                            <p v-if="form.errors.comment" class="form-error">{{ form.errors.comment }}</p>
                        </div>

                        <button type="submit" class="btn btn-primary btn-sm" :disabled="form.processing">
                            Acknowledge Alert
                        </button>
                    </form>
                </section>
            </aside>
        </div>
    </AppLayout>
</template>
