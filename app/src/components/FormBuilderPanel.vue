<template>
    <div class="rcmi-formbuilder-page">
        <header class="rcmi-formbuilder-page-header">
            <div>
                <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                    <router-link to="/create">Create Ticket</router-link>
                    <span class="rcmi-breadcrumb-sep">/</span>
                    <span class="font-semibold text-gray-700">Form Builder</span>
                </nav>
                <h1 class="text-2xl font-bold text-gray-900">Ticket Form Builder</h1>
                <p class="mt-1 text-sm text-gray-600">Design the fields people complete when submitting a ticket.</p>
            </div>
            <router-link to="/create" class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3 py-2 text-sm">
                <Icon name="chevron-left" /> Back to ticket form
            </router-link>
        </header>
        <div class="rcmi-formbuilder-workspace">
            <aside class="rcmi-formbuilder-sidebar">
                <!-- Public submissions status — editing lives on the Messages page -->
                <div class="rcmi-card p-4">
                    <p class="rcmi-section-label mb-2">Public Submissions</p>
                    <p class="text-xs text-gray-500">Let people without a UH account submit tickets anonymously.</p>
                    <p class="mt-3">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                            :class="allowPublic ? 'bg-teal-100 text-teal-800' : 'bg-gray-100 text-gray-600'">
                            {{ allowPublic ? 'Enabled' : 'Disabled' }}
                        </span>
                    </p>
                    <router-link to="/messages" class="rcmi-button-secondary mt-3 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs">
                        <Icon name="inbox" /> Manage messages &amp; settings
                    </router-link>
                </div>
                <div class="rcmi-card p-4">
                    <p class="rcmi-section-label mb-3">Add field</p>
                    <div class="grid grid-cols-2 gap-2">
                        <button v-for="t in paletteTypes" :key="t.type" @click="addField(t.type)" class="rcmi-formbuilder-palette-button">
                            <Icon :name="t.icon" /> <span>{{ t.label }}</span>
                        </button>
                    </div>
                </div>
                <div class="rcmi-formbuilder-guide">
                    <p class="rcmi-section-label mb-2">Organize your form</p>
                    <p class="text-xs leading-relaxed text-gray-600">Keep foundational questions at the top. Conditional fields appear in dependency groups so complex forms stay easy to scan.</p>
                    <ul class="mt-3 space-y-2 text-xs text-gray-600">
                        <li><strong>Always shown</strong> — visible on every ticket.</li>
                        <li><strong>Conditional</strong> — shown after another field matches.</li>
                        <li><strong>Sections</strong> — use dividers for clear steps.</li>
                    </ul>
                </div>
            </aside>
            <main class="rcmi-formbuilder-canvas">
                <div class="rcmi-formbuilder-canvas-header">
                    <div>
                        <h2 class="text-base font-bold text-gray-900">Fields</h2>
                        <p class="text-xs text-gray-500">{{ fields.length }} field{{ fields.length === 1 ? '' : 's' }} · {{ searchQuery ? 'Clear search to reorder' : 'Drag the handle to reorder' }}</p>
                    </div>
                    <div class="rcmi-formbuilder-toolbar">
                        <div class="flex items-center" role="group" aria-label="Undo and redo">
                            <button type="button" class="rcmi-button-ghost p-1.5 disabled:opacity-40 disabled:cursor-not-allowed"
                                :disabled="!canUndo" @click="undo" title="Undo (Ctrl+Z)" aria-label="Undo">
                                <Icon name="rotate-ccw" />
                            </button>
                            <button type="button" class="rcmi-button-ghost p-1.5 disabled:opacity-40 disabled:cursor-not-allowed"
                                :disabled="!canRedo" @click="redo" title="Redo (Ctrl+Shift+Z)" aria-label="Redo">
                                <Icon name="rotate-cw" />
                            </button>
                        </div>
                        <label class="rcmi-formbuilder-search">
                            <span class="sr-only">Search fields</span>
                            <Icon name="search" />
                            <input v-model.trim="searchQuery" type="search" placeholder="Search fields or conditions" />
                        </label>
                        <button type="button" class="rcmi-button-secondary px-2.5 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-50" :disabled="Boolean(searchQuery)" @click="toggleAllGroups">
                            {{ allGroupsCollapsed ? 'Expand all' : 'Collapse all' }}
                        </button>
                    </div>
                </div>
                <!-- Field list -->
                <div class="flex-1 overflow-y-auto px-5 py-4">
                        <p v-if="fields.length === 0" class="text-center text-sm text-gray-500 py-8">
                            No custom fields yet. Add one from the palette above.
                        </p>
                        <p v-else-if="visibleFieldEntries.length === 0" class="text-center text-sm text-gray-500 py-8">
                            No fields match “{{ searchQuery }}”.
                        </p>
                        <ul v-else class="space-y-2">
                            <template v-for="({ field: f, index: idx }, visibleIdx) in visibleFieldEntries" :key="f.id">
                                <li v-if="isVisibleGroupStart(idx, visibleIdx)" class="rcmi-formbuilder-group-header">
                                    <button type="button" class="rcmi-formbuilder-group-toggle"
                                        :aria-expanded="!isGroupCollapsed(groupMeta(idx).id) || Boolean(searchQuery)"
                                        :disabled="Boolean(searchQuery)"
                                        @click="toggleGroup(groupMeta(idx).id)">
                                        <Icon name="chevron-right" :class="{ 'rcmi-formbuilder-chevron-open': !isGroupCollapsed(groupMeta(idx).id) || searchQuery }" />
                                        <span class="flex-1">
                                            <strong>{{ groupMeta(idx).label }}</strong>
                                            <small>{{ groupMeta(idx).count }} field{{ groupMeta(idx).count === 1 ? '' : 's' }}</small>
                                        </span>
                                    </button>
                                </li>
                                <template v-if="!isGroupCollapsed(groupMeta(idx).id) || searchQuery">
                                <li v-if="!searchQuery" class="rcmi-formbuilder-drop-zone"
                                    :class="{ 'rcmi-formbuilder-drop-zone-active': dragOverIdx === idx && dragOverSide === 'above' }"
                                    @dragover.prevent="onDragOver(idx, 'above')"
                                    @drop.prevent="onDrop(idx, 'above')">
                                    <span v-if="dragOverIdx === idx && dragOverSide === 'above'">Drop above {{ f.label || 'this field' }}</span>
                                </li>
                                <li :class="['rcmi-formbuilder-field', { 'rcmi-formbuilder-field-dragging': dragIdx === idx, 'rcmi-formbuilder-field-conditional': groupMeta(idx).key !== 'always' }]">
                                <!-- Field header -->
                                <div class="flex items-center gap-2">
                                    <span :class="searchQuery ? 'cursor-not-allowed text-gray-300' : 'cursor-grab text-gray-400'" aria-label="Drag to reorder"
                                        :draggable="!searchQuery"
                                        @dragstart="onDragStart(idx)"
                                        @dragend="onDragEnd"><Icon name="grip" /></span>
                                    <span class="flex h-7 w-7 items-center justify-center rounded bg-gray-100 text-gray-500">
                                        <Icon :name="typeIcon(f.type)" />
                                    </span>
                                    <span class="min-w-0 flex-1 truncate text-sm font-semibold text-gray-800">{{ f.label || '(untitled)' }}</span>
                                    <span v-if="f.reserved" class="rcmi-formbuilder-reserved-chip" title="Reserved field — value syncs to ticket Due Date">Reserved</span>
                                    <span class="text-xs text-gray-400">{{ f.type }}</span>
                                    <span v-if="f.config.logic && f.config.logic.field_key" class="rcmi-formbuilder-condition-chip" :title="conditionSummary(f)">
                                        {{ conditionSummary(f) }}
                                    </span>
                                    <button @click="toggleEdit(f.id)" class="rcmi-button-ghost px-2 py-1 text-xs">{{ editingId === f.id ? 'Done' : 'Edit' }}</button>
                                    <button v-if="!f.reserved" @click="deleteField(f.id)" class="rcmi-button-ghost px-2 py-1 text-xs text-red-700 hover:text-red-800"><Icon name="trash" /></button>
                                </div>

                                <!-- Field editor (collapsible) -->
                                <div v-if="editingId === f.id" class="mt-3 space-y-3 border-t border-gray-100 pt-3">
                                    <div>
                                        <label class="rcmi-field-label">Label</label>
                                        <input v-model="f.label" @input="autoKey(f)" :disabled="f.reserved" class="rcmi-input" :class="{ 'opacity-60 cursor-not-allowed': f.reserved }" placeholder="Field label" />
                                        <p v-if="f.reserved" class="mt-1 text-xs text-amber-600">Reserved field — label is locked. Its value syncs to the ticket's Due Date for sorting.</p>
                                    </div>

                                    <div v-if="f.type !== 'section'" class="flex items-center gap-2">
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" v-model="f.required" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                                            Required
                                        </label>
                                    </div>

                                    <!-- Options editor (dropdown/radio/checkbox) -->
                                    <div v-if="['dropdown', 'radio', 'checkbox'].includes(f.type)">
                                        <label class="rcmi-field-label">Options</label>
                                        <ul class="space-y-1.5">
                                            <li v-for="(opt, oi) in (f.config.options || [])" :key="oi" class="flex items-center gap-2">
                                                <input v-model="f.config.options[oi]" class="rcmi-input flex-1" />
                                                <button @click="f.config.options.splice(oi, 1)" class="rcmi-button-ghost px-2 py-1 text-xs text-red-700"><Icon name="x" /></button>
                                            </li>
                                        </ul>
                                        <button @click="addOption(f)" class="rcmi-button-secondary mt-2 inline-flex items-center gap-1 px-2.5 py-1.5 text-xs">
                                            <Icon name="plus" /> Add option
                                        </button>
                                        <label v-if="f.type === 'dropdown'" class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" v-model="f.config.allow_other" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                                            Allow "Other" — add a free-text option to the list
                                        </label>
                                    </div>

                                    <!-- Cascade tree (cascade type only) -->
                                    <div v-if="f.type === 'cascade'">
                                        <label class="rcmi-field-label">Display style</label>
                                        <div class="mt-1 grid grid-cols-2 gap-2" role="radiogroup" aria-label="Cascade display style">
                                            <button v-for="s in cascadeStyles" :key="s.value" type="button" role="radio"
                                                :aria-checked="f.config.cascade_style === s.value"
                                                @click="f.config.cascade_style = s.value"
                                                class="rcmi-style-card"
                                                :class="{ 'rcmi-style-card-active': f.config.cascade_style === s.value }">
                                                <Icon :name="s.icon" />
                                                <span class="flex flex-col items-start">
                                                    <strong>{{ s.label }}</strong>
                                                    <small>{{ s.hint }}</small>
                                                </span>
                                            </button>
                                        </div>
                                        <p v-if="cascadeFitHint(f)" class="mt-2 text-xs text-amber-600">{{ cascadeFitHint(f) }}</p>

                                        <label class="rcmi-field-label mt-3">Preview</label>
                                        <div class="rounded-md border border-dashed border-gray-300 p-3">
                                            <CascadeField :field="f"
                                                :model-value="previewAnswers[f.id] || []"
                                                :required="false"
                                                @update:model-value="previewAnswers[f.id] = $event" />
                                        </div>

                                        <label class="mt-3 inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" v-model="f.config.cascade_other" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                                            Allow "Other" — show a free-text option at every level
                                        </label>
                                        <label class="rcmi-field-label mt-3">Options tree</label>
                                        <p class="mb-2 text-xs text-gray-500">Add children under any option to build unlimited levels. The answer is saved as the full path (e.g. “A › B › C”).</p>
                                        <CascadeTreeEditor :nodes="f.config.cascade_tree" />
                                    </div>

                                    <!-- Logic -->
                                    <div v-if="f.type !== 'section'">
                                        <details class="rounded-md border border-gray-200 p-3">
                                            <summary class="cursor-pointer text-sm font-semibold text-gray-700">Conditional logic (optional)</summary>
                                            <div v-if="f.config.logic" class="mt-3 space-y-2">
                                                <div class="flex items-center gap-2 text-sm">
                                                    <select v-model="f.config.logic.action" class="rcmi-input flex-1">
                                                        <option value="show">Show this field when</option>
                                                        <option value="hide">Hide this field when</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="rcmi-field-label">Depends on</label>
                                                    <select v-model="f.config.logic.field_key" class="rcmi-input">
                                                        <option value="">None</option>
                                                        <option v-for="df in otherFields(f)" :key="df.field_key" :value="df.field_key">{{ df.label }}</option>
                                                    </select>
                                                </div>
                                                <div v-if="f.config.logic.field_key" class="grid grid-cols-2 gap-2">
                                                    <div>
                                                        <label class="rcmi-field-label">Operator</label>
                                                        <select v-model="f.config.logic.op" class="rcmi-input">
                                                            <option value="equals">equals</option>
                                                            <option value="not_equals">not equals</option>
                                                            <option value="contains">contains</option>
                                                            <option value="not_contains">not contains</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="rcmi-field-label">Value</label>
                                                        <input v-model="f.config.logic.value" class="rcmi-input" placeholder="Value to match" />
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
                                    </div>

                                    <!-- Placeholder (text/longtext/number) -->
                                    <div v-if="['text', 'longtext', 'number'].includes(f.type)">
                                        <label class="rcmi-field-label">Placeholder</label>
                                        <input v-model="f.config.placeholder" class="rcmi-input" placeholder="Placeholder text" />
                                    </div>

                                    <!-- Date config: min days from today -->
                                    <div v-if="f.type === 'date'">
                                        <label class="rcmi-field-label">Minimum date (days from today)</label>
                                        <input v-model.number="f.config.min_days" type="number" min="0" class="rcmi-input" placeholder="e.g. 3 blocks dates before 3 days from today" />
                                        <p class="mt-1 text-xs text-gray-500">Leave empty for no restriction. 0 = today or later, 3 = 3 days from today or later.</p>
                                        <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" v-model="f.config.include_weekend" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                                            Include weekends
                                        </label>
                                        <p class="mt-1 text-xs text-gray-500">When unchecked, min days count only business days (Mon–Fri).</p>
                                    </div>

                                    <div class="flex justify-end gap-2 pt-2">
                                        <button @click="saveField(f)" :disabled="saving === f.id"
                                            class="rcmi-button-primary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs disabled:opacity-50">
                                            <Icon name="save" /> {{ saving === f.id ? 'Saving…' : 'Save' }}
                                        </button>
                                    </div>
                                </div>
                            </li>
                            </template>
                            </template>
                            <li v-if="!searchQuery" class="rcmi-formbuilder-drop-zone"
                                :class="{ 'rcmi-formbuilder-drop-zone-active': dragOverIdx === fields.length && dragOverSide === 'below' }"
                                @dragover.prevent="onDragOver(fields.length, 'below')"
                                @drop.prevent="onDrop(fields.length, 'below')">
                                <span v-if="dragOverIdx === fields.length && dragOverSide === 'below'">Drop at end</span>
                            </li>
                        </ul>
                </div>
            </main>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, reactive, watch, onMounted, onUnmounted } from 'vue';
