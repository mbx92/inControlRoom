<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FlashMessage from '@/Components/FlashMessage.vue';

const page = usePage();

const user = computed(() => page.props.auth.user);

const isAdmin = computed(() => user.value?.role === 'admin');

const allNavItems = [
    {
        name: 'Dashboard',
        route: 'dashboard',
        description: 'System posture and live signal density',
        icon: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    },
    {
        name: 'Alerts',
        route: 'alerts.index',
        description: 'Acknowledgement queue, recovery flow, and incident pressure',
        icon: 'M12 9v4m0 4h.01M10.29 3.86l-7.5 13A1 1 0 003.65 18h16.7a1 1 0 00.86-1.5l-7.5-13a1 1 0 00-1.72 0z',
    },
    {
        name: 'Topology',
        route: 'topology.index',
        description: 'Live infrastructure map — inventory assets and Proxmox hypervisors',
        icon: 'M4 5a1 1 0 011-1h4a1 1 0 011 1v5a1 1 0 01-1 1H5a1 1 0 01-1-1V5zm10 0a1 1 0 011-1h4a1 1 0 011 1v3a1 1 0 01-1 1h-4a1 1 0 01-1-1V5zM4 16a1 1 0 011-1h4a1 1 0 011 1v3a1 1 0 01-1 1H5a1 1 0 01-1-1v-3zm10-3a1 1 0 011-1h4a1 1 0 011 1v6a1 1 0 01-1 1h-4a1 1 0 01-1-1v-6z',
    },
    {
        name: 'Integrations',
        route: 'integrations.index',
        description: 'Provision and validate infrastructure and API systems',
        icon: 'M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1',
    },
    {
        name: 'Vault',
        route: 'vault.index',
        description: 'Encrypted secrets for operators and integrations',
        icon: 'M12 8c-2.21 0-4 1.79-4 4v5h8v-5c0-2.21-1.79-4-4-4zm6 4v5a2 2 0 01-2 2H8a2 2 0 01-2-2v-5a6 6 0 1112 0z',
    },
    {
        name: 'Inventory',
        route: 'inventory.index',
        description: 'Lean CMDB for asset inventory and operational ownership',
        icon: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10',
    },
    {
        name: 'Audit Log',
        route: 'audit-logs.index',
        description: 'Immutable evidence of every operator action',
        icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    },
    {
        name: 'Settings',
        route: 'settings.index',
        description: 'Theme control and shared room preferences',
        icon: 'M11.49 3.17c.325-1.423 2.695-1.423 3.02 0a1.724 1.724 0 002.573 1.066c1.25-.75 2.877.877 2.127 2.127a1.724 1.724 0 001.065 2.573c1.424.325 1.424 2.695 0 3.02a1.724 1.724 0 00-1.065 2.573c.75 1.25-.877 2.877-2.127 2.127a1.724 1.724 0 00-2.573 1.065c-.325 1.424-2.695 1.424-3.02 0a1.724 1.724 0 00-2.573-1.065c-1.25.75-2.877-.877-2.127-2.127a1.724 1.724 0 00-1.066-2.573c-1.423-.325-1.423-2.695 0-3.02a1.724 1.724 0 001.066-2.573c-.75-1.25.877-2.877 2.127-2.127A1.724 1.724 0 0011.49 3.17zM13 15a3 3 0 100-6 3 3 0 000 6z',
    },
];

const adminNavItems = [
    {
        name: 'Sites',
        route: 'sites.index',
        description: 'Location registry and scope ownership',
        icon: 'M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0L6.343 16.657a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z',
    },
    {
        name: 'Users',
        route: 'users.index',
        description: 'Operator accounts and role-based access control',
        icon: 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
    },
];

const navItems = computed(() => {
    const visible = [...allNavItems];

    // Hide Vault from viewers
    if (user.value?.role === 'viewer') {
        return visible.filter(item => item.route !== 'vault.index');
    }

    // Append Users for admin
    if (isAdmin.value) {
        visible.push(...adminNavItems);
    }

    return visible;
});

