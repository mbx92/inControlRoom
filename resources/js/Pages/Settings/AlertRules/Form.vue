<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    rule: { type: Object, default: null },
    sites: { type: Array, default: () => [] },
    availableRules: { type: Object, required: true },
    submitLabel: { type: String, required: true },
    submitUrl: { type: String, required: true },
    method: { type: String, default: 'post' },
});

const form = useForm({
    site_id: props.rule?.site_id ?? '',
    name: props.rule?.name ?? '',
    rule_key: props.rule?.rule_key ?? 'integration_health_failure',
    warning_threshold: props.rule?.warning_threshold ?? 80,
    critical_threshold: props.rule?.critical_threshold ?? 90,
    is_active: props.rule?.is_active ?? true,
});

const thresholdRules = [
    'proxmox_guest_cpu_usage_percent',
    'proxmox_guest_memory_usage_percent',
    'proxmox_guest_disk_usage_percent',
];

const usesThresholds = computed(() => thresholdRules.includes(form.rule_key));
const submitMethod = computed(() => props.method.toLowerCase());

function submit() {
    form[submitMethod.value](props.submitUrl);
}
</script>

<template>
    <form class="panel-card p-6 space-y-5" @submit.prevent="submit">
        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <label class="form-label" for="rule-site">Site Scope</label>
                <select id="rule-site" v-model="form.site_id" class="select mt-2 w-full">
                    <option value="">Global default</option>
                    <option v-for="site in sites" :key="site.id" :value="site.id">
                        {{ site.name }} ({{ site.code }})
                    </option>
                </select>
                <p v-if="form.errors.site_id" class="form-error">{{ form.errors.site_id }}</p>
            </div>

            <div>
                <label class="form-label" for="rule-key">Rule Type</label>
                <select id="rule-key" v-model="form.rule_key" class="select mt-2 w-full">
                    <option v-for="(label, value) in availableRules" :key="value" :value="value">{{ label }}</option>
                </select>
                <p v-if="form.errors.rule_key" class="form-error">{{ form.errors.rule_key }}</p>
            </div>
        </div>

        <div>
            <label class="form-label" for="rule-name">Display Name</label>
            <input id="rule-name" v-model="form.name" type="text" class="input mt-2 w-full" :class="{ 'input-error': form.errors.name }" />
            <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
        </div>

        <div v-if="usesThresholds" class="grid gap-5 md:grid-cols-2">
            <div>
                <label class="form-label" for="warning-threshold">Warning Threshold</label>
                <input
                    id="warning-threshold"
                    v-model="form.warning_threshold"
                    type="number"
                    step="0.1"
                    class="input mt-2 w-full"
                    :class="{ 'input-error': form.errors.warning_threshold }"
                />
                <p v-if="form.errors.warning_threshold" class="form-error">{{ form.errors.warning_threshold }}</p>
            </div>

            <div>
                <label class="form-label" for="critical-threshold">Critical Threshold</label>
                <input
                    id="critical-threshold"
                    v-model="form.critical_threshold"
                    type="number"
                    step="0.1"
                    class="input mt-2 w-full"
                    :class="{ 'input-error': form.errors.critical_threshold }"
                />
                <p v-if="form.errors.critical_threshold" class="form-error">{{ form.errors.critical_threshold }}</p>
            </div>
        </div>

        <label class="flex items-center gap-3">
            <input v-model="form.is_active" type="checkbox" class="checkbox" />
            <span class="text-body-sm text-body">Rule is active</span>
        </label>

        <button type="submit" class="btn btn-primary" :disabled="form.processing">
            <span v-if="form.processing" class="loading loading-spinner loading-xs"></span>
            {{ form.processing ? 'Saving...' : submitLabel }}
        </button>
    </form>
</template>