import { api } from '../api.js';
import Icon from './Icon.vue';
import CascadeTreeEditor from './CascadeTreeEditor.vue';
import CascadeField from './CascadeField.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    initialFields: { type: Array, default: () => [] },
    initialAllowPublic: { type: Boolean, default: false },
});
const emit = defineEmits(['updated']);

const toast = useToast();
const fields = ref([]);
const editingId = ref(null);
const saving = ref(null);
const dragIdx = ref(null);
const dragOverIdx = ref(null);
const dragOverSide = ref(null);
const searchQuery = ref('');
const previewAnswers = reactive({}); // field id => cascade preview path
const collapsedGroups = ref([]);

// Public submissions status badge (edited on the Messages page)
const allowPublic = ref(false);

const paletteTypes = [
    { type: 'text',      label: 'Text',        icon: 'text' },
    { type: 'longtext',  label: 'Long Text',   icon: 'textarea' },
    { type: 'dropdown',  label: 'Dropdown',    icon: 'dropdown' },
    { type: 'cascade',   label: 'Cascade',     icon: 'flow' },
    { type: 'checkbox',  label: 'Checkbox',    icon: 'checkbox-icon' },
    { type: 'radio',     label: 'Radio',       icon: 'radio-icon' },
    { type: 'date',      label: 'Date',        icon: 'calendar' },
    { type: 'number',    label: 'Number',      icon: 'hashtag' },
    { type: 'section',   label: 'Section',     icon: 'divider' },
];

