<template>
    <div>
        <!-- Breadcrumb + header -->
        <nav class="rcmi-breadcrumb mb-4" aria-label="Breadcrumb">
            <router-link to="/" class="text-gray-500 hover:text-red-700">Tickets</router-link>
            <span class="mx-1 text-gray-400">/</span>
            <span class="text-gray-900">Ticket Heaven</span>
        </nav>

        <div class="mb-6">
            <h2 class="rcmi-page-title">Ticket Heaven</h2>
            <p class="mt-1 text-sm text-gray-500">Archived completed tickets. Resurrect a ticket to bring it back to the main list.</p>
        </div>

        <!-- Search + sort + view toggle + export -->
        <div class="mb-5 flex flex-wrap items-center gap-3">
            <div class="relative flex-1 min-w-[200px]">
                <input v-model="search" type="search" placeholder="Search archived tickets…"
                    class="rcmi-input pl-9" @input="debouncedLoad" />
                <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            </div>
            <label class="sr-only" for="heaven-sort">Sort tickets</label>
            <select id="heaven-sort" v-model="sort" @change="loadTickets" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700">
                <option value="created_at">Newest first</option>
                <option value="updated_at">Recently updated</option>
                <option value="title">Title</option>
            </select>
            <button @click="toggleOrder" class="rcmi-button-secondary px-3 py-2 text-sm" :aria-label="order === 'desc' ? 'Sort ascending' : 'Sort descending'">
                <Icon :name="order === 'desc' ? 'arrow-down' : 'arrow-up'" />
            </button>
            <div class="ml-auto flex items-center gap-2">
                <!-- View toggle -->
                <div class="rcmi-view-toggle" aria-label="View options">
                    <button @click="setView('card')" :aria-pressed="view === 'card'" :class="['rcmi-view-toggle-btn', view === 'card' ? 'rcmi-view-toggle-btn-active' : '']" title="Card view" aria-label="Card view">
                        <Icon name="grid" />
                    </button>
                    <button @click="setView('list')" :aria-pressed="view === 'list'" :class="['rcmi-view-toggle-btn', view === 'list' ? 'rcmi-view-toggle-btn-active' : '']" title="List view" aria-label="List view">
                        <Icon name="list" />
                    </button>
                </div>
                <!-- Export CSV -->
                <button @click="exportCsv" :disabled="exporting"
                    class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3 py-2 text-sm disabled:opacity-50">
                    <Icon name="download" /> {{ exporting ? 'Exporting…' : 'Export CSV' }}
                </button>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="py-12 text-center text-gray-500">
            <div class="inline-block h-6 w-6 animate-spin rounded-full border-2 border-gray-300 border-t-red-700"></div>
            <p class="mt-2 text-sm">Loading archived tickets…</p>
        </div>

        <!-- Empty state -->
        <div v-else-if="tickets.length === 0" class="rounded-lg border border-gray-200 bg-gray-50 py-16 text-center">
            <div class="mb-3 text-4xl">☁️</div>
            <h3 class="text-sm font-semibold text-gray-700">Ticket Heaven is empty</h3>
            <p class="mt-1 text-xs text-gray-500">Completed tickets sent here will appear in this archive.</p>
        </div>

        <div v-else>
            <!-- Card view -->
            <div v-if="view === 'card'" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="t in tickets" :key="t.id"
                    class="group relative rounded-lg border border-gray-200 bg-white p-4 shadow-sm transition hover:shadow-md">
                    <!-- Header -->
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-xs font-semibold text-gray-400">#{{ t.id }}</span>
                        <StatusBadge :status="t.status" />
                    </div>
                    <!-- Title -->
                    <router-link :to="`/ticket/${t.id}`"
                        class="block truncate text-sm font-semibold text-gray-900 hover:text-red-700">
                        {{ t.title }}
                    </router-link>
                    <!-- Meta -->
                    <div class="mt-2 flex items-center gap-2 text-xs text-gray-500">
                        <span>{{ t.author_name || 'Unknown' }}</span>
                        <span>·</span>
                        <span>{{ formatDate(t.created_at) }}</span>
                    </div>
                    <!-- Assignees -->
                    <div v-if="t.assignees && t.assignees.length" class="mt-2 flex items-center gap-1">
                        <div v-for="a in t.assignees.slice(0, 3)" :key="a.id"
                            class="flex h-6 w-6 items-center justify-center rounded-full bg-gray-200 text-[10px] font-semibold text-gray-600"
                            :title="a.display_name">
                            {{ initials(a.display_name) }}
                        </div>
                        <span v-if="t.assignees.length > 3" class="text-xs text-gray-400">+{{ t.assignees.length - 3 }}</span>
                    </div>
                    <!-- Actions -->
                    <div class="mt-3 flex items-center gap-2 border-t border-gray-100 pt-3">
                        <button @click="resurrect(t)"
                            class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                            :disabled="resurrecting === t.id">
                            <Icon name="check-circle" /> {{ resurrecting === t.id ? 'Resurrecting…' : 'Resurrect' }}
                        </button>
                        <router-link :to="`/ticket/${t.id}`"
                            class="ml-auto text-xs text-gray-500 hover:text-red-700">
                            View →
                        </router-link>
                    </div>
                </div>
            </div>

            <!-- List view (table) -->
            <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <table class="rcmi-inbox-table">
                    <thead>
                        <tr>
                            <th>Ticket</th>
                            <th>Status</th>
                            <th>Requestor</th>
                            <th>Assignee</th>
                            <th>Created</th>
                            <th style="width: 5rem"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in tickets" :key="t.id">
                            <td>
                                <router-link :to="`/ticket/${t.id}`" class="font-semibold text-gray-900 hover:text-red-800">
                                    {{ t.title }}
                                </router-link>
                                <span class="ml-2 text-xs text-gray-400">#{{ t.id }}</span>
                            </td>
                            <td class="whitespace-nowrap">
                                <StatusBadge :status="t.status" />
                            </td>
                            <td class="whitespace-nowrap text-gray-600">
                                {{ t.author_name || '—' }}
                            </td>
                            <td class="whitespace-nowrap text-gray-600">
                                <span v-if="t.assignees && t.assignees.length">{{ t.assignees[0].display_name }}</span>
                                <span v-else class="text-gray-400">Unassigned</span>
                            </td>
                            <td class="whitespace-nowrap text-gray-500">
                                {{ formatDate(t.created_at) }}
                            </td>
                            <td>
                                <button @click="resurrect(t)"
                                    class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50"
                                    :disabled="resurrecting === t.id">
                                    <Icon name="check-circle" /> {{ resurrecting === t.id ? '…' : 'Resurrect' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="totalPages > 1" class="mt-6 flex items-center justify-center gap-2">
                <button @click="goPage(page - 1)" :disabled="page <= 1"
                    class="rcmi-button-secondary px-3 py-2 text-sm disabled:opacity-40">← Prev</button>
                <span class="text-sm text-gray-600">Page {{ page }} of {{ totalPages }}</span>
                <button @click="goPage(page + 1)" :disabled="page >= totalPages"
                    class="rcmi-button-secondary px-3 py-2 text-sm disabled:opacity-40">Next →</button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';
import StatusBadge from '../components/StatusBadge.vue';
import { useToast } from '../composables/useToast.js';

const toast = useToast();

const tickets = ref([]);
const loading = ref(true);
const search = ref('');
const sort = ref('created_at');
const order = ref('desc');
const page = ref(1);
const perPage = ref(9);
const total = ref(0);
const totalPages = ref(0);
const resurrecting = ref(null);
const view = ref(localStorage.getItem('rcmi_heaven_view') || 'card');
const exporting = ref(false);

let debounceTimer = null;

function setView(v) {
    view.value = v;
    localStorage.setItem('rcmi_heaven_view', v);
}

function debouncedLoad() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(() => {
        page.value = 1;
        loadTickets();
    }, 300);
}

function toggleOrder() {
    order.value = order.value === 'desc' ? 'asc' : 'desc';
    loadTickets();
}

function goPage(p) {
    if (p < 1 || p > totalPages.value) return;
    page.value = p;
    loadTickets();
}

function formatDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
}

