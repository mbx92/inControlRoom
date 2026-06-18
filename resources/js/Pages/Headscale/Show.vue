<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const page = usePage();

const props = defineProps({
    integration: { type: Object, required: true },
    users: { type: Array, default: () => [] },
    nodes: { type: Array, default: () => [] },
    preAuthKeys: { type: Array, default: () => [] },
    apiKeys: { type: Array, default: () => [] },
    policy: { type: Object, default: null },
    health: { type: Object, default: null },
    stats: { type: Object, required: true },
    apiError: { type: String, default: null },
    featureErrors: { type: Object, default: () => ({}) },
});

const permissions = computed(() => page.props.auth.permissions ?? {});
const canExecute = computed(() => permissions.value.can_execute);
const isAdmin = computed(() => permissions.value.is_admin);
const canManagePolicy = computed(() => permissions.value.is_admin);

const usersState = ref([...props.users]);
const nodesState = ref([...props.nodes]);
const preAuthKeysState = ref([...props.preAuthKeys]);
const apiKeysState = ref([...props.apiKeys]);
const policyDraft = ref(props.policy?.text ?? '');
const policyUpdatedAt = ref(props.policy?.updated_at ?? null);
const flashMessage = ref('');
const actionError = ref('');
const busyAction = ref('');
const nodeModalVisible = ref(false);
const preAuthModalVisible = ref(false);
const rotatePreAuthModalVisible = ref(false);
const userModalVisible = ref(false);
const apiKeyModalVisible = ref(false);
const activeNode = ref(null);
const apiKeyReveal = ref('');
const rotatePreAuthSource = ref(null);
const rotatePreAuthReveal = ref('');
const copiedValue = ref('');

const nodeForm = reactive({
    newName: '',
    tagsText: '',
    routesText: '',
    disableExpiry: false,
});

const preAuthForm = reactive({
    userId: '',
    reusable: true,
    ephemeral: false,
    expiration: '',
    aclTagsText: '',
});

const rotatePreAuthForm = reactive({
    userId: '',
    reusable: true,
    ephemeral: false,
    expiration: '',
    aclTagsText: '',
    expireOldKey: true,
});

const userForm = reactive({
    id: '',
    name: '',
    mode: 'create',
});

const apiKeyForm = reactive({
    expiration: '',
});

const connectionHealth = computed(() => {
    if (props.integration.last_test_status === 'success') {
        return {
            label: 'Healthy',
            dotClass: 'signal-dot--live',
        };
    }

    if (props.integration.last_test_status === 'failure') {
        return {
            label: 'Needs attention',
            dotClass: 'signal-dot--critical',
        };
    }

    return {
        label: 'Not tested',
        dotClass: 'signal-dot--warning',
    };
});

const routeRows = computed(() => (
    nodesState.value
        .filter((node) => node.available_routes.length || node.approved_routes.length || node.subnet_routes.length)
        .map((node) => ({
            id: node.id,
            name: node.name,
            user_name: node.user_name,
            available: node.available_routes,
            approved: node.approved_routes,
            subnet: node.subnet_routes,
            pending: node.available_routes.filter((route) => !node.approved_routes.includes(route)),
        }))
));

const policyLineCount = computed(() => {
    if (!policyDraft.value.trim()) {
        return 0;
    }

    return policyDraft.value.split(/\r?\n/).length;
});

watch(() => props.users, (value) => {
    usersState.value = [...value];
});

watch(() => props.nodes, (value) => {
    nodesState.value = [...value];
});

watch(() => props.preAuthKeys, (value) => {
    preAuthKeysState.value = [...value];
});

watch(() => props.apiKeys, (value) => {
    apiKeysState.value = [...value];
});

function testConnection() {
    router.post(route('integrations.test', props.integration.id), {}, {
        preserveScroll: true,
    });
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return String(value).replace('T', ' ').replace('Z', '');
}

function terminalUrl(node) {
    return route('headscale.terminal.page', {
        integration: props.integration.id,
        node_name: node.name,
        host: node.ips?.[0] ?? '',
    });
}

function readCookie(name) {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? match[1] : null;
}

function splitList(value) {
    return String(value)
        .split(/[\n,]/)
        .map((item) => item.trim())
        .filter(Boolean);
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        method: options.method ?? 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(readCookie('XSRF-TOKEN') ?? ''),
            ...(options.headers ?? {}),
        },
        body: options.body ? JSON.stringify(options.body) : null,
    });

    let payload = {};

    try {
        payload = await response.json();
    } catch {
        payload = {};
    }

    if (!response.ok) {
        const message = payload.message
            ?? Object.values(payload.errors ?? {}).flat().join(' ')
            ?? `Request failed with HTTP ${response.status}.`;

        throw new Error(message);
    }

    return payload;
}

function replaceNode(updatedNode) {
    const index = nodesState.value.findIndex((node) => node.id === updatedNode.id);

    if (index === -1) {
        nodesState.value.unshift(updatedNode);
    } else {
        nodesState.value[index] = updatedNode;
    }

    if (activeNode.value?.id === updatedNode.id) {
        activeNode.value = updatedNode;
        seedNodeForm(updatedNode);
    }
}

function replaceUser(updatedUser) {
    const index = usersState.value.findIndex((user) => user.id === updatedUser.id);

    if (index === -1) {
        usersState.value.unshift(updatedUser);
    } else {
        usersState.value[index] = updatedUser;
    }
}

