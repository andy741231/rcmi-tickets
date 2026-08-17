<template>
    <div class="mx-auto max-w-5xl">
        <!-- Header -->
        <div class="mb-6">
            <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">Approval Chains</span>
            </nav>
            <h2 class="text-xl font-bold text-gray-900">Approval Chain Editor</h2>
            <p class="mt-1 text-sm text-gray-600">Define multi-step approval workflows. Each chain routes a ticket through ordered approvers.</p>
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Chain list -->
            <div class="rcmi-card p-4 lg:col-span-1">
                <div class="mb-3 flex items-center justify-between">
                    <h3 class="rcmi-section-label">Chains</h3>
                    <button @click="newChain" class="rcmi-button-primary inline-flex items-center gap-1 px-2.5 py-1.5 text-xs">
                        <Icon name="plus" /> New
                    </button>
                </div>
                <ul class="space-y-2">
                    <li v-for="c in chains" :key="c.id"
                        @click="selectChain(c.id)"
                        :class="['cursor-pointer rounded-md border px-3 py-2.5 transition',
                            selectedChainId === c.id ? 'border-red-300 bg-red-50' : 'border-gray-200 hover:border-gray-300']">
                        <p class="text-sm font-semibold text-gray-800">{{ c.name }}</p>
                        <p class="text-xs text-gray-500">
                            {{ c.steps.length }} step(s)
                            <span v-if="c.trigger_field_key"> · trigger: {{ c.trigger_field_key }}={{ c.trigger_value }}</span>
                            <span v-else> · default</span>
                        </p>
                        <p class="text-xs text-gray-400">on reject: {{ c.on_reject }}</p>
                    </li>
                    <li v-if="chains.length === 0" class="text-center text-sm text-gray-500 py-6">
                        No chains yet. Click "New" to create one.
                    </li>
                </ul>
            </div>

            <!-- Chain editor -->
            <div class="lg:col-span-2">
                <div v-if="!selectedChain" class="rcmi-card p-8 text-center text-sm text-gray-500">
                    Select a chain from the left, or create a new one.
                </div>
                <div v-else class="rcmi-card space-y-5 p-6">
                    <!-- Chain metadata -->
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="rcmi-field-label">Name</label>
                            <input v-model="selectedChain.name" class="rcmi-input" placeholder="e.g. Marketing requests" />
                        </div>
                        <div>
                            <label class="rcmi-field-label">On reject</label>
                            <select v-model="selectedChain.on_reject" class="rcmi-input">
                                <option value="restart">Restart from step 1 (back to requestor)</option>
                                <option value="back_one">Go back one step</option>
                                <option value="terminal">Terminal reject (close ticket)</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="rcmi-field-label">Description</label>
                        <textarea v-model="selectedChain.description" rows="2" class="rcmi-input resize-y" placeholder="Optional description"></textarea>
                    </div>

                    <!-- Trigger -->
                    <div class="rounded-md border border-gray-200 p-4">
                        <h4 class="rcmi-section-label mb-3">Trigger (when does this chain apply?)</h4>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label class="rcmi-field-label">Field</label>
                                <select v-model="selectedChain.trigger_field_key" class="rcmi-input">
                                    <option value="">No trigger (default chain)</option>
                                    <option v-for="f in dropdownFields" :key="f.field_key" :value="f.field_key">{{ f.label }}</option>
                                </select>
                            </div>
                            <div v-if="selectedChain.trigger_field_key">
                                <label class="rcmi-field-label">Value</label>
                                <select v-model="selectedChain.trigger_value" class="rcmi-input">
                                    <option value="">Select value…</option>
                                    <option v-for="opt in triggerOptions" :key="opt" :value="opt">{{ opt }}</option>
                                </select>
                            </div>
                        </div>
                        <p class="rcmi-field-help">If a trigger is set, this chain applies when the ticket's "{{ triggerFieldLabel }}" field equals the selected value. Otherwise it's the default chain (used when no other chain matches).</p>
                    </div>

                    <!-- Steps -->
                    <div>
                        <div class="mb-3 flex items-center justify-between">
                            <h4 class="rcmi-section-label">Steps</h4>
                            <button @click="addStep" class="rcmi-button-secondary inline-flex items-center gap-1 px-2.5 py-1.5 text-xs">
                                <Icon name="plus" /> Add step
                            </button>
                        </div>
                        <ol class="space-y-3">
                            <li v-for="(step, idx) in selectedChain.steps" :key="idx"
                                class="rounded-md border border-gray-200 p-3">
                                <div class="flex items-center gap-2">
                                    <span class="flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-700">{{ idx + 1 }}</span>
                                    <input v-model="step.name" class="rcmi-input flex-1" :placeholder="'Step ' + (idx + 1) + ' name'" />
                                    <button @click="removeStep(idx)" class="rcmi-button-ghost px-2 py-1 text-xs text-red-700"><Icon name="trash" /></button>
                                </div>
                                <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="rcmi-field-label">Approver type</label>
                                        <select v-model="step.approver_type" class="rcmi-input">
                                            <option value="user">Specific user</option>
                                            <option value="role">Anyone with a role</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="rcmi-field-label">Approver</label>
                                        <select v-if="step.approver_type === 'user'" v-model="step.approver_user_id" class="rcmi-input">
                                            <option :value="null">Select user…</option>
                                            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.display_name }} ({{ u.user_login }})</option>
                                        </select>
                                        <select v-else v-model="step.approver_role" class="rcmi-input">
                                            <option value="">Select role…</option>
                                            <option value="rcmi_ticket_manager">Ticket Manager</option>
                                            <option value="administrator">Administrator</option>
                                        </select>
                                    </div>
                                </div>
                            </li>
                        </ol>
                        <p v-if="selectedChain.steps.length === 0" class="text-center text-sm text-gray-500 py-4">No steps yet. Add at least one.</p>
                    </div>

                    <!-- Active toggle -->
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" v-model="selectedChain.is_active" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                        Active
                    </label>

                    <!-- Default assignee -->
                    <div class="rounded-md border border-gray-200 p-4">
                        <h4 class="rcmi-section-label mb-3">Default Assignee</h4>
                        <label class="rcmi-field-label">Assign ticket to</label>
                        <select v-model="selectedChain.completion_assignee_id" class="rcmi-input">
                            <option :value="null">No default assignee</option>
                            <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.display_name }} ({{ u.user_login }})</option>
                        </select>
                        <p class="rcmi-field-help">The selected person is assigned when a ticket enters this approval chain (at creation). They receive the Approved notification once all steps clear, and can then move the ticket to In Progress and Complete.</p>
                    </div>

                    <!-- Completion message -->
                    <div class="rounded-md border border-gray-200 p-4">
                        <h4 class="rcmi-section-label mb-3">Completion Message</h4>
                        <RichTextEditor v-model="selectedChain.completion_message" />
                        <p class="rcmi-field-help mt-2">This message is included in the completion email sent to the ticket requestor. Use it to provide next steps, contact info, links, or a thank-you note specific to this approval chain. Leave blank to send a default notification.</p>
                    </div>

                    <!-- Save / Delete -->
                    <div class="flex items-center gap-3 border-t border-gray-100 pt-4">
                        <button @click="saveChain" :disabled="saving"
                            class="rcmi-button-primary inline-flex items-center gap-1.5 px-4 py-2 text-sm disabled:opacity-50">
                            <Icon name="save" /> {{ saving ? 'Saving…' : 'Save Chain' }}
                        </button>
                        <button v-if="selectedChain.id" @click="deleteChain"
                            class="rcmi-button-danger inline-flex items-center gap-1.5 px-3.5 py-2 text-sm">
                            <Icon name="trash" /> Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';
