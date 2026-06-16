<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PrinterForm from './Form.vue';

defineProps({
    printer: { type: Object, required: true },
    driverOptions: { type: Array, required: true },
    connectionModeOptions: { type: Array, required: true },
});
</script>

<template>
    <Head title="Edit Printer" />

    <AppLayout>
        <PrinterForm
            mode="edit"
            :printer="printer"
            :driver-options="driverOptions"
            :connection-mode-options="connectionModeOptions"
        />
    </AppLayout>
</template>