function removeUser(userId) {
    usersState.value = usersState.value.filter((user) => user.id !== userId);
}

function removeApiKey(prefix) {
    apiKeysState.value = apiKeysState.value.filter((key) => key.prefix !== prefix);
}

function seedNodeForm(node) {
    nodeForm.newName = node.name ?? '';
    nodeForm.tagsText = (node.tags ?? []).join(', ');
    nodeForm.routesText = (node.approved_routes ?? []).join(', ');
    nodeForm.disableExpiry = false;
}

function openNodeModal(node) {
    activeNode.value = node;
    seedNodeForm(node);
    actionError.value = '';
    flashMessage.value = '';
    nodeModalVisible.value = true;
}

function openPreAuthModal() {
    preAuthForm.userId = usersState.value[0]?.id ?? '';
    preAuthForm.reusable = true;
    preAuthForm.ephemeral = false;
    preAuthForm.expiration = '';
    preAuthForm.aclTagsText = '';
    actionError.value = '';
    flashMessage.value = '';
    preAuthModalVisible.value = true;
}

function openRotatePreAuthModal(key) {
    rotatePreAuthSource.value = key;
    rotatePreAuthReveal.value = '';
    rotatePreAuthForm.userId = key.user_id ?? '';
    rotatePreAuthForm.reusable = key.reusable;
    rotatePreAuthForm.ephemeral = key.ephemeral;
    rotatePreAuthForm.expiration = '';
    rotatePreAuthForm.aclTagsText = (key.acl_tags ?? []).join(', ');
    rotatePreAuthForm.expireOldKey = true;
    actionError.value = '';
    flashMessage.value = '';
    rotatePreAuthModalVisible.value = true;
}

function openUserCreateModal() {
    userForm.id = '';
    userForm.name = '';
    userForm.mode = 'create';
    actionError.value = '';
    flashMessage.value = '';
    userModalVisible.value = true;
}

function openUserRenameModal(user) {
    userForm.id = user.id;
    userForm.name = user.name;
    userForm.mode = 'rename';
    actionError.value = '';
    flashMessage.value = '';
    userModalVisible.value = true;
}

function openApiKeyModal() {
    apiKeyForm.expiration = '';
    apiKeyReveal.value = '';
    actionError.value = '';
    flashMessage.value = '';
    apiKeyModalVisible.value = true;
}

async function submitNodeTags() {
    if (!activeNode.value) {
        return;
    }

    busyAction.value = `tags:${activeNode.value.id}`;
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.nodes.tags', {
            integration: props.integration.id,
            nodeId: activeNode.value.id,
        }), {
            body: { tags: splitList(nodeForm.tagsText) },
        });

        replaceNode(payload.node);
        flashMessage.value = payload.message;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to update node tags.';
    } finally {
        busyAction.value = '';
    }
}

async function submitNodeRoutes() {
    if (!activeNode.value) {
        return;
    }

    busyAction.value = `routes:${activeNode.value.id}`;
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.nodes.routes', {
            integration: props.integration.id,
            nodeId: activeNode.value.id,
        }), {
            body: { routes: splitList(nodeForm.routesText) },
        });

        replaceNode(payload.node);
        flashMessage.value = payload.message;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to update approved routes.';
    } finally {
        busyAction.value = '';
    }
}

async function submitNodeRename() {
    if (!activeNode.value) {
        return;
    }

    busyAction.value = `rename:${activeNode.value.id}`;
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.nodes.rename', {
            integration: props.integration.id,
            nodeId: activeNode.value.id,
        }), {
            body: { new_name: nodeForm.newName },
        });

        replaceNode(payload.node);
        flashMessage.value = payload.message;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to rename node.';
    } finally {
        busyAction.value = '';
    }
}

async function submitNodeExpiry(disableExpiry = false) {
    if (!activeNode.value) {
        return;
    }

    busyAction.value = `expiry:${activeNode.value.id}`;
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.nodes.expire', {
            integration: props.integration.id,
            nodeId: activeNode.value.id,
        }), {
            body: { disable_expiry: disableExpiry },
        });

        replaceNode(payload.node);
        flashMessage.value = payload.message;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to update expiry.';
    } finally {
        busyAction.value = '';
    }
}

async function submitPreAuthKey() {
    busyAction.value = 'preauth-create';
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.preauth-keys.create', {
            integration: props.integration.id,
        }), {
            body: {
                user_id: Number(preAuthForm.userId),
                reusable: preAuthForm.reusable,
                ephemeral: preAuthForm.ephemeral,
                expiration: preAuthForm.expiration ? new Date(preAuthForm.expiration).toISOString() : null,
                acl_tags: splitList(preAuthForm.aclTagsText),
            },
        });

        preAuthKeysState.value.unshift(payload.pre_auth_key);
        flashMessage.value = payload.message;
        preAuthModalVisible.value = false;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to create pre-auth key.';
    } finally {
        busyAction.value = '';
    }
}

async function expirePreAuthKey(key) {
    busyAction.value = `preauth-expire:${key.id}`;
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.preauth-keys.expire', {
            integration: props.integration.id,
            keyId: key.id,
        }));

        preAuthKeysState.value = preAuthKeysState.value.map((item) => (
            item.id === key.id
                ? { ...item, used: true, expiration: item.expiration ?? new Date().toISOString() }
                : item
        ));
        flashMessage.value = payload.message;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to expire pre-auth key.';
    } finally {
        busyAction.value = '';
    }
}

