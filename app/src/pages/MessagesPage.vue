<template>
    <div>
        <!-- Header -->
        <div class="mb-6">
            <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">Automated emails</span>
            </nav>
            <h2 class="text-xl font-bold text-gray-900">Automated Emails</h2>
            <p class="mt-1 text-sm text-gray-600">These notifications are sent automatically. Templates are built-in; per-email customization is planned for a future update.</p>
        </div>

        <!-- Automated emails reference -->
        <div class="rcmi-card p-5">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="pb-2 pr-4 font-semibold">Notification</th>
                            <th class="pb-2 pr-4 font-semibold">Sent when</th>
                            <th class="pb-2 pr-4 font-semibold">Goes to</th>
                            <th class="pb-2 text-right font-semibold">Preview</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="n in emailNotifications" :key="n.key">
                            <td class="py-2.5 pr-4 font-medium text-gray-800">{{ n.name }}</td>
                            <td class="py-2.5 pr-4 text-gray-600">{{ n.when }}</td>
                            <td class="py-2.5 pr-4 text-gray-600">{{ n.to }}</td>
                            <td class="py-2.5 text-right">
                                <button @click="openPreview(n)" class="rcmi-button-ghost inline-flex items-center gap-1 px-2 py-1 text-xs">
                                    <Icon name="eye" /> Preview
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Email preview modal -->
        <Modal v-if="previewModal" :title="previewModal.name" wide @close="previewModal = null">
            <div class="space-y-3">
                <p class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800">
                    <Icon name="eye" /> Preview only — rendered from real ticket data, nothing was sent.
                </p>

                <div v-if="previewLoading" class="py-10 text-center text-sm text-gray-500">Rendering preview…</div>

                <template v-else-if="previewData">
                    <template v-if="previewData.available">
                        <dl class="space-y-1 border-b border-gray-100 pb-3 text-sm">
                            <div class="flex gap-2">
                                <dt class="w-14 shrink-0 font-medium text-gray-500">Subject</dt>
                                <dd class="font-semibold text-gray-900">{{ previewData.subject }}</dd>
                            </div>
                            <div class="flex gap-2">
                                <dt class="w-14 shrink-0 font-medium text-gray-500">To</dt>
                                <dd class="text-gray-700">{{ previewData.to.join(', ') }}</dd>
                            </div>
                        </dl>
                        <p v-if="previewData.sent > 1" class="text-xs text-gray-500">
                            This notification sends {{ previewData.sent }} separate emails (one per recipient group); showing the first.
                        </p>
                        <div class="flex gap-1 border-b border-gray-200" role="tablist" aria-label="Preview format">
                            <button v-for="t in ['rendered', 'plain']" :key="t" role="tab" :aria-selected="previewTab === t"
                                @click="previewTab = t"
                                class="px-3 py-1.5 text-xs font-medium"
                                :class="previewTab === t ? 'border-b-2 border-red-700 text-red-800' : 'text-gray-500 hover:text-gray-700'">
                                {{ t === 'rendered' ? 'HTML email' : 'Plain text' }}
                            </button>
                        </div>
                        <iframe v-if="previewTab === 'rendered'" :srcdoc="previewData.html" sandbox
                            title="Rendered email preview" class="h-[26rem] w-full rounded-md border border-gray-200 bg-white"></iframe>
                        <pre v-else class="max-h-[26rem] overflow-auto whitespace-pre-wrap rounded-md border border-gray-200 bg-gray-50 p-3 text-xs text-gray-800">{{ previewData.plain || '(no plain-text version)' }}</pre>
                    </template>
                    <p v-else class="py-6 text-center text-sm text-gray-500">{{ previewData.note }}</p>
                </template>
            </div>
        </Modal>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import { useToast } from '../composables/useToast.js';

const toast = useToast();

const emailNotifications = [
    { key: 'ticket_created', name: 'New ticket assigned', when: 'A ticket is created with assignees', to: 'The assignees' },
    { key: 'submitter_receipt', name: 'Submission receipt', when: 'A signed-in user submits a ticket', to: 'The submitter' },
    { key: 'public_receipt', name: 'Public submission receipt', when: 'An anonymous guest submits a ticket', to: 'The email address they entered' },
    { key: 'approval_step', name: 'Approval needed', when: 'A ticket reaches an approval step', to: "That step's approvers" },
    { key: 'approval_rejected', name: 'Approval rejected', when: 'An approver rejects the ticket', to: 'The submitter (includes the reviewer\u2019s comment)' },
    { key: 'status_approved', name: 'Status: Assigned', when: 'A ticket is approved', to: 'The assignees' },
    { key: 'status_completed', name: 'Status: Completed', when: 'A ticket is completed', to: 'The submitter (includes the shared completion message from Form Builder → Settings)' },
    { key: 'due_date_changed', name: 'Due date changed', when: 'Someone edits the due date', to: 'Submitter + assignees' },
    { key: 'assignees_changed', name: 'New assignee', when: 'Someone is added as an assignee', to: 'The newly added assignees' },
    { key: 'mention', name: 'Mention', when: 'Someone @mentions a user in a comment', to: 'The mentioned users' },
];

const previews = ref(null);
const previewLoading = ref(false);
const previewModal = ref(null);
const previewData = ref(null);
const previewTab = ref('rendered');

async function openPreview(n) {
    previewModal.value = n;
    previewData.value = null;
    previewTab.value = 'rendered';
    if (!previews.value) {
        previewLoading.value = true;
        try {
            const data = await api('/settings/email-previews');
            previews.value = data.previews || {};
        } catch (e) {
            toast.error(e.message || 'Failed to load email previews');
            previews.value = {};
        } finally {
            previewLoading.value = false;
        }
    }
    previewData.value = previews.value[n.key] || { available: false, note: 'Preview is not available for this email.' };
}
</script>