function normalizeField(f) {
    if (!f.config) f.config = {};
    if (!f.config.logic) f.config.logic = { action: 'show', field_key: '', op: 'equals', value: '' };
    if (['dropdown', 'radio', 'checkbox'].includes(f.type) && !f.config.options) f.config.options = [];
    if (f.type === 'dropdown') {
        // Ensure cascade_options is always a plain object ({}), never an
        // array ([]). PHP's empty array serializes to JSON [] which loses
        // string-keyed properties on JSON.stringify in JS.
        if (!f.config.cascade_options || Array.isArray(f.config.cascade_options)) f.config.cascade_options = {};
    }
    if (f.type === 'cascade') {
        if (!Array.isArray(f.config.cascade_tree)) f.config.cascade_tree = [];
        if (!f.config.cascade_style) f.config.cascade_style = 'dropdown';
    }
    return f;
}

// Sync local fields when the page data loads (flush:'pre' guarantees
// config.logic exists before the template accesses it).
watch(() => props.initialFields, (nextFields) => {
    const cloned = JSON.parse(JSON.stringify(nextFields || []));
    cloned.forEach(normalizeField);
    fields.value = cloned;
    editingId.value = null;
}, { immediate: true, flush: 'pre' });

// Sync public submissions toggle from parent when meta loads
watch(() => props.initialAllowPublic, (next) => {
    allowPublic.value = !!next;
}, { immediate: true });