async function submitRotatePreAuthKey() {
    if (!rotatePreAuthSource.value) {
        return;
    }

    busyAction.value = `preauth-rotate:${rotatePreAuthSource.value.id}`;
    actionError.value = '';

    try {
        const createPayload = await requestJson(route('headscale.preauth-keys.create', {
            integration: props.integration.id,
        }), {
            body: {
                user_id: Number(rotatePreAuthForm.userId),
                reusable: rotatePreAuthForm.reusable,
                ephemeral: rotatePreAuthForm.ephemeral,
                expiration: rotatePreAuthForm.expiration ? new Date(rotatePreAuthForm.expiration).toISOString() : null,
                acl_tags: splitList(rotatePreAuthForm.aclTagsText),
            },
        });

        preAuthKeysState.value.unshift(createPayload.pre_auth_key);
        rotatePreAuthReveal.value = createPayload.pre_auth_key?.key_full ?? '';

        if (rotatePreAuthForm.expireOldKey) {
            const expirePayload = await requestJson(route('headscale.preauth-keys.expire', {
                integration: props.integration.id,
                keyId: rotatePreAuthSource.value.id,
            }));

            preAuthKeysState.value = preAuthKeysState.value.map((item) => (
                item.id === rotatePreAuthSource.value.id
                    ? { ...item, used: true, expiration: item.expiration ?? new Date().toISOString() }
                    : item
            ));

            flashMessage.value = `${createPayload.message} ${expirePayload.message}`;
        } else {
            flashMessage.value = createPayload.message;
        }
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to rotate pre-auth key.';
    } finally {
        busyAction.value = '';
    }
}

async function savePolicy() {
    busyAction.value = 'policy-save';
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.policy.update', {
            integration: props.integration.id,
        }), {
            method: 'PUT',
            body: { policy: policyDraft.value },
        });

        policyDraft.value = payload.policy?.text ?? policyDraft.value;
        policyUpdatedAt.value = payload.policy?.updated_at ?? policyUpdatedAt.value;
        flashMessage.value = payload.message;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to update policy.';
    } finally {
        busyAction.value = '';
    }
}

async function submitUser() {
    busyAction.value = `user-${userForm.mode}`;
    actionError.value = '';

    try {
        if (userForm.mode === 'create') {
            const payload = await requestJson(route('headscale.users.create', {
                integration: props.integration.id,
            }), {
                body: { name: userForm.name },
            });

            replaceUser(payload.user);
            flashMessage.value = payload.message;
        } else {
            const payload = await requestJson(route('headscale.users.rename', {
                integration: props.integration.id,
                userId: userForm.id,
            }), {
                body: { new_name: userForm.name },
            });

            replaceUser(payload.user);
            flashMessage.value = payload.message;
        }

        userModalVisible.value = false;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to save user.';
    } finally {
        busyAction.value = '';
    }
}

async function deleteUser(user) {
    busyAction.value = `user-delete:${user.id}`;
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.users.delete', {
            integration: props.integration.id,
            userId: user.id,
        }), {
            method: 'DELETE',
        });

        removeUser(user.id);
        flashMessage.value = payload.message;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to delete user.';
    } finally {
        busyAction.value = '';
    }
}

async function submitApiKey() {
    busyAction.value = 'api-key-create';
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.api-keys.create', {
            integration: props.integration.id,
        }), {
            body: {
                expiration: apiKeyForm.expiration ? new Date(apiKeyForm.expiration).toISOString() : null,
            },
        });

        apiKeyReveal.value = payload.api_key ?? '';
        flashMessage.value = payload.message;
        router.reload({ only: ['apiKeys', 'stats', 'featureErrors'] });
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to create API key.';
    } finally {
        busyAction.value = '';
    }
}

async function deleteApiKey(key) {
    busyAction.value = `api-key-delete:${key.prefix}`;
    actionError.value = '';

    try {
        const payload = await requestJson(route('headscale.api-keys.delete', {
            integration: props.integration.id,
            prefix: key.prefix,
        }), {
            method: 'DELETE',
        });

        removeApiKey(key.prefix);
        flashMessage.value = payload.message;
    } catch (error) {
        actionError.value = error instanceof Error ? error.message : 'Unable to delete API key.';
    } finally {
        busyAction.value = '';
    }
}

async function copyToClipboard(value, successMessage = 'Copied to clipboard.') {
    if (!value) {
        return;
    }

    actionError.value = '';

    try {
        await navigator.clipboard.writeText(value);
        copiedValue.value = value;
        flashMessage.value = successMessage;
        window.setTimeout(() => {
            if (copiedValue.value === value) {
                copiedValue.value = '';
            }
        }, 2000);
    } catch {
        actionError.value = 'Unable to copy to clipboard.';
    }
}
</script>

