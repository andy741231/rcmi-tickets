<template>
    <div class="mx-auto max-w-4xl">
        <!-- Header -->
        <div class="mb-6">
            <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">Tag Rules</span>
            </nav>
            <h2 class="text-xl font-bold text-gray-900">Auto-Tag Rules</h2>
            <p class="mt-1 text-sm text-gray-600">Automatically tag tickets based on form field values. When a ticket is created or updated, matching rules add the specified tag.</p>
        </div>

        <!-- Add rule form -->
        <div class="rcmi-card mb-6 p-5">
            <h3 class="rcmi-section-label mb-4">Add Rule</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <label class="rcmi-field-label">Field</label>
                    <select v-model="newRule.field_key" class="rcmi-input">
                        <option value="">Select field…</option>
                        <option v-for="f in formFields" :key="f.field_key" :value="f.field_key">{{ f.label }}</option>
                    </select>
                </div>
                <div>
                    <label class="rcmi-field-label">Operator</label>
                    <select v-model="newRule.operator" class="rcmi-input">
                        <option value="equals">Equals</option>
                        <option value="not_equals">Not equals</option>
                        <option value="contains">Contains</option>
                        <option value="not_contains">Does not contain</option>
                    </select>
                </div>
                <div>
                    <label class="rcmi-field-label">Value</label>
                    <input v-model.trim="newRule.value" class="rcmi-input" placeholder="Value to match" />
                </div>
                <div>
                    <label class="rcmi-field-label">Tag name</label>
                    <input v-model.trim="newRule.tag_name" class="rcmi-input" placeholder="e.g. IT, Urgent" />
                </div>
            </div>
            <div class="mt-4 flex justify-end">
                <button @click="createRule" :disabled="!canCreate || creating"
                    class="rcmi-button-primary inline-flex items-center gap-1.5 px-3 py-2 text-sm disabled:opacity-50">
                    <Icon name="plus" /> {{ creating ? 'Adding…' : 'Add Rule' }}
                </button>
            </div>
        </div>

        <!-- Rules list -->
        <div v-if="loading" class="rcmi-card p-8 text-center text-sm text-gray-600">Loading tag rules…</div>
        <div v-else-if="rules.length === 0" class="rcmi-card p-8 text-center text-sm text-gray-500">
            No tag rules yet. Add one above to automatically tag tickets based on form field values.
        </div>
        <ul v-else class="space-y-3">
            <li v-for="rule in rules" :key="rule.id" class="rcmi-card p-4">
                <div v-if="editingId !== rule.id" class="flex items-center gap-3">
                    <span class="flex h-8 w-8 items-center justify-center rounded bg-gray-100 text-gray-500">
                        <Icon name="tag" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-gray-800">
                            {{ fieldLabel(rule.field_key) }}
                            <span class="font-normal text-gray-500">{{ operatorLabel(rule.operator) }}</span>
                            <span class="font-semibold text-gray-700">"{{ rule.value }}"</span>
                            <span class="font-normal text-gray-500">→ tag</span>
                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">{{ rule.tag_name }}</span>
                        </p>
                    </div>
                    <label class="inline-flex items-center gap-1.5 text-xs text-gray-600">
                        <input type="checkbox" :checked="rule.is_active" @change="toggleActive(rule)" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                        Active
                    </label>
                    <button @click="startEdit(rule)" class="rcmi-button-ghost px-2 py-1 text-xs">Edit</button>
                    <button @click="deleteRule(rule)" class="rcmi-button-ghost px-2 py-1 text-xs text-red-700 hover:text-red-800"><Icon name="trash" /></button>
                </div>
                <!-- Inline edit -->
                <div v-else class="space-y-3">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div>
                            <label class="rcmi-field-label">Field</label>
                            <select v-model="editRule.field_key" class="rcmi-input">
                                <option value="">Select field…</option>
                                <option v-for="f in formFields" :key="f.field_key" :value="f.field_key">{{ f.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="rcmi-field-label">Operator</label>
                            <select v-model="editRule.operator" class="rcmi-input">
                                <option value="equals">Equals</option>
                                <option value="not_equals">Not equals</option>
                                <option value="contains">Contains</option>
                                <option value="not_contains">Does not contain</option>
                            </select>
                        </div>
                        <div>
                            <label class="rcmi-field-label">Value</label>
                            <input v-model.trim="editRule.value" class="rcmi-input" />
                        </div>
                        <div>
                            <label class="rcmi-field-label">Tag name</label>
                            <input v-model.trim="editRule.tag_name" class="rcmi-input" />
                        </div>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button @click="cancelEdit" class="rcmi-button-secondary px-3 py-1.5 text-xs">Cancel</button>
                        <button @click="saveEdit" :disabled="saving"
                            class="rcmi-button-primary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs disabled:opacity-50">
                            <Icon name="save" /> {{ saving ? 'Saving…' : 'Save' }}
                        </button>
                    </div>
                </div>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';
import { useToast } from '../composables/useToast.js';

const toast = useToast();
const loading = ref(true);
const creating = ref(false);
const saving = ref(false);
const rules = ref([]);
const formFields = ref([]);
const editingId = ref(null);
const editRule = reactive({});

const newRule = reactive({
    field_key: '',
    operator: 'equals',
    value: '',
    tag_name: '',
});

const canCreate = computed(() => newRule.field_key && newRule.value && newRule.tag_name);

function fieldLabel(key) {
    const f = formFields.value.find(x => x.field_key === key);
    return f?.label || key;
}

function operatorLabel(op) {
    return { equals: 'equals', not_equals: 'does not equal', contains: 'contains', not_contains: 'does not contain' }[op] || op;
}

async function loadRules() {
    loading.value = true;
    try {
        const data = await api('/meta');
        rules.value = data.tag_rules || [];
        formFields.value = (data.form_fields || []).filter(f => f.type !== 'section');
    } catch (e) {
        toast.error(e.message || 'Failed to load tag rules');
    } finally {
        loading.value = false;
    }
}

async function createRule() {
    if (!canCreate.value) return;
    creating.value = true;
    try {
        const created = await api('/tag-rules', { method: 'POST', body: { ...newRule, is_active: true } });
        rules.value.unshift(created);
        newRule.field_key = '';
        newRule.operator = 'equals';
        newRule.value = '';
        newRule.tag_name = '';
        toast.success('Rule added');
    } catch (e) {
        toast.error(e.message || 'Failed to create rule');
    } finally {
        creating.value = false;
    }
}

function startEdit(rule) {
    editingId.value = rule.id;
    Object.assign(editRule, { ...rule });
}

function cancelEdit() {
    editingId.value = null;
}

async function saveEdit() {
    saving.value = true;
    try {
        const updated = await api(`/tag-rules/${editingId.value}`, {
            method: 'PUT',
            body: {
                field_key: editRule.field_key,
                operator: editRule.operator,
                value: editRule.value,
                tag_name: editRule.tag_name,
                is_active: editRule.is_active,
            },
        });
        const idx = rules.value.findIndex(r => r.id === updated.id);
        if (idx >= 0) rules.value[idx] = updated;
        editingId.value = null;
        toast.success('Rule saved');
    } catch (e) {
        toast.error(e.message || 'Failed to save rule');
    } finally {
        saving.value = false;
    }
}

async function toggleActive(rule) {
    try {
        const updated = await api(`/tag-rules/${rule.id}`, {
            method: 'PUT',
            body: { is_active: !rule.is_active },
        });
        const idx = rules.value.findIndex(r => r.id === updated.id);
        if (idx >= 0) rules.value[idx] = updated;
        toast.success(updated.is_active ? 'Rule activated' : 'Rule deactivated');
    } catch (e) {
        toast.error(e.message || 'Failed to toggle rule');
    }
}

async function deleteRule(rule) {
    if (!confirm(`Delete this rule? Tickets that already have the "${rule.tag_name}" tag will keep it.`)) return;
    try {
        await api(`/tag-rules/${rule.id}`, { method: 'DELETE' });
        rules.value = rules.value.filter(r => r.id !== rule.id);
        toast.success('Rule deleted');
    } catch (e) {
        toast.error(e.message || 'Failed to delete rule');
    }
}

onMounted(loadRules);
</script>
