/**
 * Dedicated approval chain editor page.
 * Route: /approval-edit/:id  (id = numeric chain id, or "new" for a blank chain)
 * Warns before leaving with unsaved changes.
 */
<template>
    <div class="mx-auto max-w-4xl">
        <!-- Header -->
        <div class="mb-6">
            <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <router-link to="/approval-edit" class="text-gray-500 hover:text-red-700">Approval Chains</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">{{ isNew ? 'New Chain' : (chain?.name || '…') }}</span>
            </nav>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">{{ isNew ? 'New Approval Chain' : 'Edit Approval Chain' }}</h2>
                    <p class="mt-1 text-sm text-gray-600">Define the workflow steps, trigger, and notifications for this chain.</p>
                </div>
                <router-link to="/approval-edit"
                    class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3.5 py-2 text-sm">
                    <Icon name="chevron-left" /> All Chains
                </router-link>
            </div>
        </div>

        <!-- Unsaved changes bar -->
        <div v-if="isDirty" class="mb-4 flex items-center justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 px-4 py-2.5">
            <p class="text-sm text-amber-800"><strong>Unsaved changes.</strong> Save before leaving to keep your edits.</p>
            <div class="flex items-center gap-2">
                <button @click="discardAndBack" class="rcmi-button-ghost px-3 py-1.5 text-xs">Discard</button>
                <button @click="saveChain" :disabled="saving"
                    class="rcmi-button-primary inline-flex items-center gap-1 px-3 py-1.5 text-xs disabled:opacity-50">
                    <Icon name="save" /> {{ saving ? 'Saving…' : 'Save' }}
                </button>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="py-16 text-center text-gray-500">
            <div class="inline-block h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-red-700"></div>
            <p class="mt-2 text-sm">Loading chain…</p>
        </div>

        <!-- Not found -->
        <div v-else-if="loadError" class="rounded-xl border border-gray-200 bg-white py-14 text-center shadow-sm">
            <p class="text-sm font-semibold text-gray-700">{{ loadError }}</p>
            <router-link to="/approval-edit" class="rcmi-button-secondary mt-4 inline-flex px-4 py-2 text-sm">Back to Chains</router-link>
        </div>

        <!-- Editor -->
        <div v-else-if="chain" class="rcmi-card space-y-5 p-6">
            <!-- Chain metadata -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label class="rcmi-field-label">Name</label>
                    <input v-model="chain.name" class="rcmi-input" placeholder="e.g. Marketing requests" />
                </div>
                <div>
                    <label class="rcmi-field-label">On reject</label>
                    <select v-model="chain.on_reject" class="rcmi-input">
                        <option value="restart">Restart from step 1 (back to requestor)</option>
                        <option value="back_one">Go back one step</option>
                        <option value="terminal">Terminal reject (close ticket)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="rcmi-field-label">Description</label>
                <textarea v-model="chain.description" rows="2" class="rcmi-input resize-y" placeholder="Optional description"></textarea>
            </div>

            <!-- Trigger -->
            <div class="rounded-md border border-gray-200 p-4">
                <h4 class="rcmi-section-label mb-3">Trigger (when does this chain apply?)</h4>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="rcmi-field-label">Field</label>
                        <select v-model="chain.trigger_field_key" class="rcmi-input">
                            <option value="">No trigger (default chain)</option>
                            <option v-for="f in dropdownFields" :key="f.field_key" :value="f.field_key">{{ f.label }}</option>
                        </select>
                    </div>
                    <div v-if="chain.trigger_field_key">
                        <label class="rcmi-field-label">Value</label>
                        <select v-model="chain.trigger_value" class="rcmi-input">
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
                    <li v-for="(step, idx) in chain.steps" :key="idx"
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
                <p v-if="chain.steps.length === 0" class="text-center text-sm text-gray-500 py-4">No steps yet. Add at least one.</p>
            </div>

            <!-- Active toggle -->
            <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" v-model="chain.is_active" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                Active
            </label>

            <!-- Default assignee -->
            <div class="rounded-md border border-gray-200 p-4">
                <h4 class="rcmi-section-label mb-3">Default Assignee</h4>
                <label class="rcmi-field-label">Assign ticket to</label>
                <select v-model="chain.completion_assignee_id" class="rcmi-input">
                    <option :value="null">No default assignee</option>
                    <option v-for="u in assignableUsers" :key="u.id" :value="u.id">{{ u.display_name }} ({{ u.user_login }})</option>
                </select>
                <p class="rcmi-field-help">The selected person is assigned when a ticket enters this approval chain (at creation). They receive the Approved notification once all steps clear, and can then move the ticket to In Progress and Complete.</p>
            </div>

            <!-- Completion message -->
            <div class="rounded-md border border-gray-200 p-4">
                <h4 class="rcmi-section-label mb-3">Completion Message</h4>
                <RichTextEditor v-model="chain.completion_message" />
                <p class="rcmi-field-help mt-2">This message is included in the completion email sent to the ticket requestor. Use it to provide next steps, contact info, links, or a thank-you note specific to this approval chain. Leave blank to send a default notification.</p>
            </div>

            <!-- Save / Delete -->
            <div class="flex items-center gap-3 border-t border-gray-100 pt-4">
                <button @click="saveChain" :disabled="saving"
                    class="rcmi-button-primary inline-flex items-center gap-1.5 px-4 py-2 text-sm disabled:opacity-50">
                    <Icon name="save" /> {{ saving ? 'Saving…' : 'Save Chain' }}
                </button>
                <button v-if="chain.id" @click="deleteChain"
                    class="rcmi-button-danger inline-flex items-center gap-1.5 px-3.5 py-2 text-sm">
                    <Icon name="trash" /> Delete
                </button>
                <router-link to="/approval-edit" class="ml-auto text-sm text-gray-500 hover:text-red-700">
                    Cancel
                </router-link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRouter, onBeforeRouteLeave } from 'vue-router';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';