const cascadeStyles = [
    { value: 'dropdown', label: 'Dropdowns', icon: 'dropdown', hint: 'Compact — one select per level' },
    { value: 'pills',    label: 'Pills',     icon: 'tag',      hint: 'Fast for shallow trees (2–3 levels)' },
    { value: 'columns',  label: 'Columns',   icon: 'grid',     hint: 'Deep trees — see context across levels' },
    { value: 'search',   label: 'Search',    icon: 'search',   hint: 'Large trees — type to find a path' },
];

// Count leaf nodes and max depth of a cascade tree, then flag styles that
// clearly mismatch the tree's shape.
function cascadeFitHint(f) {
    let leaves = 0;
    let maxDepth = 0;
    const walk = (nodes, depth) => {
        for (const n of nodes || []) {
            maxDepth = Math.max(maxDepth, depth);
            if (n.children?.length) walk(n.children, depth + 1);
            else leaves++;
        }
    };
    walk(f.config?.cascade_tree, 1);
    const style = f.config?.cascade_style || 'dropdown';
    if (leaves > 30 && style !== 'search') {
        return `This tree has ${leaves} leaf options — "Search" may be easier to navigate.`;
    }
    if (maxDepth >= 4 && style === 'pills') {
        return `This tree is ${maxDepth} levels deep — pills will wrap a lot; "Columns" or "Dropdowns" may read better.`;
    }
    if (leaves > 0 && leaves <= 8 && style === 'search') {
        return 'Small tree — "Pills" or "Dropdowns" are faster than typing to search.';
    }
    return '';
}

