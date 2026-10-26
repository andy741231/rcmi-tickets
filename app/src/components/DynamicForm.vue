<template>
    <!-- Read-only mode: render submitted answers -->
    <div v-if="readonly" class="space-y-4 rcmi-readonly-fields">
        <div v-for="f in normalizedFields" :key="f.id">
            <template v-if="f.type === 'section'">
                <h4 class="rcmi-section-label mt-6 mb-2">{{ f.label }}</h4>
            </template>
            <template v-else>
                <div class="flex flex-col gap-1 border-b border-gray-100 pb-3">
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ f.label }}</span>
                    <span class="text-sm text-gray-800">
                        <template v-if="!hasAnswer(f)"><span class="text-gray-400">—</span></template>
                        <template v-else-if="f.type === 'checkbox'">
                            {{ Array.isArray(answers[f.field_key]) ? answers[f.field_key].join(', ') : answers[f.field_key] }}
                        </template>
                        <template v-else-if="f.type === 'cascade'">
                            {{ Array.isArray(answers[f.field_key]) ? answers[f.field_key].join(' › ') : answers[f.field_key] }}
                        </template>
                        <template v-else-if="f.type === 'dropdown' && f.config?.cascades_from">
                            {{ parentLabel(f, answers[f.config.cascades_from]) }} › {{ answers[f.field_key] }}
                        </template>
                        <template v-else-if="f.type === 'longtext'">
                            <span class="whitespace-pre-wrap">{{ answers[f.field_key] }}</span>
                        </template>
                        <template v-else-if="f.type === 'rating'">
                            <span class="inline-flex items-center gap-0.5" :aria-label="`${answers[f.field_key]} of ${ratingMax(f)} stars`">
                                <Icon v-for="n in ratingMax(f)" :key="n" name="star"
                                    class="h-4 w-4" :class="n <= Number(answers[f.field_key]) ? 'text-amber-400' : 'text-gray-200'" />
                            </span>
                        </template>
                        <template v-else>{{ answers[f.field_key] }}</template>
                    </span>
                </div>
            </template>
        </div>
    </div>

    <!-- Editable mode: render inputs -->
    <div v-else class="space-y-5">
        <template v-for="f in normalizedFields" :key="f.id">
            <!-- Section divider -->
            <template v-if="f.type === 'section'">
                <div v-if="isFieldVisible(f)" class="border-t border-gray-200 pt-4">
                    <h4 class="text-sm font-bold text-gray-900">{{ f.label }}</h4>
                </div>
            </template>

            <!-- Regular field -->
            <div v-else-if="isFieldVisible(f)">
                <label :for="'field-' + f.field_key" class="rcmi-field-label">
                    {{ f.label }}
                    <span v-if="f.required" class="rcmi-field-required">*</span>
                </label>

                <!-- text -->
                <input v-if="f.type === 'text'" :id="'field-' + f.field_key"
                    v-model="answers[f.field_key]" type="text"
                    :placeholder="f.config?.placeholder || ''"
                    :required="f.required" class="rcmi-input" />

                <!-- longtext -->
                <textarea v-else-if="f.type === 'longtext'" :id="'field-' + f.field_key"
                    v-model="answers[f.field_key]" rows="4"
                    :placeholder="f.config?.placeholder || ''"
                    :required="f.required" class="rcmi-input resize-y"></textarea>

                <!-- number -->
                <input v-else-if="f.type === 'number'" :id="'field-' + f.field_key"
                    v-model.number="answers[f.field_key]" type="number"
                    :placeholder="f.config?.placeholder || ''"
                    :min="f.config?.min" :max="f.config?.max" :step="f.config?.integer_only ? 1 : f.config?.step"
                    :required="f.required" class="rcmi-input" />

                <!-- date -->
                <template v-else-if="f.type === 'date'">
                    <input :id="'field-' + f.field_key"
                        v-model="answers[f.field_key]" type="date"
                        :min="dateMin(f)"
                        :required="f.required" class="rcmi-input"
                        @click="openDatePicker"
                        @change="validateDate(f)" />
                    <p v-if="dateErrors[f.field_key]" class="rcmi-field-help text-red-700">{{ dateErrors[f.field_key] }}</p>
                    <p v-else-if="dateHint(f)" class="rcmi-field-help">{{ dateHint(f) }}</p>
                </template>

                <!-- dropdown (with optional cascading + "Other" free-text) -->
                <template v-else-if="f.type === 'dropdown'">
                    <select :id="'field-' + f.field_key"
                        :value="dropdownSelectValue(f)" :required="f.required" class="rcmi-input"
                        :disabled="f.config?.cascades_from && !answers[f.config.cascades_from]"
                        @change="onDropdownChange(f, $event.target.value)">
                        <option value="">{{ f.config?.cascades_from ? 'Select ' + f.label.toLowerCase() + '…' : 'Select…' }}</option>
                        <option v-for="opt in dropdownOptions(f)" :key="opt" :value="opt">{{ opt }}</option>
                        <option v-if="f.config?.allow_other" :value="DROPDOWN_OTHER">Other (specify…)</option>
                    </select>
                    <input v-if="dropdownIsOther(f)" type="text"
                        v-model="answers[f.field_key]" class="rcmi-input mt-2"
                        placeholder="Type your answer…" :required="f.required" />
                </template>
                <p v-if="f.config?.cascades_from && !answers[f.config.cascades_from]"
                    class="rcmi-field-help">Select "{{ parentFieldLabel(f.config.cascades_from) }}" first.</p>

                <!-- cascade (multi-level tree) -->
                <CascadeField v-else-if="f.type === 'cascade'" :field="f"
                    :model-value="Array.isArray(answers[f.field_key]) ? answers[f.field_key] : []"
                    :required="f.required"
                    @update:model-value="answers[f.field_key] = $event" />

                <!-- radio -->
                <div v-else-if="f.type === 'radio'" class="mt-1 flex flex-wrap gap-3">
                    <label v-for="opt in (f.config?.options || [])" :key="opt"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm transition hover:border-gray-300 has-[:checked]:border-red-300 has-[:checked]:bg-red-50">
                        <input type="radio" :value="opt" v-model="answers[f.field_key]"
                            :name="'field-' + f.field_key" :required="f.required"
                            class="h-4 w-4 border-gray-400 text-red-700 focus:ring-red-700" />
                        <span class="font-medium text-gray-700">{{ opt }}</span>
                    </label>
                </div>

                <!-- checkbox -->
                <div v-else-if="f.type === 'checkbox'" class="mt-1 flex flex-wrap gap-3">
                    <label v-for="opt in (f.config?.options || [])" :key="opt"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm transition hover:border-gray-300 has-[:checked]:border-red-300 has-[:checked]:bg-red-50">
                        <input type="checkbox" :value="opt"
                            :checked="(answers[f.field_key] || []).includes(opt)"
                            @change="toggleCheckbox(f.field_key, opt)"
                            :required="f.required && (answers[f.field_key] || []).length === 0"
                            class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                        <span class="font-medium text-gray-700">{{ opt }}</span>
                    </label>
                </div>

                <!-- rating (stars) -->
                <div v-else-if="f.type === 'rating'" class="mt-1 flex items-center gap-3">
                    <div class="inline-flex items-center gap-0.5" role="radiogroup" :aria-label="f.label">
                        <button v-for="n in ratingMax(f)" :key="n" type="button" role="radio"
                            :aria-checked="Number(answers[f.field_key]) === n"
                            :aria-label="`${n} of ${ratingMax(f)} stars`"
                            @click="setRating(f, n)"
                            @keydown="onRatingKey(f, n, $event)"
                            class="rounded p-0.5 transition focus:outline-none focus:ring-2 focus:ring-red-400">
                            <Icon name="star" class="h-7 w-7"
                                :class="n <= Number(answers[f.field_key] || 0) ? 'text-amber-400' : 'text-gray-300 hover:text-amber-300'" />
                        </button>
                    </div>
                    <span v-if="ratingEndLabels(f)[0] || ratingEndLabels(f)[1]" class="text-xs text-gray-500">
                        {{ ratingEndLabels(f)[0] }}{{ ratingEndLabels(f)[0] && ratingEndLabels(f)[1] ? ' · ' : '' }}{{ ratingEndLabels(f)[1] }}
                    </span>
                </div>

                <!-- scale (min–max button row) -->
                <div v-else-if="f.type === 'scale'" class="mt-1">
                    <div class="flex items-center gap-2">
                        <span v-if="scaleLabels(f).low" class="w-16 shrink-0 text-right text-xs text-gray-500">{{ scaleLabels(f).low }}</span>
                        <div class="flex flex-wrap gap-1" role="radiogroup" :aria-label="f.label">
                            <button v-for="n in scaleRange(f)" :key="n" type="button" role="radio"
                                :aria-checked="answers[f.field_key] !== '' && answers[f.field_key] != null && Number(answers[f.field_key]) === n"
                                :aria-label="`${n} of ${scaleMax(f)}`"
                                @click="answers[f.field_key] = n"
                                class="flex h-9 w-9 items-center justify-center rounded-md border text-sm font-semibold transition focus:outline-none focus:ring-2 focus:ring-red-400"
                                :class="answers[f.field_key] !== '' && answers[f.field_key] != null && Number(answers[f.field_key]) === n
                                    ? 'border-red-700 bg-red-700 text-white'
                                    : 'border-gray-300 bg-white text-gray-700 hover:border-red-300 hover:bg-red-50'">
                                {{ n }}
                            </button>
                        </div>
                        <span v-if="scaleLabels(f).high" class="w-16 shrink-0 text-xs text-gray-500">{{ scaleLabels(f).high }}</span>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import CascadeField from './CascadeField.vue';
