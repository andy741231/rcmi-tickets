<template>
    <div v-if="loading" class="rcmi-card p-8 text-center text-sm text-gray-600">Loading survey…</div>
    <div v-else-if="error" class="rcmi-card p-8 text-center">
        <p class="text-sm text-red-700">{{ error }}</p>
        <router-link to="/" class="rcmi-button-secondary mt-4 inline-flex px-3 py-2 text-sm">Back to tickets</router-link>
    </div>
    <FormBuilderPanel v-else
        :initial-fields="fields"
        api-base="/survey-fields"
        :palette="palette"
        title="Satisfaction Survey Builder"
        subtitle="Design the survey emailed to requestors when their ticket is completed."
        :breadcrumbs="[{ to: '/', label: 'Tickets' }, { label: 'Survey Builder' }]"
        back-to="/"
        back-label="Back to tickets"
        :extra-condition-sources="ticketSources"
        @updated="loadFields"
        @fields-change="liveFields = $event">
        <template #tabs>
            <div class="rcmi-builder-tabs" role="tablist" aria-label="Survey builder sections">
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
            <!-- Settings -->
            <div v-if="tab === 'settings'">
                <!-- Left column matches the Preview tab's "Simulate ticket"
                     panel width (300px). Arbitrary grid-cols value avoids the
                     WP core :root .grid-cols-N utility that outranks variants. -->
                <div class="grid items-start gap-5 lg:grid-cols-[300px_minmax(0,1fr)]">
                <div class="rcmi-card p-5">
                    <h2 class="rcmi-section-label mb-4">Reminders &amp; availability</h2>
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" v-model="settings.reminders_enabled" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                        Send reminders when there's no response
                    </label>
                    <div v-if="settings.reminders_enabled" class="mt-3 space-y-3">
                        <div>
                            <label class="rcmi-field-label" for="survey-first-reminder">First reminder after (days)</label>
                            <input id="survey-first-reminder" v-model.number="settings.first_reminder_days" type="number" min="0" class="rcmi-input" />
                        </div>
                        <div>
                            <label class="rcmi-field-label" for="survey-repeat">Repeat every (days)</label>
                            <input id="survey-repeat" v-model.number="settings.repeat_every_days" type="number" min="1" class="rcmi-input" />
                        </div>
                        <div>
                            <label class="rcmi-field-label" for="survey-max-reminders">Max reminders</label>
                            <input id="survey-max-reminders" v-model.number="settings.max_reminders" type="number" min="0" class="rcmi-input" />
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="rcmi-field-label" for="survey-close">Close survey link after (days)</label>
                        <input id="survey-close" v-model.number="settings.close_after_days" type="number" min="1" class="rcmi-input sm:max-w-52" />
                        <p class="mt-1 text-xs text-gray-500">Counted from the ticket's completion date.</p>
                    </div>
                </div>

                <div class="space-y-5">
                <div class="rcmi-card p-5">
                    <h2 class="rcmi-section-label mb-4">Survey content</h2>
                    <div class="space-y-4">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="checkbox" v-model="settings.enabled" class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                            Survey enabled
                        </label>
                        <div>
                            <label class="rcmi-field-label" for="survey-title">Survey title</label>
                            <input id="survey-title" v-model="settings.title" type="text" class="rcmi-input" placeholder="How did we do?" />
                        </div>
                        <div>
                            <label class="rcmi-field-label">Intro text</label>
                            <RichTextEditor v-model="settings.intro_text" />
                            <p class="rcmi-field-help mt-1">Shown at the top of the survey, under the heading.</p>
                        </div>
                        <div>
                            <label class="rcmi-field-label">Thank-you message</label>
                            <RichTextEditor v-model="settings.thank_you_text" />
                            <p class="rcmi-field-help mt-1">Shown after the requestor submits.</p>
                        </div>
                    </div>
                </div>

                <div class="rcmi-card p-5">
                    <h2 class="rcmi-section-label mb-4">Survey e-mail</h2>
                    <p class="mb-4 text-xs text-gray-500">Used for the survey invitation and its reminders. Placeholders: <code class="rounded bg-gray-100 px-1">{ticket}</code> = ticket number, <code class="rounded bg-gray-100 px-1">{title}</code> = ticket title. Reminders automatically get "Reminder:" in front of the subject.</p>
                    <div class="space-y-4">
                        <div>
                            <label class="rcmi-field-label" for="survey-email-subject">E-mail subject</label>
                            <input id="survey-email-subject" v-model="settings.email_subject" type="text" class="rcmi-input" />
                        </div>
                        <div>
                            <label class="rcmi-field-label">Invitation text</label>
                            <RichTextEditor v-model="settings.email_lead" />
                            <p class="rcmi-field-help mt-1">Body of the first survey e-mail, above the ticket line and button.</p>
                        </div>
                        <div>
                            <label class="rcmi-field-label">Reminder text</label>
                            <RichTextEditor v-model="settings.reminder_lead" />
                            <p class="rcmi-field-help mt-1">Body of reminder e-mails instead of the invitation text.</p>
                        </div>
                        <div>
                            <label class="rcmi-field-label" for="survey-email-button">Button label</label>
                            <input id="survey-email-button" v-model="settings.email_button" type="text" class="rcmi-input sm:max-w-52" />
                        </div>
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

            <!-- Preview -->
            <div v-else-if="tab === 'preview'" class="space-y-4">
                <div class="rcmi-card flex flex-wrap items-center gap-3 px-4 py-3">
                    <p class="text-xs text-gray-500">Preview — responses are not saved.</p>
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
                        <button type="button" @click="resetPreview"
                            class="rcmi-button-secondary inline-flex items-center gap-1 px-2.5 py-1.5 text-xs">
                            <Icon name="rotate-ccw" /> Reset
                        </button>
                    </div>
                </div>

                <!-- NB: `grid-cols-N` class tokens collide with WP core's
                     unlayered `:root .grid-cols-N` utility CSS, which beats
                     layered Tailwind variants — use arbitrary track values. -->
                <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-5 lg:grid-cols-[300px_minmax(0,1fr)]">
                    <!-- Simulated ticket answers -->
                    <div class="rcmi-card p-4 lg:sticky lg:top-4">
                        <h3 class="rcmi-section-label mb-3">Simulate ticket</h3>
                        <DynamicForm v-if="simFields.length" :fields="simFields" v-model="simAnswers" />
                        <p v-else class="text-xs text-gray-500">No questions depend on ticket answers.</p>

                        <div v-if="conditionalFields.length" class="mt-5 border-t border-gray-100 pt-4">
                            <h4 class="rcmi-section-label mb-3">Conditional questions</h4>
                            <ul class="space-y-2">
                                <li v-for="f in conditionalFields" :key="f.id"
                                    class="flex items-center justify-between gap-2 text-xs">
                                    <span class="min-w-0 truncate text-gray-700">{{ f.label || '(untitled)' }}</span>
                                    <span class="shrink-0 rounded-full px-2 py-0.5 font-semibold"
                                        :class="isPreviewFieldVisible(f) ? 'bg-teal-100 text-teal-800' : 'bg-gray-100 text-gray-500'">
                                        {{ isPreviewFieldVisible(f) ? 'Shown' : 'Hidden' }}
                                    </span>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Respondent view -->
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 sm:p-6">
                        <div class="mx-auto transition-all" :class="previewDevice === 'mobile' ? 'max-w-[390px]' : 'max-w-2xl'">
                            <SurveyView
                                :ticket-id="123"
                                ticket-title="Sample ticket"
                                :title="settings.title"
                                :intro-text="settings.intro_text"
                                :thank-you-text="settings.thank_you_text"
                                :fields="liveFields"
                                :context="previewContext"
                                :state="previewState"
                                v-model="previewAnswers"
                                :submitting="false"
                                :submit-error="previewError"
                                submitted-at=""
                                :submitted-fields="previewSubmittedFields"
                                :submitted-answers="previewSubmittedAnswers"
                                @submit="previewSubmit" />
                        </div>
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
import DynamicForm from '../components/DynamicForm.vue';
import RichTextEditor from '../components/RichTextEditor.vue';
import SurveyView from '../components/SurveyView.vue';
import Icon from '../components/Icon.vue';
import { normalizeLogic, isFieldVisible } from '../lib/fieldLogic.js';
import { toEditorHtml } from '../lib/richText.js';
import { useToast } from '../composables/useToast.js';

