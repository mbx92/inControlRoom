<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    agent: { type: Object, required: true },
});
</script>

<template>
    <div>
        <Link
            v-if="agent.inventory_asset"
            :href="route('inventory.show', agent.inventory_asset.id)"
            class="font-medium text-body hover:underline"
        >
            {{ agent.inventory_asset.name }}
        </Link>
        <span v-else class="text-muted">Not linked</span>
        <div v-if="agent.inventory_asset?.asset_tag" class="text-caption text-muted">
            {{ agent.inventory_asset.asset_tag }}
        </div>
    </div>
</template>
