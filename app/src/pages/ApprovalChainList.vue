/**
 * Approval Chain list page. Shows all chains in a searchable, sortable,
 * grouped table. Clicking a row (or Edit) opens the dedicated editor at
 * /approval-edit/:id. Supports duplicate and quick active toggle.
 */
<template>
    <div class="mx-auto max-w-6xl">
        <!-- Header -->
        <div class="mb-6">
            <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">Approval Chains</span>
            </nav>
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-xl font-bold text-gray-900">Approval Chains</h2>
                    <p class="mt-1 text-sm text-gray-600">Define multi-step approval workflows. Each chain routes a ticket through ordered approvers.</p>
                </div>
                <router-link to="/approval-edit/new"
                    class="rcmi-button-primary inline-flex items-center gap-1.5 px-4 py-2 text-sm shadow-sm">
                    <Icon name="plus" /> New Chain
                </router-link>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <div class="relative min-w-[220px] flex-1">
                <input v-model="search" type="search" placeholder="Search chains…"
                    class="rcmi-input pl-9" />
                <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </div>
            <label class="sr-only" for="chain-sort">Sort chains</label>
            <select id="chain-sort" v-model="sort" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700">
                <option value="name">Name (A–Z)</option>
                <option value="updated">Recently updated</option>
                <option value="steps">Step count</option>
            </select>
            <div class="flex rounded-md border border-gray-200 bg-white p-0.5" role="group" aria-label="Filter by status">
                <button @click="statusFilter = 'all'"
                    :class="['rounded px-3 py-1.5 text-xs font-semibold transition', statusFilter === 'all' ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-gray-100']">
                    All
                </button>
                <button @click="statusFilter = 'active'"
                    :class="['rounded px-3 py-1.5 text-xs font-semibold transition', statusFilter === 'active' ? 'bg-emerald-600 text-white' : 'text-gray-600 hover:bg-gray-100']">
                    Active
                </button>
                <button @click="statusFilter = 'inactive'"
                    :class="['rounded px-3 py-1.5 text-xs font-semibold transition', statusFilter === 'inactive' ? 'bg-gray-400 text-white' : 'text-gray-600 hover:bg-gray-100']">
                    Inactive
                </button>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="py-16 text-center text-gray-500">
            <div class="inline-block h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-red-700"></div>
            <p class="mt-2 text-sm">Loading chains…</p>
        </div>

        <!-- Empty state -->
        <div v-else-if="chains.length === 0" class="rounded-xl border border-gray-200 bg-white py-16 text-center shadow-sm">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100">
                <Icon name="flow" />
            </div>
            <h3 class="text-sm font-semibold text-gray-700">No approval chains yet</h3>
            <p class="mt-1 text-xs text-gray-500">Create your first chain to route tickets through ordered approvers.</p>
            <router-link to="/approval-edit/new" class="rcmi-button-primary mt-5 inline-flex items-center gap-1.5 px-4 py-2 text-sm">
                <Icon name="plus" /> New Chain
            </router-link>
        </div>

        <!-- No results -->
        <div v-else-if="filteredChains.length === 0" class="rounded-xl border border-gray-200 bg-white py-14 text-center shadow-sm">
            <p class="text-sm font-semibold text-gray-700">No chains match your filters</p>
            <p class="mt-1 text-xs text-gray-500">Try a different search term or status filter.</p>
            <button @click="clearFilters" class="rcmi-button-secondary mt-4 px-4 py-2 text-sm">Clear filters</button>
        </div>

        <!-- Grouped tables -->
        <div v-else class="space-y-8">
            <!-- Triggered chains -->
            <section v-if="triggered.length > 0">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-gray-500">Triggered chains</h3>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">{{ triggered.length }}</span>
                    <p class="text-xs text-gray-400">Apply when a ticket's field matches the trigger value</p>
                </div>
                <ChainTable :chains="triggered" :field-labels="fieldLabels" :toggling-id="togglingId"
                    @toggle-active="toggleActive" @delete="requestDelete" @duplicate="duplicate" />
            </section>

            <!-- Default chains -->
            <section v-if="defaults.length > 0">
                <div class="mb-2 flex flex-wrap items-center gap-2">
                    <h3 class="text-sm font-bold uppercase tracking-wide text-gray-500">Default chains</h3>
                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-600">{{ defaults.length }}</span>
                    <p class="text-xs text-gray-400">Used when no triggered chain matches</p>
                </div>
                <ChainTable :chains="defaults" :field-labels="fieldLabels" :toggling-id="togglingId"
                    @toggle-active="toggleActive" @delete="requestDelete" @duplicate="duplicate" />
            </section>
        </div>

        <!-- Delete confirmation modal -->
        <Modal v-if="deleteTarget" @close="deleteTarget = null" title="Delete chain">
            <p class="text-sm text-gray-700">
                Delete <strong class="text-gray-900">"{{ deleteTarget.name }}"</strong>?
                Existing approval history on tickets is kept.
            </p>
            <template #footer>
                <button @click="confirmDelete" :disabled="deleting"
                    class="rcmi-button-danger px-4 py-2 text-sm disabled:opacity-50">
                    {{ deleting ? 'Deleting…' : 'Yes, delete' }}
                </button>
                <button @click="deleteTarget = null" class="rcmi-button-secondary px-4 py-2 text-sm">Cancel</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import ChainTable from '../components/ChainTable.vue';
