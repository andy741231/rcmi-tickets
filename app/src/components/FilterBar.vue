<template>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <!-- Search row -->
        <div class="mb-4 flex flex-col gap-3 sm:flex-row">
            <label class="relative flex-1">
                <span class="sr-only">Search tickets</span>
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400" aria-hidden="true">
                    <Icon name="search" />
                </span>
                <input v-model="local.search" type="search" placeholder="Search tickets…"
                    class="w-full rounded-md border border-gray-300 py-2.5 pl-10 pr-3 text-sm focus:border-red-700 focus:ring-1 focus:ring-red-700"
                    @input="debouncedEmit" />
            </label>
            <label>
                <span class="sr-only">Ticket scope</span>
                <select v-model="local.scope" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2.5 text-sm sm:w-auto"
                    @change="emitChange">
                    <option value="all">All tickets</option>
                    <option value="assigned">Assigned to me</option>
                    <option value="submitted">Submitted by me</option>
                </select>
            </label>
        </div>

        <!-- Filters row -->
        <div class="flex flex-wrap items-center gap-2.5 border-t border-gray-100 pt-4">
            <!-- Active filter count -->
            <span v-if="activeCount > 0" class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-semibold text-red-800">
                {{ activeCount }} active
            </span>

            <!-- Status multi-select -->
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs font-medium text-gray-500">Status:</span>
                <label v-for="s in statuses" :key="s" class="inline-flex items-center gap-1 text-sm">
                    <input type="checkbox" :value="s" v-model="local.status"
                        class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700"
                        @change="emitChange" />
                    {{ s }}
                </label>
            </div>

            <!-- Assignee select -->
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs font-medium text-gray-500">Assignee:</span>
                <select v-model="local.assigneeId" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                    @change="emitChange">
                    <option :value="null">Anyone</option>
                    <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.display_name }}</option>
                </select>
            </div>

            <!-- Tag select -->
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs font-medium text-gray-500">Tag:</span>
                <select v-model="local.tagId" class="rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                    @change="emitChange">
                    <option :value="null">Any tag</option>
                    <option v-for="t in tags" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </div>

            <!-- Date range -->
            <div class="flex flex-wrap items-center gap-1.5">
                <span class="text-xs font-medium text-gray-500">From:</span>
                <input v-model="local.dateFrom" type="date"
                    class="rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                    @change="emitChange" aria-label="Filter from date" />
                <span class="text-xs text-gray-500">to</span>
                <input v-model="local.dateTo" type="date"
                    class="rounded-md border border-gray-300 px-2 py-1.5 text-sm"
                    @change="emitChange" aria-label="Filter to date" />
            </div>

            <button v-if="activeCount > 0" @click="reset"
                class="ml-auto inline-flex items-center gap-1 rounded-md bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-200">
                <Icon name="x" /> Clear ({{ activeCount }})
            </button>
            <button v-else @click="reset" disabled
                class="ml-auto rounded-md bg-gray-50 px-3 py-1.5 text-xs font-medium text-gray-400">
                Clear Filters
            </button>
        </div>
    </div>
</template>

<script setup>
import { reactive, computed, watch } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    statuses:        { type: Array, default: () => [] },
    tags:            { type: Array, default: () => [] },
    assignableUsers: { type: Array, default: () => [] },
    modelValue:      { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

const defaults = {
    search: '', scope: 'all', status: [],
    assigneeId: null, tagId: null,
    dateFrom: '', dateTo: '',
};

const local = reactive({ ...defaults, ...props.modelValue });

const activeCount = computed(() => {
    let count = 0;
    if (local.search) count++;
    if (local.scope !== 'all') count++;
    if (local.status.length) count++;
    if (local.assigneeId) count++;
    if (local.tagId) count++;
    if (local.dateFrom) count++;
    if (local.dateTo) count++;
    return count;
});

let debounceTimer = null;
function debouncedEmit() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(emitChange, 300);
}

function emitChange() {
    const payload = { ...local };
    payload.assignee_ids = payload.assigneeId ? [payload.assigneeId] : [];
    payload.tag_ids = payload.tagId ? [payload.tagId] : [];
    delete payload.assigneeId;
    delete payload.tagId;
    emit('update:modelValue', payload);
}

function reset() {
    Object.assign(local, defaults);
    emitChange();
}
</script>