import RichTextEditor from '../components/RichTextEditor.vue';
import { useToast } from '../composables/useToast.js';

const toast = useToast();
const chains = ref([]);
const selectedChainId = ref(null);
const selectedChain = ref(null);
const formFields = ref([]);
const assignableUsers = ref([]);
const saving = ref(false);

const dropdownFields = computed(() => formFields.value.filter(f => f.type === 'dropdown'));
const triggerFieldLabel = computed(() => {
    const f = formFields.value.find(f => f.field_key === selectedChain.value?.trigger_field_key);
    return f ? f.label : '';
});
const triggerOptions = computed(() => {
    const f = formFields.value.find(f => f.field_key === selectedChain.value?.trigger_field_key);
    return f?.config?.options || [];
});

async function loadMeta() {
    try {
        const data = await api('/meta');
        formFields.value = data.form_fields || [];
        assignableUsers.value = data.assignable_users || [];
        chains.value = data.approval_chains || [];
        if (chains.value.length > 0 && !selectedChainId.value) {
            selectChain(chains.value[0].id);
        }
    } catch (e) {
        toast.error('Failed to load data');
    }
}

function selectChain(id) {
    selectedChainId.value = id;
    // Deep clone so editing doesn't mutate the list until saved
    const c = chains.value.find(x => x.id === id);
    selectedChain.value = c ? JSON.parse(JSON.stringify(c)) : null;
}

