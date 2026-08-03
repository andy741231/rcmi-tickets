<template>
    <div ref="root" class="relative">
        <!-- Selected tags + input -->
        <div class="flex flex-wrap items-center gap-1.5 rounded-md border border-gray-300 bg-white p-2 transition focus-within:border-red-700 focus-within:ring-1 focus-within:ring-red-700">
            <span v-for="tag in selected" :key="tag.id" class="rcmi-tag-pill">
                {{ tag.name }}
                <button type="button" @click="removeTag(tag)" class="ml-0.5 rounded-full hover:text-red-700" aria-label="Remove tag {{ tag.name }}">
                    <Icon name="x" />
                </button>
            </span>
            <input v-model="query" type="text" :placeholder="selected.length ? '' : 'Type to search or create tags…'"
                class="flex-1 min-w-[120px] border-0 p-0 text-sm focus:ring-0 focus:outline-none"
                @input="onInput" @keydown.enter.prevent="onEnter" @keydown.backspace="onBackspace"
                aria-label="Add tags" />
        </div>

        <!-- Autocomplete dropdown -->
        <div v-if="showDropdown && suggestions.length > 0"
            class="absolute z-10 mt-1 w-full rounded-md border border-gray-200 bg-white py-1 shadow-lg">
            <button v-for="s in suggestions" :key="s.id" type="button"
                @click="addTag(s)"
                class="block w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-red-50 hover:text-red-700">
                {{ s.name }}
            </button>
            <button v-if="query.trim() && !exactMatch" type="button" @click="createTag"
                class="block w-full border-t border-gray-100 px-3 py-2 text-left text-sm font-medium text-red-700 hover:bg-red-50">
                <Icon name="plus" /> Create "{{ query.trim() }}"
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { api } from '../api.js';
import Icon from './Icon.vue';

const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    availableTags: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:modelValue']);

const root = ref(null);
const selected = ref([...props.modelValue]);
const query = ref('');
const showDropdown = ref(false);
const suggestions = ref([]);

const exactMatch = computed(() => {
    const q = query.value.trim().toLowerCase();
    return q && [...props.availableTags, ...suggestions.value].some(t => t.name.toLowerCase() === q);
});

function onInput() {
    showDropdown.value = true;
    const q = query.value.trim().toLowerCase();
    if (!q) {
        suggestions.value = [];
        return;
    }
    suggestions.value = props.availableTags.filter(t =>
        t.name.toLowerCase().includes(q) && !selected.value.some(s => s.id === t.id)
    ).slice(0, 8);
}

function onEnter() {
    if (suggestions.value.length > 0) {
        addTag(suggestions.value[0]);
    } else if (query.value.trim() && !exactMatch.value) {
        createTag();
    }
}

function onBackspace() {
    if (query.value === '' && selected.value.length > 0) {
        selected.value.pop();
        emitUpdate();
    }
}

function addTag(tag) {
    if (!selected.value.some(t => t.id === tag.id)) {
        selected.value.push(tag);
        emitUpdate();
    }
    query.value = '';
    showDropdown.value = false;
    suggestions.value = [];
}

function removeTag(tag) {
    selected.value = selected.value.filter(t => t.id !== tag.id);
    emitUpdate();
}

async function createTag() {
    const name = query.value.trim();
    if (!name) return;
    try {
        const tag = await api('/tags', { method: 'POST', body: { name } });
        addTag(tag);
    } catch (e) {
        if (e.status === 409) {
            const existing = props.availableTags.find(t => t.name.toLowerCase() === name.toLowerCase());
            if (existing) addTag(existing);
        } else {
            console.error('Failed to create tag:', e);
        }
    }
}

function emitUpdate() {
    emit('update:modelValue', [...selected.value]);
}

watch(() => props.modelValue, (v) => {
    selected.value = [...v];
});

function onDocClick(e) {
    if (root.value && !root.value.contains(e.target)) showDropdown.value = false;
}

onMounted(() => {
    document.addEventListener('click', onDocClick);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocClick);
});
</script>
