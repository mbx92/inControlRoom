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
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing" class="loading loading-spinner loading-xs"></span>
                            <template v-else>Sign In</template>
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
    position: relative;
    overflow: hidden;
    min-height: 100vh;
    min-height: 100dvh;
    display: grid;
    place-items: center;
    padding: 32px;
    background:
        linear-gradient(var(--theme-login-grid) 1px, transparent 1px),
        linear-gradient(90deg, var(--theme-login-grid) 1px, transparent 1px),
        var(--color-canvas);
    background-size: 40px 40px, 40px 40px, auto;
}

.login-shell {
    position: relative;
    z-index: 1;
    width: min(1360px, 100%);
    margin: 0 auto;
}

.login-grid {
    display: grid;
    gap: 24px;
    justify-content: center;
}

.login-card {
    position: relative;
    width: min(420px, 100%);
    padding: 32px;
    display: flex;
    flex-direction: column;
    gap: 28px;
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
    .login-grid {
        place-items: center;
    }

    .login-card {
        max-width: 420px;
        width: 100%;
    }
}

@media (max-width: 640px) {
    .login-page {
        padding: 20px;
    }

    .login-card {
        padding: 24px;
    }
}
</style>