function typeIcon(type) {
    const map = { text: 'text', longtext: 'textarea', dropdown: 'dropdown', cascade: 'flow', checkbox: 'checkbox-icon', radio: 'radio-icon', date: 'calendar', number: 'hashtag', section: 'divider' };
    return map[type] || 'list';
}

function groupKey(f) {
    return f.config?.logic?.field_key || 'always';
}

const fieldGroupMeta = computed(() => {
    let segment = -1;
    let previousKey = null;
    let id = '';
    const counts = {};
    const metadata = fields.value.map((field) => {
        const key = groupKey(field);
        if (key !== previousKey) {
            segment++;
            previousKey = key;
            id = `${key}:${segment}`;
        }
        counts[id] = (counts[id] || 0) + 1;
        const parent = fields.value.find(x => x.field_key === key);
        return { id, key, label: key === 'always' ? 'Always shown' : `Conditional · ${parent?.label || key}` };
    });
    return metadata.map(meta => ({ ...meta, count: counts[meta.id] }));
});

function conditionSummary(f) {
    const logic = f.config?.logic;
    if (!logic?.field_key) return '';
    const parent = fields.value.find(x => x.field_key === logic.field_key);
    const operators = { equals: 'is', not_equals: 'is not', contains: 'contains', not_contains: 'does not contain' };
    const action = logic.action === 'hide' ? 'Hide if' : 'Show if';
    return `${action} ${parent?.label || logic.field_key} ${operators[logic.op] || logic.op} “${logic.value || '…'}”`;
}

