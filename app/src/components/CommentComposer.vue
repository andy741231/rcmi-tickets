<template>
    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <!-- Reply indicator -->
        <div v-if="replyTo" class="mb-3 flex items-center justify-between rounded-md bg-teal-50 px-3 py-2 text-sm text-teal-800">
            <span class="inline-flex items-center gap-1.5"><Icon name="reply" /> Replying to <strong>{{ replyTo.user_name }}</strong></span>
            <button type="button" @click="$emit('cancel-reply')" class="rounded p-0.5 text-teal-600 hover:text-teal-800" aria-label="Cancel reply">
                <Icon name="x" />
            </button>
        </div>

        <form @submit.prevent="submit">
            <!-- Textarea with mention autocomplete -->
            <div class="relative">
                <textarea ref="textarea" v-model="body" rows="3"
                    :placeholder="placeholder"
                    class="rcmi-input resize-y"
                    @input="onInput" @keydown="onKeydown"
                    aria-label="Comment body"></textarea>

                <MentionAutocomplete
                    :visible="mentionOpen"
                    :query="mentionQuery"
                    :users="mentionableUsers"
                    @select="insertMention"
                    @close="mentionOpen = false"
                />
            </div>

            <!-- Attachment upload -->
            <div v-if="ticketId" class="mt-2">
                <button type="button" @click="$refs.commentFileInput.click()"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-600 hover:text-red-700">
                    <Icon name="paperclip" /> Attach file
                </button>
                <input ref="commentFileInput" type="file" multiple class="hidden" @change="onFilesSelected" />
            </div>

            <!-- Pending attachments -->
            <ul v-if="pendingAttachments.length > 0" class="mt-2 space-y-1">
                <li v-for="(a, i) in pendingAttachments" :key="i"
                    class="flex items-center justify-between rounded-md bg-gray-50 px-3 py-1.5 text-xs">
                    <span class="font-medium text-gray-700">{{ a.name }} ({{ formatSize(a.size) }})</span>
                    <div class="flex items-center gap-2">
                        <span v-if="a.status === 'uploading'" class="font-medium text-blue-600">Uploading…</span>
                        <span v-else-if="a.status === 'done'" class="inline-flex items-center gap-1 font-medium text-emerald-700">
                            <Icon name="check" /> Ready
                        </span>
                        <span v-else-if="a.status === 'error'" class="inline-flex items-center gap-1 font-medium text-red-700">
                            <Icon name="alert" /> {{ a.error }}
                        </span>
                        <button type="button" @click="removePending(i, a.attachmentId)"
                            class="text-red-700 hover:text-red-800" aria-label="Remove attachment">&times;</button>
                    </div>
                </li>
            </ul>

            <!-- Submit -->
            <div class="mt-3 flex items-center justify-between">
                <p class="text-xs text-gray-500">@ to mention someone</p>
                <button type="submit" :disabled="submitting || (!body.trim() && pendingAttachments.length === 0)"
                    class="rcmi-button-primary px-4 py-2 text-sm disabled:opacity-50">
                    {{ submitting ? 'Posting…' : submitLabel }}
                </button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { ref, computed, nextTick } from 'vue';
import { api } from '../api.js';
import MentionAutocomplete from './MentionAutocomplete.vue';
import Icon from './Icon.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    ticketId:         { type: Number, required: true },
    replyTo:          { type: Object, default: null },
    mentionableUsers: { type: Array, default: () => [] },
});
const emit = defineEmits(['posted', 'cancel-reply']);

const toast = useToast();
const body = ref('');
const submitting = ref(false);
const pendingAttachments = ref([]);
const textarea = ref(null);
const commentFileInput = ref(null);

const mentionOpen = ref(false);
const mentionQuery = ref('');
const mentionStart = ref(-1);

const submitLabel = computed(() => props.replyTo ? 'Reply' : 'Comment');
const placeholder = computed(() => props.replyTo
    ? `Reply to ${props.replyTo.user_name}…`
    : 'Write a comment…'
);

