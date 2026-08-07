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
                                    <span class="text-xs text-gray-400">{{ f.type }}</span>
                                    <span v-if="f.config.logic && f.config.logic.field_key" class="rcmi-formbuilder-condition-chip" :title="conditionSummary(f)">
                                        {{ conditionSummary(f) }}
                                    </span>
                                    <button @click="toggleEdit(f.id)" class="rcmi-button-ghost px-2 py-1 text-xs">{{ editingId === f.id ? 'Done' : 'Edit' }}</button>
                                    <button @click="deleteField(f.id)" class="rcmi-button-ghost px-2 py-1 text-xs text-red-700 hover:text-red-800"><Icon name="trash" /></button>
                                </div>

                                <!-- Field editor (collapsible) -->
                                <div v-if="editingId === f.id" class="mt-3 space-y-3 border-t border-gray-100 pt-3">
                                    <div>
                                        <label class="rcmi-field-label">Label</label>
                                        <input v-model="f.label" @input="autoKey(f)" class="rcmi-input" placeholder="Field label" />
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
                                    </div>

                                    <!-- Cascading (dropdown only) -->
                                    <div v-if="f.type === 'dropdown'">
                                        <details class="rounded-md border border-gray-200 p-3">
                                            <summary class="cursor-pointer text-sm font-semibold text-gray-700">Cascading dropdown (optional)</summary>
                                            <div class="mt-3 space-y-3">
                                                <div>
                                                    <label class="rcmi-field-label">Parent field</label>
                                                    <select v-model="f.config.cascades_from" class="rcmi-input">
                                                        <option value="">None</option>
                                                        <option v-for="pf in cascadeableParents(f)" :key="pf.field_key" :value="pf.field_key">{{ pf.label }}</option>
                                                    </select>
                                                </div>
                                                <div v-if="f.config.cascades_from">
                                                    <label class="rcmi-field-label">Child options per parent value</label>
                                                    <div v-if="!f.config.cascade_options" class="text-xs text-gray-500">Set the parent field's options first, then configure children here.</div>
                                                    <div v-else class="space-y-2">
                                                        <div v-for="parentOpt in parentOptionsFor(f)" :key="parentOpt" class="rounded-md bg-gray-50 p-2">
                                                            <p class="mb-1 text-xs font-semibold text-gray-600">When parent = "{{ parentOpt }}":</p>
                                                            <input v-model="cascadeInput[f.field_key + '|' + parentOpt]"
                                                                @keyup.enter="addCascadeOption(f, parentOpt)"
                                                                class="rcmi-input mb-1 text-xs" placeholder="Add sub-option, press Enter" />
                                                            <div class="flex flex-wrap gap-1">
                                                                <span v-for="(co, ci) in (f.config.cascade_options[parentOpt] || [])" :key="ci"
                                                                    class="inline-flex items-center gap-1 rounded bg-white px-2 py-0.5 text-xs border border-gray-200">
                                                                    {{ co }}
                                                                    <button @click="f.config.cascade_options[parentOpt].splice(ci, 1)" class="text-red-600">×</button>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </details>
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
import { computed, ref, reactive, watch } from 'vue';
import { api } from '../api.js';
import Icon from './Icon.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    initialFields: { type: Array, default: () => [] },
});
const emit = defineEmits(['updated']);

const toast = useToast();
const fields = ref([]);
const editingId = ref(null);
const saving = ref(null);
const dragIdx = ref(null);
const dragOverIdx = ref(null);
const dragOverSide = ref(null);
const cascadeInput = reactive({});
const searchQuery = ref('');
const collapsedGroups = ref([]);

const paletteTypes = [
    { type: 'text',      label: 'Text',        icon: 'text' },
    { type: 'longtext',  label: 'Long Text',   icon: 'textarea' },
    { type: 'dropdown',  label: 'Dropdown',    icon: 'dropdown' },
    { type: 'checkbox',  label: 'Checkbox',    icon: 'checkbox-icon' },
    { type: 'radio',     label: 'Radio',       icon: 'radio-icon' },
    { type: 'date',      label: 'Date',        icon: 'calendar' },
    { type: 'number',    label: 'Number',      icon: 'hashtag' },
    { type: 'section',   label: 'Section',     icon: 'divider' },
];

