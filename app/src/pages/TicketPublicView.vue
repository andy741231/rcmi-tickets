<template>
    <div class="mx-auto max-w-5xl">
        <!-- Loading -->
        <div v-if="loading" class="space-y-6" aria-busy="true">
            <div class="rcmi-card p-6">
                <div class="h-4 w-32 animate-pulse rounded bg-gray-100"></div>
                <div class="mt-4 h-7 w-3/4 animate-pulse rounded bg-gray-100"></div>
            </div>
        </div>

        <!-- Error -->
        <div v-else-if="loadError" class="rcmi-card border-red-200 bg-red-50 p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-700">
                <Icon name="alert" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-red-900">{{ loadError }}</h2>
            <p class="mt-2 text-sm text-red-800">Contact the ticket team if you need help.</p>
        </div>

        <template v-else-if="ticket">
            <!-- Title + status header -->
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 flex-1">
                    <h2 class="text-2xl font-bold leading-tight text-gray-900">{{ ticket.title }}</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-gray-500">
                        <span>#{{ ticket.id }}</span>
                        <span aria-hidden="true">&middot;</span>
                        <StatusBadge :status="ticket.status" />
                        <span aria-hidden="true">&middot;</span>
                        <span>{{ formatDateTime(ticket.created_at) }}</span>
                    </div>
                </div>
                <div class="flex flex-shrink-0 items-center gap-2">
                    <a v-if="ticket.status === 'Rejected: Pending Revision'"
                        :href="revisionUrl"
                        class="rcmi-button-primary inline-flex items-center gap-1.5 px-4 py-2 text-sm">
                        <Icon name="edit" /> Edit & Resubmit
                    </a>
                </div>
            </div>

            <!-- Two-column layout -->
            <div class="rcmi-ticket-detail-layout">
                <!-- Main column -->
                <div class="space-y-6">
                    <!-- Form answers -->
                    <section v-if="meta.form_fields && meta.form_fields.length > 0 && hasAnswers" class="rcmi-card p-6">
                        <h3 class="rcmi-section-label mb-4">Ticket Details</h3>
                        <DynamicForm :fields="meta.form_fields" :model-value="ticket.form_answers || {}" readonly />
                    </section>

                    <!-- Attachments -->
                    <section v-if="ticket.attachments && ticket.attachments.length > 0" class="rcmi-card p-6">
                        <h3 class="rcmi-section-label mb-4">Attachments</h3>
                        <ul class="space-y-2">
                            <li v-for="a in ticket.attachments" :key="a.id" class="flex items-center gap-3 rounded-md border border-gray-100 px-3 py-2 text-sm">
                                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500">
                                    <Icon name="file" />
                                </span>
                                <a :href="downloadUrl(a.id)" class="flex-1 truncate font-medium text-gray-800 hover:text-red-700 hover:underline">
                                    {{ a.original_name }}
                                </a>
                                <span class="flex-shrink-0 text-xs text-gray-500">{{ formatSize(a.size) }}</span>
                            </li>
                        </ul>
                    </section>

                    <!-- Comments -->
                    <section class="rcmi-card p-6">
                        <CommentThread
                            :ticket-id="ticket.id"
                            :current-user-id="0"
                            :can-manage="false"
                            :public-token="token"
                        />
                    </section>
                </div>

                <!-- Sidebar -->
                <div class="space-y-4">
                    <!-- Details card -->
                    <section class="rcmi-card p-5">
                        <h3 class="rcmi-section-label mb-4">Details</h3>
                        <dl class="space-y-4">
                            <div v-if="ticket.due_date">
                                <dt class="text-xs font-semibold text-gray-500">Due Date</dt>
                                <dd class="mt-1 text-sm text-gray-700">{{ ticket.due_date }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Status</dt>
                                <dd class="mt-1 text-sm text-gray-700">{{ ticket.status }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Submitted</dt>
                                <dd class="mt-1 text-sm text-gray-700">{{ formatDateTime(ticket.created_at) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Updated</dt>
                                <dd class="mt-1 text-sm text-gray-700">{{ formatDateTime(ticket.updated_at) }}</dd>
                            </div>
                        </dl>
                    </section>

                    <!-- Approval timeline -->
                    <section v-if="ticket.approval_history && ticket.approval_history.length > 0" class="rcmi-card p-5">
                        <h3 class="rcmi-section-label mb-4">Approval Timeline</h3>
                        <ApprovalTimeline :steps="ticket.approval_history" :chain="ticket.approval_chain" />
                    </section>
                </div>
            </div>

            <p class="mt-6 text-center text-xs text-gray-400">You will receive updates by email as your ticket is processed.</p>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../api.js';
import DynamicForm from '../components/DynamicForm.vue';
import CommentThread from '../components/CommentThread.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import StatusBadge from '../components/StatusBadge.vue';
import Icon from '../components/Icon.vue';

const props = defineProps({ id: { type: String, required: true } });
const route = useRoute();
const meta = reactive({ form_fields: [] });
const ticket = ref(null);
const loading = ref(true);
const loadError = ref('');
const token = String(route.query.token || '');

const hasAnswers = computed(() => {
    const answers = ticket.value?.form_answers || {};
    return Object.values(answers).some(v => v !== '' && v !== null && v !== undefined);
});

const revisionUrl = computed(() => {
    return `/#/revision/${props.id}?token=${encodeURIComponent(token)}`;
});

function formatDateTime(dt) {
    if (!dt) return '\u2014';
    return new Date(dt.replace(' ', 'T') + (dt.includes('+') || dt.includes('Z') ? '' : '')).toLocaleString();
}

function formatSize(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let size = bytes;
    while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
    return size.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
}

function downloadUrl(attachmentId) {
    const config = window.rcmiTickets || {};
    const sep = config.apiBase.includes('?') ? '&' : '?';
    return `${config.apiBase}/attachments/${attachmentId}/download${sep}token=${encodeURIComponent(token)}`;
}

async function load() {
    if (!token) {
        loadError.value = 'This view link is invalid or incomplete.';
        loading.value = false;
        return;
    }
    try {
        const [metaData, ticketData] = await Promise.all([
            api('/public/meta'),
            api(`/public/tickets/${props.id}`, { params: new URLSearchParams({ token }) }),
        ]);
        meta.form_fields = metaData.form_fields || [];
        ticket.value = ticketData;
    } catch (e) {
        loadError.value = e.message || 'This view link is invalid or has expired.';
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>
