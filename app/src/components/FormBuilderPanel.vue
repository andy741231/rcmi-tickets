<template>
    <div class="rcmi-formbuilder-page">
        <header class="rcmi-formbuilder-page-header">
            <div>
                <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                    <template v-for="(crumb, i) in breadcrumbs" :key="i">
                        <router-link v-if="crumb.to" :to="crumb.to">{{ crumb.label }}</router-link>
                        <span v-else class="font-semibold text-gray-700">{{ crumb.label }}</span>
                        <span v-if="i < breadcrumbs.length - 1" class="rcmi-breadcrumb-sep">/</span>
                    </template>
                </nav>
                <h1 class="text-2xl font-bold text-gray-900">{{ title }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ subtitle }}</p>
            </div>
            <router-link :to="backTo" class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3 py-2 text-sm">
                <Icon name="chevron-left" /> {{ backLabel }}
            </router-link>
        </header>
        <slot name="tabs" />
        <!-- A conditional `workspace` slot lets pages swap in another view
             (e.g. settings/preview) while keeping this builder mounted —
             v-show preserves field state, editingId and undo history. -->
        <div v-show="!$slots.workspace" class="rcmi-formbuilder-workspace">
            <aside class="rcmi-formbuilder-sidebar">
                <slot name="sidebar" />
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
                <!-- Field list (no overflow-y here — it never scrolls, and a
                     scrollable ancestor would break the sticky Save bar) -->
                <div class="flex-1 px-5 py-4">
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
                                    <span v-if="conditionSummary(f)" class="rcmi-formbuilder-condition-chip" :title="conditionSummary(f)">
                                        {{ conditionSummary(f) }}
                                    </span>
                                    <button @click="toggleEdit(f.id)" class="rcmi-button-ghost px-2 py-1 text-xs">{{ editingId === f.id ? 'Done' : 'Edit' }}</button>
                                    <button v-if="!f.reserved" @click="duplicateField(f)" class="rcmi-button-ghost inline-flex items-center gap-1 px-2 py-1 text-xs" title="Duplicate field"><Icon name="copy" /> Duplicate</button>
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
                                                <span v-if="isDupOption(f, opt)" class="shrink-0 text-amber-500"
                                                    title="Duplicate label — approval chains trigger on the label alone, so both copies would match the same chain">
                                                    <Icon name="alert" />
                                                </span>
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

                                        <div v-if="f.config.cascade_style === 'steps'" class="mt-3">
                                            <label class="rcmi-field-label">Step labels</label>
                                            <div class="mt-1 space-y-1.5">
                                                <input v-for="si in cascadeTreeStats(f).maxDepth" :key="si" type="text"
                                                    class="rcmi-input"
                                                    :value="f.config.cascade_level_labels?.[si - 1] || ''"
                                                    :placeholder="`Step ${si} label`"
                                                    @input="setCascadeLevelLabel(f, si - 1, $event.target.value)" />
                                            </div>
                                            <p class="mt-1 text-xs text-gray-500">Optional — blank steps fall back to "Choose an option", then "Under ‹previous answer›".</p>
                                        </div>

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
                                                    <select v-model="f.config.logic.match" class="rcmi-input w-28">
                                                        <option value="all">all</option>
                                                        <option value="any">any</option>
                                                    </select>
                                                    <span class="text-xs text-gray-500">of these rules match:</span>
                                                </div>
                                                <div v-for="(rule, ri) in f.config.logic.rules" :key="ri"
                                                    class="grid grid-cols-[1fr_auto_1fr_auto] items-end gap-2">
                                                    <div>
                                                        <label class="rcmi-field-label">Field</label>
                                                        <select v-model="rule.field_key" class="rcmi-input">
                                                            <option value="">Choose…</option>
                                                            <optgroup v-for="group in conditionSourceGroups(f)" :key="group.label" :label="group.label">
                                                                <option v-for="df in group.fields" :key="df.field_key" :value="df.field_key">{{ df.label }}</option>
                                                            </optgroup>
                                                        </select>
                                                    </div>
                                                    <div class="w-32">
                                                        <label class="rcmi-field-label">Operator</label>
                                                        <select v-model="rule.op" class="rcmi-input">
                                                            <option value="equals">equals</option>
                                                            <option value="not_equals">not equals</option>
                                                            <option value="contains">contains</option>
                                                            <option value="not_contains">not contains</option>
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label class="rcmi-field-label">Value</label>
                                                        <input v-model="rule.value" class="rcmi-input" placeholder="Value to match" />
                                                    </div>
                                                    <button type="button" @click="removeRule(f, ri)"
                                                        class="rcmi-button-ghost mb-1 px-2 py-1 text-xs text-red-700"
                                                        :disabled="f.config.logic.rules.length <= 1"
                                                        aria-label="Remove rule"><Icon name="x" /></button>
                                                </div>
                                                <button type="button" @click="addRule(f)"
                                                    :disabled="f.config.logic.rules.length >= 10"
                                                    class="rcmi-button-secondary inline-flex items-center gap-1 px-2.5 py-1.5 text-xs disabled:opacity-50">
                                                    <Icon name="plus" /> Add rule
                                                </button>
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

                                    <!-- Number: integer-only toggle -->
                                    <div v-if="f.type === 'number'">
                                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" v-model="f.config.integer_only" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                                            Integers only — reject decimal values
                                        </label>
                                    </div>

                                    <!-- Rating config (survey) -->
                                    <div v-if="f.type === 'rating'">
                                        <label class="rcmi-field-label">Max stars</label>
                                        <input v-model.number="f.config.max" type="number" min="3" max="10" class="rcmi-input" placeholder="5" />
                                        <div class="mt-2 grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="rcmi-field-label">Low end label</label>
                                                <input v-model="f.config.low_label" class="rcmi-input" placeholder="e.g. Poor" />
                                            </div>
                                            <div>
                                                <label class="rcmi-field-label">High end label</label>
                                                <input v-model="f.config.high_label" class="rcmi-input" placeholder="e.g. Excellent" />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Scale config (survey) -->
                                    <div v-if="f.type === 'scale'">
                                        <div class="grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="rcmi-field-label">Min</label>
                                                <input v-model.number="f.config.min" type="number" class="rcmi-input" placeholder="0" />
                                            </div>
                                            <div>
                                                <label class="rcmi-field-label">Max</label>
                                                <input v-model.number="f.config.max" type="number" class="rcmi-input" placeholder="10" />
                                            </div>
                                        </div>
                                        <div class="mt-2 grid grid-cols-2 gap-2">
                                            <div>
                                                <label class="rcmi-field-label">Low end label</label>
                                                <input v-model="f.config.low_label" class="rcmi-input" placeholder="e.g. Not likely" />
                                            </div>
                                            <div>
                                                <label class="rcmi-field-label">High end label</label>
                                                <input v-model="f.config.high_label" class="rcmi-input" placeholder="e.g. Very likely" />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Sticky so Save stays reachable while a long
                                         editor scrolls (same treatment as the survey
                                         Settings tab save bar). -->
                                    <div class="sticky bottom-0 z-10 -mx-3 -mb-3 mt-3 flex justify-end gap-2 rounded-b-md border-t border-gray-100 bg-white/90 px-3 py-2.5 backdrop-blur">
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
        <slot name="workspace" />

        <!-- Warn before deleting a field that has stored answers -->
        <Modal v-if="pendingDeleteField" @close="pendingDeleteField = null" title="Delete field" titleClass="rcmi-modal-title-sans">
            <p class="text-sm text-gray-700">
                <strong class="text-gray-900">{{ pendingDeleteField.label }}</strong> has
                <strong class="text-red-700">{{ pendingDeleteField.answer_count }}</strong>
                stored answer{{ pendingDeleteField.answer_count === 1 ? '' : 's' }} on existing tickets.
                <template v-if="deletesStoredAnswers">Deleting the field permanently deletes those answers — this cannot be undone.</template>
                <template v-else>Past responses keep their copy of this question and answer — only the field is removed for future surveys.</template>
            </p>
            <template #footer>
                <button @click="performDeleteField(pendingDeleteField.id)"
                    class="rcmi-button-primary px-4 py-2 text-sm">
                    {{ deletesStoredAnswers ? 'Yes, delete field and answers' : 'Yes, delete field' }}
                </button>
                <button @click="pendingDeleteField = null"
                    class="rcmi-button-secondary px-4 py-2 text-sm">Cancel</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { computed, ref, reactive, watch, onMounted, onUnmounted } from 'vue';