const toast = useToast();
const route = useRoute();
const router = useRouter();

const fields = ref([]);      // :initial-fields input only — see liveFields
const liveFields = ref([]);  // live copy emitted by FormBuilderPanel (@fields-change)
const ticketFields = ref([]);
const settings = ref({});
const settingsSnapshot = ref('');
const settingsSaving = ref(false);
const loading = ref(true);
const error = ref('');

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

// Keep the tab in sync with back/forward navigation.
watch(() => route.query.tab, (t) => {
    tab.value = TAB_KEYS.includes(t) ? t : 'questions';
});

// Preview state — answers/context live only in the page, never submitted.
const previewDevice = ref('desktop');
const previewState = ref('open'); // 'open' | 'submitted'
const previewAnswers = ref({});
const previewError = ref('');
const simAnswers = ref({}); // answers to the simulated ticket fields

const palette = [
    { type: 'rating',    label: 'Rating',      icon: 'star' },
    { type: 'scale',     label: 'Scale',       icon: 'sliders' },
    { type: 'text',      label: 'Text',        icon: 'text' },
    { type: 'longtext',  label: 'Long Text',   icon: 'textarea' },
    { type: 'dropdown',  label: 'Dropdown',    icon: 'dropdown' },
    { type: 'checkbox',  label: 'Checkbox',    icon: 'checkbox-icon' },
    { type: 'radio',     label: 'Radio',       icon: 'radio-icon' },
    { type: 'number',    label: 'Number',      icon: 'hashtag' },
    { type: 'date',      label: 'Date',        icon: 'calendar' },
    { type: 'section',   label: 'Section',     icon: 'divider' },
];


