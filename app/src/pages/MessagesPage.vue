<template>
    <div class="rcmi-formbuilder-page">
        <header class="rcmi-formbuilder-page-header">
            <div>
                <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                    <router-link to="/form-builder">Form Builder</router-link>
                    <span class="rcmi-breadcrumb-sep">/</span>
                    <span class="font-semibold text-gray-700">Messages</span>
                </nav>
                <h1 class="text-2xl font-bold text-gray-900">Messages &amp; Notifications</h1>
                <p class="mt-1 text-sm text-gray-600">Control what submitters see on-screen and which emails go out.</p>
            </div>
            <router-link to="/form-builder" class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3 py-2 text-sm">
                <Icon name="chevron-left" /> Back to form builder
            </router-link>
        </header>

        <div v-if="loading" class="rcmi-card p-8 text-center text-sm text-gray-600">Loading settings…</div>
        <div v-else-if="error" class="rcmi-card p-8 text-center text-sm text-red-700">{{ error }}</div>

        <template v-else>
            <div class="grid grid-cols-1 gap-5 xl:grid-cols-2">
                <!-- Public submissions settings -->
                <div class="rcmi-card p-5">
                    <p class="rcmi-section-label mb-2">Public Submissions</p>
                    <p class="text-xs text-gray-500">Allow people without a UH account to submit tickets anonymously.</p>

                    <label class="mt-3 flex items-center gap-2 text-sm text-gray-700">
                        <input v-model="allowPublic" type="checkbox" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                        <span>Allow public submissions</span>
                    </label>
                    <p class="mt-2 text-xs text-gray-500">When off (default), /create asks visitors to sign in with UH SSO and the public API rejects anonymous submissions. Existing public tickets stay viewable via their links.</p>

                    <div class="mt-4 border-t border-gray-100 pt-4">
                        <p class="rcmi-field-label mb-1">Submission confirmation (on-screen)</p>
                        <p class="mb-3 text-xs text-gray-500">Shown to the guest right after they submit — not an email.</p>
                        <div class="space-y-3">
                            <div>
                                <label class="rcmi-field-label">Heading</label>
                                <input v-model="successConfig.heading" class="rcmi-input" :placeholder="DEFAULT_SUCCESS.heading" />
                            </div>
                            <div>
                                <label class="rcmi-field-label">Message</label>
                                <textarea v-model="successConfig.message" rows="4" class="rcmi-input" :placeholder="DEFAULT_SUCCESS.message"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-2">
                        <button @click="saveSettings" :disabled="saving"
                            class="rcmi-button-primary inline-flex items-center gap-1.5 px-3 py-1.5 text-xs disabled:opacity-50">
                            <Icon name="save" /> {{ saving ? 'Saving…' : 'Save' }}
                        </button>
                        <button @click="resetMessage" class="rcmi-button-ghost px-2 py-1.5 text-xs">Reset message to default</button>
                    </div>
                </div>

                <!-- Live preview -->
                <div>
                    <p class="rcmi-field-label mb-2">Preview — what the guest sees</p>
                    <div class="rounded-md border border-dashed border-gray-300 p-4">
                        <div class="rcmi-card p-8 text-center">
                            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-teal-100">
                                <Icon name="check" />
                            </div>
                            <h3 class="text-lg font-bold text-gray-900">{{ successConfig.heading || DEFAULT_SUCCESS.heading }}</h3>
                            <p class="mt-2 text-sm text-gray-600">{{ successConfig.message || DEFAULT_SUCCESS.message }}</p>
                            <span class="rcmi-button-secondary mt-6 inline-flex items-center gap-1.5 px-4 py-2 text-sm">
                                <Icon name="plus" /> Submit another request
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Automated emails reference -->
            <div class="rcmi-card mt-6 p-5">
                <p class="rcmi-section-label mb-1">Automated Emails</p>
                <p class="mb-4 text-xs text-gray-500">These notifications are sent automatically. Templates are built-in; per-email customization is planned for a future update.</p>
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
        </template>

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
import { reactive, ref, onMounted } from 'vue';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';
import Modal from '../components/Modal.vue';
import { useToast } from '../composables/useToast.js';

const toast = useToast();

const DEFAULT_SUCCESS = {
    heading: 'Thank you for your submission',
    message: 'Your ticket has been submitted. A confirmation has been sent to your email.',
};

const successConfig = reactive({ heading: '', message: '' });
const allowPublic = ref(false);
const loading = ref(true);
const saving = ref(false);
const error = ref('');

const emailNotifications = [
    { key: 'ticket_created', name: 'New ticket assigned', when: 'A ticket is created with assignees', to: 'The assignees' },
    { key: 'submitter_receipt', name: 'Submission receipt', when: 'A signed-in user submits a ticket', to: 'The submitter' },
    { key: 'public_receipt', name: 'Public submission receipt', when: 'An anonymous guest submits a ticket', to: 'The email address they entered' },
    { key: 'approval_step', name: 'Approval needed', when: 'A ticket reaches an approval step', to: "That step's approvers" },
    { key: 'approval_rejected', name: 'Approval rejected', when: 'An approver rejects the ticket', to: 'The submitter (includes the reviewer\u2019s comment)' },
    { key: 'status_approved', name: 'Status: Approved', when: 'A ticket is approved', to: 'The assignees' },
    { key: 'status_completed', name: 'Status: Completed', when: 'A ticket is completed', to: 'The submitter (includes the chain completion message when set)' },
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

async function load() {
    try {
        const data = await api('/settings');
        successConfig.heading = data.public_success?.heading || DEFAULT_SUCCESS.heading;
        successConfig.message = data.public_success?.message || DEFAULT_SUCCESS.message;
        allowPublic.value = !!data.allow_public_submit;
    } catch (e) {
        error.value = e.message || 'Unable to load settings.';
    } finally {
        loading.value = false;
    }
}

async function saveSettings() {
    saving.value = true;
    try {
        const updated = await api('/settings', {
            method: 'PUT',
            body: {
                public_success: { heading: successConfig.heading, message: successConfig.message },
                allow_public_submit: allowPublic.value,
            },
        });
        successConfig.heading = updated.public_success.heading;
        successConfig.message = updated.public_success.message;
        allowPublic.value = !!updated.allow_public_submit;
        toast.success('Settings saved');
    } catch (e) {
        toast.error(e.message || 'Failed to save settings');
    } finally {
        saving.value = false;
    }
}

function resetMessage() {
    successConfig.heading = DEFAULT_SUCCESS.heading;
    successConfig.message = DEFAULT_SUCCESS.message;
}

onMounted(load);
</script>