async function loadTickets() {
    loading.value = true;
    try {
        const params = new URLSearchParams({
            archived: 'true',
            sort: sort.value,
            order: order.value,
            page: String(page.value),
            per_page: String(perPage.value),
        });
        if (search.value.trim()) params.set('search', search.value.trim());
        const res = await api(`/tickets?${params.toString()}`);
        tickets.value = res.items || [];
        total.value = res.total || 0;
        totalPages.value = res.total_pages || 0;
    } catch (e) {
        toast.error(e.message || 'Failed to load archived tickets');
    } finally {
        loading.value = false;
    }
}

async function resurrect(t) {
    resurrecting.value = t.id;
    try {
        await api(`/tickets/${t.id}/resurrect`, { method: 'POST' });
        toast.success(`Ticket #${t.id} resurrected!`);
        await loadTickets();
    } catch (e) {
        toast.error(e.message || 'Failed to resurrect ticket');
    } finally {
        resurrecting.value = null;
    }
}

async function exportCsv() {
    exporting.value = true;
    try {
        const params = new URLSearchParams();
        params.set('archived', 'true');
        params.set('sort', sort.value);
        params.set('order', order.value);
        if (search.value.trim()) params.set('search', search.value.trim());

        const config = window.rcmiTickets || {};
        const sep = config.apiBase.includes('?') ? '&' : '?';
        const url = `${config.apiBase}/tickets/export${sep}${params.toString()}`;

        const res = await fetch(url, {
            headers: { 'X-WP-Nonce': config.nonce },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`Export failed (${res.status})`);
        const blob = await res.blob();
        const disposition = res.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^"]+)"?/);
        const filename = match ? match[1] : 'ticket-heaven-export.csv';
        const objUrl = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = objUrl;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(objUrl);
        toast.success('CSV exported');
    } catch (e) {
        console.error('CSV export failed:', e);
        toast.error('Failed to export CSV. Please try again.');
    } finally {
        exporting.value = false;
    }
}

onMounted(() => {
    loadTickets();
});
</script>