import RichTextEditor from '../components/RichTextEditor.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({ id: { type: String, required: true } });
const router = useRouter();
const toast = useToast();

const chain = ref(null);
const formFields = ref([]);
const assignableUsers = ref([]);
const loading = ref(true);
const loadError = ref('');
const saving = ref(false);

// Unsaved changes tracking
const cleanSnapshot = ref('');
const isDirty = ref(false);
let skipGuard = false;

const isNew = computed(() => props.id === 'new');
const dropdownFields = computed(() => formFields.value.filter(f => f.type === 'dropdown'));

const triggerFieldLabel = computed(() => {
    const f = formFields.value.find(f => f.field_key === chain.value?.trigger_field_key);
    return f ? f.label : '';
});

const triggerOptions = computed(() => {
    const f = formFields.value.find(f => f.field_key === chain.value?.trigger_field_key);
    if (!f) return [];
    // For cascade dropdowns, flatten all child options from cascade_options map
    if (f.config?.cascade_options && typeof f.config.cascade_options === 'object' && !Array.isArray(f.config.cascade_options)) {
        const all = new Set();
        for (const children of Object.values(f.config.cascade_options)) {
            if (Array.isArray(children)) children.forEach(c => all.add(c));
        }
        return [...all].sort();
    }
    return f?.config?.options || [];
});

function snapshotChain() {
    return JSON.stringify(chain.value);
}

function markClean() {
    cleanSnapshot.value = snapshotChain();
    isDirty.value = false;
}

watch(chain, () => {
    isDirty.value = snapshotChain() !== cleanSnapshot.value;
}, { deep: true });

// Warn on browser tab close / reload
function handleBeforeUnload(e) {
    if (!isDirty.value) return;
    e.preventDefault();
    e.returnValue = '';
}

onBeforeRouteLeave((to, from, next) => {
    if (skipGuard) {
        skipGuard = false;
        return next();
    }
    if (!isDirty.value) return next();
    if (window.confirm('You have unsaved changes. Leave without saving?')) {
        return next();
    }
    next(false);
});

async function loadMeta() {
    try {
        const data = await api('/meta');
        formFields.value = data.form_fields || [];
        assignableUsers.value = data.assignable_users || [];
    } catch (e) {
        toast.error('Failed to load form fields');
    }
}

async function load() {
    loading.value = true;
    loadError.value = '';
    await loadMeta();
    if (isNew.value) {
        chain.value = {
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
        markClean();
        loading.value = false;
        return;
    }
    try {
        chain.value = await api(`/approval-chains/${props.id}`);
        markClean();
    } catch (e) {
        loadError.value = e.message || 'Approval chain not found';
    } finally {
        loading.value = false;
    }
}

function addStep() {
    chain.value.steps.push({
        name: 'Step ' + (chain.value.steps.length + 1),
        approver_type: 'user',
        approver_user_id: null,
        approver_role: '',
    });
}

function removeStep(idx) {
    chain.value.steps.splice(idx, 1);
}

async function saveChain() {
    // Client-side validation with clear messages
    if (!chain.value.name || !chain.value.name.trim()) {
        toast.error('Please give this chain a name');
        return;
    }
    for (let i = 0; i < chain.value.steps.length; i++) {
        const s = chain.value.steps[i];
        if (s.approver_type === 'user' && !s.approver_user_id) {
            toast.error(`Step ${i + 1}: select an approver user`);
            return;
        }
        if (s.approver_type === 'role' && !s.approver_role) {
            toast.error(`Step ${i + 1}: select an approver role`);
            return;
        }
    }
    saving.value = true;
    try {
        const body = {
            name: chain.value.name,
            description: chain.value.description || '',
            trigger_field_key: chain.value.trigger_field_key || '',
            trigger_value: chain.value.trigger_value || '',
            on_reject: chain.value.on_reject,
            completion_assignee_id: chain.value.completion_assignee_id || 0,
            completion_message: chain.value.completion_message || '',
            is_active: chain.value.is_active,
            steps: chain.value.steps.map((s, i) => ({
                name: s.name || ('Step ' + (i + 1)),
                approver_type: s.approver_type,
                approver_user_id: s.approver_type === 'user' ? parseInt(s.approver_user_id) : null,
                approver_role: s.approver_type === 'role' ? s.approver_role : null,
                sort_order: i + 1,
            })),
        };
        let saved;
        const wasNew = !chain.value.id;
        if (!wasNew) {
            saved = await api('/approval-chains/' + chain.value.id, { method: 'PUT', body });
        } else {
            saved = await api('/approval-chains', { method: 'POST', body });
        }
        chain.value = saved;
        markClean();
        // If this was a brand-new chain, move to its own editor URL
        if (wasNew) {
            skipGuard = true;
            router.replace(`/approval-edit/${saved.id}`);
        }
        toast.success('Chain saved');
    } catch (e) {
        toast.error(e.message || 'Failed to save chain');
    } finally {
        saving.value = false;
    }
}

async function deleteChain() {
    if (!chain.value.id) return;
    if (!window.confirm('Delete this chain? Existing approval history is kept.')) return;
    try {
        await api('/approval-chains/' + chain.value.id, { method: 'DELETE' });
        toast.success('Chain deleted');
        router.push('/approval-edit');
    } catch (e) {
        toast.error(e.message || 'Failed to delete chain');
    }
}

function discardAndBack() {
    skipGuard = true;
    router.push('/approval-edit');
}

onMounted(() => {
    load();
    window.addEventListener('beforeunload', handleBeforeUnload);
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', handleBeforeUnload);
});
</script>