const routeMeta = computed(() => {
    const current = route().current() ?? 'dashboard';

    if (current.startsWith('topology.')) {
        return {
            section: 'Topology Map',
            detail: 'Live infrastructure map — inventory assets and Proxmox hypervisors',
        };
    }

    if (current.startsWith('alerts.')) {
        return {
            section: 'Signal Queue',
            detail: 'Acknowledgement flow, active incidents, and recovery history',
        };
    }

    if (current.startsWith('integrations.')) {
        return {
            section: 'Source Mesh',
            detail: 'Infrastructure endpoints, custom APIs, and sync health',
        };
    }

    if (current.startsWith('audit-logs.')) {
        return {
            section: 'Evidence Ledger',
            detail: 'Verified action history and operational forensics',
        };
    }

    if (current.startsWith('vault.')) {
        return {
            section: 'Vault Grid',
            detail: 'Encrypted secrets, scoped access, and reveal audit',
        };
    }

    if (current.startsWith('inventory.')) {
        return {
            section: 'Asset Register',
            detail: 'Custom inventory records, lightweight CMDB structure, and site ownership',
        };
    }

    if (current.startsWith('settings.')) {
        return {
            section: 'Settings Grid',
            detail: 'Theme control and shared room preferences',
        };
    }

    if (current.startsWith('notification-channels.')) {
        return {
            section: 'Delivery Mesh',
            detail: 'Telegram routing, site fallback, and alert delivery targets',
        };
    }

    if (current.startsWith('alert-rules.')) {
        return {
            section: 'Rule Engine',
            detail: 'Threshold policy, site overrides, and alert evaluation defaults',
        };
    }

    if (current.startsWith('users.')) {
        return {
            section: 'Access Control',
            detail: 'Operator accounts and role-based access control',
        };
    }

    if (current.startsWith('sites.')) {
        return {
            section: 'Settings Grid',
            detail: 'Operational locations and scope boundaries',
        };
    }

    return {
        section: 'Control Surface',
        detail: 'Signal posture, alert pressure, and operator overview',
    };
});

function isActive(itemRoute) {
    const current = route().current();
    if (!current) {
        return false;
    }

    if (itemRoute === 'dashboard') {
        return current === 'dashboard';
    }

    if (itemRoute === 'settings.index') {
        return current === 'settings.index'
            || current.startsWith('settings.')
            || current.startsWith('notification-channels.')
            || current.startsWith('alert-rules.');
    }

    const prefix = itemRoute.split('.')[0];
    return current === itemRoute || current.startsWith(`${prefix}.`);
}

function logout() {
    router.post(route('logout'));
}
</script>

<template>
    <div class="app-shell">
        <div class="app-frame">
            <aside class="app-sidebar">
                <div class="app-brand">
                    <Link :href="route('dashboard')" class="brand-mark">IC</Link>

                    <div class="min-w-0">
                        <p class="eyebrow">InfraControl</p>
                        <div class="text-title-sm text-body mt-2">Operations Control Room</div>
                    </div>
                </div>

                <div class="sidebar-nav">
                    <div class="sidebar-nav__label">Navigation</div>

                    <Link
                        v-for="item in navItems"
                        :key="item.route"
                        :href="route(item.route)"
                        class="sidebar-nav__item"
                        :class="{ 'sidebar-nav__item--active': isActive(item.route) }"
                    >
                        <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="item.icon" />
                        </svg>

                        <div class="min-w-0">
                            <div class="text-nav-link">{{ item.name }}</div>
                            <div class="text-caption mt-1 text-muted truncate">{{ item.description }}</div>
                        </div>
                    </Link>
                </div>

                <div class="panel-subtle operator-card">
                    <div class="flex items-center gap-3">
                        <div class="brand-mark !h-10 !w-10 !rounded-full !bg-elevated !text-body">
                            {{ page.props.auth.user?.name?.charAt(0)?.toUpperCase() ?? '?' }}
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <div class="text-body-sm truncate">{{ page.props.auth.user?.name }}</div>
                                <span
                                    class="status-chip"
                                    :class="{
                                        'bg-error/10 text-error': user?.role === 'admin',
                                        'bg-warning/10 text-warning': user?.role === 'operator',
                                    }"
                                >
                                    {{ user?.role }}
                                </span>
                            </div>
                            <div class="text-caption text-muted truncate" :title="page.props.auth.user?.email">
                                {{ page.props.auth.user?.email }}
                            </div>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="btn btn-secondary btn-sm mt-4 w-full"
                        @click="logout"
                    >
                        Sign Out
                    </button>
                </div>
            </aside>

            <div class="app-main">
                <header class="app-topbar">
                    <div>
                        <div class="eyebrow">{{ routeMeta.section }}</div>
                        <div class="mt-2 flex flex-wrap items-center gap-3">
                            <p class="text-title-sm text-body">{{ routeMeta.detail }}</p>
                            <span class="status-chip">
                                <span class="signal-dot signal-dot--live animate-pulse-dot" />
                                Ops Mesh Online
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                        <Link v-if="isAdmin" :href="route('users.index')" class="btn btn-ghost btn-sm">
                            Users
                        </Link>
                        <Link :href="route('audit-logs.index')" class="btn btn-secondary btn-sm">
                            Audit Trail
                        </Link>
                        <Link v-if="isAdmin" :href="route('vault.create')" class="btn btn-secondary btn-sm">
                            Add Secret
                        </Link>
                        <Link v-if="isAdmin" :href="route('inventory.create')" class="btn btn-secondary btn-sm">
                            Add Asset
                        </Link>
                        <Link v-if="isAdmin" :href="route('integrations.create')" class="btn btn-primary btn-sm">
                            Add Integration
                        </Link>
                    </div>
                </header>

                <FlashMessage />

                <main class="workspace w-full">
                    <slot />
                </main>
            </div>
        </div>
    </div>
</template>
