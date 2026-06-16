<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

defineProps({
    roleOptions: { type: Array, required: true },
    allSites: { type: Array, required: true },
});

const form = useForm({
    name: '',
    email: '',
    role: 'operator',
    password: '',
    site_ids: [],
});

function toggleSite(siteId) {
    const idx = form.site_ids.indexOf(siteId);

    if (idx === -1) {
        form.site_ids.push(siteId);
    } else {
        form.site_ids.splice(idx, 1);
    }
}

const isScopeRelevant = computed(() => form.role === 'operator' || form.role === 'viewer');
</script>

<template>
    <Head title="Invite User" />

    <AppLayout>
        <PageHeader
            title="Invite User"
            subtitle="Tambahkan akun operator baru ke control room dengan scope site."
            eyebrow="Admin"
        />

        <section class="panel-card p-6 lg:p-7 max-w-2xl">
            <form class="space-y-6" @submit.prevent="form.post(route('users.store'))">
                <div class="grid gap-5 md:grid-cols-2">
                    <div>
                        <label class="form-label" for="user-name">Full Name</label>
                        <input
                            id="user-name"
                            v-model="form.name"
                            type="text"
                            class="input mt-2 w-full"
                            :class="{ 'input-error': form.errors.name }"
                            placeholder="John Doe"
                            required
                        />
                        <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
                    </div>

                    <div>
                        <label class="form-label" for="user-email">Email</label>
                        <input
                            id="user-email"
                            v-model="form.email"
                            type="email"
                            class="input mt-2 w-full"
                            :class="{ 'input-error': form.errors.email }"
                            placeholder="john@company.com"
                            required
                        />
                        <p v-if="form.errors.email" class="form-error">{{ form.errors.email }}</p>
                    </div>
                </div>

                <div>
                    <label class="form-label" for="user-role">Role</label>
                    <select
                        id="user-role"
                        v-model="form.role"
                        class="select mt-2 w-full"
                        :class="{ 'select-error': form.errors.role }"
                        required
                    >
                        <option v-for="opt in roleOptions" :key="opt.value" :value="opt.value">
                            {{ opt.label }}
                        </option>
                    </select>
                    <p class="text-body-sm text-muted mt-2">
                        <strong>Admin</strong>: full access ke semua site ·
                        <strong>Operator</strong>: execute actions di site assigned ·
                        <strong>Viewer</strong>: read-only di site assigned
                    </p>
                    <p v-if="form.errors.role" class="form-error">{{ form.errors.role }}</p>
                </div>

                <div v-if="isScopeRelevant">
                    <label class="form-label">Site Scope</label>
                    <p class="text-body-sm text-muted mt-1 mb-3">
                        Pilih site yang bisa diakses user ini. Kosongkan agar user bisa lihat semua site.
                    </p>

                    <div v-if="allSites.length === 0" class="text-body-sm text-muted italic">
                        Belum ada site terdaftar. Buat site dulu di <strong>Settings → Sites</strong>.
                    </div>

                    <div v-else class="grid gap-3 md:grid-cols-2">
                        <label
                            v-for="site in allSites"
                            :key="site.id"
                            class="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3 transition-default hover:border-primary/40"
                            :class="{ 'border-primary bg-primary/5': form.site_ids.includes(site.id) }"
                        >
                            <input
                                type="checkbox"
                                class="checkbox checkbox-primary mt-0.5"
                                :checked="form.site_ids.includes(site.id)"
                                @change="toggleSite(site.id)"
                            />
                            <div>
                                <div class="text-body-sm font-semibold text-body">{{ site.name }}</div>
                                <div class="text-caption text-muted">{{ site.code }}</div>
                            </div>
                        </label>
                    </div>
                    <p v-if="form.errors.site_ids" class="form-error mt-2">{{ form.errors.site_ids }}</p>
                </div>

                <div v-else class="panel-subtle p-4 rounded-lg">
                    <div class="text-body-sm text-body">
                        <strong>Admin</strong> otomatis memiliki akses ke <strong>semua site</strong>. Site scope hanya berlaku untuk Operator dan Viewer.
                    </div>
                </div>

                <div>
                    <label class="form-label" for="user-password">Password</label>
                    <input
                        id="user-password"
                        v-model="form.password"
                        type="password"
                        class="input mt-2 w-full"
                        :class="{ 'input-error': form.errors.password }"
                        placeholder="Min. 8 characters"
                        required
                    />
                    <p v-if="form.errors.password" class="form-error">{{ form.errors.password }}</p>
                </div>

                <div class="flex flex-wrap gap-3 pt-2">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :class="{ loading: form.processing }"
                        :disabled="form.processing"
                    >
                        Create User
                    </button>
                    <Link :href="route('users.index')" class="btn btn-ghost">
                        Cancel
                    </Link>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
