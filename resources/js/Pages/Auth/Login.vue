<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const isDev = import.meta.env.DEV;

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function fillDemoAccess() {
    form.email = 'admin@infracontrol.local';
    form.password = 'password';
    form.remember = true;
}

function submit() {
    form.post(route('login'));
}
</script>

<template>
    <Head title="Sign In" />

    <div class="login-page">
        <div class="login-shell">
            <div class="login-grid">
                <section class="login-hero panel-card">
                    <div class="eyebrow">InfraControl</div>
                    <h1 class="text-display-lg text-body mt-5 max-w-2xl">
                        A control room should feel decisive before the first alert even lands.
                    </h1>
                    <div class="login-chip-row">
                        <span class="status-chip">
                            <span class="signal-dot signal-dot--live animate-pulse-dot" />
                            Alert-aware shell
                        </span>
                        <span class="status-chip">Audit-first workflow</span>
                        <span class="status-chip">Dense panel hierarchy</span>
                    </div>

                    <div class="login-stats">
                        <div class="panel-subtle login-stat">
                            <div class="text-caption text-muted">Primary Accent</div>
                            <div class="text-number-md text-brand mt-2">#FCD535</div>
                        </div>
                        <div class="panel-subtle login-stat">
                            <div class="text-caption text-muted">Numerical Voice</div>
                            <div class="text-number-md text-body mt-2">IBM Plex Mono</div>
                        </div>
                        <div class="panel-subtle login-stat">
                            <div class="text-caption text-muted">Surface Bias</div>
                            <div class="text-number-md text-body mt-2">Dark Control Deck</div>
                        </div>
                    </div>

                </section>

                <section class="login-card panel-subtle">
                    <div>
                        <div class="eyebrow">Operator Access</div>
                        <h2 class="text-title-lg text-body mt-4">Sign in to the room</h2>
                        <p class="text-body-sm text-muted mt-2">
                            Use your operator credentials to access telemetry, integrations, and audit evidence.
                        </p>
                    </div>

                    <form class="login-form" @submit.prevent="submit">
                        <div class="login-field">
                            <label for="email" class="form-label">Email</label>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                placeholder="admin@infracontrol.local"
                                class="input w-full"
                                :class="{ 'input-error': form.errors.email }"
                                autocomplete="username"
                                required
                            />
                            <p v-if="form.errors.email" class="form-error">{{ form.errors.email }}</p>
                        </div>

                        <div class="login-field">
                            <label for="password" class="form-label">Password</label>
                            <input
                                id="password"
                                v-model="form.password"
                                type="password"
                                placeholder="Enter your password"
                                class="input w-full"
                                :class="{ 'input-error': form.errors.password }"
                                autocomplete="current-password"
                                required
                            />
                            <p v-if="form.errors.password" class="form-error">{{ form.errors.password }}</p>
                        </div>

                        <label class="login-remember">
                            <input
                                v-model="form.remember"
                                type="checkbox"
                                class="checkbox checkbox-primary checkbox-sm"
                            />
                            <span class="text-body-sm text-muted">Remember me on this station</span>
                        </label>

                        <button
                            type="submit"
                            class="btn btn-primary w-full"
                            :class="{ loading: form.processing }"
                            :disabled="form.processing"
                        >
                            Sign In
                        </button>
                    </form>

                    <button
                        v-if="isDev"
                        type="button"
                        class="btn btn-secondary btn-sm"
                        @click="fillDemoAccess"
                    >
                        Fill Demo Access
                    </button>
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
.login-page {
    min-height: 100vh;
    padding: 32px;
    background:
        linear-gradient(var(--theme-login-grid) 1px, transparent 1px),
        linear-gradient(90deg, var(--theme-login-grid) 1px, transparent 1px),
        var(--color-canvas);
    background-size: 40px 40px, 40px 40px, auto;
}

.login-shell {
    width: min(1360px, 100%);
    margin: 0 auto;
}

.login-grid {
    display: grid;
    gap: 24px;
    grid-template-columns: minmax(0, 1.2fr) minmax(360px, 420px);
}

.login-hero,
.login-card {
    position: relative;
    padding: 32px;
}

.login-card {
    display: flex;
    flex-direction: column;
    gap: 28px;
}

.login-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 28px;
}

.login-stats {
    display: grid;
    gap: 14px;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin-top: 28px;
}

.login-stat {
    padding: 16px;
}

.login-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.login-field {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.login-remember {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    user-select: none;
}

@media (max-width: 960px) {
    .login-page {
        display: block;
    }

    .login-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (max-width: 640px) {
    .login-page {
        padding: 20px;
    }

    .login-hero,
    .login-card {
        padding: 24px;
    }

    .login-stats {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