// Ticket form fields as condition sources — rules reference them with a
// "ticket:<field_key>" prefix so the survey can branch on ticket answers.
const ticketSources = computed(() =>
    (ticketFields.value || [])
        .filter(f => f.type !== 'section')
        .map(f => ({ field_key: 'ticket:' + f.field_key, label: 'Ticket · ' + f.label }))
);

// Distinct ticket field keys referenced by survey conditions (the
// "ticket:<key>" rules). normalizeLogic covers both the v2 rules[] shape
// and legacy single-rule configs.
const ticketConditionKeys = computed(() => {
    const keys = new Set();
    for (const f of liveFields.value || []) {
        const logic = normalizeLogic(f.config?.logic);
        for (const rule of logic?.rules || []) {
            if (rule.field_key.startsWith('ticket:')) {
                keys.add(rule.field_key.slice(7));
            }
        }
    }
    return [...keys];
});

// The real ticket-form controls for every ticket field a survey rule
// references — logic stripped and required off so they always render.
const simFields = computed(() =>
    ticketConditionKeys.value
        .map(key => (ticketFields.value || []).find(f => f.field_key === key))
        .filter(Boolean)
        .map(f => {
            const clone = JSON.parse(JSON.stringify(f));
            clone.required = false;
            if (clone.config) delete clone.config.logic;
            return clone;
        })
);

// Simulated context: only keys with a non-empty value are sent —
// the same "ticket:<key>" map the server produces.
const previewContext = computed(() => {
    const ctx = {};
    for (const key of ticketConditionKeys.value) {
        const v = simAnswers.value[key];
        const empty = v === '' || v === null || v === undefined || (Array.isArray(v) && v.length === 0);
        if (!empty) ctx['ticket:' + key] = v;
    }
    return ctx;
});

