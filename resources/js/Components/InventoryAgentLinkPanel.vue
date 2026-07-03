<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import SearchablePicker from '@/Components/SearchablePicker.vue';

const props = defineProps({
    asset: { type: Object, required: true },
    linkedAgent: { type: Object, default: null },
    availableAgents: { type: Array, default: () => [] },
    canManage: { type: Boolean, default: false },
});

const form = useForm({
    agent_id: props.linkedAgent?.id ?? '',
});

const pickerItems = computed(() => props.availableAgents.map((agent) => ({
    id: agent.id,
    subtitle: [agent.primary_ip, agent.device_id, agent.status].filter(Boolean).join(' · '),
    agent,
})));

function agentLabel(item) {
    return item.agent?.hostname ?? item.hostname ?? '-';
}

function agentSearch(item, query) {
    const agent = item.agent ?? item;
    const haystack = [
        agent.hostname,
        agent.primary_ip,
        agent.device_id,
        agent.status,
    ].filter(Boolean).join(' ').toLowerCase();

    return haystack.includes(query);
}

function submit() {
    form.put(route('inventory.agent-link.update', props.asset.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <section class="panel-subtle p-5">
        <div class="eyebrow">InfraControl Agent</div>

        <div v-if="!canManage">
            <template v-if="linkedAgent">
                <h2 class="text-title-sm text-body mt-2">{{ linkedAgent.hostname }}</h2>
                <div class="data-list mt-5">
                    <div class="data-list__row">
                        <div>
                            <div class="text-caption text-muted">Status</div>
                            <p class="text-body-sm text-body mt-2">{{ linkedAgent.status }}</p>
                        </div>
                    </div>
                    <div class="data-list__row">
                        <div>
                            <div class="text-caption text-muted">Primary IP</div>
                            <p class="text-body-sm text-body mt-2 font-mono-num">{{ linkedAgent.primary_ip || '—' }}</p>
                        </div>
                    </div>
                </div>
                <Link :href="route('agents.metrics.show', linkedAgent.id)" class="btn btn-secondary btn-sm mt-5 w-full">
                    View Agent Metrics
                </Link>
            </template>
            <p v-else class="mt-3 text-body-sm text-muted">No agent linked to this asset.</p>
        </div>

        <div v-else>
            <p class="mt-2 text-body-sm text-muted">
                Cari agent Windows terdaftar di site yang sama untuk dihubungkan ke asset ini.
            </p>

            <form class="mt-4 space-y-4" @submit.prevent="submit">
                <div>
                    <label class="form-label" for="inventory-agent-link">Registered Agent</label>
                    <SearchablePicker
                        v-model="form.agent_id"
                        class="mt-2"
                        input-id="inventory-agent-link"
                        :items="pickerItems"
                        :label-fn="agentLabel"
                        :search-fn="agentSearch"
                        placeholder="Search hostname, IP, or device ID"
                        empty-label="No agent matches your search."
                        :disabled="!asset.site_id"
                    />
                    <p v-if="!asset.site_id" class="text-caption text-muted mt-2">
                        Asset harus memiliki site sebelum bisa di-link ke agent.
                    </p>
                    <p v-if="form.errors.agent_id" class="form-error mt-2">{{ form.errors.agent_id }}</p>
                </div>

                <button
                    type="submit"
                    class="btn btn-primary btn-sm w-full"
                    :disabled="form.processing || !asset.site_id"
                >
                    {{ form.processing ? 'Saving...' : 'Save Agent Link' }}
                </button>
            </form>

            <div v-if="linkedAgent" class="mt-5 border-t border-hairline pt-5">
                <div class="text-caption text-muted">Current agent</div>
                <h2 class="text-title-sm text-body mt-2">{{ linkedAgent.hostname }}</h2>
                <div class="mt-3 flex flex-wrap gap-2 text-body-sm text-muted">
                    <span>{{ linkedAgent.status }}</span>
                    <span v-if="linkedAgent.primary_ip">· {{ linkedAgent.primary_ip }}</span>
                </div>
                <Link :href="route('agents.metrics.show', linkedAgent.id)" class="btn btn-ghost btn-sm mt-4 w-full">
                    View Agent Metrics
                </Link>
            </div>
        </div>
    </section>
</template>
