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
            <div class="grid gap-5 xl:grid-cols-2">
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
                                <th class="pb-2 font-semibold">Goes to</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="n in emailNotifications" :key="n.name">
                                <td class="py-2.5 pr-4 font-medium text-gray-800">{{ n.name }}</td>
                                <td class="py-2.5 pr-4 text-gray-600">{{ n.when }}</td>
                                <td class="py-2.5 text-gray-600">{{ n.to }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';
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
    { name: 'New ticket assigned', when: 'A ticket is created with assignees', to: 'The assignees' },
    { name: 'Submission receipt', when: 'Any ticket is submitted', to: 'The submitter (guests: the email they entered)' },
    { name: 'Approval needed', when: 'A ticket reaches an approval step', to: "That step's approvers" },
    { name: 'Approval decision', when: 'An approver approves or rejects', to: 'The submitter (includes the reviewer\u2019s comment)' },
    { name: 'Status changed', when: 'A ticket is approved or completed', to: 'Approved \u2192 assignees; Completed \u2192 submitter' },
    { name: 'Due date changed', when: 'Someone edits the due date', to: 'Submitter + assignees' },
    { name: 'New assignee', when: 'Someone is added as an assignee', to: 'The newly added assignees' },
    { name: 'Mention', when: 'Someone @mentions a user in a comment', to: 'The mentioned users' },
    { name: 'Completion message', when: 'Included in the Completed email', to: 'Set per approval chain in the Chains editor' },
];

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