function newChain() {
    selectedChain.value = {
        id: null,
        name: 'New Chain',
        description: '',
        trigger_field_key: '',
        trigger_value: '',
        on_reject: 'restart',
        completion_assignee_id: null,
        completion_message: '',
        is_active: true,
        steps: [
            { name: 'Step 1', approver_type: 'user', approver_user_id: null, approver_role: '' },
        ],
    };
    selectedChainId.value = null;
}

function addStep() {
    selectedChain.value.steps.push({
        name: 'Step ' + (selectedChain.value.steps.length + 1),
        approver_type: 'user',
        approver_user_id: null,
        approver_role: '',
    });
}

function removeStep(idx) {
    selectedChain.value.steps.splice(idx, 1);
}

async function saveChain() {
    saving.value = true;
    try {
        const body = {
            name: selectedChain.value.name,
            description: selectedChain.value.description || '',
            trigger_field_key: selectedChain.value.trigger_field_key || '',
            trigger_value: selectedChain.value.trigger_value || '',
            on_reject: selectedChain.value.on_reject,
            completion_assignee_id: selectedChain.value.completion_assignee_id || 0,
            completion_message: selectedChain.value.completion_message || '',
            is_active: selectedChain.value.is_active,
            steps: selectedChain.value.steps.map((s, i) => ({
                name: s.name || ('Step ' + (i + 1)),
                approver_type: s.approver_type,
                approver_user_id: s.approver_type === 'user' ? parseInt(s.approver_user_id) : null,
                approver_role: s.approver_type === 'role' ? s.approver_role : null,
                sort_order: i + 1,
            })),
        };
        if (selectedChain.value.id) {
            const updated = await api('/approval-chains/' + selectedChain.value.id, { method: 'PUT', body });
            const idx = chains.value.findIndex(c => c.id === updated.id);
            if (idx >= 0) chains.value[idx] = updated;
            selectChain(updated.id);
        } else {
            const created = await api('/approval-chains', { method: 'POST', body });
            chains.value.push(created);
            selectChain(created.id);
        }
        toast.success('Chain saved');
    } catch (e) {
        toast.error(e.message || 'Failed to save chain');
    } finally {
        saving.value = false;
    }
}

async function deleteChain() {
    if (!selectedChain.value.id) return;
    if (!confirm('Delete this chain? Existing approval history is kept.')) return;
    try {
        await api('/approval-chains/' + selectedChain.value.id, { method: 'DELETE' });
        chains.value = chains.value.filter(c => c.id !== selectedChain.value.id);
        selectedChain.value = null;
        selectedChainId.value = null;
        toast.success('Chain deleted');
    } catch (e) {
        toast.error(e.message || 'Failed to delete chain');
    }
}

onMounted(loadMeta);
</script>
