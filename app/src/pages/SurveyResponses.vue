<template>
    <div class="mx-auto max-w-5xl">
        <!-- Header -->
        <div class="mb-6">
            <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">Survey responses</span>
            </nav>
            <h2 class="text-xl font-bold text-gray-900">Survey responses</h2>
            <p class="mt-1 text-sm text-gray-600">Satisfaction surveys submitted by requestors. Click a row to read the full response.</p>
        </div>

        <!-- Filter bar -->
        <FilterBar mode="survey" v-model="filters" :form-fields="surveyFields"
            :requestors="meta.requestors || []" :departments="meta.departments || []"
            field-values-endpoint="/survey-field-values"
            @clear-all="clearAllFilters"
            class="mb-5" />

        <!-- Toolbar: export + per page -->
        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="ml-auto flex items-center gap-2">
                <button @click="exportCsv" :disabled="exporting"
                    class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3 py-2 text-sm disabled:opacity-50">
                    <Icon name="download" /> {{ exporting ? 'Exporting…' : 'Export CSV' }}
                </button>
                <label for="per-page" class="text-sm text-gray-600">Per page</label>
                <select id="per-page" v-model.number="perPage" @change="onPerPageChange" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700">
                    <option :value="6">6</option>
                    <option :value="12">12</option>
                    <option :value="24">24</option>
                    <option :value="48">48</option>
                </select>
            </div>
        </div>

        <!-- Loading state -->
        <div v-if="loading" aria-live="polite" aria-busy="true">
            <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
                <div v-for="n in 6" :key="n" class="flex items-center gap-4 border-b border-gray-100 px-5 py-4">
                    <div class="h-4 w-40 animate-pulse rounded bg-gray-100"></div>
                    <div class="h-4 w-32 animate-pulse rounded bg-gray-100"></div>
                    <div class="h-4 w-20 animate-pulse rounded bg-gray-100"></div>
                    <div class="h-4 flex-1 animate-pulse rounded bg-gray-100"></div>
                </div>
            </div>
        </div>

        <!-- Error state -->
        <div v-else-if="loadError" class="rcmi-card px-6 py-14 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-700" aria-hidden="true">
                <Icon name="alert" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">Couldn't load responses</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600">{{ loadError }}</p>
            <button @click="load" class="rcmi-button-primary mt-5 inline-flex px-4 py-2 text-sm">Try again</button>
        </div>

        <!-- Empty: filters active, no matches -->
        <div v-else-if="items.length === 0 && hasActiveFilters" class="rcmi-card px-6 py-14 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400" aria-hidden="true">
                <Icon name="search" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">No responses match these filters</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600">Try adjusting or clearing your filters.</p>
            <button @click="clearAllFilters" class="rcmi-button-secondary mt-5 inline-flex px-4 py-2 text-sm">Clear all filters</button>
        </div>

        <!-- Empty: no responses at all -->
        <div v-else-if="items.length === 0" class="rcmi-card px-6 py-14 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400" aria-hidden="true">
                <Icon name="star-outline" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">No responses yet</h2>
            <p class="mx-auto mt-2 max-w-md text-sm text-gray-600">Survey answers appear here once requestors respond. The survey email goes out when a ticket is completed.</p>
        </div>

        <!-- Responses table -->
        <div v-else class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
            <table class="rcmi-inbox-table">
                <thead>
                    <tr>
                        <th>Ticket</th>
                        <th>Respondent</th>
                        <th>Submitted</th>
                        <th>Summary</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in items" :key="r.id" class="cursor-pointer" @click="openDetail(r)">
                        <td>
                            <span class="font-semibold text-gray-900">{{ r.ticket_title || 'Untitled ticket' }}</span>
                            <span class="ml-2 text-xs text-gray-400">#{{ r.ticket_id }}</span>
                        </td>
                        <td class="whitespace-nowrap text-gray-600">
                            {{ r.respondent_name || r.respondent_email || '—' }}
                            <span v-if="r.respondent_name && r.respondent_email" class="block text-xs text-gray-400">{{ r.respondent_email }}</span>
                        </td>
                        <td class="whitespace-nowrap text-gray-500" :title="formatDateTime(r.submitted_at)">
                            {{ relativeTime(r.submitted_at) }}
                        </td>
                        <td class="text-gray-600" :title="summaryFor(r)">{{ summaryFor(r) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <Pagination v-if="!loading && items.length > 0" :page="page" :per-page="perPage" :total="total"
            :total-pages="totalPages" @change="onPageChange" />

        <!-- Response detail modal -->
        <Modal v-if="detail" @close="detail = null" :title="`Response — ${detail.ticket_title || 'Ticket #' + detail.ticket_id}`" titleClass="rcmi-modal-title-sans" wide>
            <p class="text-xs text-gray-500">
                Submitted {{ formatDateTime(detail.submitted_at) }}
                · <router-link :to="`/ticket/${detail.ticket_id}`" class="font-medium text-red-700 hover:underline" @click="detail = null">Ticket #{{ detail.ticket_id }}</router-link>
            </p>
            <div class="mb-4 mt-2 flex items-center gap-3">
                <img v-if="detail.requestor_photo" :src="detail.requestor_photo" :alt="detail.respondent_name" class="h-10 w-10 rounded-full object-cover" />
                <span v-else class="rcmi-avatar h-10 w-10 text-sm">{{ initials(detail.respondent_name || detail.requestor_email) }}</span>
                <div class="min-w-0">
                    <div class="truncate text-sm font-semibold text-gray-900">{{ detail.respondent_name || detail.requestor_email || '—' }}</div>
                    <a v-if="detail.requestor_email" :href="`mailto:${detail.requestor_email}`" class="text-xs text-gray-500 hover:text-red-700 hover:underline">{{ detail.requestor_email }}</a>
                    <div v-if="detail.requestor_job_title || detail.requestor_department" class="mt-0.5 truncate text-xs text-gray-500">
                        {{ [detail.requestor_job_title, detail.requestor_department].filter(Boolean).join(' · ') }}
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-100 pt-4">
            <dl v-if="(detail.answers || []).length" class="space-y-3">
                <div v-for="a in detail.answers" :key="a.field_id + ':' + a.field_key">
                    <dt class="text-xs font-semibold text-gray-500">{{ a.label }}</dt>
                    <dd v-if="a.type === 'rating'" class="mt-0.5">
                        <span class="inline-flex items-center gap-0.5" :aria-label="`${a.value} of ${ratingMax(a.field_key)} stars`">
                            <Icon v-for="n in ratingMax(a.field_key)" :key="n" name="star"
                                class="h-4 w-4" :class="n <= Number(a.value) ? 'text-amber-400' : 'text-gray-200'" />
                            <span class="ml-1 text-xs text-gray-500">{{ a.value }}/{{ ratingMax(a.field_key) }}</span>
                        </span>
                    </dd>
                    <dd v-else-if="a.type === 'scale'" class="mt-0.5 text-sm text-gray-700">
                        {{ a.value }} / {{ scaleMax(a.field_key) }}
                    </dd>
                    <dd v-else class="mt-0.5 whitespace-pre-wrap text-sm text-gray-700">{{ answerText(a) }}</dd>
                </div>
            </dl>
            <p v-else class="text-sm text-gray-500">No answers recorded.</p>
            </div>
            <template #footer>
                <router-link :to="`/ticket/${detail.ticket_id}`" class="rcmi-button-secondary px-4 py-2 text-sm" @click="detail = null">Open ticket</router-link>
                <button @click="detail = null" class="rcmi-button-secondary px-4 py-2 text-sm">Close</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import Pagination from '../components/Pagination.vue';
import FilterBar from '../components/FilterBar.vue';
import { useToast } from '../composables/useToast.js';

const toast = useToast();
const route = useRoute();
const router = useRouter();
const meta = reactive({ requestors: [], departments: [] });
const items = ref([]);
const surveyFields = ref([]); // current field configs — rating/scale bounds + question filter
const loading = ref(true);
const loadError = ref('');
const page = ref(1);
const perPage = ref(parseInt(localStorage.getItem('rcmi_survey_responses_per_page'), 10) || 12);
const total = ref(0);
const totalPages = ref(0);
const detail = ref(null);
const exporting = ref(false);
const mounted = ref(false);

function defaultFilters() {
    return { search: '', scope: 'all', status: [], assignee_ids: [], tag_ids: [], author_id: '', department: '', date_from: '', date_to: '', field_filters: {} };
}
const filters = ref(defaultFilters());

// Persist filters to the route query (shareable) and localStorage —
// same pattern as TicketList, with the survey-only key set (search,
// requestor, department, submitted range, question filters).
const FILTER_QUERY_KEYS = ['q', 'author', 'dept', 'from', 'to'];
const FILTERS_STORAGE_KEY = 'rcmi_survey_responses_filters';

function filtersToQuery() {
    const f = filters.value;
    const q = {};
    if (f.search) q.q = f.search;
    if (f.author_id) q.author = String(f.author_id);
    if (f.department) q.dept = f.department;
    if (f.date_from) q.from = f.date_from;
    if (f.date_to) q.to = f.date_to;
    for (const [k, v] of Object.entries(f.field_filters || {})) {
        if (v) q[`f.${k}`] = v;
    }
    return q;
}

function persistFilters() {
    const query = filtersToQuery();
    router.replace({ query }).catch(() => {});
    try {
        if (Object.keys(query).length === 0) {
            localStorage.removeItem(FILTERS_STORAGE_KEY);
        } else {
            localStorage.setItem(FILTERS_STORAGE_KEY, JSON.stringify({ filters: filters.value }));
        }
    } catch { /* storage unavailable — URL still persists */ }
}

function queryToFilters(query) {
    const has = FILTER_QUERY_KEYS.some(k => query[k] !== undefined)
        || Object.keys(query).some(k => k.startsWith('f.'));
    if (!has) return null;
    const f = defaultFilters();
    if (query.q) f.search = String(query.q);
    if (query.author) f.author_id = String(query.author);
    if (query.dept) f.department = String(query.dept);
    if (query.from) f.date_from = String(query.from);
    if (query.to) f.date_to = String(query.to);
    for (const [k, v] of Object.entries(query)) {
        if (k.startsWith('f.') && v) f.field_filters[k.slice(2)] = String(v);
    }
    return f;
}

function storageToFilters() {
    try {
        const saved = JSON.parse(localStorage.getItem(FILTERS_STORAGE_KEY) || 'null');
        if (!saved || typeof saved !== 'object') return null;
        const f = defaultFilters();
        const src = saved.filters && typeof saved.filters === 'object' ? saved.filters : {};
        if (typeof src.search === 'string') f.search = src.search;
        if (src.author_id) f.author_id = String(src.author_id);
        if (typeof src.department === 'string') f.department = src.department;
        if (typeof src.date_from === 'string') f.date_from = src.date_from;
        if (typeof src.date_to === 'string') f.date_to = src.date_to;
        if (src.field_filters && typeof src.field_filters === 'object') {
            for (const [k, v] of Object.entries(src.field_filters)) {
                if (v) f.field_filters[k] = String(v);
            }
        }
        return f;
    } catch {
        return null;
    }
}

// Route query wins over localStorage so shared links always apply.
function restoreFilters() {
    const restored = queryToFilters(route.query) || storageToFilters();
    if (restored) {
        filters.value = restored;
    }
}

// Drop values that no longer resolve (removed field keys, users/departments
// with no visible tickets).
function pruneStaleFilters() {
    const f = filters.value;
    if (f.author_id && !(meta.requestors || []).some(r => String(r.id) === String(f.author_id))) f.author_id = '';
    if (f.department && !(meta.departments || []).some(d => d.value === f.department)) f.department = '';
    const filterable = new Set((surveyFields.value || [])
        .filter(x => ['dropdown', 'radio', 'checkbox', 'cascade', 'rating', 'scale', 'number', 'date'].includes(x.type))
        .map(x => x.field_key));
    for (const k of Object.keys(f.field_filters || {})) {
        if (!filterable.has(k) || !f.field_filters[k]) delete f.field_filters[k];
    }
}

const hasActiveFilters = computed(() => {
    const f = filters.value;
    return !!(f.search || f.author_id || f.department || f.date_from || f.date_to
        || (f.field_filters && Object.keys(f.field_filters).length));
});

function clearAllFilters() {
    filters.value = defaultFilters();
}

function openDetail(r) {
    detail.value = r;
}

// Same initials fallback as the requestor avatar in TicketDetail.vue.
function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

// The stored answer keeps the label snapshot; bounds come from the field's
// current config (same approach as TicketDetail's Customer feedback card).
function answerField(key) {
    return (surveyFields.value || []).find(f => f.field_key === key);
}

function ratingMax(key) {
    const m = parseInt(answerField(key)?.config?.max, 10);
    return Number.isFinite(m) ? Math.min(10, Math.max(3, m)) : 5;
}

function scaleMax(key) {
    const c = answerField(key)?.config || {};
    const min = Number.isFinite(parseInt(c.min, 10)) ? parseInt(c.min, 10) : 0;
    const max = Number.isFinite(parseInt(c.max, 10)) ? parseInt(c.max, 10) : 10;
    return Math.max(min, max);
}

// Readable value: multi-value types arrive as arrays (cascade = a path).
function answerText(a) {
    if (Array.isArray(a.value)) {
        return a.type === 'cascade' ? a.value.join(' › ') : a.value.join(', ');
    }
    return String(a.value ?? '');
}

function truncate(s, n) {
    s = String(s ?? '');
    return s.length > n ? s.slice(0, n - 1) + '…' : s;
}

// Compact one-line summary: prefer scored answers (rating/scale), else the
// first two answers — e.g. "Overall rating 4/5 · Recommend 9/10".
function summaryFor(r) {
    const answers = r.answers || [];
    if (!answers.length) return '—';
    const scored = answers.filter(a => ['rating', 'scale'].includes(a.type));
    const picked = (scored.length ? scored : answers).slice(0, 2);
    return picked.map(a => {
        if (a.type === 'rating') return `${truncate(a.label, 22)} ${a.value}/${ratingMax(a.field_key)}`;
        if (a.type === 'scale') return `${truncate(a.label, 22)} ${a.value}/${scaleMax(a.field_key)}`;
        return `${truncate(a.label, 22)}: ${truncate(answerText(a), 30)}`;
    }).join(' · ');
}

function formatDateTime(d) {
    if (!d) return '';
    return new Date(String(d).replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function formatDate(d) {
    if (!d) return '';
    return new Date(String(d).replace(' ', 'T')).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function relativeTime(d) {
    if (!d) return '';
    const now = new Date();
    const then = new Date(String(d).replace(' ', 'T'));
    const diffMin = Math.floor((now - then) / 60000);
    const diffHr = Math.floor(diffMin / 60);
    const diffDay = Math.floor(diffHr / 24);
    if (diffMin < 1) return 'just now';
    if (diffMin < 60) return `${diffMin}m ago`;
    if (diffHr < 24) return `${diffHr}h ago`;
    if (diffDay < 7) return `${diffDay}d ago`;
    return formatDate(d);
}

// Map the FilterBar payload onto the /survey-responses params (the
// ticket-only keys — scope, status, assignee, tag — never leave the bar).
function applyFilterParams(params) {
    const f = filters.value;
    if (f.search) params.set('search', f.search);
    if (f.author_id) params.set('author_id', f.author_id);
    if (f.department) params.set('department', f.department);
    if (f.date_from) params.set('date_from', f.date_from);
    if (f.date_to) params.set('date_to', f.date_to);
    if (f.field_filters) {
        for (const [k, v] of Object.entries(f.field_filters)) {
            if (v) params.set(`field_filters[${k}]`, v);
        }
    }
    return params;
}

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const params = new URLSearchParams();
        params.set('page', page.value);
        params.set('per_page', perPage.value);
        applyFilterParams(params);
        const data = await api('/survey-responses', { params });
        items.value = data.items || [];
        total.value = data.total || 0;
        totalPages.value = data.total_pages || 0;
    } catch (e) {
        loadError.value = e.message || 'Something went wrong. Please try again.';
        items.value = [];
        total.value = 0;
        totalPages.value = 0;
    } finally {
        loading.value = false;
    }
}

async function exportCsv() {
    exporting.value = true;
    try {
        const params = applyFilterParams(new URLSearchParams());

        const config = window.rcmiTickets || {};
        const sep = config.apiBase.includes('?') ? '&' : '?';
        const url = `${config.apiBase}/survey-responses/export${sep}${params.toString()}`;

        const res = await fetch(url, {
            headers: { 'X-WP-Nonce': config.nonce },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`Export failed (${res.status})`);
        const blob = await res.blob();
        const disposition = res.headers.get('Content-Disposition') || '';
        const match = disposition.match(/filename="?([^"]+)"?/);
        const filename = match ? match[1] : 'survey-responses.csv';
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

function onPerPageChange() {
    localStorage.setItem('rcmi_survey_responses_per_page', perPage.value);
    page.value = 1;
    load();
}

function onPageChange(p) {
    page.value = p;
    load();
}

// Reload when filters change (debounced in FilterBar). The `mounted` flag
// keeps restore + the explicit initial load below from double-firing.
watch(filters, () => {
    persistFilters();
    if (!mounted.value) return;
    page.value = 1;
    load();
}, { deep: true });

async function loadMeta() {
    try {
        const data = await api('/meta');
        Object.assign(meta, data);
    } catch (e) {
        console.error('Failed to load meta:', e);
    }
}

onMounted(async () => {
    restoreFilters();
    await Promise.all([
        loadMeta(),
        // Field configs drive rating/scale bounds + the question filter.
        api('/survey-fields').then(f => { surveyFields.value = f || []; }).catch(() => {}),
    ]);
    pruneStaleFilters();
    persistFilters();
    await load();
    mounted.value = true;
});
</script>