import { useToast } from '../composables/useToast.js';

const toast = useToast();
const router = useRouter();

const chains = ref([]);
const formFields = ref([]);
const loading = ref(true);
const search = ref('');
const sort = ref('name');
const statusFilter = ref('all');
const togglingId = ref(null);
const deleteTarget = ref(null);
const deleting = ref(false);

const fieldLabels = computed(() => {
    const map = {};
    for (const f of formFields.value) map[f.field_key] = f.label;
    return map;
});

const onRejectLabels = {
    restart: 'Restart from step 1',
    back_one: 'Back one step',
    terminal: 'Terminal reject',
};

const filteredChains = computed(() => {
    const q = search.value.trim().toLowerCase();
    return chains.value.filter(c => {
        if (statusFilter.value === 'active' && !c.is_active) return false;
        if (statusFilter.value === 'inactive' && c.is_active) return false;
        if (q && !c.name.toLowerCase().includes(q)) return false;
        return true;
    });
});

const sortedChains = computed(() => {
    const list = [...filteredChains.value];
    if (sort.value === 'name') {
        list.sort((a, b) => a.name.localeCompare(b.name));
    } else if (sort.value === 'updated') {
        list.sort((a, b) => new Date(b.updated_at || 0) - new Date(a.updated_at || 0));
    } else if (sort.value === 'steps') {
        list.sort((a, b) => (a.steps?.length || 0) - (b.steps?.length || 0) || a.name.localeCompare(b.name));
    }
    return list;
});

const triggered = computed(() => sortedChains.value.filter(c => c.trigger_field_key));
const defaults = computed(() => sortedChains.value.filter(c => !c.trigger_field_key));

function clearFilters() {
    search.value = '';
    statusFilter.value = 'all';
    sort.value = 'name';
}

async function load() {
    loading.value = true;
    try {
        const [meta, list] = await Promise.all([
            api('/meta'),
            api('/approval-chains'),
        ]);
        formFields.value = meta.form_fields || [];
        chains.value = list || [];
    } catch (e) {
        toast.error(e.message || 'Failed to load approval chains');
    } finally {
        loading.value = false;
    }
}

async function toggleActive(c) {
    togglingId.value = c.id;
    try {
        const updated = await api(`/approval-chains/${c.id}`, {
            method: 'PUT',
            body: { is_active: !c.is_active },
        });
        const idx = chains.value.findIndex(x => x.id === c.id);
        if (idx >= 0) chains.value[idx] = updated;
        toast.success(updated.is_active ? `"${updated.name}" activated` : `"${updated.name}" deactivated`);
    } catch (e) {
        toast.error(e.message || 'Failed to update chain');
    } finally {
        togglingId.value = null;
    }
}

function requestDelete(c) {
    deleteTarget.value = c;
}

async function confirmDelete() {
    deleting.value = true;
    try {
        await api(`/approval-chains/${deleteTarget.value.id}`, { method: 'DELETE' });
        chains.value = chains.value.filter(c => c.id !== deleteTarget.value.id);
        toast.success('Chain deleted');
        deleteTarget.value = null;
    } catch (e) {
        toast.error(e.message || 'Failed to delete chain');
    } finally {
        deleting.value = false;
    }
}

async function duplicate(c) {
    try {
        const body = {
            name: c.name + ' (Copy)',
            description: c.description || '',
            trigger_field_key: c.trigger_field_key || '',
            trigger_value: c.trigger_value || '',
            on_reject: c.on_reject || 'restart',
            completion_assignee_id: c.completion_assignee_id || 0,
            completion_message: c.completion_message || '',
            is_active: false, // start copies inactive so they don't take effect accidentally
            steps: (c.steps || []).map((s, i) => ({
                name: s.name || ('Step ' + (i + 1)),
                approver_type: s.approver_type,
                approver_user_id: s.approver_type === 'user' ? parseInt(s.approver_user_id) : null,
                approver_role: s.approver_type === 'role' ? s.approver_role : null,
                sort_order: i + 1,
            })),
        };
        const created = await api('/approval-chains', { method: 'POST', body });
        chains.value.push(created);
        toast.success('Chain duplicated — review and activate when ready');
        router.push(`/approval-edit/${created.id}`);
    } catch (e) {
        toast.error(e.message || 'Failed to duplicate chain');
    }
}

onMounted(load);
</script>