import { api } from '../api.js';
import Icon from './Icon.vue';
import CascadeTreeEditor from './CascadeTreeEditor.vue';
import CascadeField from './CascadeField.vue';
import { useToast } from '../composables/useToast.js';
import Modal from './Modal.vue';
import { normalizeLogic } from '../lib/fieldLogic.js';

const props = defineProps({
    initialFields: { type: Array, default: () => [] },
    // REST base for field CRUD — '/form-fields' for the ticket form,
    // '/survey-fields' for the satisfaction survey.
    apiBase: { type: String, default: '/form-fields' },
    // Palette buttons; defaults to the ticket-form types.
    palette: { type: Array, default: null },
    // Header text.
    title: { type: String, default: 'Ticket Form Builder' },
    subtitle: { type: String, default: 'Design the fields people complete when submitting a ticket.' },
    breadcrumbs: {
        type: Array,
        default: () => [
            { to: '/create', label: 'Create Ticket' },
            { label: 'Form Builder' },
        ],
    },
    backTo: { type: String, default: '/create' },
    backLabel: { type: String, default: 'Back to ticket form' },
    // Extra condition sources appended after this form's own fields —
    // e.g. the ticket form's fields keyed "ticket:<key>" for the survey.
    extraConditionSources: { type: Array, default: () => [] },
});
const emit = defineEmits(['updated', 'fields-change']);

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

