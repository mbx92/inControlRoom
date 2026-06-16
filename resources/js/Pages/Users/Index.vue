<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const page = usePage();

const props = defineProps({
    users: { type: Array, required: true },
    roleOptions: { type: Array, required: true },
    allSites: { type: Array, required: true },
});

const currentUserId = page.props.auth.user?.id;

const editingUser = ref(null);
const selectedSiteIds = ref([]);
const dialogRef = ref(null);

function openSiteDialog(user) {
    editingUser.value = user;
    selectedSiteIds.value = (user.sites ?? []).map(s => s.id);
    dialogRef.value?.showModal();
}

function closeSiteDialog() {
    editingUser.value = null;
    selectedSiteIds.value = [];
    dialogRef.value?.close();
}

function toggleSite(siteId) {
    const idx = selectedSiteIds.value.indexOf(siteId);
    if (idx === -1) {
        selectedSiteIds.value.push(siteId);
    } else {
        selectedSiteIds.value.splice(idx, 1);
    }
}

function saveSites() {
    if (!editingUser.value) return;

    router.put(route('users.update-sites', editingUser.value.id), {
        site_ids: selectedSiteIds.value,
    }, {
        preserveScroll: true,
        onSuccess: () => closeSiteDialog(),
    });
}

function changeRole(user, newRole) {
    router.put(route('users.update-role', user.id), { role: newRole }, {
        preserveScroll: true,
    });
}

function removeUser(user) {
    if (!confirm(`Hapus user "${user.name}"? Semua data audit dan access log akan tetap tersimpan.`)) {
        return;
    }

    router.delete(route('users.destroy', user.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Users" />

    <AppLayout>
        <PageHeader
            title="User Management"
            subtitle="Kelola akun operator control room dengan akses berbasis role dan scope per site."
            eyebrow="Admin"
        >
            <template #meta>
                <span class="status-chip">{{ users.length }} user(s)</span>
                <span class="status-chip">Admin only</span>
            </template>
        </PageHeader>

        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div class="text-body-sm text-muted">
                    Admin: semua site · Operator/Viewer: dibatasi ke site yg di-assign. Kosongkan site assignment agar user bisa lihat semua.
                </div>

                <Link :href="route('users.create')" class="btn btn-primary btn-sm">
                    Invite User
                </Link>
            </div>

            <section class="panel-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="table w-full">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Sites</th>
                                <th>Created</th>
                                <th class="text-right">Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <tr v-for="user in users" :key="user.id">
                                <td>
                                    <div>
                                        <span class="text-body-sm font-semibold text-body">{{ user.name }}</span>
                                        <span
                                            v-if="user.id === currentUserId"
                                            class="text-caption text-muted ml-2"
                                        >(you)</span>
                                    </div>
                                    <div class="text-caption text-muted">{{ user.email }}</div>
                                </td>

                                <td>
                                    <select
                                        v-if="user.id !== currentUserId"
                                        :value="user.role"
                                        class="select select-xs"
                                        @change="changeRole(user, ($event.target.value))"
                                    >
                                        <option
                                            v-for="opt in roleOptions"
                                            :key="opt.value"
                                            :value="opt.value"
                                        >
                                            {{ opt.label }}
                                        </option>
                                    </select>
                                    <span v-else class="status-chip">{{ user.role_label }}</span>
                                </td>

                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        <span
                                            v-if="user.sites.length === 0"
                                            class="text-caption text-muted"
                                        >All sites</span>
                                        <span
                                            v-for="site in user.sites"
                                            :key="site.id"
                                            class="status-chip"
                                        >{{ site.name }}</span>
                                    </div>
                                </td>

                                <td>
                                    <span class="text-caption text-muted">{{ user.created_at }}</span>
                                </td>

                                <td>
                                    <div class="flex items-center justify-end">
                                        <button
                                            v-if="user.id !== currentUserId"
                                            type="button"
                                            class="btn btn-ghost btn-xs"
                                            @click="openSiteDialog(user)"
                                        >
                                            Sites
                                        </button>
                                        <button
                                            v-if="user.id !== currentUserId"
                                            type="button"
                                            class="btn btn-ghost btn-xs text-error"
                                            @click="removeUser(user)"
                                        >
                                            Remove
                                        </button>
                                        <span v-else class="text-caption text-muted">—</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <!-- daisyUI Modal -->
        <dialog ref="dialogRef" class="modal">
            <div class="modal-box !max-w-2xl">
                <h3 class="text-title-lg text-body">
                    Site Access
                </h3>
                <p class="text-body-sm text-body mt-1">
                    {{ editingUser?.name }}
                </p>
                <p class="text-body-sm text-muted mt-3">
                    Pilih site yang bisa diakses user ini. Kosongkan semua agar user bisa lihat semua site.
                </p>

                <div v-if="allSites.length === 0" class="text-body-sm text-muted italic mt-4">
                    Belum ada site terdaftar.
                </div>

                <div v-else class="mt-5 space-y-2">
                    <label
                        v-for="site in allSites"
                        :key="site.id"
                        class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 transition-default hover:border-primary/40"
                        :class="{ 'border-primary bg-primary/5': selectedSiteIds.includes(site.id) }"
                    >
                        <input
                            type="checkbox"
                            class="checkbox checkbox-primary mt-0.5"
                            :checked="selectedSiteIds.includes(site.id)"
                            @change="toggleSite(site.id)"
                        />
                        <div>
                            <div class="text-body-sm font-semibold text-body">{{ site.name }}</div>
                            <div class="text-caption text-muted">{{ site.code }}</div>
                        </div>
                    </label>
                </div>

                <div class="modal-action">
                    <form method="dialog">
                        <button class="btn btn-ghost" type="button" @click="closeSiteDialog">Cancel</button>
                    </form>
                    <button class="btn btn-primary" @click="saveSites">Save</button>
                </div>
            </div>

            <form method="dialog" class="modal-backdrop">
                <button>close</button>
            </form>
        </dialog>
    </AppLayout>
</template>