const visibleFieldEntries = computed(() => {
    const query = searchQuery.value.toLowerCase();
    return fields.value
        .map((field, index) => ({ field, index }))
        .filter(({ field }) => !query || [field.label, field.field_key, field.type, conditionSummary(field)]
            .some(value => String(value || '').toLowerCase().includes(query)));
});

const groupIds = computed(() => [...new Set(fieldGroupMeta.value.map(meta => meta.id))]);
const allGroupsCollapsed = computed(() => groupIds.value.length > 0 && groupIds.value.every(id => collapsedGroups.value.includes(id)));

function groupMeta(index) {
    return fieldGroupMeta.value[index];
}

function isVisibleGroupStart(index, visibleIndex) {
    if (visibleIndex === 0) return true;
    const previousIndex = visibleFieldEntries.value[visibleIndex - 1].index;
    return groupMeta(index).id !== groupMeta(previousIndex).id;
}

function isGroupCollapsed(id) {
    return collapsedGroups.value.includes(id);
}

function toggleGroup(id) {
    collapsedGroups.value = isGroupCollapsed(id)
        ? collapsedGroups.value.filter(groupId => groupId !== id)
        : [...collapsedGroups.value, id];
}

function toggleAllGroups() {
    collapsedGroups.value = allGroupsCollapsed.value ? [] : [...groupIds.value];
}

// ── Undo/redo (in-session command history; server stays source of truth) ──
const HISTORY_LIMIT = 50;
const history = ref([]);
const historyIndex = ref(-1);
const canUndo = computed(() => historyIndex.value >= 0);
const canRedo = computed(() => historyIndex.value < history.value.length - 1);
const editSnapshots = reactive({}); // field id => deep clone captured when its editor opened

function pushHistory(cmd) {
    history.value = history.value.slice(0, historyIndex.value + 1);
    history.value.push(cmd);
    if (history.value.length > HISTORY_LIMIT) history.value.shift();
    historyIndex.value = history.value.length - 1;
}

let historyBusy = false; // an in-flight undo/redo must finish before the next can capture the cursor

async function undo() {
    const cmd = history.value[historyIndex.value];
    if (!cmd || historyBusy) return;
    historyBusy = true;
    try {
        await cmd.undo();
        historyIndex.value--;
    } catch (e) {
        toast.error(e.message || 'Undo failed');
    } finally {
        historyBusy = false;
    }
}

async function redo() {
    const cmd = history.value[historyIndex.value + 1];
    if (!cmd || historyBusy) return;
    historyBusy = true;
    try {
        await cmd.redo();
        historyIndex.value++;
    } catch (e) {
        toast.error(e.message || 'Redo failed');
    } finally {
        historyBusy = false;
    }
}

function removeFieldLocal(id) {
    fields.value = fields.value.filter(x => x.id !== id);
    if (editingId.value === id) editingId.value = null;
}

function insertFieldLocal(field, idx = fields.value.length) {
    fields.value.splice(Math.min(idx, fields.value.length), 0, field);
}

function orderFieldsByIds(ids) {
    const map = new Map(fields.value.map(x => [x.id, x]));
    fields.value = ids.map(id => map.get(id)).filter(Boolean)
        .concat(fields.value.filter(x => !ids.includes(x.id)));
}

// The exact body PUT /form-fields/{id} and POST /form-fields accept.
function buildFieldPayload(f) {
    const config = { ...f.config };
    if (config.logic && !config.logic.field_key) delete config.logic;
    if (config.cascades_from === '') delete config.cascades_from;
    if (config.cascade_options && Object.keys(config.cascade_options).length === 0) delete config.cascade_options;
    if (!config.allow_other) delete config.allow_other;
    return { label: f.label, field_key: f.field_key, type: f.type, required: f.required, config };
}

// PUT a snapshot back — used by undo/redo of field saves.
async function putFieldSnapshot(snapshot) {
    const updated = await api('/form-fields/' + snapshot.id, { method: 'PUT', body: buildFieldPayload(snapshot) });
    const idx = fields.value.findIndex(x => x.id === snapshot.id);
    const merged = normalizeField({ ...(idx >= 0 ? fields.value[idx] : {}), ...updated });
    if (idx >= 0) fields.value[idx] = merged; else insertFieldLocal(merged);
}

