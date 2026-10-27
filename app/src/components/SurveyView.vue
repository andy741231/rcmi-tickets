<template>
    <div>
        <!-- Header -->
        <div class="mb-6">
            <h2 class="text-2xl font-bold text-gray-900">{{ title || 'How did we do?' }}</h2>
            <p class="mt-1 text-sm text-gray-600">
                Ticket #{{ ticketId }}<span v-if="ticketTitle"> — {{ ticketTitle }}</span>
            </p>
            <div v-if="introText" class="rcmi-rich-text mt-3 text-sm text-gray-700" v-html="renderRichText(introText)"></div>
        </div>

        <!-- Submitted: thank-you + read-only answers -->
        <div v-if="state === 'submitted'" class="rcmi-card p-6">
            <div class="flex items-start gap-3">
                <span class="mt-0.5 flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                    <Icon name="check" />
                </span>
                <div class="min-w-0">
                    <div class="rcmi-rich-text text-sm font-semibold text-gray-900"
                        v-html="renderRichText(thankYouText || 'Thank you for your feedback!')"></div>
                    <p v-if="submittedAt" class="mt-0.5 text-xs font-normal text-gray-500">
                        Submitted {{ formatDateTime(submittedAt) }}
                    </p>
                </div>
            </div>
            <div v-if="(submittedFields || []).length" class="mt-6 border-t border-gray-100 pt-5">
                <DynamicForm :fields="submittedFields" :model-value="submittedAnswers" readonly />
            </div>
        </div>

        <!-- Open: the survey form -->
        <form v-else class="rcmi-card p-6" @submit.prevent="$emit('submit')">
            <DynamicForm :fields="fields" :model-value="modelValue" :context="context"
                @update:model-value="$emit('update:modelValue', $event)" />
            <div class="mt-6 flex items-center gap-3 border-t border-gray-100 pt-5">
                <button type="submit" :disabled="submitting"
                    class="rcmi-button-primary inline-flex items-center gap-1.5 px-5 py-2.5 text-sm disabled:opacity-50">
                    {{ submitting ? 'Submitting…' : 'Submit feedback' }}
                </button>
                <p v-if="submitError" class="text-sm text-red-700" role="alert">{{ submitError }}</p>
            </div>
        </form>
    </div>
</template>

<script setup>
import DynamicForm from './DynamicForm.vue';
import Icon from './Icon.vue';
import { renderRichText } from '../lib/richText.js';

defineProps({
    ticketId: { type: [Number, String], default: '' },
    ticketTitle: { type: String, default: '' },
    title: { type: String, default: 'How did we do?' },
    introText: { type: String, default: '' },
    thankYouText: { type: String, default: '' },
    fields: { type: Array, default: () => [] },
    // Answers evaluated by conditions but rendered elsewhere —
    // "ticket:<key>" context on the real survey, simulated in preview.
    context: { type: Object, default: () => ({}) },
    state: { type: String, default: 'open' }, // 'open' | 'submitted'
    modelValue: { type: Object, default: () => ({}) },
    submitting: { type: Boolean, default: false },
    submitError: { type: String, default: '' },
    submittedAt: { type: String, default: '' },
    // Read-only answers for the submitted state.
    submittedFields: { type: Array, default: () => [] },
    submittedAnswers: { type: Object, default: () => ({}) },
});
defineEmits(['update:modelValue', 'submit']);

function formatDateTime(d) {
    if (!d) return '';
    return new Date(String(d).replace(' ', 'T')).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
}
</script>
