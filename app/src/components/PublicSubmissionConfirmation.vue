<template>
    <!-- What a guest sees right after submitting a public ticket.
         Message renders the same sanitized rich-text subset the server
         allows (rcmi_tickets_sanitize_completion_message); legacy plain
         text upgrades to <p>/<br> via toEditorHtml inside renderRichText. -->
    <div class="rcmi-card p-8 text-center">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-teal-100">
            <Icon name="check" />
        </div>
        <h3 class="text-lg font-bold text-gray-900">{{ heading || DEFAULT_HEADING }}</h3>
        <div class="rcmi-rich-text mt-2 text-sm text-gray-600" v-html="renderRichText(message || DEFAULT_MESSAGE)"></div>
        <button type="button" @click="$emit('submit-another')"
            class="rcmi-button-secondary mt-6 inline-flex items-center gap-1.5 px-4 py-2 text-sm">
            <Icon name="plus" /> Submit another request
        </button>
    </div>
</template>

<script setup>
import Icon from './Icon.vue';
import { renderRichText } from '../lib/richText.js';

defineProps({
    heading: { type: String, default: '' },
    message: { type: String, default: '' },
});
defineEmits(['submit-another']);

// Mirrors rcmi_tickets_default_success_message() server-side.
const DEFAULT_HEADING = 'Thank you for your submission';
const DEFAULT_MESSAGE = 'Your ticket has been submitted. A confirmation has been sent to your email.';
</script>
