<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrinterForm from './Form.vue';

defineProps({
    driverOptions: { type: Array, required: true },
    connectionModeOptions: { type: Array, required: true },
});
</script>

<template>
    <Head title="Add Printer" />

    <AppLayout>
        <PrinterForm
            mode="create"
            :driver-options="driverOptions"
            :connection-mode-options="connectionModeOptions"
        />
    </AppLayout>
</template>
