<template>
    <div class="mx-auto max-w-2xl">
        <!-- Loading -->
        <div v-if="loading" class="rcmi-card p-8 text-center" aria-busy="true" aria-live="polite">
            <p class="text-sm text-gray-600">Loading survey…</p>
        </div>

        <!-- Unavailable / bad link -->
        <div v-else-if="state === 'unavailable'" class="rcmi-card p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-500">
                <Icon name="alert" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">Survey unavailable</h2>
            <p class="mt-1 text-sm text-gray-600">{{ unavailableMessage }}</p>
        </div>

        <!-- Closed -->
        <div v-else-if="state === 'closed'" class="rcmi-card p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-500">
                <Icon name="clock" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-gray-900">This survey has closed</h2>
            <p class="mt-1 text-sm text-gray-600">The feedback window for this ticket has ended. Thanks anyway!</p>
        </div>

        <SurveyView v-else
            :ticket-id="data.ticket_id"
            :ticket-title="data.ticket_title"
            :intro-text="data.intro_text"
            :thank-you-text="data.thank_you_text"
            :fields="data.fields || []"
            :context="data.ticket_answers || {}"
            :state="state"
            v-model="answers"
            :submitting="submitting"
            :submit-error="submitError"
            :submitted-at="data.response?.submitted_at || ''"
            :submitted-fields="submittedFields"
            :submitted-answers="submittedAnswers"
            @submit="submit" />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../api.js';
import SurveyView from '../components/SurveyView.vue';
import Icon from '../components/Icon.vue';

const props = defineProps({ id: { type: String, required: true } });
const route = useRoute();

const loading = ref(true);
const data = ref({});
const state = ref('open'); // 'open' | 'submitted' | 'closed' | 'unavailable'
const unavailableMessage = ref('This survey link is invalid or the survey is not available.');
const answers = ref({});
const submitting = ref(false);
const submitError = ref('');

const token = computed(() => route.query.token || '');

// Read-only view of the stored response: keep the snapshot label/type and
// borrow the current field config (e.g. rating max) when it still exists.
const submittedAnswers = computed(() => {
    const out = {};
    for (const a of data.value.response?.answers || []) {
        out[a.field_key] = a.value;
    }
    return out;
});

const submittedFields = computed(() => {
    const current = {};
    for (const f of data.value.fields || []) current[f.field_key] = f;
    return (data.value.response?.answers || []).map(a => ({
        field_key: a.field_key,
        label: a.label,
        type: a.type,
        required: false,
        config: current[a.field_key]?.config || {},
    }));
});

async function load() {
    loading.value = true;
    try {
        const params = new URLSearchParams();
        if (token.value) params.set('token', token.value);
        data.value = await api(`/survey/${props.id}`, { params });
        state.value = data.value.state || 'unavailable';
    } catch (e) {
        state.value = 'unavailable';
        unavailableMessage.value = e.message || 'This survey link is invalid or the survey is not available.';
    } finally {
        loading.value = false;
    }
}

async function submit() {
    submitting.value = true;
    submitError.value = '';
    try {
        const res = await api(`/survey/${props.id}`, {
            method: 'POST',
            body: { token: token.value, answers: answers.value },
        });
        data.value = { ...data.value, ...res };
        state.value = 'submitted';
    } catch (e) {
        if (e.status === 409) {
            // Already submitted or window closed — re-sync the state.
            await load();
        } else {
            submitError.value = e.message || 'Failed to submit. Please try again.';
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(load);
</script>
