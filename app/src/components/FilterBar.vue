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
                :aria-expanded="showAdvanced" aria-controls="rcmi-filters-panel">
                <Icon name="sliders" />
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
            <div v-if="showAdvanced" id="rcmi-filters-panel" class="mt-4 space-y-5 border-t border-gray-100 pt-4">
                <!-- Status -->
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Status</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="s in visibleStatuses" :key="s" @click="toggleStatus(s)"
                            :aria-pressed="local.status.includes(s)"
                            :class="['inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition', local.status.includes(s) ? statusColor(s).on : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300']">
                            <span class="h-2 w-2 rounded-full" :class="statusColor(s).dot" aria-hidden="true"></span>
                            {{ statusDisplayLabel(s) }}
                            <span class="opacity-60">{{ statusCounts[s] ?? 0 }}</span>
                        </button>
                    </div>
                </div>

                <!-- People -->
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">People</p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <label for="filter-assignee" class="mb-1 block text-xs font-medium text-gray-600">Assignee</label>
                            <SearchSelect id="filter-assignee" v-model="local.assigneeId"
                                :options="assigneeOptions" placeholder="Anyone" clear-label="Anyone"
                                @change="emitChange" />
                        </div>
                        <div v-if="requestors.length">
                            <label for="filter-requestor" class="mb-1 block text-xs font-medium text-gray-600">Requestor</label>
                            <SearchSelect id="filter-requestor" v-model="local.requestorId"
                                :options="requestorOptions" placeholder="Any requestor" clear-label="Any requestor"
                                @change="emitChange" />
                        </div>
                        <div v-if="departments.length">
                            <label for="filter-department" class="mb-1 block text-xs font-medium text-gray-600">Department</label>
                            <SearchSelect id="filter-department" v-model="local.department"
                                :options="departmentOptions" placeholder="Any department" clear-label="Any department"
                                @change="emitChange" />
                        </div>
                    </div>
                </div>

                <!-- Classification -->
                <div v-if="tags.length || filterableFields.length">
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Classification</p>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <div v-if="tags.length">
                            <label for="filter-tag" class="mb-1 block text-xs font-medium text-gray-600">Tag</label>
                            <SearchSelect id="filter-tag" v-model="local.tagId"
                                :options="tagOptions" placeholder="Any tag" clear-label="Any tag"
                                @change="emitChange" />
                        </div>
                        <div v-if="filterableFields.length">
                            <label for="filter-field-key" class="mb-1 block text-xs font-medium text-gray-600">Form field</label>
                            <SearchSelect id="filter-field-key" v-model="local.fieldKey"
                                :options="fieldKeyOptions" placeholder="Any field" clear-label="Any field"
                                @change="onFieldKeyChange" />
                        </div>
                        <div v-if="filterableFields.length">
                            <label for="filter-field-value" class="mb-1 block text-xs font-medium text-gray-600">Field value</label>
                            <SearchSelect id="filter-field-value" v-model="local.fieldValue"
                                :options="fieldValueOptions" allow-custom
                                :placeholder="!local.fieldKey ? 'Pick a field first' : (fieldValuesLoading ? 'Loading values…' : 'Any value')"
                                clear-label="Any value"
                                :disabled="!local.fieldKey || fieldValuesLoading"
                                @change="emitChange" />
                        </div>
                    </div>
                </div>

                <!-- Created -->
                <div>
                    <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Created</p>
                    <div class="flex flex-wrap items-center gap-2">
                        <input v-model="local.dateFrom" type="date" class="rounded-md border border-gray-300 px-3 py-2 text-sm" @change="emitChange" aria-label="From date" />
                        <span class="text-xs text-gray-400">to</span>
                        <input v-model="local.dateTo" type="date" class="rounded-md border border-gray-300 px-3 py-2 text-sm" @change="emitChange" aria-label="To date" />
                    </div>
                </div>

                <!-- Footer -->
                <div class="flex items-center justify-between border-t border-gray-100 pt-3">
                    <p class="text-xs text-gray-500">{{ activeCount ? `${activeCount} filter${activeCount === 1 ? '' : 's'} active` : 'No filters applied' }}</p>
                    <div class="flex items-center gap-3">
                        <button @click="reset" class="text-xs font-semibold text-gray-500 hover:text-red-700">Clear all</button>
                        <button @click="showAdvanced = false" class="rcmi-button-secondary px-3 py-1.5 text-xs">Done</button>
                    </div>
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { reactive, computed, watch, ref } from 'vue';
import Icon from './Icon.vue';
import SearchSelect from './SearchSelect.vue';
import { api } from '../api.js';

