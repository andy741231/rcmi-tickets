<template>
    <div v-if="loading" class="rcmi-card p-8 text-center text-sm text-gray-600">Loading form fields…</div>
    <div v-else-if="error" class="rcmi-card p-8 text-center">
        <p class="text-sm text-red-700">{{ error }}</p>
        <router-link to="/create" class="rcmi-button-secondary mt-4 inline-flex px-3 py-2 text-sm">Back to ticket form</router-link>
    </div>
    <FormBuilderPanel v-else :initial-fields="fields" @updated="loadFields">
        <template #tabs>
            <div class="rcmi-builder-tabs" role="tablist" aria-label="Form builder sections">
                <button v-for="t in tabs" :key="t.key" type="button" role="tab"
                    :aria-selected="tab === t.key"
                    class="rcmi-builder-tab"
                    :class="{ 'rcmi-builder-tab-active': tab === t.key }"
                    @click="setTab(t.key)">
                    {{ t.label }}<span v-if="t.key === 'settings' && settingsDirty"
                        class="mb-0.5 ml-1.5 inline-block h-1.5 w-1.5 rounded-full bg-amber-500 align-middle"
                        title="Unsaved changes"></span>
                </button>
            </div>
        </template>
        <template v-if="tab !== 'questions'" #workspace>
            <!-- Settings — public submissions + on-screen confirmation.
                 Left column matches the survey builder's 300px panel. -->
            <div v-if="tab === 'settings'">
                <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-5 lg:grid-cols-[300px_minmax(0,1fr)]">
                    <div class="rcmi-card p-5">
                        <h2 class="rcmi-section-label mb-4">Public submissions</h2>
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" v-model="settings.allow_public_submit" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                            Allow public submissions
                        </label>
                        <p class="mt-2 text-xs text-gray-500">When off (default), the form asks visitors to sign in with UH SSO and the public API rejects anonymous submissions. Existing public tickets stay viewable via their links.</p>
                    </div>

                    <div class="min-w-0 space-y-5">
                        <div class="rcmi-card p-5">
                            <h2 class="rcmi-section-label mb-4">Submission confirmation</h2>
                            <p class="mb-4 text-xs text-gray-500">Shown to the guest on screen right after they submit — not an email.</p>
                            <div class="space-y-4">
                                <div>
                                    <label class="rcmi-field-label" for="public-success-heading">Heading</label>
                                    <input id="public-success-heading" v-model="settings.heading" class="rcmi-input" :placeholder="DEFAULT_SUCCESS.heading" />
                                </div>
                                <div>
                                    <label class="rcmi-field-label">Message</label>
                                    <RichTextEditor v-model="settings.message" />
                                </div>
                                <button @click="resetMessage" class="rcmi-button-ghost px-2 py-1.5 text-xs">Reset message to default</button>
                            </div>
                        </div>

                        <div class="rcmi-card p-5">
                            <h2 class="rcmi-section-label mb-4">Completion Email Message</h2>
                            <div class="space-y-4">
                                <RichTextEditor v-model="settings.completion_message" />
                                <p class="rcmi-field-help">Included in the email sent to the requestor when any ticket is completed. Leave blank to use the default message.</p>
                                <button @click="resetCompletion" class="rcmi-button-ghost px-2 py-1.5 text-xs">Reset completion message to default</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="sticky bottom-0 z-10 mt-5 flex items-center gap-3 border-t border-gray-200 bg-white/90 px-4 py-3 backdrop-blur">
                    <button @click="saveSettings" :disabled="settingsSaving"
                        class="rcmi-button-primary inline-flex items-center gap-1.5 px-4 py-2 text-sm disabled:opacity-50">
                        <Icon name="save" /> {{ settingsSaving ? 'Saving…' : 'Save settings' }}
                    </button>
                    <span v-if="settingsDirty" class="text-xs font-semibold text-amber-600">Unsaved changes</span>
                </div>
            </div>

            <!-- Preview — what the guest sees after a public submission.
                 Same device toggle + frame as the survey builder preview. -->
            <div v-else-if="tab === 'preview'" class="space-y-4">
                <div class="rcmi-card flex flex-wrap items-center gap-3 px-4 py-3">
                    <p class="text-xs text-gray-500">Preview — nothing is submitted.</p>
                    <div class="ml-auto flex items-center gap-2">
                        <div class="inline-flex items-center gap-0.5 rounded-md border border-gray-200 p-0.5" role="group" aria-label="Preview device">
                            <button type="button" @click="previewDevice = 'desktop'"
                                class="rounded px-2.5 py-1 text-xs font-semibold transition"
                                :class="previewDevice === 'desktop' ? 'bg-red-700 text-white' : 'text-gray-600 hover:text-gray-900'"
                                :aria-pressed="previewDevice === 'desktop'">Desktop</button>
                            <button type="button" @click="previewDevice = 'mobile'"
                                class="rounded px-2.5 py-1 text-xs font-semibold transition"
                                :class="previewDevice === 'mobile' ? 'bg-red-700 text-white' : 'text-gray-600 hover:text-gray-900'"
                                :aria-pressed="previewDevice === 'mobile'">Mobile</button>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 sm:p-6">
                    <div class="mx-auto transition-all" :class="previewDevice === 'mobile' ? 'max-w-[390px]' : 'max-w-2xl'">
                        <PublicSubmissionConfirmation
                            :heading="settings.heading || DEFAULT_SUCCESS.heading"
                            :message="settings.message || DEFAULT_SUCCESS.message" />
                    </div>
                </div>
            </div>
        </template>
    </FormBuilderPanel>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api.js';