function onHistoryKeydown(e) {
    const tag = e.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target?.isContentEditable) return;
    const active = document.activeElement;
    if (active && (active.tagName === 'INPUT' || active.tagName === 'TEXTAREA' || active.tagName === 'SELECT' || active.isContentEditable)) return;
    if (document.querySelector('.rcmi-modal-root')) return;
    if (!(e.ctrlKey || e.metaKey)) return;
    const k = e.key.toLowerCase();
    if (k === 'z' && !e.shiftKey) { e.preventDefault(); undo(); }
    else if ((k === 'z' && e.shiftKey) || k === 'y') { e.preventDefault(); redo(); }
}

onMounted(() => window.addEventListener('keydown', onHistoryKeydown));
onUnmounted(() => window.removeEventListener('keydown', onHistoryKeydown));

function autoKey(f) {
    // Always derive key from label (slugified). Add suffix if collision.
    const base = (f.label || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 50) || 'field';
    let key = base;
    let n = 2;
    while (fields.value.some(x => x.id !== f.id && x.field_key === key)) {
        key = base + '_' + n++;
    }
    f.field_key = key;
}

function addField(type) {
    const label = type === 'section' ? 'New Section' : 'New ' + paletteTypes.find(t => t.type === type).label + ' Field';
    const config = {};
    if (['dropdown', 'radio', 'checkbox'].includes(type)) config.options = ['Option 1'];
    if (type === 'cascade') {
        config.cascade_style = 'dropdown';
        config.cascade_tree = [{ label: 'Option 1', children: [] }];
    }
    config.logic = { action: 'show', field_key: '', op: 'equals', value: '' };

    // Generate slug from label, with collision protection
    const base = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 50) || 'field';
    let field_key = base;
    let n = 2;
    while (fields.value.some(x => x.field_key === field_key)) {
        field_key = base + '_' + n++;
    }

    api('/form-fields', {
        method: 'POST',
        body: { label, field_key, type, required: false, config },
    }).then((created) => {
        fields.value.push(normalizeField(created));
        editingId.value = created.id;
        const cell = { id: created.id };
        pushHistory({
            label: 'Add field',
            undo: async () => {
                // The row may have been re-created under a new id by an
                // intervening delete+undo — resolve by field_key, which is
                // unique and survives re-creation.
                const current = fields.value.find(x => x.field_key === field_key);
                const targetId = current ? current.id : cell.id;
                try {
                    await api('/form-fields/' + targetId, { method: 'DELETE' });
                } catch (err) {
                    if (current) throw err; // already gone server-side = goal achieved
                }
                removeFieldLocal(targetId);
            },
            redo: async () => {
                const again = normalizeField(await api('/form-fields', {
                    method: 'POST',
                    body: { label, field_key, type, required: false, config },
                }));
                cell.id = again.id;
                fields.value.push(again);
            },
        });
        toast.success('Field added');
    }).catch((e) => toast.error(e.message || 'Failed to add field'));
}

function toggleEdit(id) {
    const opening = editingId.value !== id;
    editingId.value = opening ? id : null;
    if (opening) {
        const f = fields.value.find(x => x.id === id);
        if (f) editSnapshots[id] = JSON.parse(JSON.stringify(f));
    } else {
        delete editSnapshots[id];
    }
}

function addOption(f) {
    if (!f.config.options) f.config.options = [];
    f.config.options.push('Option ' + (f.config.options.length + 1));
}

function otherFields(f) {
    return fields.value.filter(x => x.id !== f.id && x.type !== 'section');
}

