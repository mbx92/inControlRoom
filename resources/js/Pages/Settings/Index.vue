<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import ThemeSwitcher from '@/Components/ThemeSwitcher.vue';

const page = usePage();
const isAdmin = computed(() => page.props.auth.permissions?.is_admin ?? false);
</script>

<template>
    <Head title="Settings" />

    <AppLayout>
        <PageHeader
            title="Settings"
            subtitle="Theme control dan konfigurasi printer thermal."
            eyebrow="Settings"
        />

        <div class="space-y-8">
            <section>
                <div class="eyebrow">Control Plane</div>
                <h2 class="text-title-lg text-body mt-3">Headscale Manager</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Modul khusus untuk domain Headscale, API key dari vault, monitoring node, dan user inventory dari control plane.
                </p>

                <div class="flex flex-wrap gap-3">
                    <Link :href="route('headscale.index')" class="btn btn-primary">
                        Open Headscale Module
                    </Link>
                    <Link :href="route('integrations.index')" class="btn btn-secondary">
                        View All Integrations
                    </Link>
                </div>
            </section>

            <hr class="border-border" />

            <section>
                <div class="eyebrow">Appearance</div>
                <h2 class="text-title-lg text-body mt-3">Theme Control</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Pilih karakter visual yang paling pas untuk ritme kerja tim.
                </p>

                <ThemeSwitcher :framed="false" :show-header="false" />
            </section>

            <hr class="border-border" />

            <section>
                <div class="eyebrow">Peripheral</div>
                <h2 class="text-title-lg text-body mt-3">Label Printer</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Konfigurasikan printer thermal via SMB Windows atau LAN raw TCP untuk cetak label asset 50×15mm.
                </p>

                <Link :href="route('print-smb.index')" class="btn btn-primary">
                    Configure Printer
                </Link>
            </section>

            <hr class="border-border" />

            <section v-if="isAdmin">
                <div class="eyebrow">Alerting</div>
                <h2 class="text-title-lg text-body mt-3">Delivery and Rules</h2>
                <p class="text-body-sm text-muted mt-2 mb-6">
                    Kelola channel Telegram per-site dan threshold rules untuk health degradation, guest down, dan resource pressure.
                </p>

                <div class="flex flex-wrap gap-3">
                    <Link :href="route('notification-channels.index')" class="btn btn-primary">
                        Notification Channels
                    </Link>
                    <Link :href="route('alert-rules.index')" class="btn btn-secondary">
                        Alert Rules
                    </Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
