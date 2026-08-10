<template>
    <div class="mx-auto max-w-2xl">
        <div v-if="loading" class="rcmi-card space-y-5 p-8" aria-busy="true">
            <div class="h-6 w-48 animate-pulse rounded bg-gray-100"></div>
            <div class="h-24 w-full animate-pulse rounded bg-gray-100"></div>
        </div>

        <div v-else-if="resubmitted" class="rcmi-card border-emerald-200 bg-emerald-50 p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                <Icon name="check-circle" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-emerald-900">Ticket resubmitted</h2>
            <p class="mt-2 text-sm text-emerald-800">Your revised ticket has been sent back for approval.</p>
        </div>

        <div v-else-if="loadError" class="rcmi-card border-red-200 bg-red-50 p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-700">
                <Icon name="alert" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-red-900">{{ loadError }}</h2>
            <p class="mt-2 text-sm text-red-800">Contact the ticket team if you still need to revise your request.</p>
        </div>

        <template v-else>
            <div class="mb-6">
                <h2 class="text-xl font-bold text-gray-900">Revise Ticket #{{ id }}</h2>
                <p class="mt-1 text-sm text-gray-600">Update the requested information and resubmit your ticket for approval.</p>
            </div>

            <form @submit.prevent="submit" class="rcmi-card space-y-6 p-6 sm:p-8">
                <div v-if="meta.form_fields.length">
                    <h3 class="rcmi-section-label mb-4">Ticket Details</h3>
                    <DynamicForm :fields="meta.form_fields" v-model="formAnswers" />
                </div>

                <div v-if="error" role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <span class="inline-flex items-center gap-2"><Icon name="alert" /> {{ error }}</span>
                </div>

                <div class="flex items-center gap-3 border-t border-gray-100 pt-5">
                    <button type="submit" :disabled="submitting"
                        class="rcmi-button-primary inline-flex items-center gap-2 px-5 py-2.5 text-sm disabled:opacity-50">
                        <Icon name="check" />
                        {{ submitting ? 'Resubmitting…' : 'Resubmit Ticket' }}
                    </button>
                </div>
            </form>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../api.js';
import DynamicForm from '../components/DynamicForm.vue';
import Icon from '../components/Icon.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({ id: { type: String, required: true } });
const route = useRoute();
const toast = useToast();
const meta = reactive({ form_fields: [] });
const formAnswers = ref({});
const loading = ref(true);
const loadError = ref('');
const resubmitted = ref(false);
const error = ref('');
const submitting = ref(false);
const token = String(route.query.token || '');

function revisionParams() {
    return new URLSearchParams({ token });
}

async function load() {
    if (!token) {
        loadError.value = 'This revision link is invalid or incomplete.';
        loading.value = false;
        return;
    }

    try {
        const [metaData, ticket] = await Promise.all([
            api('/public/meta'),
            api(`/public/tickets/${props.id}/revision`, { params: revisionParams() }),
        ]);
        meta.form_fields = metaData.form_fields || [];
        formAnswers.value = ticket.form_answers || {};
    } catch (e) {
        loadError.value = e.message || 'This revision link is invalid or has expired.';
    } finally {
        loading.value = false;
    }
}

async function submit() {
    submitting.value = true;
    error.value = '';
    try {
        const result = await api(`/public/tickets/${props.id}/revision`, {
            method: 'PUT',
            params: revisionParams(),
            body: { token, form_answers: formAnswers.value },
        });
        toast.success(result.message || 'Ticket resubmitted');
        resubmitted.value = true;
    } catch (e) {
        error.value = e.message || 'Failed to resubmit ticket.';
        toast.error(error.value);
    } finally {
        submitting.value = false;
    }
}

onMounted(load);
</script>
