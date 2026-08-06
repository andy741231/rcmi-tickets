<template>
    <div>
        <!-- Loading skeleton -->
        <div v-if="loading" class="space-y-6" aria-busy="true" aria-live="polite">
            <div class="rcmi-card p-6">
                <div class="h-4 w-32 animate-pulse rounded bg-gray-100"></div>
                <div class="mt-4 h-7 w-3/4 animate-pulse rounded bg-gray-100"></div>
                <div class="mt-4 flex gap-2">
                    <div class="h-8 w-24 animate-pulse rounded bg-gray-100"></div>
                    <div class="h-8 w-24 animate-pulse rounded bg-gray-100"></div>
                </div>
            </div>
            <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2 space-y-6">
                    <div class="rcmi-card p-6">
                        <div class="h-4 w-24 animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-3 h-4 w-full animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-2 h-4 w-5/6 animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-2 h-4 w-4/6 animate-pulse rounded bg-gray-100"></div>
                    </div>
                </div>
                <div class="space-y-4">
                    <div class="rcmi-card p-5">
                        <div class="h-4 w-20 animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-3 h-4 w-full animate-pulse rounded bg-gray-100"></div>
                        <div class="mt-2 h-4 w-2/3 animate-pulse rounded bg-gray-100"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Error -->
        <div v-else-if="loadError" class="rcmi-card border-red-200 bg-red-50 p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-700">
                <Icon name="alert" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-red-900">{{ loadError }}</h2>
            <router-link to="/" class="rcmi-button-secondary mt-5 inline-flex px-4 py-2 text-sm">
                <Icon name="chevron-left" /> Back to tickets
            </router-link>
        </div>

        <template v-else-if="ticket">
            <!-- Breadcrumb -->
            <nav class="rcmi-breadcrumb mb-4" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">#{{ ticket.id }}</span>
            </nav>

            <!-- Title + status header -->
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 flex-1">
                    <h2 class="text-2xl font-bold leading-tight text-gray-900">{{ ticket.title }}</h2>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-gray-500">
                        <span class="font-semibold text-gray-600">{{ ticket.author_name }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{ formatDateTime(ticket.created_at) }}</span>
                        <span :class="['rcmi-priority-' + ticket.priority.toLowerCase(), 'inline-flex items-center font-semibold']">
                            <span class="rcmi-priority-dot"></span>{{ ticket.priority }} priority
                        </span>
                    </div>
                </div>
                <div class="flex flex-shrink-0 items-center gap-2">
                    <StatusBadge :status="ticket.status" />
                </div>
            </div>

            <!-- Action buttons -->
            <div class="mb-6 flex flex-wrap items-center gap-2 border-b border-gray-200 pb-4">
                <!-- Rejected: Pending Resubmission — show message instead of buttons -->
                <div v-if="ticket.status === 'Rejected: Pending Resubmission'" class="w-full rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <span class="inline-flex items-center gap-2">
                        <Icon name="alert" />
                        Please update ticket and resubmit for approval.
                        <router-link v-if="canEditTicket" :to="`/ticket/${ticket.id}/edit`"
                            class="ml-2 font-semibold text-red-700 underline hover:text-red-800">
                            Edit ticket
                        </router-link>
                    </span>
                </div>

                <!-- Chain approve/reject (current-step approver only) -->
                <template v-else-if="canApproveChain">
                    <button @click="chainApprove" :disabled="approvalBusy"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-emerald-700 disabled:opacity-50">
                        <Icon name="check-circle" /> Approve Step
                    </button>
                    <button @click="chainRejectOpen = !chainRejectOpen" :disabled="approvalBusy"
                        class="inline-flex items-center gap-1.5 rounded-md border border-red-300 bg-red-50 px-3.5 py-2 text-sm font-semibold text-red-700 transition hover:bg-red-100 disabled:opacity-50">
                        <Icon name="x-circle" /> Reject Step
                    </button>
                    <div v-if="chainRejectOpen" class="mt-2 w-full rounded-lg border border-red-200 bg-red-50/70 p-4 sm:max-w-2xl">
                        <div class="flex items-start gap-3">
                            <span class="mt-0.5 flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full bg-red-100 text-red-700">
                                <Icon name="alert" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-sm font-semibold text-red-900">Reject this approval step</h3>
                                <p class="mt-1 text-xs leading-relaxed text-red-800">Explain what needs to change. This reason will be saved in the approval timeline.</p>
                                <label for="chain-reject-reason" class="sr-only">Reason for rejection</label>
                                <textarea id="chain-reject-reason" v-model="chainRejectComment" rows="3"
                                    placeholder="Enter a clear reason for rejection…"
                                    class="rcmi-input mt-3 resize-y bg-white" aria-label="Reason for rejection"></textarea>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <button @click="chainReject" :disabled="!chainRejectComment.trim() || approvalBusy"
                                        class="rcmi-button-danger inline-flex items-center gap-1.5 px-3.5 py-2 text-sm disabled:opacity-50">
                                        <Icon name="x-circle" /> {{ approvalBusy ? 'Submitting…' : 'Submit rejection' }}
                                    </button>
                                    <button @click="chainRejectOpen = false; chainRejectComment = ''"
                                        class="rcmi-button-ghost px-3 py-2 text-sm">Cancel</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>

                <!-- Legacy status changes (only when NO chain is active) -->
                <template v-if="!hasActiveChain">
                    <template v-if="canChangeStatus('Approved') && ['Received', 'Pending Approval'].includes(ticket.status)">
                        <button @click="changeStatus('Approved')" :disabled="statusChanging"
                            class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3.5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">
                            <Icon name="check-circle" /> Approve
                        </button>
                    </template>
                    <template v-if="canChangeStatus('Rejected')">
                        <button @click="rejectOpen = !rejectOpen" :disabled="statusChanging"
                            class="inline-flex items-center gap-1.5 rounded-md border border-red-300 bg-red-50 px-3.5 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:opacity-50">
                            <Icon name="x-circle" /> Reject
                        </button>
                    </template>
                </template>
                <div v-if="canChangeStatus('Completed') && ticket.status === 'Approved'" class="mt-2 flex w-full items-center gap-2 border-t border-gray-200 pt-3">
                    <span class="mr-1 text-xs text-gray-500">Ticket actions</span>
                    <button @click="changeStatus('Completed')" :disabled="statusChanging"
                        class="inline-flex items-center gap-1.5 rounded-md bg-emerald-600 px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-50">
                        <Icon name="check-badge" /> Complete ticket
                    </button>
                </div>

                <!-- Legacy reject reason input -->
                <div v-if="!hasActiveChain && rejectOpen" class="flex w-full items-center gap-2 sm:w-auto">
                    <input v-model="rejectMessage" type="text" placeholder="Reason for rejection…"
                        class="rcmi-input flex-1 sm:w-64" aria-label="Reason for rejection" />
                    <button @click="changeStatus('Rejected')" :disabled="!rejectMessage.trim() || statusChanging"
                        class="rcmi-button-primary px-3.5 py-2 text-sm disabled:opacity-50">
                        {{ statusChanging ? '…' : 'Confirm' }}
                    </button>
                    <button @click="rejectOpen = false; rejectMessage = ''"
                        class="rcmi-button-ghost px-3 py-2 text-sm">Cancel</button>
                </div>

                <!-- Edit / Delete / Copy link -->
                <div class="ml-auto flex items-center gap-2">
                    <button @click="copyLink" class="rcmi-button-ghost inline-flex items-center gap-1.5 px-3 py-2 text-sm">
                        <Icon name="copy" /> Copy link
                    </button>
                    <router-link v-if="canEditTicket" :to="`/ticket/${ticket.id}/edit`"
                        class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3.5 py-2 text-sm">
                        <Icon name="edit" /> Edit
                    </router-link>
                    <button v-if="canDeleteTicket" @click="confirmDelete = true"
                        class="rcmi-button-danger inline-flex items-center gap-1.5 px-3.5 py-2 text-sm">
                        <Icon name="trash" /> Delete
                    </button>
                </div>
            </div>

            <!-- Two-column layout -->
            <div class="rcmi-ticket-detail-layout">
                <!-- Main column -->
                <div class="space-y-6">
                    <!-- Ticket Details (custom fields) -->
                    <section v-if="meta.form_fields && meta.form_fields.length > 0 && hasCustomAnswers" class="rcmi-card p-6">
                        <h3 class="rcmi-section-label mb-4">Ticket Details</h3>
                        <DynamicForm :fields="meta.form_fields" :model-value="ticket.form_answers || {}" readonly />
                    </section>

                    <!-- Attachments -->
                    <section v-if="ticket.attachments && ticket.attachments.length > 0" class="rcmi-card p-6">
                        <h3 class="rcmi-section-label mb-4">Attachments</h3>
                        <ul class="space-y-2">
                            <li v-for="a in ticket.attachments" :key="a.id" class="flex items-center gap-3 rounded-md border border-gray-100 px-3 py-2 text-sm transition hover:border-gray-200 hover:bg-gray-50">
                                <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500">
                                    <Icon :name="fileIcon(a.mime_type)" />
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
                            :current-user-id="meta.current_user.id"
                            :can-manage="meta.caps.manage"
                        />
                    </section>
                </div>

                <!-- Sidebar -->
                <div class="space-y-4">
                    <!-- Details card -->
                    <section class="rcmi-card p-5">
                        <h3 class="rcmi-section-label mb-4">Details</h3>
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Priority</dt>
                                <dd class="mt-1 text-sm font-semibold" :class="['rcmi-priority-' + ticket.priority.toLowerCase()]">
                                    <span class="rcmi-priority-dot"></span>{{ ticket.priority }}
                                </dd>
                            </div>
                            <div v-if="ticket.due_date">
                                <dt class="text-xs font-semibold text-gray-500">Due Date</dt>
                                <dd class="mt-1 text-sm text-gray-700">{{ formatDate(ticket.due_date) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Author</dt>
                                <dd class="mt-1 flex items-center gap-2 text-sm text-gray-700">
                                    <span class="rcmi-avatar h-7 w-7 text-xs">{{ initials(ticket.author_name) }}</span>
                                    <span>{{ ticket.author_name || 'Unknown' }}</span>
                                </dd>
                                <dd v-if="ticket.author_email" class="mt-1 pl-9 text-xs text-gray-500">
                                    <a :href="`mailto:${ticket.author_email}`" class="hover:text-red-700 hover:underline">{{ ticket.author_email }}</a>
                                </dd>
                            </div>
                            <div v-if="ticket.updated_by_name">
                                <dt class="text-xs font-semibold text-gray-500">Last Updated By</dt>
                                <dd class="mt-1 text-sm text-gray-700">{{ ticket.updated_by_name }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs font-semibold text-gray-500">Created</dt>
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

                    <!-- Tags card -->
                    <section v-if="ticket.tags && ticket.tags.length > 0" class="rcmi-card p-5">
                        <h3 class="rcmi-section-label mb-4">Tags</h3>
                        <div class="flex flex-wrap gap-1.5">
                            <span v-for="t in ticket.tags" :key="t.id" class="rcmi-tag-pill">
                                {{ t.name }}
                            </span>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Delete confirmation modal -->
            <Modal v-if="confirmDelete" @close="confirmDelete = false" title="Delete ticket">
                <p class="text-sm text-gray-700">
                    Delete <strong class="text-gray-900">ticket #{{ ticket.id }}</strong> permanently?
                    This will remove all comments and attachments.
                </p>
                <template #footer>
                    <button @click="deleteTicket" :disabled="deleting"
                        class="rcmi-button-primary px-4 py-2 text-sm disabled:opacity-50">
                        {{ deleting ? 'Deleting…' : 'Yes, delete' }}
                    </button>
                    <button @click="confirmDelete = false"
                        class="rcmi-button-secondary px-4 py-2 text-sm">Cancel</button>
                </template>
            </Modal>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api.js';
import StatusBadge from '../components/StatusBadge.vue';
import CommentThread from '../components/CommentThread.vue';
import Modal from '../components/Modal.vue';
import Icon from '../components/Icon.vue';
import DynamicForm from '../components/DynamicForm.vue';
import ApprovalTimeline from '../components/ApprovalTimeline.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({ id: { type: String, required: true } });
const router = useRouter();
const toast = useToast();

const ticket = ref(null);
const meta = reactive({ current_user: {}, caps: {}, form_fields: [] });
const loading = ref(true);
const loadError = ref('');
const statusChanging = ref(false);
const deleting = ref(false);
const confirmDelete = ref(false);
const rejectOpen = ref(false);
const rejectMessage = ref('');
const chainRejectOpen = ref(false);
const chainRejectComment = ref('');
const approvalBusy = ref(false);

const isManager = computed(() => meta.caps.manage === true);
const isAuthor = computed(() => ticket.value && meta.current_user.id === ticket.value.author_id);
const isAssignee = computed(() => ticket.value && ticket.value.assignee_ids && ticket.value.assignee_ids.includes(meta.current_user.id));

const hasActiveChain = computed(() => {
    return ticket.value && ticket.value.approval_history && ticket.value.approval_history.length > 0;
});

const canApproveChain = computed(() => {
    if (!ticket.value || !ticket.value.current_approval_step) return false;
    return ticket.value.status === 'Pending Approval';
});

const hasCustomAnswers = computed(() => {
    if (!ticket.value || !ticket.value.form_answers) return false;
    return Object.keys(ticket.value.form_answers).length > 0;
});

const canEditTicket = computed(() => {
    if (!ticket.value) return false;
    if (isManager.value) return true;
    // Author can edit when status is Received or rejected (pending resubmission)
    return isAuthor.value && ['Received', 'Rejected: Pending Resubmission'].includes(ticket.value.status);
});

const canDeleteTicket = computed(() => isManager.value);

function canChangeStatus(newStatus) {
    if (!ticket.value) return false;
    const current = ticket.value.status;
    if (isManager.value) return newStatus !== current;
    if (isAssignee.value && newStatus === 'Completed' && current === 'Approved') return true;
    return false;
}

async function changeStatus(newStatus) {
    statusChanging.value = true;
    try {
        const body = { status: newStatus };
        if (newStatus === 'Rejected' && rejectMessage.value.trim()) {
            body.message = rejectMessage.value.trim();
        }
        await api(`/tickets/${ticket.value.id}/status`, { method: 'POST', body });
        ticket.value.status = newStatus;
        rejectOpen.value = false;
        rejectMessage.value = '';
        toast.success(`Status changed to ${newStatus}`);
    } catch (e) {
        console.error('Failed to change status:', e);
        toast.error('Failed to change status. Please try again.');
    } finally {
        statusChanging.value = false;
    }
}

async function chainApprove() {
    approvalBusy.value = true;
    try {
        const body = {};
        if (chainRejectComment.value.trim()) body.comment = chainRejectComment.value.trim();
        const res = await api(`/tickets/${ticket.value.id}/approve`, { method: 'POST', body });
        toast.success(res.ticket_status === 'Approved' ? 'Ticket approved!' : 'Step approved — advanced to next step');
        await loadTicket();
        chainRejectOpen.value = false;
        chainRejectComment.value = '';
    } catch (e) {
        toast.error(e.message || 'Failed to approve step');
    } finally {
        approvalBusy.value = false;
    }
}

async function chainReject() {
    approvalBusy.value = true;
    try {
        const body = { comment: chainRejectComment.value.trim() };
        const res = await api(`/tickets/${ticket.value.id}/reject`, { method: 'POST', body });
        const msg = res.on_reject === 'terminal' ? 'Ticket rejected (terminal)'
            : res.on_reject === 'back_one' ? 'Sent back one step'
            : 'Sent back to author for revision';
        toast.success(msg);
        await loadTicket();
        chainRejectOpen.value = false;
        chainRejectComment.value = '';
    } catch (e) {
        toast.error(e.message || 'Failed to reject step');
    } finally {
        approvalBusy.value = false;
    }
}

async function deleteTicket() {
    deleting.value = true;
    try {
        await api(`/tickets/${ticket.value.id}`, { method: 'DELETE' });
        toast.success('Ticket deleted');
        router.push('/');
    } catch (e) {
        console.error('Failed to delete ticket:', e);
        toast.error('Failed to delete ticket. Please try again.');
        confirmDelete.value = false;
    } finally {
        deleting.value = false;
    }
}

async function copyLink() {
    try {
        await navigator.clipboard.writeText(window.location.href);
        toast.success('Link copied to clipboard');
    } catch (e) {
        console.error('Failed to copy link:', e);
        toast.error('Failed to copy link');
    }
}

function formatDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function formatDateTime(d) {
    if (!d) return '';
    return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function fileIcon(mime) {
    if (!mime) return 'file-generic';
    if (mime.startsWith('image/')) return 'file-image';
    if (mime === 'application/pdf') return 'file-text';
    if (mime.includes('zip')) return 'file-archive';
    if (mime.includes('word') || mime.includes('document')) return 'file-word';
    if (mime.includes('sheet') || mime.includes('excel')) return 'file-spreadsheet';
    return 'file-generic';
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function downloadUrl(id) {
    const config = window.rcmiTickets || {};
    const base = `${config.apiBase}/attachments/${id}/download`;
    // Append nonce as query param so the <a href> click authenticates
    const sep = base.includes('?') ? '&' : '?';
    return `${base}${sep}_wpnonce=${config.nonce}`;
}

async function loadMeta() {
    try {
        const data = await api('/meta');
        Object.assign(meta, data);
    } catch (e) {
        console.error('Failed to load meta:', e);
    }
}

async function loadTicket() {
    try {
        ticket.value = await api(`/tickets/${props.id}`);
    } catch (e) {
        loadError.value = e.status === 404 ? 'Ticket not found.' : 'Failed to load ticket.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    await loadMeta();
    await loadTicket();
});
</script>