const DEFAULT_PALETTE = [
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
const paletteTypes = computed(() => props.palette || DEFAULT_PALETTE);

function normalizeField(f) {
    if (!f.config) f.config = {};
    // v2 condition shape: { action, match, rules }. normalizeLogic also
    // upgrades legacy single-rule configs; null → one blank rule row.
    f.config.logic = normalizeLogic(f.config.logic)
        || { action: 'show', match: 'all', rules: [{ field_key: '', op: 'equals', value: '' }] };
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
    // Keep an open editor across reloads (e.g. a duplicate just opened for
    // renaming) — close only when the field itself is gone.
    if (editingId.value !== null && !cloned.some(f => f.id === editingId.value)) {
        editingId.value = null;
    }
}, { immediate: true, flush: 'pre' });

// Let the host page mirror the live field list (previews, status counts).
// Deep-cloned so parents can never mutate panel state.
watch(fields, (next) => {
    emit('fields-change', JSON.parse(JSON.stringify(next || [])));
}, { deep: true, immediate: true });

const cascadeStyles = [
    { value: 'dropdown', label: 'Dropdowns', icon: 'dropdown', hint: 'Compact — one select per level' },
    { value: 'pills',    label: 'Pills',     icon: 'tag',      hint: 'Fast for shallow trees (2–3 levels)' },
    { value: 'steps',    label: 'Steps',     icon: 'list',     hint: 'Guided — one level at a time, answered levels collapse' },
    { value: 'columns',  label: 'Columns',   icon: 'grid',     hint: 'Deep trees — see context across levels' },
    { value: 'search',   label: 'Search',    icon: 'search',   hint: 'Large trees — type to find a path' },
];

// Count leaf nodes and max depth of a cascade tree, then flag styles that
// clearly mismatch the tree's shape.
// True when this option's label appears more than once in the field's list —
// approval chains trigger on the label alone, so duplicates are ambiguous.
function isDupOption(f, opt) {
    const k = (opt || '').trim().toLowerCase();
    return !!k && (f.config.options || []).filter(o => (o || '').trim().toLowerCase() === k).length > 1;
}

// Leaf count and max depth of a cascade tree.
function cascadeTreeStats(f) {
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
    return { leaves, maxDepth };
}

// Steps style: set a per-level label, initializing the config array lazily.
// Blank entries fall back to the built-in step labels in CascadeField.
function setCascadeLevelLabel(f, i, v) {
    if (!Array.isArray(f.config.cascade_level_labels)) f.config.cascade_level_labels = [];
    f.config.cascade_level_labels[i] = v;
}