// Sync local fields when the page data loads (flush:'pre' guarantees
// config.logic exists before the template accesses it).
watch(() => props.initialFields, (nextFields) => {
    fields.value = JSON.parse(JSON.stringify(nextFields || []));
    for (const f of fields.value) {
        if (!f.config) f.config = {};
        if (!f.config.logic) f.config.logic = { action: 'show', field_key: '', op: 'equals', value: '' };
        if (['dropdown', 'radio', 'checkbox'].includes(f.type) && !f.config.options) f.config.options = [];
        if (f.type === 'dropdown') {
            if (!f.config.cascade_options || Array.isArray(f.config.cascade_options)) f.config.cascade_options = {};
        }
    }
    editingId.value = null;
}, { immediate: true, flush: 'pre' });

function typeIcon(type) {
    const map = { text: 'text', longtext: 'textarea', dropdown: 'dropdown', checkbox: 'checkbox-icon', radio: 'radio-icon', date: 'calendar', number: 'hashtag', section: 'divider' };
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
    if (type === 'dropdown') config.cascade_options = {};
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
        fields.value.push(created);
        editingId.value = created.id;
        toast.success('Field added');
    }).catch((e) => toast.error(e.message || 'Failed to add field'));
}

function toggleEdit(id) {
    editingId.value = editingId.value === id ? null : id;
}

function addOption(f) {
    if (!f.config.options) f.config.options = [];
    f.config.options.push('Option ' + (f.config.options.length + 1));
}

function addCascadeOption(f, parentOpt) {
    const key = f.field_key + '|' + parentOpt;
    const val = (cascadeInput[key] || '').trim();
    if (!val) return;
    if (!f.config.cascade_options[parentOpt]) f.config.cascade_options[parentOpt] = [];
    if (!f.config.cascade_options[parentOpt].includes(val)) {
        f.config.cascade_options[parentOpt].push(val);
    }
    cascadeInput[key] = '';
}

function cascadeableParents(f) {
    return fields.value.filter(x => x.id !== f.id && x.type === 'dropdown' && !x.config?.cascades_from);
}

function parentOptionsFor(f) {
    if (!f.config?.cascades_from) return [];
    const parent = fields.value.find(x => x.field_key === f.config.cascades_from);
    return parent?.config?.options || [];
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
    // Clean config: drop empty logic if no field_key
    const config = { ...f.config };
    if (config.logic && !config.logic.field_key) delete config.logic;
    if (config.cascades_from === '') delete config.cascades_from;
    if (config.cascade_options && Object.keys(config.cascade_options).length === 0) delete config.cascade_options;

    api('/form-fields/' + f.id, {
        method: 'PUT',
        body: {
            label: f.label,
            field_key: f.field_key,
            type: f.type,
            required: f.required,
            config,
        },
    }).then((updated) => {
        const idx = fields.value.findIndex(x => x.id === f.id);
        if (idx >= 0) {
            // Preserve the current position; merge server response but
            // keep local config.logic if the server stripped the empty one
            const merged = { ...fields.value[idx], ...updated };
            if (!merged.config) merged.config = {};
            if (!merged.config.logic) {
                merged.config.logic = { action: 'show', field_key: '', op: 'equals', value: '' };
            }
            if (['dropdown', 'radio', 'checkbox'].includes(merged.type) && !merged.config.options) {
                merged.config.options = [];
            }
            if (merged.type === 'dropdown') {
                // Ensure cascade_options is always a plain object ({}), never an
                // array ([]). PHP's empty array serializes to JSON [] which loses
                // string-keyed properties on JSON.stringify in JS.
                if (!merged.config.cascade_options || Array.isArray(merged.config.cascade_options)) {
                    merged.config.cascade_options = {};
                }
            }
            fields.value[idx] = merged;
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

function deleteField(id) {
    if (!confirm('Delete this field? Submitted answers will also be removed.')) return;
    api('/form-fields/' + id, { method: 'DELETE' }).then(() => {
        fields.value = fields.value.filter(f => f.id !== id);
        toast.success('Field deleted');
    }).catch((e) => toast.error(e.message || 'Failed to delete field'));
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
    const moved = fields.value.splice(from, 1)[0];
    fields.value.splice(insertAt, 0, moved);
    dragIdx.value = null;
    // Persist reorder
    api('/form-fields/reorder', {
        method: 'PUT',
        body: { ids: fields.value.map(f => f.id) },
    }).then(() => {
        emit('updated');
        toast.success('Order saved');
    }).catch((e) => toast.error(e.message || 'Failed to reorder'));
}
</script>
