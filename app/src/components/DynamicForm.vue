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
                        <template v-else-if="f.type === 'dropdown' && f.config?.cascades_from">
                            {{ parentLabel(f, answers[f.config.cascades_from]) }} › {{ answers[f.field_key] }}
                        </template>
                        <template v-else-if="f.type === 'longtext'">
                            <span class="whitespace-pre-wrap">{{ answers[f.field_key] }}</span>
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
                    :min="f.config?.min" :max="f.config?.max" :step="f.config?.step"
                    :required="f.required" class="rcmi-input" />

                <!-- date -->
                <input v-else-if="f.type === 'date'" :id="'field-' + f.field_key"
                    v-model="answers[f.field_key]" type="date"
                    :required="f.required" class="rcmi-input" />

                <!-- dropdown (with optional cascading) -->
                <select v-else-if="f.type === 'dropdown'" :id="'field-' + f.field_key"
                    v-model="answers[f.field_key]" :required="f.required" class="rcmi-input"
                    :disabled="f.config?.cascades_from && !answers[f.config.cascades_from]">
                    <option value="">{{ f.config?.cascades_from ? 'Select ' + f.label.toLowerCase() + '…' : 'Select…' }}</option>
                    <option v-for="opt in dropdownOptions(f)" :key="opt" :value="opt">{{ opt }}</option>
                </select>
                <p v-if="f.config?.cascades_from && !answers[f.config.cascades_from]"
                    class="rcmi-field-help">Select "{{ parentFieldLabel(f.config.cascades_from) }}" first.</p>

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
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, watch } from 'vue';

const props = defineProps({
    fields: { type: Array, default: () => [] },
    modelValue: { type: Object, default: () => ({}) }, // answers map: field_key => value
    readonly: { type: Boolean, default: false },
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

// Ensure every field has an entry in answers
watch(normalizedFields, (fields) => {
    if (!fields) return;
    const a = { ...props.modelValue };
    let changed = false;
    for (const f of fields) {
        if (f.type === 'section') continue;
        if (!(f.field_key in a)) {
            a[f.field_key] = f.type === 'checkbox' ? [] : '';
            changed = true;
        }
    }
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

// Logic evaluation: should this field be shown?
function isFieldVisible(field) {
    const config = field.config || {};
    const logic = config.logic;
    if (!logic || !logic.field_key) return true;

    const depVal = answers.value[logic.field_key];
    const target = logic.value;
    let conditionMet = false;

    switch (logic.op) {
        case 'equals':       conditionMet = String(depVal) === String(target); break;
        case 'not_equals':   conditionMet = String(depVal) !== String(target); break;
        case 'contains':     conditionMet = Array.isArray(depVal) ? depVal.includes(target) : String(depVal).includes(target); break;
        case 'not_contains': conditionMet = Array.isArray(depVal) ? !depVal.includes(target) : !String(depVal).includes(target); break;
    }

    return logic.action === 'show' ? conditionMet : !conditionMet;
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

// Watch cascading dropdowns: when parent changes, reset child
watch(answers, (a) => {
    if (!normalizedFields.value) return;
    for (const f of normalizedFields.value) {
        if (f.type === 'dropdown' && f.config?.cascades_from) {
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
    if (f.type === 'checkbox') return Array.isArray(v) && v.length > 0;
    return v !== undefined && v !== null && v !== '';
}
</script>
