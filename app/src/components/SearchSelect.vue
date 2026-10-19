<template>
    <div ref="rootEl" class="relative">
        <button ref="triggerEl" type="button" :id="id" :disabled="disabled"
            class="inline-flex w-full items-center justify-between gap-2 rounded-md border border-gray-300 bg-white px-3 py-2 text-left text-sm disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-400"
            aria-haspopup="listbox" :aria-expanded="open" @click="toggle">
            <span class="truncate" :class="displayLabel ? 'text-gray-900' : 'text-gray-400'">{{ displayLabel || placeholder }}</span>
            <span class="shrink-0 text-gray-400" aria-hidden="true"><Icon name="chevron-down" /></span>
        </button>

        <div v-if="open" class="absolute z-30 mt-1 w-full min-w-[14rem] rounded-md border border-gray-200 bg-white shadow-lg">
            <div class="border-b border-gray-100 p-2">
                <input ref="searchEl" v-model="query" type="text" :placeholder="searchPlaceholder"
                    role="combobox" aria-expanded="true" :aria-controls="`${uid}-list`" :aria-activedescendant="activeDescId"
                    class="w-full rounded-md border border-gray-300 px-2.5 py-1.5 text-sm focus:border-red-700 focus:ring-1 focus:ring-red-700"
                    @keydown="onKeydown" />
            </div>
            <ul :id="`${uid}-list`" role="listbox" class="max-h-64 overflow-auto py-1">
                <li v-for="(row, i) in rows" :key="row.key" :id="`${uid}-opt-${i}`" role="option"
                    :aria-selected="rowSelected(row)"
                    :class="['flex cursor-pointer items-center gap-2 px-3 py-2 text-sm', i === highlight ? 'bg-red-50' : '', rowSelected(row) ? 'font-semibold text-gray-900' : 'text-gray-700']"
                    @mouseenter="highlight = i" @click="activate(row)">
                    <template v-if="row.type === 'custom'">
                        <span class="truncate">Use &ldquo;{{ query.trim() }}&rdquo;</span>
                    </template>
                    <template v-else>
                        <span class="truncate">{{ row.type === 'clear' ? clearLabel : row.option.label }}</span>
                        <span v-if="row.type === 'opt' && row.option.count !== undefined" class="ml-auto pl-2 text-xs font-normal text-gray-400">{{ row.option.count }}</span>
                        <span v-if="rowSelected(row)" class="shrink-0 text-red-700" :class="{ 'ml-auto': !(row.type === 'opt' && row.option.count !== undefined) }" aria-hidden="true"><Icon name="check" /></span>
                    </template>
                </li>
                <li v-if="rows.length <= 1" class="px-3 py-2 text-sm text-gray-400">No matches</li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    modelValue:        { type: [String, Number], default: null },
    options:           { type: Array, default: () => [] }, // [{ value, label, count? }]
    placeholder:       { type: String, default: 'Select…' },
    clearLabel:        { type: String, default: 'Any' },
    disabled:          { type: Boolean, default: false },
    allowCustom:       { type: Boolean, default: false },
    id:                { type: String, default: '' },
    searchPlaceholder: { type: String, default: 'Search…' },
});
const emit = defineEmits(['update:modelValue', 'change']);

let counter = 0;
const uid = `rcmi-ss-${++counter}-${Math.random().toString(36).slice(2, 7)}`;

const rootEl = ref(null);
const triggerEl = ref(null);
const searchEl = ref(null);
const open = ref(false);
const query = ref('');
const highlight = ref(0);

const selectedOption = computed(() =>
    props.options.find(o => o.value === props.modelValue) || null
);

const displayLabel = computed(() => {
    if (selectedOption.value) {
        const o = selectedOption.value;
        return o.count !== undefined ? `${o.label} (${o.count})` : o.label;
    }
    // Custom (allowCustom) values aren't in options — show them raw.
    return props.modelValue !== null && props.modelValue !== '' ? String(props.modelValue) : '';
});

const filtered = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return props.options;
    return props.options.filter(o => String(o.label).toLowerCase().includes(q));
});

// With allowCustom, a typed value that doesn't exactly match an option
// value gets a "Use …" row emitting the raw text (free-text answers).
const customRow = computed(() => {
    const text = query.value.trim();
    if (!props.allowCustom || !text) return null;
    if (props.options.some(o => String(o.value) === text)) return null;
    return text;
});

// Flat row list the keyboard highlight walks: clear row, matches, custom.
const rows = computed(() => {
    const list = [{ type: 'clear', key: '__clear' }];
    filtered.value.forEach(o => list.push({ type: 'opt', key: `v:${String(o.value)}`, option: o }));
    if (customRow.value !== null) list.push({ type: 'custom', key: '__custom' });
    return list;
});

const activeDescId = computed(() => `${uid}-opt-${highlight.value}`);

watch(query, () => { highlight.value = 0; });

function rowSelected(row) {
    if (row.type === 'clear') return props.modelValue === null || props.modelValue === '';
    if (row.type === 'opt') return row.option.value === props.modelValue;
    return false;
}

function toggle() {
    if (props.disabled) return;
    open.value ? close() : openPopover();
}

function openPopover() {
    open.value = true;
    const sel = rows.value.findIndex(rowSelected);
    highlight.value = sel >= 0 ? sel : 0;
    document.addEventListener('mousedown', onDocMouseDown);
    nextTick(() => searchEl.value?.focus());
}

function close() {
    if (!open.value) return;
    open.value = false;
    query.value = '';
    document.removeEventListener('mousedown', onDocMouseDown);
}

function onDocMouseDown(e) {
    if (rootEl.value && !rootEl.value.contains(e.target)) close();
}

function activate(row) {
    if (row.type === 'clear') choose(null);
    else if (row.type === 'opt') choose(row.option.value);
    else choose(query.value.trim());
}

function choose(value) {
    emit('update:modelValue', value);
    emit('change', value);
    close();
    triggerEl.value?.focus();
}

function onKeydown(e) {
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        highlight.value = Math.min(highlight.value + 1, rows.value.length - 1);
        scrollActiveIntoView();
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        highlight.value = Math.max(highlight.value - 1, 0);
        scrollActiveIntoView();
    } else if (e.key === 'Enter') {
        e.preventDefault();
        const row = rows.value[highlight.value];
        if (row) activate(row);
    } else if (e.key === 'Escape') {
        e.preventDefault();
        close();
        triggerEl.value?.focus();
    } else if (e.key === 'Tab') {
        close();
    }
}

function scrollActiveIntoView() {
    nextTick(() => {
        document.getElementById(`${uid}-opt-${highlight.value}`)?.scrollIntoView({ block: 'nearest' });
    });
}

onBeforeUnmount(() => document.removeEventListener('mousedown', onDocMouseDown));
</script>
