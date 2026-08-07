<template>
    <div>
        <!-- Queue summary chips -->
        <div v-if="meta.inbox_summary" class="mb-4 flex flex-wrap gap-2">
            <button @click="setQueue('all')" :class="['rcmi-queue-chip', queue === 'all' ? 'rcmi-queue-chip-active' : '']">
                All <span class="rcmi-queue-chip-count">{{ meta.inbox_summary.total }}</span>
            </button>
            <button v-if="meta.inbox_summary.pending_approval > 0" @click="setQueue('pending_approval')"
                :class="['rcmi-queue-chip', queue === 'pending_approval' ? 'rcmi-queue-chip-active-warning' : '']">
                Needs approval <span class="rcmi-queue-chip-count">{{ meta.inbox_summary.pending_approval }}</span>
            </button>
            <button v-if="meta.inbox_summary.received > 0" @click="setQueue('received')"
                :class="['rcmi-queue-chip', queue === 'received' ? 'rcmi-queue-chip-active' : '']">
                Received <span class="rcmi-queue-chip-count">{{ meta.inbox_summary.received }}</span>
            </button>
            <button v-if="meta.inbox_summary.due_soon > 0" @click="setQueue('due_soon')"
                :class="['rcmi-queue-chip', queue === 'due_soon' ? 'rcmi-queue-chip-active-warning' : '']">
                Due soon <span class="rcmi-queue-chip-count">{{ meta.inbox_summary.due_soon }}</span>
            </button>
            <button v-if="meta.inbox_summary.overdue > 0" @click="setQueue('overdue')"
                :class="['rcmi-queue-chip', queue === 'overdue' ? 'rcmi-queue-chip-active-danger' : '']">
                Overdue <span class="rcmi-queue-chip-count">{{ meta.inbox_summary.overdue }}</span>
            </button>
        </div>

        <!-- Filter bar -->
        <FilterBar v-model="filters" :statuses="meta.statuses" :tags="meta.tags" :assignable-users="meta.assignable_users"
            class="mb-5" />

        <!-- View toggle + sort -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="rcmi-view-toggle" aria-label="View options">
                <button @click="setView('card')" :aria-pressed="view === 'card'" :class="['rcmi-view-toggle-btn', view === 'card' ? 'rcmi-view-toggle-btn-active' : '']" title="Card view" aria-label="Card view">
                    <Icon name="grid" />
                </button>
                <button @click="setView('list')" :aria-pressed="view === 'list'" :class="['rcmi-view-toggle-btn', view === 'list' ? 'rcmi-view-toggle-btn-active' : '']" title="List view" aria-label="List view">
                    <Icon name="list" />
                </button>
            </div>
            <label class="sr-only" for="ticket-sort">Sort tickets</label>
            <select id="ticket-sort" v-model="sort" @change="loadTickets" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700">
                <option value="created_at">Newest first</option>
                <option value="updated_at">Recently updated</option>
                <option value="title">Title</option>
                <option value="status">Status</option>
                <option value="due_date">Due date</option>
            </select>
            <button @click="toggleOrder" class="rcmi-button-secondary px-3 py-2 text-sm" :aria-label="order === 'desc' ? 'Sort ascending' : 'Sort descending'" :title="order === 'desc' ? 'Sort ascending' : 'Sort descending'">
                <Icon :name="order === 'desc' ? 'arrow-down' : 'arrow-up'" />
            </button>
            <div class="ml-auto flex items-center gap-2">
                <template v-if="isManager && tickets.length > 0">
                    <button v-if="selectedIds.size > 0" @click="confirmBatchDelete = true"
                        class="rcmi-button-danger inline-flex items-center gap-1.5 px-3 py-2 text-sm">
                        <Icon name="trash" /> Delete ({{ selectedIds.size }})
                    </button>
                    <button v-if="selectMode" @click="clearSelection" class="rcmi-button-ghost px-3 py-2 text-sm">
                        Cancel
                    </button>
                    <button v-else @click="selectMode = true" class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3 py-2 text-sm">
                        <Icon name="trash" /> Select
                    </button>
                </template>
                <label for="per-page" class="text-sm text-gray-600">Per page</label>
                <select id="per-page" v-model.number="perPage" @change="onPerPageChange" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700">
                    <option :value="3">3</option>
                    <option :value="6">6</option>
                    <option :value="9">9</option>
                    <option :value="12">12</option>
                    <option :value="24">24</option>
                </select>
            </div>
        </div>

        <!-- Loading state -->
        <div v-if="loading" aria-live="polite" aria-busy="true">
            <!-- Card skeleton -->
            <div v-if="view === 'card'" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="n in 6" :key="n" class="rcmi-card p-5">
                    <div class="mb-4 flex items-center justify-between">
                        <div class="h-4 w-16 animate-pulse rounded bg-gray-100"></div>
                        <div class="h-5 w-20 animate-pulse rounded-full bg-gray-100"></div>
                    </div>
                    <div class="mb-3 h-5 w-3/4 animate-pulse rounded bg-gray-100"></div>
                    <div class="mb-6 h-4 w-1/2 animate-pulse rounded bg-gray-100"></div>
                    <div class="flex justify-between border-t border-gray-100 pt-3">
                        <div class="h-3 w-20 animate-pulse rounded bg-gray-100"></div>
                        <div class="h-3 w-16 animate-pulse rounded bg-gray-100"></div>
                    </div>
                </div>
            </div>
            <!-- List skeleton -->
            <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <div v-for="n in 6" :key="n" class="flex items-center gap-4 border-b border-gray-100 px-5 py-4">
                    <div class="h-4 w-8 animate-pulse rounded bg-gray-100"></div>
                    <div class="flex-1"><div class="h-4 w-48 animate-pulse rounded bg-gray-100"></div></div>
                    <div class="h-5 w-20 animate-pulse rounded-full bg-gray-100"></div>
                    <div class="h-4 w-16 animate-pulse rounded bg-gray-100"></div>
                    <div class="h-4 w-20 animate-pulse rounded bg-gray-100"></div>
                </div>
            </div>
        </div>

        <!-- Error state -->
        <div v-else-if="loadError" class="rcmi-card px-6 py-14 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-700" aria-hidden="true">
                <Icon name="alert" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">Couldn't load tickets</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600">{{ loadError }}</p>
            <button @click="loadTickets" class="rcmi-button-primary mt-5 inline-flex px-4 py-2 text-sm">Try again</button>
        </div>

        <!-- Empty: no tickets at all -->
        <div v-else-if="tickets.length === 0 && !hasActiveFilters" class="rcmi-card px-6 py-14 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400" aria-hidden="true">
                <Icon name="inbox" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">No tickets yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600">Create your first ticket to get started.</p>
            <router-link to="/create" class="rcmi-button-primary mt-5 inline-flex px-4 py-2 text-sm">Create New Ticket</router-link>
        </div>

        <!-- Empty: filters active, no matches -->
        <div v-else-if="tickets.length === 0 && hasActiveFilters" class="rcmi-card px-6 py-14 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400" aria-hidden="true">
                <Icon name="search" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">No tickets match these filters</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600">Try adjusting or clearing your filters.</p>
            <button @click="clearAllFilters" class="rcmi-button-secondary mt-5 inline-flex px-4 py-2 text-sm">Clear all filters</button>
        </div>

        <!-- Card view -->
        <div v-else-if="view === 'card'" class="rcmi-ticket-card-grid grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-3 xl:grid-cols-3">
            <div v-for="t in tickets" :key="t.id"
                :class="['group rounded-xl border bg-white p-5 shadow-sm transition hover:border-gray-300 hover:shadow-md', selectMode ? 'cursor-default' : '', selectedIds.has(t.id) ? 'border-red-400 ring-1 ring-red-200' : 'border-gray-200']">
                <!-- Header -->
                <div class="mb-3 flex items-start justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <input v-if="selectMode" type="checkbox" :checked="selectedIds.has(t.id)"
                            @change="toggleSelect(t.id)"
                            class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                        <span class="text-xs font-semibold tracking-wide text-gray-500">#{{ t.id }}</span>
                    </div>
                    <StatusBadge :status="t.status" />
                </div>
                <!-- Title -->
                <router-link :to="selectMode ? null : `/ticket/${t.id}`" @click.prevent="selectMode ? toggleSelect(t.id) : null"
                    class="block mb-3 line-clamp-2 text-base font-semibold leading-snug text-gray-900 group-hover:text-red-800">
                    {{ t.title }}
                </router-link>
                <!-- Approval callout -->
                <div v-if="t.status === 'Pending Approval' && t.approval_history" class="mb-3 rounded-md bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800">
                    <span v-if="t.current_approval_step">Approval step {{ currentStepNumber(t) }} of {{ t.approval_history.length }}</span>
                    <span v-else>Awaiting approval</span>
                </div>
                <!-- Footer -->
                <div class="flex items-center justify-between border-t border-gray-100 pt-3 text-xs">
                    <span class="text-gray-400">{{ t.author_name || 'Unknown' }}</span>
                    <span v-if="t.due_date" :class="dueDateClass(t.due_date, t.status)" class="font-medium">
                        {{ dueDateLabel(t.due_date) }}
                    </span>
                    <span v-else class="text-gray-400">No due date</span>
                </div>
            </div>
        </div>

        <!-- List view (table) -->
        <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="rcmi-inbox-table">
                <thead>
                    <tr>
                        <th v-if="selectMode" style="width: 2.5rem">
                            <input type="checkbox" :checked="allSelected" @change="toggleSelectAll"
                                class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                        </th>
                        <th style="width: 3rem"></th>
                        <th>Ticket</th>
                        <th>Status</th>
                        <th>Owner</th>
                        <th>Due</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in tickets" :key="t.id" :class="selectMode && selectedIds.has(t.id) ? 'bg-red-50' : ''">
                        <td v-if="selectMode">
                            <input type="checkbox" :checked="selectedIds.has(t.id)"
                                @change="toggleSelect(t.id)"
                                class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                        </td>
                        <td class="whitespace-nowrap">
                            <span v-if="isOverdue(t.due_date, t.status)" class="rcmi-row-indicator rcmi-row-indicator-danger" aria-label="Overdue"></span>
                            <span v-else-if="t.status === 'Pending Approval'" class="rcmi-row-indicator rcmi-row-indicator-warning" aria-label="Pending approval"></span>
                        </td>
                        <td>
                            <router-link :to="`/ticket/${t.id}`" class="font-semibold text-gray-900 hover:text-red-800">
                                {{ t.title }}
                            </router-link>
                            <span class="ml-2 text-xs text-gray-400">#{{ t.id }}</span>
                        </td>
                        <td class="whitespace-nowrap">
                            <StatusBadge :status="t.status" />
                            <span v-if="t.status === 'Pending Approval' && t.current_approval_step" class="ml-1.5 text-xs text-gray-500">
                                {{ currentStepNumber(t) }}/{{ t.approval_history.length }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap text-gray-600">
                            <span v-if="t.assignees && t.assignees.length">{{ t.assignees[0].display_name }}</span>
                            <span v-else class="text-gray-400">Unassigned</span>
                        </td>
                        <td class="whitespace-nowrap" :class="dueDateClass(t.due_date, t.status)">
                            <span v-if="t.due_date" :title="formatDate(t.due_date)">{{ dueDateLabel(t.due_date) }}</span>
                            <span v-else class="text-gray-400">—</span>
                        </td>
                        <td class="whitespace-nowrap text-gray-500">
                            {{ formatDateTime(t.updated_at) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <Pagination v-if="!loading && tickets.length > 0" :page="page" :per-page="perPage" :total="total"
            :total-pages="totalPages" @change="onPageChange" />

        <!-- Batch delete confirmation modal -->
        <Modal v-if="confirmBatchDelete" @close="confirmBatchDelete = false" title="Delete tickets">
            <p class="text-sm text-gray-700">
                Delete <strong class="text-gray-900">{{ selectedIds.size }} ticket(s)</strong> permanently?
                This will remove all comments, attachments, and approval history.
            </p>
            <template #footer>
                <button @click="batchDelete" :disabled="batchDeleting"
                    class="rcmi-button-danger px-4 py-2 text-sm disabled:opacity-50">
                    {{ batchDeleting ? 'Deleting…' : 'Yes, delete' }}
                </button>
                <button @click="confirmBatchDelete = false"
                    class="rcmi-button-secondary px-4 py-2 text-sm">Cancel</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { api } from '../api.js';
import FilterBar from '../components/FilterBar.vue';
import StatusBadge from '../components/StatusBadge.vue';
import Pagination from '../components/Pagination.vue';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import { useToast } from '../composables/useToast.js';

const toast = useToast();
const meta = reactive({ statuses: [], priorities: [], tags: [], assignable_users: [], inbox_summary: null, caps: {} });
const tickets = ref([]);
const loading = ref(true);
const loadError = ref('');
const view = ref(localStorage.getItem('rcmi_tickets_view') || 'card');
const sort = ref(localStorage.getItem('rcmi_tickets_sort') || 'created_at');
const order = ref(localStorage.getItem('rcmi_tickets_order') || 'desc');
const page = ref(1);
const perPage = ref(parseInt(localStorage.getItem('rcmi_tickets_per_page'), 10) || 9);
const total = ref(0);
const totalPages = ref(0);
const filters = ref({ search: '', scope: 'all', status: [], assignee_ids: [], tag_ids: [], date_from: '', date_to: '' });
const queue = ref('all');

// Batch delete state
const selectMode = ref(false);
const selectedIds = ref(new Set());
const confirmBatchDelete = ref(false);
const batchDeleting = ref(false);

const isManager = computed(() => meta.caps?.manage === true);
const allSelected = computed(() => tickets.value.length > 0 && tickets.value.every(t => selectedIds.value.has(t.id)));

function toggleSelect(id) {
    const next = new Set(selectedIds.value);
    if (next.has(id)) next.delete(id); else next.add(id);
    selectedIds.value = next;
}

function toggleSelectAll() {
    if (allSelected.value) {
        selectedIds.value = new Set();
    } else {
        selectedIds.value = new Set(tickets.value.map(t => t.id));
    }
}

function clearSelection() {
    selectMode.value = false;
    selectedIds.value = new Set();
}

async function batchDelete() {
    batchDeleting.value = true;
    try {
        const ids = [...selectedIds.value];
        await api('/tickets/batch-delete', { method: 'POST', body: { ids } });
        toast.success(`${ids.length} ticket(s) deleted`);
        confirmBatchDelete.value = false;
        clearSelection();
        await loadTickets();
    } catch (e) {
        toast.error(e.message || 'Failed to delete tickets');
    } finally {
        batchDeleting.value = false;
    }
}

const activeFilterCount = computed(() => {
    let c = 0;
    if (filters.value.search) c++;
    if (filters.value.scope !== 'all') c++;
    if (filters.value.status?.length) c++;
    if (filters.value.assignee_ids?.length) c++;
    if (filters.value.tag_ids?.length) c++;
    if (filters.value.date_from) c++;
    if (filters.value.date_to) c++;
    return c;
});

const hasActiveFilters = computed(() => activeFilterCount.value > 0 || queue.value !== 'all');

function setView(v) {
    view.value = v;
    localStorage.setItem('rcmi_tickets_view', v);
}

function setQueue(q) {
    if (queue.value === q) {
        queue.value = 'all';
    } else {
        queue.value = q;
    }
    applyQueueFilter();
    page.value = 1;
    loadTickets();
}

function applyQueueFilter() {
    // Reset status filter then apply queue-specific filters
    filters.value.status = [];
    filters.value.date_from = '';
    filters.value.date_to = '';

    if (queue.value === 'pending_approval') {
        filters.value.status = ['Pending Approval'];
    } else if (queue.value === 'received') {
        filters.value.status = ['Received'];
    } else if (queue.value === 'due_soon') {
        const today = new Date();
        const soon = new Date();
        soon.setDate(soon.getDate() + 7);
        filters.value.date_from = today.toISOString().split('T')[0];
        filters.value.date_to = soon.toISOString().split('T')[0];
    } else if (queue.value === 'overdue') {
        const today = new Date();
        filters.value.date_to = today.toISOString().split('T')[0];
        // Overdue also needs to exclude Completed/Rejected — but the API doesn't
        // have an exclude filter. We'll rely on client-side filtering for this.
    }
}

function clearAllFilters() {
    queue.value = 'all';
    filters.value = { search: '', scope: 'all', status: [], assignee_ids: [], tag_ids: [], date_from: '', date_to: '' };
}

function toggleOrder() {
    order.value = order.value === 'desc' ? 'asc' : 'desc';
    localStorage.setItem('rcmi_tickets_order', order.value);
    loadTickets();
}

function formatDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatDateTime(d) {
    if (!d) return '';
    return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function relativeTime(d) {
    if (!d) return '';
    const now = new Date();
    const then = new Date(d);
    const diffMs = now - then;
    const diffMin = Math.floor(diffMs / 60000);
    const diffHr = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr / 24);
    if (diffMin < 1) return 'just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffHr < 24) return `${diffHr}h ago`;
    if (diffDay < 7) return `${diffDay}d ago`;
    return formatDate(d);
}

function isOverdue(dueDate, status) {
    if (!dueDate || status === 'Completed' || status === 'Rejected') return false;
    return new Date(dueDate) < new Date(new Date().toDateString());
}

function dueDateLabel(dueDate) {
    if (!dueDate) return '';
    const due = new Date(dueDate);
    const today = new Date(new Date().toDateString());
    const diffDays = Math.round((due - today) / 86400000);
    if (diffDays < 0) return `${Math.abs(diffDays)}d overdue`;
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Tomorrow';
    if (diffDays <= 7) return `In ${diffDays}d`;
    return formatDate(dueDate);
}

function dueDateClass(dueDate, status) {
    if (!dueDate) return 'text-gray-400';
    if (isOverdue(dueDate, status)) return 'text-red-700 font-semibold';
    const due = new Date(dueDate);
    const today = new Date(new Date().toDateString());
    const diffDays = Math.round((due - today) / 86400000);
    if (diffDays >= 0 && diffDays <= 3) return 'text-amber-700 font-medium';
    return 'text-gray-600';
}

function currentStepNumber(t) {
    if (!t.approval_history) return 0;
    const idx = t.approval_history.findIndex(a => a.status === 'pending');
    return idx >= 0 ? idx + 1 : 0;
}

async function loadMeta() {
    try {
        const data = await api('/meta');
        Object.assign(meta, data);
    } catch (e) {
        console.error('Failed to load meta:', e);
    }
}

async function loadTickets() {
    loading.value = true;
    loadError.value = '';
    try {
        const params = new URLSearchParams();
        params.set('page', page.value);
        params.set('per_page', perPage.value);
        params.set('sort', sort.value);
        params.set('order', order.value);
        if (filters.value.search) params.set('search', filters.value.search);
        if (filters.value.scope !== 'all') params.set('scope', filters.value.scope);
        if (filters.value.status?.length) filters.value.status.forEach(s => params.append('status[]', s));
        if (filters.value.assignee_ids?.length) filters.value.assignee_ids.forEach(id => params.append('assignee_ids[]', id));
        if (filters.value.tag_ids?.length) filters.value.tag_ids.forEach(id => params.append('tag_ids[]', id));
        if (filters.value.date_from) params.set('date_from', filters.value.date_from);
        if (filters.value.date_to) params.set('date_to', filters.value.date_to);

        const data = await api('/tickets', { params });
        let items = data.items || [];

        // Client-side overdue filter (queue=overdue excludes Completed/Rejected)
        if (queue.value === 'overdue') {
            items = items.filter(t => isOverdue(t.due_date, t.status));
        }

        tickets.value = items;
        total.value = data.total || 0;
        totalPages.value = data.total_pages || 0;
    } catch (e) {
        console.error('Failed to load tickets:', e);
        loadError.value = e.message || 'Something went wrong. Please try again.';
        tickets.value = [];
    } finally {
        loading.value = false;
    }
}

function onPageChange(p) {
    page.value = p;
    loadTickets();
}

function onPerPageChange() {
    localStorage.setItem('rcmi_tickets_per_page', perPage.value);
    page.value = 1;
    loadTickets();
}

// Reload when filters change (debounced in FilterBar)
watch(filters, () => {
    page.value = 1;
    loadTickets();
}, { deep: true });

// Persist sort preference
watch(sort, (v) => localStorage.setItem('rcmi_tickets_sort', v));

onMounted(async () => {
    await loadMeta();
    await loadTickets();
});
</script>