const props = defineProps({
    statuses:        { type: Array, default: () => [] },
    statusCounts:    { type: Object, default: () => ({}) },
    tags:            { type: Array, default: () => [] },
    assignableUsers: { type: Array, default: () => [] },
    requestors:      { type: Array, default: () => [] },
    departments:     { type: Array, default: () => [] },
    formFields:      { type: Array, default: () => [] },
    modelValue:      { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue', 'clear-all']);

const defaults = {
    search: '', scope: 'all', status: [],
    assigneeId: null, tagId: null,
    requestorId: null, department: '',
    dateFrom: '', dateTo: '',
    fieldKey: '', fieldValue: '',
};

// The parent model uses payload keys (assignee_ids, date_from, …) while the
// UI uses shorter names — translate both ways so a replaced/restored model
// repopulates the controls.
function fromModel(v) {
    const m = v || {};
    const ff = m.field_filters && typeof m.field_filters === 'object' ? m.field_filters : {};
    const fk = Object.keys(ff)[0] || '';
    return {
        ...defaults,
        search: typeof m.search === 'string' ? m.search : '',
        scope: m.scope || 'all',
        status: Array.isArray(m.status) ? [...m.status] : [],
        assigneeId: m.assignee_ids?.[0] ?? null,
        tagId: m.tag_ids?.[0] ?? null,
        requestorId: m.author_id ? Number(m.author_id) : null,
        department: m.department || '',
        dateFrom: m.date_from || '',
        dateTo: m.date_to || '',
        fieldKey: fk,
        fieldValue: fk ? (ff[fk] ?? '') : '',
    };
}

const local = reactive(fromModel(props.modelValue));
const showAdvanced = ref(false);

// Snapshot of the last payload we emitted — the watcher below skips an
// echo of our own emit so transient UI-only state (a half-chosen field
// filter: fieldKey set, no value yet) isn't wiped by the re-sync. External
// replaces (restore, clear-all, queue-chip date/status changes) have a
// different serialized form and still re-sync.
let lastEmittedJson = '';

// Re-sync when the parent replaces the model (e.g. "Clear all filters").
watch(() => props.modelValue, (v) => {
    if (JSON.stringify(v) === lastEmittedJson) return;
    Object.assign(local, fromModel(v));
}, { deep: true });

const activeCount = computed(() => {
    let count = 0;
    if (local.search) count++;
    if (local.scope !== 'all') count++;
    if (local.status.length) count++;
    if (local.assigneeId) count++;
    if (local.requestorId) count++;
    if (local.department) count++;
    if (local.tagId) count++;
    if (local.dateFrom) count++;
    if (local.dateTo) count++;
    if (local.fieldKey && local.fieldValue) count++;
    return count;
});

const filterableFields = computed(() =>
    props.formFields.filter(f => ['dropdown', 'radio', 'checkbox', 'cascade'].includes(f.type))
);

const fieldKeyOptions = computed(() =>
    filterableFields.value.map(f => ({ value: f.field_key, label: f.label }))
);
const assigneeOptions = computed(() =>
    props.assignableUsers.map(u => ({ value: u.id, label: u.display_name }))
);
const requestorOptions = computed(() =>
    props.requestors.map(r => ({ value: r.id, label: r.name, count: r.count }))
);
const departmentOptions = computed(() =>
    props.departments.map(d => ({ value: d.value, label: d.value, count: d.count }))
);
const tagOptions = computed(() =>
    props.tags.map(t => ({ value: t.id, label: t.name }))
);

// 'Received' is merged into 'Pending Approval' in the UI — it's an internal
// pre-triage state (backend still stores it; the Pending Approval filter
// matches both server-side).
const visibleStatuses = computed(() => props.statuses.filter(s => s !== 'Received'));

// Display-only renames matching StatusBadge's labels map — stored values
// stay canonical, so 'Approved' still filters server-side as 'Approved'.
const statusDisplayLabels = { 'Approved': 'Assigned' };
function statusDisplayLabel(s) {
    return statusDisplayLabels[s] || s;
}

// Pill colors mirror StatusBadge. Written out literally — Tailwind's
// content scan only keeps class names it can see in full.
const STATUS_COLORS = {
    'Pending Approval':           { dot: 'bg-purple-500',  on: 'border-purple-300 bg-purple-50 text-purple-800' },
    'Approved':                   { dot: 'bg-blue-500',    on: 'border-blue-300 bg-blue-50 text-blue-800' },
    'In Progress':                { dot: 'bg-cyan-500',    on: 'border-cyan-300 bg-cyan-50 text-cyan-800' },
    'Rejected':                   { dot: 'bg-red-500',     on: 'border-red-300 bg-red-50 text-red-800' },
    'Rejected: Pending Revision': { dot: 'bg-orange-500',  on: 'border-orange-300 bg-orange-50 text-orange-800' },
    'Completed':                  { dot: 'bg-emerald-500', on: 'border-emerald-300 bg-emerald-50 text-emerald-800' },
};
const STATUS_COLOR_FALLBACK = { dot: 'bg-gray-400', on: 'border-gray-300 bg-gray-50 text-gray-800' };
function statusColor(s) {
    return STATUS_COLORS[s] || STATUS_COLOR_FALLBACK;
}

// Value options for the selected field are driven by stored ticket data:
// GET /field-values returns distinct answer values + per-value ticket
// counts for tickets the user can see. Config options never stored are
// appended (count-free) so a brand-new field is still filterable.
const fieldValueCounts = ref({});
const fieldValuesLoading = ref(false);

watch(() => local.fieldKey, async (key) => {
    fieldValueCounts.value = {};
    if (!key) return;
    fieldValuesLoading.value = true;
    try {
        const res = await api('/field-values', { params: new URLSearchParams({ field_key: key }) });
        const map = {};
        (res.values || []).forEach(v => { map[v.value] = v.count; });
        fieldValueCounts.value = map;
    } catch {
        fieldValueCounts.value = {};
    } finally {
        fieldValuesLoading.value = false;
    }
}, { immediate: true });

// Config-driven options (cascade trees flatten to full-path labels) used as
// fallback entries for options that have never been stored on a ticket.
const configFieldOptions = computed(() => {
    const f = props.formFields.find(x => x.field_key === local.fieldKey);
    if (!f) return [];
    if (f.type === 'cascade' && Array.isArray(f.config?.cascade_tree)) {
        const out = [];
        const seen = new Set();
        const walk = (nodes, prefix) => {
            for (const n of nodes) {
                const p = [...prefix, n.label];
                if (!seen.has(n.label)) {
                    seen.add(n.label);
                    out.push({ value: n.label, label: p.join(' › ') });
                }
                walk(n.children || [], p);
            }
        };
        walk(f.config.cascade_tree, []);
        return out;
    }
    const opts = new Set(f.config?.options || []);
    if (f.config?.cascade_options && typeof f.config.cascade_options === 'object') {
        for (const children of Object.values(f.config.cascade_options)) {
            if (Array.isArray(children)) children.forEach(c => opts.add(c));
        }
    }
    return [...opts].map(v => ({ value: v, label: v }));
});

const fieldValueOptions = computed(() => {
    const out = [];
    const seen = new Set();
    for (const [value, count] of Object.entries(fieldValueCounts.value)) {
        out.push({ value, label: value, count });
        seen.add(value);
    }
    for (const o of configFieldOptions.value) {
        if (!seen.has(o.value)) out.push(o);
    }
    return out;
});

const activeFilterChips = computed(() => {
    const chips = [];
    if (local.search) chips.push({ key: 'search', label: `Search: "${local.search}"` });
    if (local.scope !== 'all') chips.push({ key: 'scope', label: { all: 'All', assigned: 'Assigned to me', submitted: 'Submitted by me' }[local.scope] || local.scope });
    local.status.forEach(s => chips.push({ key: `status:${s}`, label: `Status: ${statusDisplayLabel(s)}` }));
    if (local.assigneeId) {
        const u = props.assignableUsers.find(u => u.id === local.assigneeId);
        chips.push({ key: 'assigneeId', label: `Assignee: ${u?.display_name || local.assigneeId}` });
    }
    if (local.requestorId) {
        const r = props.requestors.find(r => r.id === local.requestorId);
        chips.push({ key: 'requestorId', label: `Requestor: ${r?.name || local.requestorId}` });
    }
    if (local.department) {
        chips.push({ key: 'department', label: `Department: ${local.department}` });
    }
    if (local.tagId) {
        const t = props.tags.find(t => t.id === local.tagId);
        chips.push({ key: 'tagId', label: `Tag: ${t?.name || local.tagId}` });
    }
    if (local.dateFrom) chips.push({ key: 'dateFrom', label: `From: ${local.dateFrom}` });
    if (local.dateTo) chips.push({ key: 'dateTo', label: `To: ${local.dateTo}` });
    if (local.fieldKey && local.fieldValue) {
        const f = props.formFields.find(x => x.field_key === local.fieldKey);
        const opt = fieldValueOptions.value.find(o => o.value === local.fieldValue);
        chips.push({ key: 'fieldValue', label: `${f?.label || local.fieldKey}: ${opt?.label || local.fieldValue}` });
    }
    return chips;
});

function onFieldKeyChange() {
    local.fieldValue = '';
    emitChange();
}

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
        // Slice after the prefix — 'Rejected: Pending Revision' contains a colon.
        const s = key.slice('status:'.length);
        const idx = local.status.indexOf(s);
        if (idx >= 0) local.status.splice(idx, 1);
    }
    else if (key === 'assigneeId') local.assigneeId = null;
    else if (key === 'requestorId') local.requestorId = null;
    else if (key === 'department') local.department = '';
    else if (key === 'tagId') local.tagId = null;
    else if (key === 'dateFrom') local.dateFrom = '';
    else if (key === 'dateTo') local.dateTo = '';
    else if (key === 'fieldValue') { local.fieldKey = ''; local.fieldValue = ''; }
    emitChange();
}

let debounceTimer = null;
function debouncedEmit() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(emitChange, 300);
}

function emitChange() {
    const payload = {
        search: local.search,
        scope: local.scope,
        status: [...local.status],
        assignee_ids: local.assigneeId ? [local.assigneeId] : [],
        tag_ids: local.tagId ? [local.tagId] : [],
        author_id: local.requestorId || '',
        department: local.department || '',
        date_from: local.dateFrom,
        date_to: local.dateTo,
        field_filters: (local.fieldKey && local.fieldValue)
            ? { [local.fieldKey]: local.fieldValue }
            : {},
    };
    lastEmittedJson = JSON.stringify(payload);
    emit('update:modelValue', payload);
}

function reset() {
    Object.assign(local, fromModel({}));
    emitChange();
    // Queue lives in the parent — let it reset that too.
    emit('clear-all');
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
    max-height: 1200px;
}
</style>