function onInput() {
    const text = body.value;
    const cursor = textarea.value?.selectionStart || 0;

    let atIndex = -1;
    for (let i = cursor - 1; i >= 0; i--) {
        if (text[i] === '@' && (i === 0 || /\s/.test(text[i - 1]))) {
            atIndex = i;
            break;
        }
        if (text[i] === '\n' || text[i] === ' ') {
            break;
        }
    }

    if (atIndex >= 0) {
        const query = text.substring(atIndex + 1, cursor);
        if (!/[\s@]/.test(query) || query.split(/\s/).length <= 3) {
            mentionStart.value = atIndex;
            mentionQuery.value = query;
            mentionOpen.value = true;
            return;
        }
    }

    mentionOpen.value = false;
    mentionStart.value = -1;
}

function onKeydown(e) {
    if (mentionOpen.value && (e.key === 'Escape')) {
        mentionOpen.value = false;
        e.preventDefault();
    }
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        e.preventDefault();
        submit();
    }
}

function insertMention(user) {
    const cursor = textarea.value?.selectionStart || 0;
    const before = body.value.substring(0, mentionStart.value);
    const after = body.value.substring(cursor);
    body.value = `${before}@${user.display_name} ${after}`;
    mentionOpen.value = false;
    mentionStart.value = -1;

    nextTick(() => {
        const newCursor = before.length + user.display_name.length + 2;
        textarea.value?.focus();
        textarea.value?.setSelectionRange(newCursor, newCursor);
    });
}

function onFilesSelected(e) {
    const files = Array.from(e.target.files);
    files.forEach(uploadAttachment);
    e.target.value = '';
}

async function uploadAttachment(file) {
    if (file.size > 10 * 1024 * 1024) {
        pendingAttachments.value.push({ file, name: file.name, size: file.size, status: 'error', error: 'Exceeds 10MB', attachmentId: null });
        return;
    }

    const entry = { file, name: file.name, size: file.size, status: 'uploading', error: null, attachmentId: null };
    pendingAttachments.value.push(entry);

    try {
        const formData = new FormData();
        formData.append('file', file);

        const config = window.rcmiTickets || {};
        const res = await fetch(`${config.apiBase}/tickets/${props.ticketId}/attachments`, {
            method: 'POST',
            headers: { 'X-WP-Nonce': config.nonce },
            credentials: 'same-origin',
            body: formData,
        });

        if (!res.ok) {
            const data = await res.json().catch(() => null);
            throw new Error(data?.message || 'Upload failed');
        }

        const data = await res.json();
        entry.status = 'done';
        entry.attachmentId = data.id;
    } catch (e) {
        entry.status = 'error';
        entry.error = e.message;
    }
}

async function removePending(index, attachmentId) {
    if (attachmentId) {
        try {
            await api(`/attachments/${attachmentId}`, { method: 'DELETE' });
        } catch (e) { /* silent */ }
    }
    pendingAttachments.value.splice(index, 1);
}

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

async function submit() {
    const trimmed = body.value.trim();
    if (!trimmed && pendingAttachments.value.length === 0) return;

    submitting.value = true;
    try {
        const attachmentIds = pendingAttachments.value
            .filter(a => a.status === 'done' && a.attachmentId)
            .map(a => a.attachmentId);

        const payload = {
            body: body.value,
            ...(props.replyTo ? { parent_id: props.replyTo.id } : {}),
            ...(attachmentIds.length ? { attachment_ids: attachmentIds } : {}),
        };

        const comment = await api(`/tickets/${props.ticketId}/comments`, {
            method: 'POST',
            body: payload,
        });

        body.value = '';
        pendingAttachments.value = [];
        emit('posted', comment);
    } catch (e) {
        console.error('Failed to post comment:', e);
        toast.error('Failed to post comment');
    } finally {
        submitting.value = false;
    }
}
</script>
