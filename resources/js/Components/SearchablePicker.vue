<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    items: { type: Array, default: () => [] },
    placeholder: { type: String, default: 'Search...' },
    emptyLabel: { type: String, default: 'No matches found.' },
    allowClear: { type: Boolean, default: true },
    inputId: { type: String, default: '' },
    maxResults: { type: Number, default: 50 },
    disabled: { type: Boolean, default: false },
    labelFn: { type: Function, required: true },
    searchFn: { type: Function, default: null },
});

const emit = defineEmits(['update:modelValue']);

const query = ref('');
const isOpen = ref(false);
const activeIndex = ref(-1);

const selectedItem = computed(() => props.items.find((item) => item.id === props.modelValue) ?? null);

const filteredItems = computed(() => {
    const normalizedQuery = query.value.trim().toLowerCase();

    let results = props.items;

    if (normalizedQuery) {
        results = props.items.filter((item) => {
            if (props.searchFn) {
                return props.searchFn(item, normalizedQuery);
            }

            return props.labelFn(item).toLowerCase().includes(normalizedQuery);
        });
    }

    return results.slice(0, props.maxResults);
});

function syncQueryFromSelection() {
    query.value = selectedItem.value ? props.labelFn(selectedItem.value) : '';
}

function openList() {
    if (props.disabled) {
        return;
    }

    isOpen.value = true;
    activeIndex.value = -1;
}

function closeList() {
    isOpen.value = false;
    activeIndex.value = -1;
    syncQueryFromSelection();
}

function selectItem(item) {
    emit('update:modelValue', item.id);
    query.value = props.labelFn(item);
    closeList();
}

function clearSelection() {
    emit('update:modelValue', '');
    query.value = '';
    closeList();
}

function onInput() {
    isOpen.value = true;
    activeIndex.value = -1;

    if (selectedItem.value && query.value !== props.labelFn(selectedItem.value)) {
        emit('update:modelValue', '');
    }
}

function onKeydown(event) {
    if (!isOpen.value && ['ArrowDown', 'ArrowUp'].includes(event.key)) {
        openList();
        return;
    }

    if (event.key === 'Escape') {
        closeList();
        return;
    }

    if (!filteredItems.value.length) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = Math.min(activeIndex.value + 1, filteredItems.value.length - 1);
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = Math.max(activeIndex.value - 1, 0);
    }

    if (event.key === 'Enter' && activeIndex.value >= 0) {
        event.preventDefault();
        selectItem(filteredItems.value[activeIndex.value]);
    }
}

watch(
    () => props.modelValue,
    () => {
        if (!isOpen.value) {
            syncQueryFromSelection();
        }
    },
    { immediate: true },
);

watch(
    () => props.items,
    () => {
        if (!isOpen.value) {
            syncQueryFromSelection();
        }
    },
);
</script>

<template>
    <div class="relative" @keydown="onKeydown">
        <div class="relative">
            <input
                :id="inputId"
                v-model="query"
                type="search"
                class="input input-bordered w-full pr-10"
                :placeholder="placeholder"
                :disabled="disabled"
                autocomplete="off"
                @focus="openList"
                @input="onInput"
            />
            <button
                v-if="allowClear && modelValue"
                type="button"
                class="btn btn-ghost btn-xs btn-circle absolute right-2 top-1/2 -translate-y-1/2"
                aria-label="Clear selection"
                @click="clearSelection"
            >
                ×
            </button>
        </div>

        <div
            v-if="isOpen"
            class="absolute z-20 mt-2 max-h-72 w-full overflow-y-auto rounded-2xl border border-hairline bg-base-100 shadow-lg"
        >
            <button
                v-if="allowClear"
                type="button"
                class="flex w-full items-center px-4 py-3 text-left text-body-sm text-muted hover:bg-base-300"
                @mousedown.prevent="clearSelection"
            >
                Not linked
            </button>

            <button
                v-for="(item, index) in filteredItems"
                :key="item.id"
                type="button"
                class="flex w-full flex-col items-start px-4 py-3 text-left hover:bg-base-300"
                :class="{ 'bg-base-300': index === activeIndex || item.id === modelValue }"
                @mousedown.prevent="selectItem(item)"
            >
                <span class="text-body-sm font-medium text-body">{{ labelFn(item) }}</span>
                <span v-if="item.subtitle" class="text-caption text-muted">{{ item.subtitle }}</span>
            </button>

            <p v-if="filteredItems.length === 0" class="px-4 py-3 text-body-sm text-muted">
                {{ emptyLabel }}
            </p>

            <p v-else-if="items.length > maxResults && !query.trim()" class="border-t border-hairline px-4 py-2 text-caption text-muted">
                Showing first {{ maxResults }} items. Type to narrow results.
            </p>
        </div>

        <div v-if="isOpen" class="fixed inset-0 z-10" @click="closeList" />
    </div>
</template>
