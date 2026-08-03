<template>
    <div>
        <!-- View toggle + sort -->
        <div class="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-semibold text-gray-900">Ticket inbox</p>
                <p class="text-sm text-gray-600">Review requests and follow the latest activity.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex rounded-md border border-gray-200 bg-gray-100 p-1" aria-label="View options">
                    <button @click="setView('card')" :aria-pressed="view === 'card'" :class="view === 'card' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                        class="rounded px-3 py-1.5 text-sm font-semibold">
                        Cards
                    </button>
                    <button @click="setView('list')" :aria-pressed="view === 'list'" :class="view === 'list' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-600 hover:text-gray-900'"
                        class="rounded px-3 py-1.5 text-sm font-semibold">
                        List
                    </button>
                </div>
                <label class="sr-only" for="ticket-sort">Sort tickets</label>
                <select id="ticket-sort" v-model="sort" @change="loadTickets" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700">
                    <option value="created_at">Newest first</option>
                    <option value="updated_at">Recently updated</option>
                    <option value="title">Title</option>
                    <option value="status">Status</option>
                    <option value="priority">Priority</option>
                    <option value="due_date">Due date</option>
                </select>
                <button @click="toggleOrder" class="rcmi-button-secondary px-3 py-2 text-sm" :aria-label="order === 'desc' ? 'Sort ascending' : 'Sort descending'">
                    {{ order === 'desc' ? '↓' : '↑' }}
                </button>
            </div>
        </div>

        <!-- Filter bar -->
        <FilterBar v-model="filters" :statuses="meta.statuses" :tags="meta.tags" :assignable-users="meta.assignable_users"
            class="mb-6" />

        <!-- Loading state -->
        <div v-if="loading" class="rcmi-surface grid gap-3 p-5" aria-live="polite" aria-busy="true">
            <div v-for="n in 3" :key="n" class="h-20 animate-pulse rounded-lg bg-gray-100"></div>
        </div>

        <!-- Empty state -->
        <div v-else-if="tickets.length === 0" class="rcmi-surface px-6 py-14 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-xl text-red-700" aria-hidden="true">+</div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">No tickets found</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600">Try adjusting your filters, or create a new ticket to get started.</p>
            <router-link to="/create" class="rcmi-button-primary mt-5 inline-flex px-4 py-2 text-sm">Create New Ticket</router-link>
        </div>

        <!-- Card view -->
        <div v-else-if="view === 'card'" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <router-link v-for="t in tickets" :key="t.id" :to="`/ticket/${t.id}`"
                class="group block rounded-xl border border-gray-200 border-l-4 border-l-red-700 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-gray-300 hover:shadow-md">
                <div class="mb-5 flex items-start justify-between gap-3">
                    <span class="text-xs font-semibold tracking-wide text-gray-500">TICKET #{{ t.id }}</span>
                    <StatusBadge :status="t.status" />
                </div>
                <h3 class="mb-2 line-clamp-2 text-base font-semibold leading-snug text-gray-900 group-hover:text-red-800">{{ t.title }}</h3>
                <p class="mb-6 line-clamp-2 min-h-[2.5rem] text-sm leading-relaxed text-gray-600">{{ t.description_text }}</p>
                <div class="flex items-center justify-between border-t border-gray-100 pt-3 text-xs">
                    <span :class="['rcmi-priority-' + t.priority.toLowerCase(), 'inline-flex items-center font-semibold']">
                        <span class="rcmi-priority-dot"></span>{{ t.priority }} priority
                    </span>
                    <span v-if="t.due_date" class="text-gray-500">Due {{ formatDate(t.due_date) }}</span>
                    <span v-else class="text-gray-500">No due date</span>
                </div>
            </router-link>
        </div>

        <!-- List view (table) -->
        <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50/80">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">#</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Title</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Status</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Priority</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Due</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold uppercase tracking-wide text-gray-500">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    <tr v-for="t in tickets" :key="t.id" class="transition hover:bg-red-50/30">
                        <td class="whitespace-nowrap px-5 py-4 text-sm font-semibold text-gray-500">{{ t.id }}</td>
                        <td class="px-5 py-4 text-sm">
                            <router-link :to="`/ticket/${t.id}`" class="font-semibold text-gray-900 hover:text-red-800">
                                {{ t.title }}
                            </router-link>
                            <p class="mt-0.5 max-w-xs truncate text-xs text-gray-500">{{ t.description_text }}</p>
                        </td>
                        <td class="whitespace-nowrap px-5 py-4"><StatusBadge :status="t.status" /></td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm font-semibold" :class="priorityClass(t.priority)">{{ t.priority }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">{{ t.due_date ? formatDate(t.due_date) : '—' }}</td>
                        <td class="whitespace-nowrap px-5 py-4 text-sm text-gray-600">{{ formatDate(t.created_at) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <Pagination v-if="!loading && tickets.length > 0" :page="page" :per-page="perPage" :total="total"
            :total-pages="totalPages" @change="onPageChange" />
    </div>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue';
import { api } from '../api.js';
import FilterBar from '../components/FilterBar.vue';
import StatusBadge from '../components/StatusBadge.vue';
import Pagination from '../components/Pagination.vue';

const meta = reactive({ statuses: [], priorities: [], tags: [], assignable_users: [] });
const tickets = ref([]);
const loading = ref(true);
const view = ref(localStorage.getItem('rcmi_tickets_view') || 'card');
const sort = ref(localStorage.getItem('rcmi_tickets_sort') || 'created_at');
const order = ref(localStorage.getItem('rcmi_tickets_order') || 'desc');
const page = ref(1);
const perPage = ref(10);
const total = ref(0);
const totalPages = ref(0);
const filters = ref({ search: '', scope: 'all', status: [], assignee_ids: [], tag_ids: [], date_from: '', date_to: '' });

function setView(v) {
    view.value = v;
    localStorage.setItem('rcmi_tickets_view', v);
}

function toggleOrder() {
    order.value = order.value === 'desc' ? 'asc' : 'desc';
    localStorage.setItem('rcmi_tickets_order', order.value);
    loadTickets();
}

function priorityClass(p) {
    return { High: 'rcmi-priority-high', Medium: 'rcmi-priority-medium', Low: 'rcmi-priority-low' }[p] || 'rcmi-priority-low';
}

function formatDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
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
        tickets.value = data.items || [];
        total.value = data.total || 0;
        totalPages.value = data.total_pages || 0;
    } catch (e) {
        console.error('Failed to load tickets:', e);
        tickets.value = [];
    } finally {
        loading.value = false;
    }
}

function onPageChange(p) {
    page.value = p;
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