import FormBuilderPanel from '../components/FormBuilderPanel.vue';
import RichTextEditor from '../components/RichTextEditor.vue';
import PublicSubmissionConfirmation from '../components/PublicSubmissionConfirmation.vue';
import Icon from '../components/Icon.vue';
import { toEditorHtml } from '../lib/richText.js';
import { useToast } from '../composables/useToast.js';

const toast = useToast();
const route = useRoute();
const router = useRouter();

const fields = ref([]);
const loading = ref(true);
const error = ref('');

const DEFAULT_SUCCESS = {
    heading: 'Thank you for your submission',
    message: 'Your ticket has been submitted. A confirmation has been sent to your email.',
};

// Public-submission settings — loaded once; field updates (@updated)
// only refresh the question list so unsaved edits survive.
const settings = ref({ allow_public_submit: false, heading: '', message: '', completion_message: '', completion_message_default: '' });
const settingsSnapshot = ref('');
const settingsSaving = ref(false);
const previewDevice = ref('desktop');

const settingsDirty = computed(() =>
    settingsSnapshot.value !== '' && JSON.stringify(settings.value) !== settingsSnapshot.value
);

// Same tabs UI as the survey builder — persisted in ?tab=.
const tabs = [
    { key: 'questions', label: 'Questions' },
    { key: 'settings', label: 'Settings' },
    { key: 'preview', label: 'Preview' },
];
const TAB_KEYS = tabs.map(t => t.key);
const tab = ref(TAB_KEYS.includes(route.query.tab) ? route.query.tab : 'questions');

function setTab(key) {
    if (!TAB_KEYS.includes(key)) return;
    tab.value = key;
    const query = { ...route.query };
    if (key === 'questions') delete query.tab;
    else query.tab = key;
    router.replace({ query });
}

watch(() => route.query.tab, (t) => {
    tab.value = TAB_KEYS.includes(t) ? t : 'questions';
});

function snapshotSettings() {
    settingsSnapshot.value = JSON.stringify(settings.value);
}

async function loadFields() {
    const data = await api('/meta');
    fields.value = data.form_fields || [];
}

async function loadAll() {
    try {
        const [meta, serverSettings] = await Promise.all([
            api('/meta'),
            api('/settings'),
        ]);
        fields.value = meta.form_fields || [];
        settings.value = {
            allow_public_submit: !!serverSettings.allow_public_submit,
            heading: serverSettings.public_success?.heading || '',
            // Legacy plain text upgrades to HTML for the editor.
            message: toEditorHtml(serverSettings.public_success?.message || ''),
            completion_message: toEditorHtml(serverSettings.completion_message || ''),
            completion_message_default: serverSettings.completion_message_default || '',
        };
        snapshotSettings();
    } catch (e) {
        error.value = e.message || 'Unable to load the ticket form.';
    } finally {
        loading.value = false;
    }
}

async function saveSettings() {
    settingsSaving.value = true;
    try {
        const updated = await api('/settings', {
            method: 'PUT',
            body: {
                allow_public_submit: settings.value.allow_public_submit,
                public_success: { heading: settings.value.heading, message: settings.value.message },
                completion_message: settings.value.completion_message,
            },
        });
        settings.value = {
            allow_public_submit: !!updated.allow_public_submit,
            heading: updated.public_success?.heading || '',
            message: toEditorHtml(updated.public_success?.message || ''),
            completion_message: toEditorHtml(updated.completion_message || ''),
            completion_message_default: updated.completion_message_default || settings.value.completion_message_default,
        };
        snapshotSettings();
        toast.success('Settings saved');
    } catch (e) {
        toast.error(e.message || 'Failed to save settings');
    } finally {
        settingsSaving.value = false;
    }
}

function resetMessage() {
    settings.value = { ...settings.value, heading: DEFAULT_SUCCESS.heading, message: toEditorHtml(DEFAULT_SUCCESS.message) };
}

// Reset only the completion email editor — never touches the public
// confirmation message. Uses the server-provided default HTML.
function resetCompletion() {
    settings.value = { ...settings.value, completion_message: settings.value.completion_message_default || '' };
}

onMounted(loadAll);
</script>
