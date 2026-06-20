<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
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
const showPasswordModal = ref(false);
const revealPassword = ref('');
const revealError = ref('');
const revealSubmitting = ref(false);
const passwordInputRef = ref(null);

const displayedSecret = computed(() => (
    isRevealed.value && props.revealedSecret !== null
        ? props.revealedSecret
        : props.entry.masked_preview
));

watch(
    () => props.revealedSecret,
    (secret) => {
        if (secret !== null) {
            isRevealed.value = true;
        }
    },
);

watch(showPasswordModal, async (visible) => {
    document.body.style.overflow = visible ? 'hidden' : '';

    if (visible) {
        await nextTick();
        passwordInputRef.value?.focus();
    }
});

onBeforeUnmount(() => {
    document.body.style.overflow = '';
});

function openReveal() {
    if (isRevealed.value) {
        isRevealed.value = false;
        return;
    }

    if (props.revealedSecret !== null) {
        isRevealed.value = true;
        return;
    }

    showPasswordModal.value = true;
    revealPassword.value = '';
    revealError.value = '';
}

function closeRevealModal(force = false) {
    if (revealSubmitting.value && !force) {
        return;
    }

    showPasswordModal.value = false;
    revealPassword.value = '';
    revealError.value = '';
}

function formatRevealError(error) {
    if (Array.isArray(error)) {
        return error[0];
    }

    return error ?? 'Incorrect password.';
}

function submitReveal() {
    if (!revealPassword.value) {
        revealError.value = 'Password is required.';
        return;
    }

    revealError.value = '';
    revealSubmitting.value = true;

    router.post(route('vault.reveal', props.entry.id), {
        password: revealPassword.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            closeRevealModal(true);
            isRevealed.value = true;
        },
        onError: (errors) => {
            revealError.value = formatRevealError(errors.password);
        },
        onFinish: () => {
            revealSubmitting.value = false;
        },
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
                <button v-if="permissions.can_execute" type="button" class="btn btn-secondary" @click="openReveal">
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

        <!-- Password verification modal -->
        <Teleport to="body">
            <div
                v-if="showPasswordModal"
                class="vault-reveal-modal"
                role="dialog"
                aria-modal="true"
                aria-labelledby="vault-reveal-title"
                @keydown.escape="closeRevealModal"
            >
                <div class="vault-reveal-modal__backdrop" @click="closeRevealModal" />

                <div class="vault-reveal-modal__panel panel-card p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="eyebrow">Security Check</div>
                            <h3 id="vault-reveal-title" class="text-title-md text-body mt-2">Confirm Identity</h3>
                        </div>

                        <button
                            type="button"
                            class="btn btn-ghost btn-sm"
                            :disabled="revealSubmitting"
                            @click="closeRevealModal"
                        >
                            Close
                        </button>
                    </div>

                    <p class="text-body-sm text-muted mt-4">
                        Enter your account password to reveal the secret for
                        <span class="text-body font-medium">{{ entry.name }}</span>.
                        This action will be recorded in the audit trail.
                    </p>

                    <form class="mt-6 space-y-4" @submit.prevent="submitReveal">
                        <div>
                            <label class="form-label" for="reveal-password">Your Password</label>
                            <input
                                id="reveal-password"
                                ref="passwordInputRef"
                                v-model="revealPassword"
                                type="password"
                                class="input mt-2 w-full"
                                :class="{ 'input-error': revealError }"
                                placeholder="Enter your account password"
                                autocomplete="current-password"
                                :disabled="revealSubmitting"
                            />
                            <p v-if="revealError" class="form-error mt-2">{{ revealError }}</p>
                        </div>

                        <div class="flex flex-wrap justify-end gap-3 pt-2">
                            <button
                                type="button"
                                class="btn btn-ghost"
                                :disabled="revealSubmitting"
                                @click="closeRevealModal"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                class="btn btn-primary"
                                :disabled="revealSubmitting"
                            >
                                <span v-if="revealSubmitting" class="loading loading-spinner loading-xs"></span>
                                {{ revealSubmitting ? 'Verifying...' : 'Reveal Secret' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>

<style scoped>
.vault-reveal-modal {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
}

.vault-reveal-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgb(2 6 23 / 0.72);
    backdrop-filter: blur(6px);
}

.vault-reveal-modal__panel {
    position: relative;
    width: min(100%, 480px);
    max-height: calc(100vh - 3rem);
    overflow: auto;
}
</style>
