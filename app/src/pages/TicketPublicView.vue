<template>
    <div class="mx-auto max-w-2xl">
        <div v-if="loading" class="rcmi-card space-y-5 p-8" aria-busy="true">
            <div class="h-6 w-48 animate-pulse rounded bg-gray-100"></div>
            <div class="h-24 w-full animate-pulse rounded bg-gray-100"></div>
        </div>

        <div v-else-if="loadError" class="rcmi-card border-red-200 bg-red-50 p-8 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-100 text-red-700">
                <Icon name="alert" />
            </div>
            <h2 class="mt-4 text-lg font-semibold text-red-900">{{ loadError }}</h2>
            <p class="mt-2 text-sm text-red-800">Contact the ticket team if you need help.</p>
        </div>

        <template v-else-if="ticket">
            <div class="mb-6">
                <h2 class="text-xl font-bold text-gray-900">{{ ticket.title }}</h2>
                <p class="mt-1 text-sm text-gray-600">Ticket #{{ ticket.id }} &middot; {{ ticket.status }}</p>
            </div>

            <!-- Form answers -->
            <div class="rcmi-card space-y-4 p-6 sm:p-8">
                <h3 class="rcmi-section-label">Ticket Details</h3>
                <dl class="divide-y divide-gray-100">
                    <div v-for="f in meta.form_fields" :key="f.field_key" class="flex flex-col gap-1 py-3 sm:flex-row sm:gap-4">
                        <dt class="w-40 flex-shrink-0 text-sm font-medium text-gray-500">{{ f.label }}</dt>
                        <dd class="text-sm text-gray-900">{{ formatAnswer(ticket.form_answers[f.field_key]) }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 py-3 sm:flex-row sm:gap-4">
                        <dt class="w-40 flex-shrink-0 text-sm font-medium text-gray-500">Status</dt>
                        <dd class="text-sm text-gray-900">{{ ticket.status }}</dd>
                    </div>
                    <div v-if="ticket.due_date" class="flex flex-col gap-1 py-3 sm:flex-row sm:gap-4">
                        <dt class="w-40 flex-shrink-0 text-sm font-medium text-gray-500">Due Date</dt>
                        <dd class="text-sm text-gray-900">{{ ticket.due_date }}</dd>
                    </div>
                    <div class="flex flex-col gap-1 py-3 sm:flex-row sm:gap-4">
                        <dt class="w-40 flex-shrink-0 text-sm font-medium text-gray-500">Submitted</dt>
                        <dd class="text-sm text-gray-900">{{ formatDateTime(ticket.created_at) }}</dd>
                    </div>
                    <div v-if="ticket.description_text" class="flex flex-col gap-1 py-3 sm:flex-row sm:gap-4">
                        <dt class="w-40 flex-shrink-0 text-sm font-medium text-gray-500">Description</dt>
                        <dd class="text-sm whitespace-pre-wrap text-gray-900">{{ ticket.description_text }}</dd>
                    </div>
                </dl>
            </div>

            <p class="mt-6 text-center text-xs text-gray-400">You will receive updates by email as your ticket is processed.</p>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';

const props = defineProps({ id: { type: String, required: true } });
const route = useRoute();
const meta = reactive({ form_fields: [] });
const ticket = ref(null);
const loading = ref(true);
const loadError = ref('');
const token = String(route.query.token || '');

function formatAnswer(val) {
    if (val === null || val === undefined || val === '') return '\u2014';
    if (Array.isArray(val)) return val.join(', ');
    return String(val);
}

function formatDateTime(dt) {
    if (!dt) return '\u2014';
    return new Date(dt.replace(' ', 'T') + (dt.includes('+') || dt.includes('Z') ? '' : '')).toLocaleString();
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