import Icon from './Icon.vue';
import { isFieldVisible as checkFieldVisible } from '../lib/fieldLogic.js';

const props = defineProps({
    fields: { type: Array, default: () => [] },
    modelValue: { type: Object, default: () => ({}) }, // answers map: field_key => value
    readonly: { type: Boolean, default: false },
    // External answers merged in for condition evaluation (e.g. the
    // ticket's own answers keyed "ticket:<field_key>" on the survey form).
    context: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:modelValue']);

// Normalize fields: ensure config is never null (API can return null)
const normalizedFields = computed(() =>
    (props.fields || []).map(f => ({
        ...f,
        config: f.config || {},
    }))
);

// Local reactive answers object — we mutate props.modelValue directly via v-model
// since Vue 3 reactive props pass by reference for objects.
const answers = computed({
    get: () => props.modelValue || {},
    set: (val) => emit('update:modelValue', val),
});

// "Other" free-text support for dropdowns. OTHER is a UI-only sentinel —
// the typed text itself is stored as the answer (same as CascadeField).
const DROPDOWN_OTHER = '__other__';
// field_key => true while the user has Other selected but hasn't typed yet
const dropdownOther = ref({});

// Ensure every field has an entry in answers
watch(normalizedFields, (fields) => {
    if (!fields) return;
    const a = { ...props.modelValue };
    let changed = false;
    for (const f of fields) {
        if (f.type === 'section') continue;
        if (!(f.field_key in a)) {
            a[f.field_key] = (f.type === 'checkbox' || f.type === 'cascade') ? [] : '';
            changed = true;
        }
    }
    dropdownOther.value = {};
    if (changed) emit('update:modelValue', a);
}, { immediate: true });

// Checkbox helper: toggle a value in the array for a field
function toggleCheckbox(key, opt) {
    if (!Array.isArray(answers.value[key])) {
        answers.value[key] = [];
    }
    const idx = answers.value[key].indexOf(opt);
    if (idx >= 0) {
        answers.value[key].splice(idx, 1);
    } else {
        answers.value[key].push(opt);
    }
}

// Logic evaluation: should this field be shown? Context answers (e.g.
// "ticket:<key>" values supplied by the parent) merge under the form's
// own answers so survey rules can read the ticket's fields.
const evalAnswers = computed(() => ({ ...(props.context || {}), ...(answers.value || {}) }));

function isFieldVisible(field) {
    return checkFieldVisible(field.config || {}, evalAnswers.value);
}

// ── rating / scale helpers (survey fields) ──────────────────────────
function ratingMax(f) {
    const m = parseInt(f.config?.max, 10);
    return Number.isFinite(m) ? Math.min(10, Math.max(3, m)) : 5;
}

function ratingEndLabels(f) {
    return [f.config?.low_label || '', f.config?.high_label || ''];
}

function setRating(f, n) {
    // Clicking the current rating again clears it.
    answers.value[f.field_key] = Number(answers.value[f.field_key]) === n ? '' : n;
}

function onRatingKey(f, n, e) {
    const max = ratingMax(f);
    let next = null;
    if (e.key === 'ArrowRight' || e.key === 'ArrowUp') next = Math.min(max, n + 1);
    else if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') next = Math.max(1, n - 1);
    else if (e.key === 'Home') next = 1;
    else if (e.key === 'End') next = max;
    if (next === null) return;
    e.preventDefault();
    answers.value[f.field_key] = next;
    const group = e.target.closest('[role="radiogroup"]');
    group?.querySelectorAll('[role="radio"]')?.[next - 1]?.focus();
}

function scaleBounds(f) {
    const min = Number.isFinite(parseInt(f.config?.min, 10)) ? parseInt(f.config?.min, 10) : 0;
    const max = Number.isFinite(parseInt(f.config?.max, 10)) ? parseInt(f.config?.max, 10) : 10;
    return { min: Math.min(min, max), max: Math.max(min, max) };
}

function scaleMax(f) {
    return scaleBounds(f).max;
}

function scaleRange(f) {
    const { min, max } = scaleBounds(f);
    const out = [];
    for (let i = min; i <= max; i++) out.push(i);
    return out;
}

function scaleLabels(f) {
    return { low: f.config?.low_label || '', high: f.config?.high_label || '' };
}

// Dropdown options (handles cascading)
function dropdownOptions(field) {
    const config = field.config || {};
    if (config.cascades_from && config.cascade_options) {
        const parentVal = answers.value[config.cascades_from];
        return config.cascade_options[parentVal] || [];
    }
    return config.options || [];
}

function dropdownIsOther(f) {
    if (!f.config?.allow_other) return false;
    if (dropdownOther.value[f.field_key]) return true;
    const v = answers.value[f.field_key];
    return Boolean(v) && !dropdownOptions(f).includes(v);
}

function dropdownSelectValue(f) {
    return dropdownIsOther(f) ? DROPDOWN_OTHER : (answers.value[f.field_key] || '');
}

function onDropdownChange(f, val) {
    if (val === DROPDOWN_OTHER) {
        dropdownOther.value = { ...dropdownOther.value, [f.field_key]: true };
        answers.value[f.field_key] = '';
        return;
    }
    dropdownOther.value = { ...dropdownOther.value, [f.field_key]: false };
    answers.value[f.field_key] = val;
}

// Compute min date for date fields with min_days config
function dateMin(field) {
    const minDays = field.config?.min_days;
    if (minDays == null || minDays === '' || isNaN(minDays)) return undefined;
    const days = parseInt(minDays, 10);
    const includeWeekend = field.config?.include_weekend !== false; // default true
    const d = new Date();
    if (includeWeekend) {
        d.setDate(d.getDate() + days);
    } else {
        // Count only business days (Mon–Fri)
        let added = 0;
        while (added < days) {
            d.setDate(d.getDate() + 1);
            const day = d.getDay();
            if (day !== 0 && day !== 6) added++;
        }
    }
    return d.toISOString().split('T')[0];
}

// Open the native date picker when clicking anywhere in the field.
function openDatePicker(e) {
    try { e.target.showPicker?.(); } catch { /* picker needs a user gesture or isn't supported */ }
}

// Human-readable "earliest available" hint so users know what's blocked
// before opening the picker (native pickers render disabled days inconsistently).
const dateErrors = ref({});

function fmtDate(iso) {
    return new Date(iso + 'T00:00:00').toLocaleDateString(undefined, {
        weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
    });
}

function isWeekend(iso) {
    const day = new Date(iso + 'T00:00:00').getDay();
    return day === 0 || day === 6;
}

function dateHint(field) {
    const min = dateMin(field);
    if (!min) return field.config?.include_weekend === false ? 'Weekdays only.' : '';
    let hint = `Earliest available: ${fmtDate(min)}`;
    if (field.config?.include_weekend === false) hint += ' · Weekdays only';
    return hint;
}

function validateDate(field) {
    const v = answers.value[field.field_key];
    const min = dateMin(field);
    let msg = '';
    if (v) {
        if (min && v < min) {
            msg = `Please choose ${fmtDate(min)} or later.`;
        } else if (field.config?.include_weekend === false && isWeekend(v)) {
            msg = 'Weekends aren’t available — please pick a weekday.';
        }
    }
    dateErrors.value = { ...dateErrors.value, [field.field_key]: msg };
}

// Watch cascading dropdowns: when parent changes, reset child
watch(answers, (a) => {
    if (!normalizedFields.value) return;
    for (const f of normalizedFields.value) {
        if (f.type === 'dropdown' && f.config?.cascades_from && !f.config?.allow_other) {
            const parentVal = a[f.config.cascades_from];
            const validChildren = (f.config?.cascade_options || {})[parentVal] || [];
            if (a[f.field_key] && !validChildren.includes(a[f.field_key])) {
                a[f.field_key] = '';
            }
        }
    }
}, { deep: true });

function parentFieldLabel(key) {
    const p = normalizedFields.value.find(f => f.field_key === key);
    return p ? p.label : key;
}

function parentLabel(field, parentVal) {
    return parentVal || '—';
}

function hasAnswer(f) {
    const v = answers.value[f.field_key];
    if (f.type === 'checkbox' || f.type === 'cascade') return Array.isArray(v) && v.length > 0;
    return v !== undefined && v !== null && v !== '';
}
</script>