function cascadeFitHint(f) {
    const { leaves, maxDepth } = cascadeTreeStats(f);
    const style = f.config?.cascade_style || 'dropdown';
    if (leaves > 30 && style !== 'search' && style !== 'steps') {
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
    const map = { text: 'text', longtext: 'textarea', dropdown: 'dropdown', cascade: 'flow', checkbox: 'checkbox-icon', radio: 'radio-icon', date: 'calendar', number: 'hashtag', section: 'divider', rating: 'star', scale: 'sliders' };
    return map[type] || 'list';
}

// A field's condition group is keyed off its first rule's field_key.
function groupKey(f) {
    const logic = normalizeLogic(f.config?.logic);
    return logic?.rules?.[0]?.field_key || 'always';
}

// Label lookup across this form's fields + extra condition sources
// (survey rules can point at "ticket:<key>").
function sourceLabel(key) {
    if (!key || key === 'always') return '';
    const own = fields.value.find(x => x.field_key === key);
    if (own) return own.label;
    const extra = (props.extraConditionSources || []).find(x => x.field_key === key);
    return extra ? extra.label : key;
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
        return { id, key, label: key === 'always' ? 'Always shown' : `Conditional · ${sourceLabel(key)}` };
    });
    return metadata.map(meta => ({ ...meta, count: counts[meta.id] }));
});

