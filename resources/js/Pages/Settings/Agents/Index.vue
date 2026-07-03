<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import AgentInventoryLinkDialog from '@/Components/AgentInventoryLinkDialog.vue';
import AgentInventoryLinkStatus from '@/Components/AgentInventoryLinkStatus.vue';

const props = defineProps({
    tokens: { type: Array, default: () => [] },
    agents: { type: Array, default: () => [] },
    sites: { type: Array, default: () => [] },
    inventoryAssets: { type: Array, default: () => [] },
    generatedToken: { type: String, default: '' },
    installer: {
        type: Object,
        default: () => ({
            configured: false,
            available: false,
            version: '1.0.0',
            filename: 'InfraControl.Agent.Setup.exe',
            size_bytes: null,
            last_modified_at: null,
            bucket_download_url: null,
            bucket_download_url_expires_at: null,
        }),
    },
});

const copyMessage = ref('');

const linkDialogRef = ref(null);

const tokenForm = useForm({
    site_id: props.sites[0]?.id ?? '',
    name: '',
    expires_in_hours: 24,
    max_uses: 1,
});

const hasGeneratedToken = computed(() => props.generatedToken !== '');

function submitToken() {
    tokenForm.post(route('settings.agents.tokens.store'), {
        preserveScroll: true,
    });
}

function revokeToken(id) {
    useForm({}).post(route('settings.agents.tokens.revoke', id), {
        preserveScroll: true,
    });
}

function formatDate(value) {
    if (!value) {
        return '-';
    }

    return new Date(value).toLocaleString();
}

function tokenStatusClass(status) {
    return status === 'Active'
        ? 'status-chip'
        : 'status-chip opacity-80';
}

function agentStatusClass(status) {
    return status === 'Online'
        ? 'status-chip'
        : 'status-chip opacity-80';
}

function openInventoryLink(agent) {
    linkDialogRef.value?.open(agent);
}

