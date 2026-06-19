<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    channel: { type: Object, default: null },
    sites: { type: Array, default: () => [] },
    types: { type: Object, required: true },
    submitLabel: { type: String, required: true },
    submitUrl: { type: String, required: true },
    method: { type: String, default: 'post' },
});

const form = useForm({
    type: props.channel?.type ?? 'telegram',
    name: props.channel?.name ?? '',
    site_id: props.channel?.site_id ?? '',
    is_active: props.channel?.is_active ?? true,
    config: {
        bot_token: props.channel?.config?.bot_token ?? '',
        chat_id: props.channel?.config?.chat_id ?? '',
        message_thread_id: props.channel?.config?.message_thread_id ?? '',
    },
});

const submitMethod = computed(() => props.method.toLowerCase());

function submit() {
    form[submitMethod.value](props.submitUrl);
}
</script>

<template>
    <form class="panel-card p-6 space-y-5" @submit.prevent="submit">
        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <label class="form-label" for="channel-type">Type</label>
                <select id="channel-type" v-model="form.type" class="select mt-2 w-full">
                    <option v-for="(label, value) in types" :key="value" :value="value">{{ label }}</option>
                </select>
                <p v-if="form.errors.type" class="form-error">{{ form.errors.type }}</p>
            </div>

            <div>
                <label class="form-label" for="channel-site">Site Scope</label>
                <select id="channel-site" v-model="form.site_id" class="select mt-2 w-full">
                    <option value="">Global fallback</option>
                    <option v-for="site in sites" :key="site.id" :value="site.id">
                        {{ site.name }} ({{ site.code }})
                    </option>
                </select>
                <p v-if="form.errors.site_id" class="form-error">{{ form.errors.site_id }}</p>
            </div>
        </div>

        <div>
            <label class="form-label" for="channel-name">Channel Name</label>
            <input id="channel-name" v-model="form.name" type="text" class="input mt-2 w-full" :class="{ 'input-error': form.errors.name }" />
            <p v-if="form.errors.name" class="form-error">{{ form.errors.name }}</p>
        </div>

        <div class="grid gap-5 md:grid-cols-2">
            <div>
                <label class="form-label" for="channel-bot-token">Bot Token</label>
                <input
                    id="channel-bot-token"
                    v-model="form.config.bot_token"
                    type="text"
                    class="input mt-2 w-full"
                    :class="{ 'input-error': form.errors['config.bot_token'] }"
                />
                <p v-if="form.errors['config.bot_token']" class="form-error">{{ form.errors['config.bot_token'] }}</p>
            </div>

            <div>
                <label class="form-label" for="channel-chat-id">Chat ID</label>
                <input
                    id="channel-chat-id"
                    v-model="form.config.chat_id"
                    type="text"
                    class="input mt-2 w-full"
                    :class="{ 'input-error': form.errors['config.chat_id'] }"
                />
                <p v-if="form.errors['config.chat_id']" class="form-error">{{ form.errors['config.chat_id'] }}</p>
            </div>
        </div>

        <div>
            <label class="form-label" for="channel-thread-id">Message Thread ID</label>
            <input
                id="channel-thread-id"
                v-model="form.config.message_thread_id"
                type="text"
                class="input mt-2 w-full"
                :class="{ 'input-error': form.errors['config.message_thread_id'] }"
            />
            <p v-if="form.errors['config.message_thread_id']" class="form-error">{{ form.errors['config.message_thread_id'] }}</p>
        </div>

        <label class="flex items-center gap-3">
            <input v-model="form.is_active" type="checkbox" class="checkbox" />
            <span class="text-body-sm text-body">Channel is active</span>
        </label>

        <div class="flex flex-wrap gap-2">
            <button type="submit" class="btn btn-primary" :disabled="form.processing">
                <span v-if="form.processing" class="loading loading-spinner loading-xs"></span>
                {{ form.processing ? 'Saving...' : submitLabel }}
            </button>
        </div>
    </form>
</template>
