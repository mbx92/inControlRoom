<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import SearchablePicker from '@/Components/SearchablePicker.vue';

const props = defineProps({
    inventoryAssets: { type: Array, default: () => [] },
});

const dialogRef = ref(null);
const selectedAgent = ref(null);

const form = useForm({
    inventory_asset_id: '',
});

const options = computed(() => {
    if (!selectedAgent.value) {
        return [];
    }

    const siteAssets = props.inventoryAssets.filter((asset) => (
        asset.site_id === selectedAgent.value.site_id
        && (!asset.linked_agent_id || asset.linked_agent_id === selectedAgent.value.id)
    ));
    const current = selectedAgent.value.inventory_asset;

    if (current && !siteAssets.some((asset) => asset.id === current.id)) {
        return [current, ...siteAssets];
    }

    return siteAssets;
});

const pickerItems = computed(() => options.value.map((asset) => ({
    id: asset.id,
    subtitle: [asset.asset_tag, asset.category, asset.primary_ip].filter(Boolean).join(' · '),
    asset,
})));

function assetLabel(item) {
    return item.asset?.name ?? item.name ?? '-';
}

function assetSearch(item, query) {
    const asset = item.asset ?? item;
    const haystack = [
        asset.name,
        asset.asset_tag,
        asset.category,
        asset.primary_ip,
    ].filter(Boolean).join(' ').toLowerCase();

    return haystack.includes(query);
}

function open(agent) {
    selectedAgent.value = agent;
    form.clearErrors();
    form.inventory_asset_id = agent.inventory_asset?.id ?? '';
    dialogRef.value?.showModal();
}

function close() {
    dialogRef.value?.close();
    selectedAgent.value = null;
}

function submit() {
    if (!selectedAgent.value) {
        return;
    }

    form.put(route('settings.agents.inventory-link.update', selectedAgent.value.id), {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}

defineExpose({ open });
</script>

<template>
    <dialog ref="dialogRef" class="modal" @close="selectedAgent = null">
        <div v-if="selectedAgent" class="modal-box w-full !max-w-3xl">
            <h3 class="text-title-lg text-body">Link Inventory Asset</h3>
            <p class="text-body-sm text-body mt-1">{{ selectedAgent.hostname }}</p>
            <p class="text-body-sm text-muted mt-3">
                Cari asset inventory di site yang sama dengan agent ini. Kosongkan untuk menghapus link.
            </p>

            <form class="mt-5 space-y-4" @submit.prevent="submit">
                <div>
                    <label class="form-label" for="inventory-asset-link">Inventory Asset</label>
                    <SearchablePicker
                        v-model="form.inventory_asset_id"
                        class="mt-2"
                        input-id="inventory-asset-link"
                        :items="pickerItems"
                        :label-fn="assetLabel"
                        :search-fn="assetSearch"
                        placeholder="Search by name, asset tag, category, or IP"
                        empty-label="No inventory asset matches your search."
                    />
                    <p v-if="form.errors.inventory_asset_id" class="form-error mt-2">{{ form.errors.inventory_asset_id }}</p>
                </div>

                <div v-if="selectedAgent.inventory_asset" class="rounded-2xl border border-hairline bg-base-300 p-4 text-body-sm">
                    <div class="text-caption text-muted">Current link</div>
                    <Link
                        :href="route('inventory.show', selectedAgent.inventory_asset.id)"
                        class="mt-2 inline-block font-medium text-body hover:underline"
                    >
                        {{ selectedAgent.inventory_asset.name }}
                    </Link>
                </div>

                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" @click="close">
                        Cancel
                    </button>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">
                        {{ form.processing ? 'Saving...' : 'Save Link' }}
                    </button>
                </div>
            </form>
        </div>

        <form method="dialog" class="modal-backdrop">
            <button type="submit">close</button>
        </form>
    </dialog>
</template>
