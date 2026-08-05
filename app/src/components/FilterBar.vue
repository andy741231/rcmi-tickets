<template>
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
        <!-- Primary filter row -->
        <div class="flex flex-wrap items-center gap-2">
            <!-- Search -->
            <label class="relative min-w-[200px] flex-1">
                <span class="sr-only">Search tickets</span>
                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400" aria-hidden="true">
                    <Icon name="search" />
                </span>
                <input v-model="local.search" type="search" placeholder="Search by ticket ID or title…"
                    class="w-full rounded-md border border-gray-300 py-2 pl-10 pr-3 text-sm focus:border-red-700 focus:ring-1 focus:ring-red-700"
                    @input="debouncedEmit" />
            </label>

            <!-- Scope dropdown -->
            <label class="sr-only" for="ticket-scope">Ticket scope</label>
            <select id="ticket-scope" v-model="local.scope" @change="emitChange"
                class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700">
                <option value="all">All tickets</option>
                <option value="assigned">Assigned to me</option>
                <option value="submitted">Submitted by me</option>
            </select>

            <!-- Filters toggle -->
            <button @click="showAdvanced = !showAdvanced"
                :class="['inline-flex items-center gap-1.5 rounded-md border px-3 py-2 text-sm font-semibold', showAdvanced || activeCount > 0 ? 'border-red-200 bg-red-50 text-red-800' : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50']"
                :aria-expanded="showAdvanced">
                <Icon name="settings" />
                Filters
                <span v-if="activeCount > 0" class="rcmi-queue-chip-count" style="background: var(--rcmi-red); color: #fff;">{{ activeCount }}</span>
            </button>
        </div>

        <!-- Active filter chips -->
        <div v-if="activeFilterChips.length > 0" class="mt-3 flex flex-wrap gap-1.5">
            <span v-for="chip in activeFilterChips" :key="chip.key" class="rcmi-filter-chip">
                {{ chip.label }}
                <button @click="removeFilter(chip.key)" :aria-label="`Remove filter: ${chip.label}`">×</button>
            </span>
            <button @click="reset" class="text-xs font-semibold text-gray-500 hover:text-red-700">Clear all</button>
        </div>

        <!-- Advanced filters panel -->
        <transition name="expand">
            <div v-if="showAdvanced" class="mt-4 space-y-4 border-t border-gray-100 pt-4">
                <!-- Status pills -->
                <div>
                    <span class="text-xs font-medium text-gray-500">Status</span>
                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                        <button v-for="s in statuses" :key="s" @click="toggleStatus(s)"
                            :class="['rounded-full border px-3 py-1 text-xs font-semibold transition', local.status.includes(s) ? 'border-red-300 bg-red-50 text-red-800' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300']">
                            {{ s }}
                        </button>
                    </div>
                </div>

                <!-- Assignee + Tag row -->
                <div class="flex flex-wrap gap-4">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-gray-500">Assignee</span>
                        <select v-model="local.assigneeId" class="rounded-md border border-gray-300 px-3 py-2 text-sm" @change="emitChange">
                            <option :value="null">Anyone</option>
                            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.display_name }}</option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-gray-500">Tag</span>
                        <select v-model="local.tagId" class="rounded-md border border-gray-300 px-3 py-2 text-sm" @change="emitChange">
                            <option :value="null">Any tag</option>
                            <option v-for="t in tags" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </label>
                </div>

                <!-- Date range -->
                <div>
                    <span class="text-xs font-medium text-gray-500">Created date</span>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <input v-model="local.dateFrom" type="date" class="rounded-md border border-gray-300 px-3 py-2 text-sm" @change="emitChange" aria-label="From date" />
                        <span class="text-xs text-gray-400">to</span>
                        <input v-model="local.dateTo" type="date" class="rounded-md border border-gray-300 px-3 py-2 text-sm" @change="emitChange" aria-label="To date" />
                    </div>
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { reactive, computed, watch, ref } from 'vue';
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
const showAdvanced = ref(false);

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

const activeFilterChips = computed(() => {
    const chips = [];
    if (local.search) chips.push({ key: 'search', label: `Search: "${local.search}"` });
    if (local.scope !== 'all') chips.push({ key: 'scope', label: { all: 'All', assigned: 'Assigned to me', submitted: 'Submitted by me' }[local.scope] || local.scope });
    local.status.forEach(s => chips.push({ key: `status:${s}`, label: `Status: ${s}` }));
    if (local.assigneeId) {
        const u = props.assignableUsers.find(u => u.id === local.assigneeId);
        chips.push({ key: 'assigneeId', label: `Assignee: ${u?.display_name || local.assigneeId}` });
    }
    if (local.tagId) {
        const t = props.tags.find(t => t.id === local.tagId);
        chips.push({ key: 'tagId', label: `Tag: ${t?.name || local.tagId}` });
    }
    if (local.dateFrom) chips.push({ key: 'dateFrom', label: `From: ${local.dateFrom}` });
    if (local.dateTo) chips.push({ key: 'dateTo', label: `To: ${local.dateTo}` });
    return chips;
});

function toggleStatus(s) {
    const idx = local.status.indexOf(s);
    if (idx >= 0) local.status.splice(idx, 1);
    else local.status.push(s);
    emitChange();
}

function removeFilter(key) {
    if (key === 'search') local.search = '';
    else if (key === 'scope') local.scope = 'all';
    else if (key.startsWith('status:')) {
        const s = key.split(':')[1];
        const idx = local.status.indexOf(s);
        if (idx >= 0) local.status.splice(idx, 1);
    }
    else if (key === 'assigneeId') local.assigneeId = null;
    else if (key === 'tagId') local.tagId = null;
    else if (key === 'dateFrom') local.dateFrom = '';
    else if (key === 'dateTo') local.dateTo = '';
    emitChange();
}

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

<style scoped>
.expand-enter-active, .expand-leave-active {
    transition: opacity 200ms ease, max-height 200ms ease;
    overflow: hidden;
}
.expand-enter-from, .expand-leave-to {
    opacity: 0;
    max-height: 0;
}
.expand-enter-to, .expand-leave-from {
    opacity: 1;
    max-height: 500px;
}
</style>