function conditionSummary(f) {
    const logic = normalizeLogic(f.config?.logic);
    if (!logic) return '';
    const operators = { equals: 'is', not_equals: 'is not', contains: 'contains', not_contains: 'does not contain' };
    const action = logic.action === 'hide' ? 'Hide if' : 'Show if';
    const ruleText = (r) => `${sourceLabel(r.field_key)} ${operators[r.op] || r.op} “${r.value || '…'}”`;
    if (logic.rules.length === 1) {
        return `${action} ${ruleText(logic.rules[0])}`;
    }
    return `${action} ${logic.match} of ${logic.rules.length} rules match`;
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

// The exact body PUT {apiBase}/{id} and POST {apiBase} accept.
function buildFieldPayload(f) {
    const config = { ...f.config };
    // Keep logic only when at least one rule names a field.
    const logic = normalizeLogic(config.logic);
    if (logic) config.logic = logic; else delete config.logic;
    if (config.cascades_from === '') delete config.cascades_from;
    if (config.cascade_options && Object.keys(config.cascade_options).length === 0) delete config.cascade_options;
    if (!config.allow_other) delete config.allow_other;
    return { label: f.label, field_key: f.field_key, type: f.type, required: f.required, config };
}

// PUT a snapshot back — used by undo/redo of field saves.
async function putFieldSnapshot(snapshot) {
    const updated = await api(props.apiBase + '/' + snapshot.id, { method: 'PUT', body: buildFieldPayload(snapshot) });
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
    const label = type === 'section' ? 'New Section'
        : type === 'rating' ? 'How would you rate your experience?'
        : type === 'scale' ? 'How likely are you to recommend us?'
        : 'New ' + paletteTypes.value.find(t => t.type === type).label + ' Field';
    const config = {};
    if (['dropdown', 'radio', 'checkbox'].includes(type)) config.options = ['Option 1'];
    if (type === 'cascade') {
        config.cascade_style = 'dropdown';
        config.cascade_tree = [{ label: 'Option 1', children: [] }];
    }
    if (type === 'rating') config.max = 5;
    if (type === 'scale') { config.min = 0; config.max = 10; }
    config.logic = { action: 'show', match: 'all', rules: [{ field_key: '', op: 'equals', value: '' }] };

    // Generate slug from label, with collision protection
    const base = label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 50) || 'field';
    let field_key = base;
    let n = 2;
    while (fields.value.some(x => x.field_key === field_key)) {
        field_key = base + '_' + n++;
    }

    api(props.apiBase, {
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
                    await api(props.apiBase + '/' + targetId, { method: 'DELETE' });
                } catch (err) {
                    if (current) throw err; // already gone server-side = goal achieved
                }
                removeFieldLocal(targetId);
            },
            redo: async () => {
                const again = normalizeField(await api(props.apiBase, {
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

// Clone a field: copies its full config, gives it a unique key, inserts it
// directly below the original (server sort_order synced via reorder), and
// opens the copy for renaming. Undo deletes by field_key; redo re-creates.
async function duplicateField(f) {
    const payload = buildFieldPayload(f);
    payload.label = (f.label || 'Field') + ' copy';
    const base = payload.label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 50) || 'field';
    let key = base;
    let n = 2;
    while (fields.value.some(x => x.field_key === key)) {
        key = base + '_' + n++;
    }
    payload.field_key = key;

    const srcIdx = fields.value.findIndex(x => x.id === f.id);
    const insertAt = srcIdx >= 0 ? srcIdx + 1 : fields.value.length;
    const cell = { id: null };
    try {
        const created = normalizeField(await api(props.apiBase, { method: 'POST', body: payload }));
        cell.id = created.id;
        fields.value.splice(insertAt, 0, created);
        // The POST landed at the end server-side — persist the real position.
        await api(props.apiBase + '/reorder', { method: 'PUT', body: { ids: fields.value.map(x => x.id) } });
        editingId.value = created.id;
        editSnapshots[created.id] = JSON.parse(JSON.stringify(created));
        pushHistory({
            label: 'Duplicate field',
            undo: async () => {
                const current = fields.value.find(x => x.field_key === key);
                const targetId = current ? current.id : cell.id;
                try {
                    await api(props.apiBase + '/' + targetId, { method: 'DELETE' });
                } catch (err) {
                    if (current) throw err; // already gone server-side = goal achieved
                }
                removeFieldLocal(targetId);
            },
            redo: async () => {
                const again = normalizeField(await api(props.apiBase, { method: 'POST', body: payload }));
                cell.id = again.id;
                fields.value.splice(Math.min(insertAt, fields.value.length), 0, again);
                await api(props.apiBase + '/reorder', { method: 'PUT', body: { ids: fields.value.map(x => x.id) } });
            },
        });
        emit('updated');
        toast.success(`Duplicated "${f.label || 'field'}"`);
    } catch (e) {
        // If the field was created but reorder failed, clean up so we don't
        // leave a stray copy parked at the end of the form.
        if (cell.id) {
            try { await api(props.apiBase + '/' + cell.id, { method: 'DELETE' }); } catch (_) { /* best effort */ }
            removeFieldLocal(cell.id);
        }
        toast.error(e.message || 'Failed to duplicate field');
    }
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

// Options for a rule's "Field" dropdown: own fields (non-section,
// excluding the field being edited) then any extra sources such as
// the ticket form's fields for the survey builder.
function conditionSourceGroups(f) {
    const groups = [{
        label: 'This form',
        fields: fields.value.filter(x => x.id !== f.id && x.type !== 'section'),
    }];
    if (props.extraConditionSources.length) {
        groups.push({ label: 'Ticket fields', fields: props.extraConditionSources });
    }
    return groups;
}

function addRule(f) {
    const logic = f.config.logic;
    if (!logic || !Array.isArray(logic.rules) || logic.rules.length >= 10) return;
    logic.rules.push({ field_key: '', op: 'equals', value: '' });
}

function removeRule(f, i) {
    const logic = f.config.logic;
    if (!logic || logic.rules.length <= 1) return;
    logic.rules.splice(i, 1);
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

    api(props.apiBase + '/' + f.id, {
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

// Ticket-form answers are deleted with the field; survey answers are
// snapshots kept for historical responses, so the modal wording differs.
const deletesStoredAnswers = computed(() => props.apiBase === '/form-fields');

// Fields with stored answers get a hard confirmation first.
const pendingDeleteField = ref(null);

function deleteField(id) {
    const field = fields.value.find(x => x.id === id);
    if (field && field.answer_count > 0) {
        pendingDeleteField.value = field;
        return;
    }
    performDeleteField(id);
}

function performDeleteField(id) {
    pendingDeleteField.value = null;
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
            api(props.apiBase + '/' + cell.id, { method: 'DELETE' })
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
            const recreated = normalizeField(await api(props.apiBase, { method: 'POST', body: buildFieldPayload(snapshot) }));
            cell.id = recreated.id;
            insertFieldLocal(recreated, idx);
        },
        redo: async () => {
            if (!committed) {
                removeFieldLocal(cell.id);
                schedule();
                return;
            }
            await api(props.apiBase + '/' + cell.id, { method: 'DELETE' });
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
    api(props.apiBase + '/reorder', {
        method: 'PUT',
        body: { ids: afterIds },
    }).then(() => {
        emit('updated');
        pushHistory({
            label: 'Reorder fields',
            undo: async () => {
                await api(props.apiBase + '/reorder', { method: 'PUT', body: { ids: beforeIds } });
                orderFieldsByIds(beforeIds);
            },
            redo: async () => {
                await api(props.apiBase + '/reorder', { method: 'PUT', body: { ids: afterIds } });
                orderFieldsByIds(afterIds);
            },
        });
        toast.success('Order saved');
    }).catch((e) => toast.error(e.message || 'Failed to reorder'));
}
</script>