function formatBytes(bytes) {
    if (!bytes) {
        return '-';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

async function copyBucketUrl() {
    if (!props.installer.bucket_download_url) {
        return;
    }

    try {
        await navigator.clipboard.writeText(props.installer.bucket_download_url);
        copyMessage.value = 'URL disalin.';
    } catch {
        copyMessage.value = 'Gagal menyalin URL.';
    }

    window.setTimeout(() => {
        copyMessage.value = '';
    }, 2000);
}
</script>

<template>
    <Head title="Agent Enrollment" />

    <AppLayout>
        <PageHeader
            title="Agent Enrollment"
            subtitle="Generate enrollment token per site, distribusikan ke installer, lalu pantau agent yang sudah berhasil enroll."
            eyebrow="InfraControl Agent"
        >
            <template #actions>
                <Link :href="route('settings.index')" class="btn btn-ghost">
                    Back to Settings
                </Link>
            </template>
        </PageHeader>

        <div class="space-y-8">
            <section class="panel-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Windows Installer</div>
                        <h2 class="mt-3 text-title-md text-body">Download Agent</h2>
                        <p class="mt-2 text-body-sm text-muted">
                            Installer Windows disimpan di MinIO dan diunduh melalui InfraControl.
                        </p>
                    </div>
                    <div class="status-chip">v{{ installer.version }}</div>
                </div>

                <div class="mt-5 grid gap-4 md:grid-cols-3">
                    <div class="rounded-2xl border border-hairline bg-base-300 p-4">
                        <div class="text-caption text-muted">File</div>
                        <div class="mt-2 text-body-sm text-body font-medium">{{ installer.filename }}</div>
                    </div>
                    <div class="rounded-2xl border border-hairline bg-base-300 p-4">
                        <div class="text-caption text-muted">Size</div>
                        <div class="mt-2 text-body-sm text-body font-medium">{{ formatBytes(installer.size_bytes) }}</div>
                    </div>
                    <div class="rounded-2xl border border-hairline bg-base-300 p-4">
                        <div class="text-caption text-muted">Updated</div>
                        <div class="mt-2 text-body-sm text-body font-medium">{{ formatDate(installer.last_modified_at) }}</div>
                    </div>
                </div>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <a
                        v-if="installer.available"
                        :href="route('settings.agents.installer.download')"
                        class="btn btn-primary"
                    >
                        Download Installer
                    </a>
                    <span v-else class="btn btn-primary btn-disabled">Download Installer</span>

                    <p v-if="!installer.configured" class="text-body-sm text-muted">
                        MinIO belum dikonfigurasi. Set <code>MINIO_ENDPOINT</code>, <code>MINIO_ACCESS_KEY</code>, <code>MINIO_SECRET_KEY</code>, dan <code>MINIO_BUCKET</code>.
                    </p>
                    <p v-else-if="!installer.available" class="text-body-sm text-muted">
                        Installer belum di-upload. Jalankan <code>php artisan agent:publish-installer</code> setelah build.
                    </p>
                </div>

                <div v-if="installer.bucket_download_url" class="mt-5 rounded-2xl border border-hairline bg-base-300 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <div class="text-caption text-muted">Shareable Download URL</div>
                            <p class="mt-2 text-body-sm text-muted">
                                Link langsung ke installer di MinIO.
                                <span v-if="installer.bucket_download_url_expires_at">
                                    Berlaku sampai {{ formatDate(installer.bucket_download_url_expires_at) }}.
                                </span>
                                <span v-else>
                                    Bucket masih private; gunakan tombol Download Installer di atas jika link ini ditolak.
                                </span>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="btn btn-ghost btn-sm" @click="copyBucketUrl">
                                Copy URL
                            </button>
                            <a
                                :href="installer.bucket_download_url"
                                class="btn btn-secondary btn-sm"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Open
                            </a>
                        </div>
                    </div>
                    <code class="mt-4 block break-all text-body-sm text-body font-mono-num">{{ installer.bucket_download_url }}</code>
                    <p v-if="copyMessage" class="mt-2 text-caption text-muted">{{ copyMessage }}</p>
                </div>
            </section>

            <section v-if="hasGeneratedToken" class="panel-card p-5">
                <div class="eyebrow">Copy Now</div>
                <h2 class="mt-3 text-title-md text-body">Enrollment token baru</h2>
                <p class="mt-2 text-body-sm text-muted">
                    Token penuh hanya ditampilkan sekali. Simpan lalu gunakan di GUI agent pada device client.
                </p>
                <div class="mt-4 rounded-2xl border border-hairline bg-base-300 p-4">
                    <code class="break-all text-body-sm text-body font-mono-num">{{ generatedToken }}</code>
                </div>
            </section>

            <section class="grid gap-5 xl:grid-cols-[0.95fr_1.05fr]">
                <form class="panel-card p-5 space-y-5" @submit.prevent="submitToken">
                    <div>
                        <div class="eyebrow">Generator</div>
                        <h2 class="mt-3 text-title-md text-body">Create Enrollment Token</h2>
                        <p class="mt-2 text-body-sm text-muted">
                            Buat token enroll one-time atau limited-use untuk dikirim ke client installer.
                        </p>
                    </div>

                    <div>
                        <label class="form-label" for="agent-site">Site</label>
                        <select id="agent-site" v-model="tokenForm.site_id" class="select select-bordered mt-2 w-full">
                            <option value="" disabled>Select site</option>
                            <option v-for="site in sites" :key="site.id" :value="site.id">
                                {{ site.name }} ({{ site.code }})
                            </option>
                        </select>
                        <p v-if="tokenForm.errors.site_id" class="form-error mt-2">{{ tokenForm.errors.site_id }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="agent-token-name">Label</label>
                        <input
                            id="agent-token-name"
                            v-model="tokenForm.name"
                            type="text"
                            class="input input-bordered mt-2 w-full"
                            placeholder="Contoh: Pilot Branch A - Windows"
                        />
                        <p v-if="tokenForm.errors.name" class="form-error mt-2">{{ tokenForm.errors.name }}</p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="form-label" for="agent-token-expiry">Expire In Hours</label>
                            <input
                                id="agent-token-expiry"
                                v-model="tokenForm.expires_in_hours"
                                type="number"
                                min="1"
                                max="720"
                                class="input input-bordered mt-2 w-full"
                            />
                            <p v-if="tokenForm.errors.expires_in_hours" class="form-error mt-2">{{ tokenForm.errors.expires_in_hours }}</p>
                        </div>

                        <div>
                            <label class="form-label" for="agent-token-uses">Max Uses</label>
                            <input
                                id="agent-token-uses"
                                v-model="tokenForm.max_uses"
                                type="number"
                                min="1"
                                max="100"
                                class="input input-bordered mt-2 w-full"
                            />
                            <p v-if="tokenForm.errors.max_uses" class="form-error mt-2">{{ tokenForm.errors.max_uses }}</p>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" :disabled="tokenForm.processing">
                        {{ tokenForm.processing ? 'Generating...' : 'Generate Token' }}
                    </button>
                </form>

                <div class="panel-card p-5">
                    <div class="eyebrow">Usage Notes</div>
                    <h2 class="mt-3 text-title-md text-body">How to use</h2>
                    <ol class="mt-4 space-y-3 text-body-sm text-body">
                        <li>1. Generate token untuk site target.</li>
                        <li>2. Download dan install agent di device client.</li>
                        <li>3. Buka <code>InfraControl Agent Config</code> dari Start Menu.</li>
                        <li>4. Isi <code>Server URL</code> dan <code>Enrollment Token</code>.</li>
                        <li>5. Klik <code>Save Config</code> lalu <code>Start</code>.</li>
                    </ol>

                    <div class="mt-5 rounded-2xl border border-hairline bg-base-300 p-4 text-body-sm text-muted">
                        Setelah enroll berhasil, token one-time akan otomatis habis pakai dan agent akan menerima bearer token permanen untuk heartbeat berikutnya.
                    </div>
                </div>
            </section>

            <section class="panel-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Tokens</div>
                        <h2 class="mt-3 text-title-md text-body">Enrollment Token List</h2>
                    </div>
                    <div class="status-chip">{{ tokens.length }} tokens</div>
                </div>

                <div v-if="tokens.length === 0" class="mt-5 text-body-sm text-muted">
                    No enrollment tokens created yet.
                </div>

                <div v-else class="mt-5 overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Label</th>
                                <th>Site</th>
                                <th>Status</th>
                                <th>Uses</th>
                                <th>Expires</th>
                                <th>Last Used</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="token in tokens" :key="token.id">
                                <td>
                                    <div class="font-medium text-body">{{ token.name }}</div>
                                    <div class="text-caption text-muted">{{ token.created_by }}</div>
                                </td>
                                <td>{{ token.site_name }}</td>
                                <td><span :class="tokenStatusClass(token.status)">{{ token.status }}</span></td>
                                <td>{{ token.used_count }} / {{ token.max_uses }}</td>
                                <td>{{ formatDate(token.expires_at) }}</td>
                                <td>{{ formatDate(token.last_used_at) }}</td>
                                <td class="text-right">
                                    <button
                                        v-if="token.is_available"
                                        type="button"
                                        class="btn btn-ghost btn-sm"
                                        @click="revokeToken(token.id)"
                                    >
                                        Revoke
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="panel-card p-5">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <div class="eyebrow">Agents</div>
                        <h2 class="mt-3 text-title-md text-body">Registered Agents</h2>
                    </div>
                    <div class="status-chip">{{ agents.length }} agents</div>
                </div>

                <div v-if="agents.length === 0" class="mt-5 text-body-sm text-muted">
                    No agents have enrolled yet.
                </div>

                <div v-else class="mt-5 overflow-x-auto">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Hostname</th>
                                <th>Site</th>
                                <th>Status</th>
                                <th>Device ID</th>
                                <th>IP</th>
                                <th>OS</th>
                                <th>Version</th>
                                <th>Inventory</th>
                                <th>Last Seen</th>
                                <th />
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="agent in agents" :key="agent.id">
                                <td>
                                    <div class="font-medium text-body">{{ agent.hostname }}</div>
                                    <div class="text-caption text-muted">{{ agent.token_name || 'Direct enroll' }}</div>
                                </td>
                                <td>{{ agent.site_name }}</td>
                                <td><span :class="agentStatusClass(agent.status)">{{ agent.status }}</span></td>
                                <td class="font-mono-num text-caption">{{ agent.device_id }}</td>
                                <td>{{ agent.primary_ip || '-' }}</td>
                                <td>{{ agent.os || '-' }}</td>
                                <td>{{ agent.agent_version || '-' }}</td>
                                <td>
                                    <AgentInventoryLinkStatus :agent="agent" />
                                    <button
                                        type="button"
                                        class="btn btn-ghost btn-xs mt-2"
                                        @click="openInventoryLink(agent)"
                                    >
                                        {{ agent.inventory_asset ? 'Change Link' : 'Link Inventory' }}
                                    </button>
                                </td>
                                <td>{{ formatDate(agent.last_seen_at) }}</td>
                                <td class="text-right">
                                    <Link :href="route('agents.metrics.show', agent.id)" class="btn btn-ghost btn-sm">
                                        Metrics
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <AgentInventoryLinkDialog
                ref="linkDialogRef"
                :inventory-assets="inventoryAssets"
            />
        </div>
    </AppLayout>
</template>
