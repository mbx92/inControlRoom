<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    message: { type: String, default: 'You do not have access to this resource.' },
});
</script>

<template>
    <Head title="403 — Forbidden" />

    <div class="flex min-h-screen items-center justify-center bg-base-200">
        <div class="card bg-elevated shadow-xl max-w-2xl w-full mx-4">
            <div class="card-body items-center text-center p-10">
                <div class="mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-error/10">
                    <svg class="h-10 w-10 text-error" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                    </svg>
                </div>

                <h1 class="text-title-xl text-body">403</h1>
                <h2 class="text-title-md text-body mt-2">Access Denied</h2>

                <p class="text-body-sm text-muted mt-4 px-8">
                    {{ message }}
                </p>

                <p class="text-caption text-muted mt-6">
                    If you believe this is a mistake, contact your administrator.
                </p>

                <div class="card-actions mt-6">
                    <Link :href="route('dashboard')" class="btn btn-primary">
                        Back to Dashboard
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