function saveField(f) {
    // Frontend validation: check for duplicate field_key
    const dup = fields.value.find(x => x.id !== f.id && x.field_key === f.field_key);
    if (dup) {
        toast.error(`Key "${f.field_key}" is already used by "${dup.label}". Change the label to generate a unique key.`);
        return;
    }
    if (!f.field_key || !f.field_key.trim()) {
        toast.error('Field key cannot be empty. Enter a label to auto-generate one.');
        return;
    }
    saving.value = f.id;
    const before = editSnapshots[f.id] ? JSON.parse(JSON.stringify(editSnapshots[f.id])) : null;

    api('/form-fields/' + f.id, {
        method: 'PUT',
        body: buildFieldPayload(f),
    }).then((updated) => {
        const idx = fields.value.findIndex(x => x.id === f.id);
        if (idx >= 0) {
            // Preserve the current position; merge server response but
            // keep local config.logic if the server stripped the empty one
            fields.value[idx] = normalizeField({ ...fields.value[idx], ...updated });
        }
        delete editSnapshots[f.id];
        if (before && idx >= 0) {
            const after = JSON.parse(JSON.stringify(fields.value[idx]));
            pushHistory({
                label: 'Save field',
                undo: () => putFieldSnapshot(before),
                redo: () => putFieldSnapshot(after),
            });
        }
        toast.success('Field saved');
    }).catch((e) => {
        // Backend also validates and returns 409 with 'Field key already exists.'
        const msg = e.message || 'Failed to save field';
        if (msg.includes('already exists') || msg.includes('field_key')) {
            toast.error(`Key "${f.field_key}" already exists. Try a different label.`);
        } else {
            toast.error(msg);
        }
    })
      .finally(() => { saving.value = null; });
}

// Delete is delayed by the undo window — the API call only fires once the
// toast expires, so undoing in time never touches submitted answers.
const DELETE_UNDO_MS = 8000;

function deleteField(id) {
    const idx = fields.value.findIndex(x => x.id === id);
    if (idx < 0) return;
    const field = fields.value[idx];
    const snapshot = JSON.parse(JSON.stringify(field));
    fields.value.splice(idx, 1);
    if (editingId.value === id) editingId.value = null;

    let committed = false;
    let timer = null;
    const cell = { id };
    const schedule = () => {
        timer = setTimeout(() => {
            committed = true;
            api('/form-fields/' + cell.id, { method: 'DELETE' })
                .catch(e => toast.error(e.message || 'Failed to delete field'));
        }, DELETE_UNDO_MS);
    };
    schedule();

    pushHistory({
        label: 'Delete field',
        undo: async () => {
            if (!committed) {
                clearTimeout(timer);
                insertFieldLocal(field, idx);
                return;
            }
            // Already deleted server-side — restore the definition by re-creating
            // it (same label/key/config; previously submitted answers stay gone).
            const recreated = normalizeField(await api('/form-fields', { method: 'POST', body: buildFieldPayload(snapshot) }));
            cell.id = recreated.id;
            insertFieldLocal(recreated, idx);
        },
        redo: async () => {
            if (!committed) {
                removeFieldLocal(cell.id);
                schedule();
                return;
            }
            await api('/form-fields/' + cell.id, { method: 'DELETE' });
            removeFieldLocal(cell.id);
        },
    });
    toast.action(`Deleted "${field.label || 'field'}"`, 'Undo', undo, DELETE_UNDO_MS);
}

// Drag-and-drop reorder (only the grip handle is draggable)
function onDragStart(idx) {
    dragIdx.value = idx;
}
function onDragEnd() {
    dragIdx.value = null;
    dragOverIdx.value = null;
    dragOverSide.value = null;
}
function onDragOver(idx, side) {
    dragOverIdx.value = idx;
    dragOverSide.value = side;
}
function onDrop(targetIdx, side) {
    const from = dragIdx.value;
    dragOverIdx.value = null;
    dragOverSide.value = null;
    if (from === null) return;
    let insertAt = side === 'below' ? targetIdx + 1 : targetIdx;
    if (from < insertAt) insertAt -= 1;
    if (from === insertAt) return;
    const beforeIds = fields.value.map(f => f.id);
    const moved = fields.value.splice(from, 1)[0];
    fields.value.splice(insertAt, 0, moved);
    const afterIds = fields.value.map(f => f.id);
    dragIdx.value = null;
    // Persist reorder
    api('/form-fields/reorder', {
        method: 'PUT',
        body: { ids: afterIds },
    }).then(() => {
        emit('updated');
        pushHistory({
            label: 'Reorder fields',
            undo: async () => {
                await api('/form-fields/reorder', { method: 'PUT', body: { ids: beforeIds } });
                orderFieldsByIds(beforeIds);
            },
            redo: async () => {
                await api('/form-fields/reorder', { method: 'PUT', body: { ids: afterIds } });
                orderFieldsByIds(afterIds);
            },
        });
        toast.success('Order saved');
    }).catch((e) => toast.error(e.message || 'Failed to reorder'));
}
</script>