<template>
    <Head :title="integration.name" />

    <AppLayout>
        <PageHeader
            :title="integration.name"
            subtitle="Headscale Manager"
            eyebrow="Mesh Control"
        >
            <template #meta>
                <span class="status-chip">
                    <span class="signal-dot" :class="connectionHealth.dotClass" />
                    {{ connectionHealth.label }}
                </span>
                <span class="status-chip">{{ integration.scope_kind === 'global' ? 'Global' : integration.scope_label }}</span>
            </template>

            <template #actions>
                <button type="button" class="btn btn-secondary" @click="testConnection">
                    Check API
                </button>
                <button v-if="isAdmin" type="button" class="btn btn-secondary" @click="openUserCreateModal">
                    New User
                </button>
                <button v-if="canExecute" type="button" class="btn btn-secondary" @click="openPreAuthModal">
                    New PreAuth Key
                </button>
                <button v-if="isAdmin" type="button" class="btn btn-secondary" @click="openApiKeyModal">
                    New API Key
                </button>
                <Link :href="route('integrations.edit', integration.id)" class="btn btn-primary">
                    Edit Source
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <div v-if="flashMessage" class="rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-body">
                {{ flashMessage }}
            </div>

            <div v-if="actionError" class="rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-error">
                {{ actionError }}
            </div>

            <section class="panel-card p-4 sm:p-5">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-6">
                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Domain</div>
                        <div class="text-title-sm text-body mt-3 break-all">{{ integration.base_host }}</div>
                        <div class="text-caption text-muted mt-2">server endpoint</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Nodes</div>
                        <div class="text-number-sm text-body mt-3">{{ nodesState.length }}</div>
                        <div class="text-caption text-muted mt-2">{{ stats.online_total }} online</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Users</div>
                        <div class="text-number-sm text-body mt-3">{{ usersState.length }}</div>
                        <div class="text-caption text-muted mt-2">identity records</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Subnet Routers</div>
                        <div class="text-number-sm text-body mt-3">{{ routeRows.length }}</div>
                        <div class="text-caption text-muted mt-2">nodes advertising routes</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">PreAuth Keys</div>
                        <div class="text-number-sm text-body mt-3">{{ preAuthKeysState.length }}</div>
                        <div class="text-caption text-muted mt-2">registration secrets</div>
                    </article>

                    <article class="rounded-xl border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">API Keys</div>
                        <div class="text-number-sm text-body mt-3">{{ apiKeysState.length }}</div>
                        <div class="text-caption text-muted mt-2">server auth tokens</div>
                    </article>
                </div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_360px]">
                <section class="space-y-6">
                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Control Plane</div>
                                <h2 class="text-title-md text-body mt-2">Headscale Feature Surface</h2>
                            </div>
                            <div class="status-chip">
                                <span class="signal-dot" :class="health?.database_connectivity ? 'signal-dot--live' : 'signal-dot--warning'" />
                                {{ health?.database_connectivity ? 'DB Connected' : 'DB Status Unknown' }}
                            </div>
                        </div>

                        <div class="mt-5 grid gap-5 sm:grid-cols-2">
                            <div>
                                <div class="text-caption text-muted">Server URL</div>
                                <div class="text-body-sm text-body mt-2 break-all font-mono-num">{{ integration.base_url }}</div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Management Endpoint</div>
                                <div class="text-body-sm text-body mt-2 break-all font-mono-num">{{ integration.api_health.endpoint }}</div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Secret Source</div>
                                <div class="text-body-sm text-body mt-2">{{ integration.secret_source_label }}</div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">SSL Verification</div>
                                <div class="text-body-sm text-body mt-2">
                                    {{ integration.api_health.verify_ssl === false ? 'Disabled' : 'Enabled' }}
                                </div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Last Check</div>
                                <div class="text-body-sm text-body mt-2">{{ integration.last_tested_at ?? 'Never' }}</div>
                            </div>

                            <div>
                                <div class="text-caption text-muted">Last Result</div>
                                <div class="text-body-sm text-body mt-2">{{ integration.last_test_message ?? 'No check recorded yet.' }}</div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Nodes</div>
                                <h2 class="text-title-md text-body mt-2">Managed Nodes</h2>
                            </div>
                            <div class="status-chip">{{ nodesState.length }} nodes</div>
                        </div>

                        <div v-if="apiError" class="mt-6 rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                            {{ apiError }}
                        </div>

                        <div v-else-if="nodesState.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No nodes returned by Headscale.
                        </div>

                        <div v-else class="mt-6 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Node</th>
                                        <th>User</th>
                                        <th>Status</th>
                                        <th>Register</th>
                                        <th>IPs</th>
                                        <th>Routes</th>
                                        <th>Tags</th>
                                        <th>Last Seen</th>
                                        <th v-if="canExecute">Terminal</th>
                                        <th v-if="canExecute">Manage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="node in nodesState" :key="node.id" class="border-hairline transition-default hover:bg-elevated/30">
                                        <td>
                                            <div class="text-body-sm text-body">{{ node.name }}</div>
                                            <div v-if="node.given_name && node.given_name !== node.name" class="text-caption text-muted mt-1">
                                                {{ node.given_name }}
                                            </div>
                                            <div class="text-caption text-muted mt-1">
                                                key {{ node.node_key ?? '-' }}
                                            </div>
                                        </td>
                                        <td class="text-body-sm text-body">{{ node.user_name }}</td>
                                        <td>
                                            <span class="status-chip">
                                                <span class="signal-dot" :class="node.is_online ? 'signal-dot--live' : 'signal-dot--warning'" />
                                                {{ node.is_online ? 'Online' : 'Offline' }}
                                            </span>
                                        </td>
                                        <td class="text-caption text-muted">{{ node.register_method }}</td>
                                        <td class="text-caption text-muted font-mono-num">
                                            {{ node.ips.length ? node.ips.join(', ') : '-' }}
                                        </td>
                                        <td class="text-caption text-muted">
                                            {{ node.approved_routes.length }}/{{ node.available_routes.length }} approved
                                        </td>
                                        <td class="text-caption text-muted">
                                            {{ node.tags.length ? node.tags.join(', ') : '-' }}
                                        </td>
                                        <td class="text-caption text-muted">{{ formatDate(node.last_seen) }}</td>
                                        <td v-if="canExecute">
                                            <a
                                                :href="terminalUrl(node)"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="btn btn-secondary btn-sm"
                                                :class="{ 'pointer-events-none opacity-50': !node.ips.length }"
                                            >
                                                Terminal
                                            </a>
                                        </td>
                                        <td v-if="canExecute">
                                            <button type="button" class="btn btn-ghost btn-sm" @click="openNodeModal(node)">
                                                Manage
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Routes</div>
                                <h2 class="text-title-md text-body mt-2">Subnet Router Overview</h2>
                            </div>
                            <div class="status-chip">{{ routeRows.length }} route nodes</div>
                        </div>

                        <div v-if="routeRows.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No advertised subnet routes found on this Headscale server.
                        </div>

                        <div v-else class="mt-6 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Node</th>
                                        <th>User</th>
                                        <th>Available</th>
                                        <th>Approved</th>
                                        <th>Pending</th>
                                        <th>Subnet</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in routeRows" :key="row.id" class="border-hairline transition-default hover:bg-elevated/30">
                                        <td class="text-body-sm text-body">{{ row.name }}</td>
                                        <td class="text-body-sm text-body">{{ row.user_name }}</td>
                                        <td class="text-caption text-muted font-mono-num">{{ row.available.length ? row.available.join(', ') : '-' }}</td>
                                        <td class="text-caption text-muted font-mono-num">{{ row.approved.length ? row.approved.join(', ') : '-' }}</td>
                                        <td class="text-caption text-muted font-mono-num">{{ row.pending.length ? row.pending.join(', ') : '-' }}</td>
                                        <td class="text-caption text-muted font-mono-num">{{ row.subnet.length ? row.subnet.join(', ') : '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">PreAuth Keys</div>
                                <h2 class="text-title-md text-body mt-2">Node Registration Keys</h2>
                            </div>
                            <button v-if="canExecute" type="button" class="btn btn-secondary btn-sm" @click="openPreAuthModal">
                                Create Key
                            </button>
                        </div>

                        <div v-if="featureErrors.pre_auth_keys" class="mt-6 rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                            {{ featureErrors.pre_auth_keys }}
                        </div>

                        <div v-else-if="preAuthKeysState.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No pre-auth keys returned by Headscale.
                        </div>

                        <div v-else class="mt-6 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>User</th>
                                        <th>Key</th>
                                        <th>Mode</th>
                                        <th>Tags</th>
                                        <th>Expires</th>
                                        <th>Created</th>
                                        <th v-if="canExecute">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="key in preAuthKeysState" :key="key.id" class="border-hairline transition-default hover:bg-elevated/30">
                                        <td class="text-body-sm text-body">{{ key.user_name }}</td>
                                        <td class="text-caption text-muted font-mono-num">{{ key.key_preview ?? '-' }}</td>
                                        <td class="text-caption text-muted">
                                            {{ key.ephemeral ? 'Ephemeral' : 'Persistent' }} / {{ key.reusable ? 'Reusable' : 'One-shot' }}
                                        </td>
                                        <td class="text-caption text-muted">{{ key.acl_tags.length ? key.acl_tags.join(', ') : '-' }}</td>
                                        <td class="text-caption text-muted">{{ formatDate(key.expiration) }}</td>
                                        <td class="text-caption text-muted">{{ formatDate(key.created_at) }}</td>
                                        <td v-if="canExecute">
                                            <div class="flex gap-2">
                                                <button
                                                    type="button"
                                                    class="btn btn-ghost btn-sm"
                                                    @click="openRotatePreAuthModal(key)"
                                                >
                                                    Rotate
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-ghost btn-sm"
                                                    :disabled="busyAction === `preauth-expire:${key.id}`"
                                                    @click="expirePreAuthKey(key)"
                                                >
                                                    Expire
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Policy</div>
                                <h2 class="text-title-md text-body mt-2">ACL / Policy Document</h2>
                            </div>
                            <div class="status-chip">{{ policyLineCount }} lines</div>
                        </div>

                        <div v-if="featureErrors.policy" class="mt-6 rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                            {{ featureErrors.policy }}
                        </div>

                        <div v-else class="mt-6 space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                                    <div class="text-caption text-muted">Updated</div>
                                    <div class="text-body-sm text-body mt-2">{{ formatDate(policyUpdatedAt) }}</div>
                                </div>
                                <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                                    <div class="text-caption text-muted">Mode</div>
                                    <div class="text-body-sm text-body mt-2">{{ canManagePolicy ? 'Editable' : 'Read only' }}</div>
                                </div>
                            </div>

                            <textarea
                                v-model="policyDraft"
                                class="textarea min-h-[320px] w-full font-mono-num text-sm"
                                :readonly="!canManagePolicy"
                                spellcheck="false"
                            />

                            <div class="flex items-center justify-between gap-3">
                                <div class="text-body-sm text-muted">
                                    Simpan policy hanya setelah `policy/check` di backend lolos.
                                </div>
                                <button
                                    v-if="canManagePolicy"
                                    type="button"
                                    class="btn btn-primary"
                                    :disabled="busyAction === 'policy-save'"
                                    @click="savePolicy"
                                >
                                    Save Policy
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="panel-card p-6">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="eyebrow">Users</div>
                                <h2 class="text-title-md text-body mt-2">Headscale Users</h2>
                            </div>
                            <div class="status-chip">{{ usersState.length }} users</div>
                        </div>

                        <div v-if="apiError" class="mt-6 rounded-lg border border-hairline bg-base-300 px-4 py-4 text-body-sm text-muted">
                            {{ apiError }}
                        </div>

                        <div v-else-if="usersState.length === 0" class="mt-6 rounded-lg border border-dashed border-hairline px-4 py-8 text-center text-body-sm text-muted">
                            No users returned by Headscale.
                        </div>

                        <div v-else class="mt-6 overflow-x-auto">
                            <table class="table table-sm">
                                <thead>
                                    <tr class="border-hairline">
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Provider</th>
                                        <th>Created</th>
                                        <th v-if="isAdmin">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="user in usersState" :key="user.id" class="border-hairline transition-default hover:bg-elevated/30">
                                        <td>
                                            <div class="text-body-sm text-body">{{ user.name }}</div>
                                            <div v-if="user.display_name && user.display_name !== user.name" class="text-caption text-muted mt-1">
                                                {{ user.display_name }}
                                            </div>
                                        </td>
                                        <td class="text-body-sm text-body">{{ user.email ?? '-' }}</td>
                                        <td class="text-body-sm text-body">{{ user.provider ?? 'local' }}</td>
                                        <td class="text-caption text-muted">{{ formatDate(user.created_at) }}</td>
                                        <td v-if="isAdmin">
                                            <div class="flex gap-2">
                                                <button type="button" class="btn btn-ghost btn-sm" @click="openUserRenameModal(user)">
                                                    Rename
                                                </button>
                                                <button
                                                    type="button"
                                                    class="btn btn-ghost btn-sm"
                                                    :disabled="busyAction === `user-delete:${user.id}`"
                                                    @click="deleteUser(user)"
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </section>

                <aside class="space-y-6">
                    <section class="panel-subtle p-5">
                        <div class="eyebrow">API Health</div>
                        <div class="data-list mt-5">
                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Status</div>
                                    <div class="mt-2 flex items-center gap-2 text-body-sm text-body">
                                        <span class="signal-dot" :class="connectionHealth.dotClass" />
                                        {{ connectionHealth.label }}
                                    </div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Auth Status</div>
                                    <div class="text-body-sm text-body mt-2">{{ integration.api_health.auth_status }}</div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Latency</div>
                                    <div class="text-body-sm text-body mt-2">
                                        {{ integration.api_health.latency_ms !== null ? `${integration.api_health.latency_ms} ms` : '-' }}
                                    </div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">HTTP Status</div>
                                    <div class="text-body-sm text-body mt-2">{{ integration.api_health.http_status ?? '-' }}</div>
                                </div>
                            </div>

                            <div class="data-list__row">
                                <div>
                                    <div class="text-caption text-muted">Health Endpoint</div>
                                    <div class="text-body-sm text-body mt-2 font-mono-num break-all">{{ integration.api_health.endpoint }}</div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-subtle p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div class="eyebrow">API Keys</div>
                            <button v-if="isAdmin" type="button" class="btn btn-ghost btn-sm" @click="openApiKeyModal">
                                Create
                            </button>
                        </div>
                        <div v-if="featureErrors.api_keys" class="mt-4 text-body-sm text-muted">
                            {{ featureErrors.api_keys }}
                        </div>
                        <div v-else-if="apiKeysState.length === 0" class="mt-4 text-body-sm text-muted">
                            No API keys returned by Headscale.
                        </div>
                        <div v-else class="mt-4 space-y-3">
                            <div v-for="key in apiKeysState" :key="key.id" class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                                <div class="text-caption text-muted">Prefix</div>
                                <div class="text-body-sm text-body mt-2 font-mono-num">{{ key.prefix }}</div>
                                <div class="text-caption text-muted mt-3">Expires {{ formatDate(key.expiration) }}</div>
                                <div class="text-caption text-muted mt-1">Last seen {{ formatDate(key.last_seen) }}</div>
                                <div v-if="isAdmin" class="mt-3 flex justify-end">
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        :disabled="busyAction === `api-key-delete:${key.prefix}`"
                                        @click="deleteApiKey(key)"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="panel-subtle p-5">
                        <div class="eyebrow">Terminal Notes</div>
                        <div class="mt-4 space-y-3 text-body-sm text-muted">
                            <p>Terminal tetap dibuka di tab baru agar sesi SSH tidak memutus konteks halaman detail Headscale.</p>
                            <p>Web SSH ini tetap butuh jalur jaringan dari server aplikasi atau proxy terminal ke IP Tailscale node target.</p>
                            <p>Untuk subnet router, approved route di Headscale tetap harus diikuti forwarding/NAT yang benar pada node router.</p>
                        </div>
                    </section>
                </aside>
            </div>
        </div>

        <div v-if="userModalVisible" class="headscale-modal">
            <div class="headscale-modal__backdrop" @click="userModalVisible = false" />
            <div class="headscale-modal__panel panel-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">User Management</div>
                        <h2 class="text-title-md text-body mt-2">
                            {{ userForm.mode === 'create' ? 'Create Headscale User' : 'Rename Headscale User' }}
                        </h2>
                    </div>

                    <button type="button" class="btn btn-ghost btn-sm" @click="userModalVisible = false">
                        Close
                    </button>
                </div>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="form-label" for="headscale-user-name">User Name</label>
                        <input id="headscale-user-name" v-model="userForm.name" type="text" class="input mt-2 w-full" />
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" class="btn btn-ghost" @click="userModalVisible = false">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-primary" :disabled="busyAction === `user-${userForm.mode}`" @click="submitUser">
                            {{ userForm.mode === 'create' ? 'Create User' : 'Save Rename' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="apiKeyModalVisible" class="headscale-modal">
            <div class="headscale-modal__backdrop" @click="apiKeyModalVisible = false" />
            <div class="headscale-modal__panel panel-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">API Key</div>
                        <h2 class="text-title-md text-body mt-2">Create Headscale API Key</h2>
                    </div>

                    <button type="button" class="btn btn-ghost btn-sm" @click="apiKeyModalVisible = false">
                        Close
                    </button>
                </div>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="form-label" for="headscale-api-expiration">Expiration</label>
                        <input id="headscale-api-expiration" v-model="apiKeyForm.expiration" type="datetime-local" class="input mt-2 w-full" />
                    </div>

                    <div v-if="apiKeyReveal" class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">New API Key</div>
                        <div class="text-body-sm text-body mt-2 font-mono-num break-all">{{ apiKeyReveal }}</div>
                        <div class="text-caption text-muted mt-2">Headscale hanya menampilkan token penuh satu kali.</div>
                        <div class="mt-3 flex justify-end">
                            <button type="button" class="btn btn-secondary btn-sm" @click="copyToClipboard(apiKeyReveal, 'API key copied.')">
                                {{ copiedValue === apiKeyReveal ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" class="btn btn-ghost" @click="apiKeyModalVisible = false">
                            Close
                        </button>
                        <button type="button" class="btn btn-primary" :disabled="busyAction === 'api-key-create'" @click="submitApiKey">
                            Create API Key
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="nodeModalVisible && activeNode" class="headscale-modal">
            <div class="headscale-modal__backdrop" @click="nodeModalVisible = false" />
            <div class="headscale-modal__panel panel-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Node Management</div>
                        <h2 class="text-title-md text-body mt-2">{{ activeNode.name }}</h2>
                    </div>

                    <button type="button" class="btn btn-ghost btn-sm" @click="nodeModalVisible = false">
                        Close
                    </button>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Machine Key</div>
                        <div class="text-body-sm text-body mt-2 font-mono-num">{{ activeNode.machine_key ?? '-' }}</div>
                    </div>
                    <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Disco Key</div>
                        <div class="text-body-sm text-body mt-2 font-mono-num">{{ activeNode.disco_key ?? '-' }}</div>
                    </div>
                </div>

                <div class="mt-5 space-y-5">
                    <div>
                        <label class="form-label" for="headscale-node-name">Rename Node</label>
                        <div class="mt-2 flex gap-3">
                            <input id="headscale-node-name" v-model="nodeForm.newName" type="text" class="input w-full" />
                            <button type="button" class="btn btn-primary" :disabled="busyAction === `rename:${activeNode.id}`" @click="submitNodeRename">
                                Rename
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="headscale-node-tags">Tags</label>
                        <textarea
                            id="headscale-node-tags"
                            v-model="nodeForm.tagsText"
                            class="textarea mt-2 min-h-[92px] w-full"
                            placeholder="tag:infra, tag:ssh"
                        />
                        <div class="mt-3 flex justify-end">
                            <button type="button" class="btn btn-secondary" :disabled="busyAction === `tags:${activeNode.id}`" @click="submitNodeTags">
                                Save Tags
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="form-label" for="headscale-node-routes">Approved Routes</label>
                        <textarea
                            id="headscale-node-routes"
                            v-model="nodeForm.routesText"
                            class="textarea mt-2 min-h-[92px] w-full font-mono-num"
                            placeholder="10.0.0.0/24, 192.168.10.0/24"
                        />
                        <div class="text-body-sm text-muted mt-2">
                            Available: {{ activeNode.available_routes.length ? activeNode.available_routes.join(', ') : '-' }}
                        </div>
                        <div class="mt-3 flex justify-end">
                            <button type="button" class="btn btn-secondary" :disabled="busyAction === `routes:${activeNode.id}`" @click="submitNodeRoutes">
                                Save Routes
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div>
                            <div class="text-caption text-muted">Expiry Control</div>
                            <div class="text-body-sm text-body mt-2">Current expiry: {{ formatDate(activeNode.expiry) }}</div>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button type="button" class="btn btn-ghost" :disabled="busyAction === `expiry:${activeNode.id}`" @click="submitNodeExpiry(false)">
                                Expire Now
                            </button>
                            <button type="button" class="btn btn-secondary" :disabled="busyAction === `expiry:${activeNode.id}`" @click="submitNodeExpiry(true)">
                                Disable Expiry
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="preAuthModalVisible" class="headscale-modal">
            <div class="headscale-modal__backdrop" @click="preAuthModalVisible = false" />
            <div class="headscale-modal__panel panel-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">PreAuth Key</div>
                        <h2 class="text-title-md text-body mt-2">Create Registration Key</h2>
                    </div>

                    <button type="button" class="btn btn-ghost btn-sm" @click="preAuthModalVisible = false">
                        Close
                    </button>
                </div>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="form-label" for="preauth-user">User</label>
                        <select id="preauth-user" v-model="preAuthForm.userId" class="select mt-2 w-full">
                            <option value="">Select user</option>
                            <option v-for="user in usersState" :key="user.id" :value="user.id">
                                {{ user.name }}
                            </option>
                        </select>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-lg border border-hairline bg-base-300 px-4 py-3">
                            <input v-model="preAuthForm.reusable" type="checkbox" class="checkbox" />
                            <span class="text-body-sm text-body">Reusable key</span>
                        </label>

                        <label class="flex items-center gap-3 rounded-lg border border-hairline bg-base-300 px-4 py-3">
                            <input v-model="preAuthForm.ephemeral" type="checkbox" class="checkbox" />
                            <span class="text-body-sm text-body">Ephemeral node</span>
                        </label>
                    </div>

                    <div>
                        <label class="form-label" for="preauth-expiration">Expiration</label>
                        <input id="preauth-expiration" v-model="preAuthForm.expiration" type="datetime-local" class="input mt-2 w-full" />
                    </div>

                    <div>
                        <label class="form-label" for="preauth-tags">ACL Tags</label>
                        <textarea
                            id="preauth-tags"
                            v-model="preAuthForm.aclTagsText"
                            class="textarea mt-2 min-h-[96px] w-full"
                            placeholder="tag:infra, tag:router"
                        />
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" class="btn btn-ghost" @click="preAuthModalVisible = false">
                            Cancel
                        </button>
                        <button type="button" class="btn btn-primary" :disabled="busyAction === 'preauth-create'" @click="submitPreAuthKey">
                            Create Key
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="rotatePreAuthModalVisible && rotatePreAuthSource" class="headscale-modal">
            <div class="headscale-modal__backdrop" @click="rotatePreAuthModalVisible = false" />
            <div class="headscale-modal__panel panel-card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Rotate Key</div>
                        <h2 class="text-title-md text-body mt-2">Rotate PreAuth Key</h2>
                    </div>

                    <button type="button" class="btn btn-ghost btn-sm" @click="rotatePreAuthModalVisible = false">
                        Close
                    </button>
                </div>

                <div class="mt-5 space-y-4">
                    <div class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">Current Key</div>
                        <div class="text-body-sm text-body mt-2 font-mono-num">{{ rotatePreAuthSource.key_preview ?? '-' }}</div>
                        <div class="text-caption text-muted mt-2">{{ rotatePreAuthSource.user_name }}</div>
                    </div>

                    <div>
                        <label class="form-label" for="rotate-preauth-user">User</label>
                        <select id="rotate-preauth-user" v-model="rotatePreAuthForm.userId" class="select mt-2 w-full">
                            <option value="">Select user</option>
                            <option v-for="user in usersState" :key="user.id" :value="user.id">
                                {{ user.name }}
                            </option>
                        </select>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="flex items-center gap-3 rounded-lg border border-hairline bg-base-300 px-4 py-3">
                            <input v-model="rotatePreAuthForm.reusable" type="checkbox" class="checkbox" />
                            <span class="text-body-sm text-body">Reusable key</span>
                        </label>

                        <label class="flex items-center gap-3 rounded-lg border border-hairline bg-base-300 px-4 py-3">
                            <input v-model="rotatePreAuthForm.ephemeral" type="checkbox" class="checkbox" />
                            <span class="text-body-sm text-body">Ephemeral node</span>
                        </label>
                    </div>

                    <div>
                        <label class="form-label" for="rotate-preauth-expiration">Expiration</label>
                        <input id="rotate-preauth-expiration" v-model="rotatePreAuthForm.expiration" type="datetime-local" class="input mt-2 w-full" />
                    </div>

                    <div>
                        <label class="form-label" for="rotate-preauth-tags">ACL Tags</label>
                        <textarea
                            id="rotate-preauth-tags"
                            v-model="rotatePreAuthForm.aclTagsText"
                            class="textarea mt-2 min-h-[96px] w-full"
                            placeholder="tag:infra, tag:router"
                        />
                    </div>

                    <label class="flex items-center gap-3 rounded-lg border border-hairline bg-base-300 px-4 py-3">
                        <input v-model="rotatePreAuthForm.expireOldKey" type="checkbox" class="checkbox" />
                        <span class="text-body-sm text-body">Expire old key after new key is created</span>
                    </label>

                    <div v-if="rotatePreAuthReveal" class="rounded-lg border border-hairline bg-base-300 px-4 py-4">
                        <div class="text-caption text-muted">New Rotated Key</div>
                        <div class="text-body-sm text-body mt-2 font-mono-num break-all">{{ rotatePreAuthReveal }}</div>
                        <div class="text-caption text-muted mt-2">Simpan token ini sekarang, full key tidak akan ditampilkan lagi.</div>
                        <div class="mt-3 flex justify-end">
                            <button type="button" class="btn btn-secondary btn-sm" @click="copyToClipboard(rotatePreAuthReveal, 'Rotated key copied.')">
                                {{ copiedValue === rotatePreAuthReveal ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button type="button" class="btn btn-ghost" @click="rotatePreAuthModalVisible = false">
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="busyAction === `preauth-rotate:${rotatePreAuthSource.id}`"
                            @click="submitRotatePreAuthKey"
                        >
                            Rotate Key
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.headscale-modal {
    position: fixed;
    inset: 0;
    z-index: 60;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
}

.headscale-modal__backdrop {
    position: absolute;
    inset: 0;
    background: rgb(2 6 23 / 0.72);
    backdrop-filter: blur(6px);
}

.headscale-modal__panel {
    position: relative;
    width: min(100%, 880px);
    max-height: calc(100vh - 3rem);
    overflow: auto;
}
</style>