const previewEval = computed(() => ({ ...previewContext.value, ...previewAnswers.value }));

const conditionalFields = computed(() =>
    liveFields.value.filter(f => f.type !== 'section' && normalizeLogic(f.config?.logic))
);

function isPreviewFieldVisible(f) {
    return isFieldVisible(f.config || {}, previewEval.value);
}

// Snapshot of the last loaded/saved settings — drives the dirty dot.
const settingsDirty = computed(() =>
    settingsSnapshot.value !== '' && JSON.stringify(settings.value) !== settingsSnapshot.value
);

// Read-only answers shown after a preview submit — mirrors how
// SurveyPage builds the stored-response view: answered questions only.
const previewSubmittedAnswers = computed(() => {
    const out = {};
    for (const f of liveFields.value) {
        if (f.type === 'section') continue;
        const v = previewAnswers.value[f.field_key];
        const empty = v === '' || v === null || v === undefined || (Array.isArray(v) && v.length === 0);
        if (!empty) out[f.field_key] = v;
    }
    return out;
});

const previewSubmittedFields = computed(() =>
    Object.keys(previewSubmittedAnswers.value).map((key) => {
        const f = liveFields.value.find(x => x.field_key === key);
        return {
            id: f?.id ?? key,
            field_key: key,
            label: f?.label || key,
            type: f?.type || 'text',
            required: false,
            config: f?.config || {},
        };
    })
);

function isEmptyAnswer(v) {
    return v === '' || v === null || v === undefined || (Array.isArray(v) && v.length === 0);
}

// Client-side version of the server's submit check: every visible,
// required, non-section question must be answered.
function previewSubmit() {
    const missing = [];
    for (const f of liveFields.value) {
        if (f.type === 'section' || !f.required) continue;
        if (!isFieldVisible(f.config || {}, previewEval.value)) continue;
        if (isEmptyAnswer(previewAnswers.value[f.field_key])) missing.push(f.label);
    }
    if (missing.length) {
        previewError.value = 'Please answer: ' + missing.join(', ');
        return;
    }
    previewError.value = '';
    previewState.value = 'submitted';
}

function resetPreview() {
    previewAnswers.value = {};
    simAnswers.value = {};
    previewState.value = 'open';
    previewError.value = '';
}

function snapshotSettings() {
    settingsSnapshot.value = JSON.stringify(settings.value);
}

// Settings reload would clobber unsaved edits — field updates only
// refresh the question list (e.g. after a reorder emits @updated).
async function loadFields() {
    fields.value = await api('/survey-fields');
}

async function loadAll() {
    try {
        const [surveyFields, surveySettings, meta] = await Promise.all([
            api('/survey-fields'),
            api('/survey-settings'),
            api('/meta'),
        ]);
        fields.value = surveyFields || [];
        // Legacy plain-text values are upgraded to HTML for the editor.
        const s = surveySettings || {};
        s.intro_text = toEditorHtml(s.intro_text);
        s.thank_you_text = toEditorHtml(s.thank_you_text);
        s.email_lead = toEditorHtml(s.email_lead);
        s.reminder_lead = toEditorHtml(s.reminder_lead);
        settings.value = s;
        snapshotSettings();
        ticketFields.value = meta.form_fields || [];
    } catch (e) {
        error.value = e.message || 'Unable to load the survey.';
    } finally {
        loading.value = false;
    }
}

async function saveSettings() {
    settingsSaving.value = true;
    try {
        settings.value = await api('/survey-settings', { method: 'PUT', body: settings.value });
        snapshotSettings();
        toast.success('Survey settings saved');
    } catch (e) {
        toast.error(e.message || 'Failed to save survey settings');
    } finally {
        settingsSaving.value = false;
    }
}

onMounted(loadAll);
</script>
