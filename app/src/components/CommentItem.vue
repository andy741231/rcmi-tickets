<template>
    <div class="rounded-lg border" :class="comment.pinned ? 'border-amber-200 bg-amber-50/60' : 'border-gray-200 bg-white'">
        <div class="p-4">
            <!-- Header -->
            <div class="mb-3 flex items-start justify-between gap-2">
                <div class="flex items-center gap-2.5">
                    <span class="rcmi-avatar h-8 w-8">
                        {{ initials(comment.user_name) }}
                    </span>
                    <div class="flex flex-col">
                        <div class="flex items-center gap-2">
                            <span v-if="comment.pinned" class="inline-flex items-center gap-1 rounded-full bg-amber-200 px-2 py-0.5 text-xs font-semibold text-amber-900">
                                <Icon name="pin" /> Pinned
                            </span>
                            <span class="text-sm font-semibold text-gray-900">{{ comment.user_name }}</span>
                        </div>
                        <div class="flex items-center gap-2 text-xs text-gray-500">
                            <span>{{ formatTime(comment.created_at) }}</span>
                            <span v-if="isEdited" class="italic">(edited)</span>
                        </div>
                    </div>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-1">
                    <button v-if="canPin" @click="$emit('pin', comment.id)" :aria-label="comment.pinned ? 'Unpin comment' : 'Pin comment'"
                        class="rcmi-button-ghost rounded-md px-2 py-1 text-xs">
                        {{ comment.pinned ? 'Unpin' : 'Pin' }}
                    </button>
                    <button v-if="canEdit" @click="startEdit" aria-label="Edit comment"
                        class="rcmi-button-ghost rounded-md px-2 py-1 text-xs">
                        Edit
                    </button>
                    <button v-if="canDelete" @click="confirmDelete = true" aria-label="Delete comment"
                        class="rounded-md px-2 py-1 text-xs font-semibold text-red-700 hover:bg-red-50">
                        Delete
                    </button>
                </div>
            </div>

            <!-- Edit mode -->
            <div v-if="editing">
                <textarea v-model="editBody" rows="3"
                    class="rcmi-input resize-y" aria-label="Edit comment body"></textarea>
                <div class="mt-2 flex gap-2">
                    <button @click="saveEdit" :disabled="editSaving"
                        class="rcmi-button-primary px-3 py-1.5 text-xs disabled:opacity-50">
                        {{ editSaving ? 'Saving…' : 'Save' }}
                    </button>
                    <button @click="editing = false" class="rcmi-button-secondary px-3 py-1.5 text-xs">Cancel</button>
                </div>
            </div>

            <!-- Body -->
            <div v-else class="prose-sm whitespace-pre-wrap text-sm leading-relaxed text-gray-700" v-html="comment.body"></div>

            <!-- Attachments -->
            <div v-if="comment.attachments && comment.attachments.length > 0" class="mt-3">
                <ul class="space-y-1.5">
                    <li v-for="a in comment.attachments" :key="a.id" class="flex items-center gap-2 text-xs">
                        <span class="flex h-6 w-6 items-center justify-center rounded bg-gray-100 text-gray-500">
                            <Icon name="paperclip" />
                        </span>
                        <a :href="downloadUrl(a.id)" class="font-medium text-gray-700 hover:text-red-700 hover:underline">{{ a.original_name }}</a>
                        <span class="text-gray-500">({{ formatSize(a.size) }})</span>
                    </li>
                </ul>
            </div>

            <!-- Mentions -->
            <div v-if="comment.mentions && comment.mentions.length > 0" class="mt-2 flex flex-wrap gap-1">
                <span v-for="uid in comment.mentions" :key="uid"
                    class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">
                    @{{ userName(uid) }}
                </span>
            </div>

            <!-- Reactions -->
            <div class="mt-3">
                <ReactionBar :comment-id="comment.id" :reactions="comment.reactions || {}" :current-user-id="currentUserId"
                    :user-names="userNames"
                    @update:reactions="$emit('update-reactions', comment.id, $event)" />
            </div>
        </div>

        <!-- Reply button -->
        <div class="border-t border-gray-100 px-4 py-2">
            <button @click="$emit('reply', comment)"
                class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-600 hover:text-red-700">
                <Icon name="reply" /> Reply
            </button>
        </div>

        <!-- Nested replies (1 level of nesting UI) -->
        <div v-if="comment.replies && comment.replies.length > 0" class="border-t border-gray-100 bg-gray-50/50 pl-4 pr-4 pb-3 sm:pl-8">
            <div class="mt-3 space-y-3 border-l-2 border-gray-200 pl-3 sm:pl-4">
                <CommentItem v-for="reply in comment.replies" :key="reply.id"
                    :comment="reply"
                    :depth="depth + 1"
                    :current-user-id="currentUserId"
                    :can-pin="canPin"
                    :can-manage="canManage"
                    :user-names="userNames"
                    @pin="$emit('pin', $event)"
                    @edit="$emit('edit', $event)"
                    @delete="$emit('delete', $event)"
                    @reply="$emit('reply', $event)"
                    @update-reactions="(id, reactions) => $emit('update-reactions', id, reactions)"
                />
            </div>
        </div>

        <!-- Delete confirmation modal -->
        <Modal v-if="confirmDelete" @close="confirmDelete = false" title="Delete comment">
            <p class="text-sm text-gray-700">
                Delete this comment{{ comment.replies && comment.replies.length ? ' and its replies' : '' }}?
            </p>
            <template #footer>
                <button @click="$emit('delete', comment.id); confirmDelete = false"
                    class="rcmi-button-primary px-4 py-2 text-sm">Yes, delete</button>
                <button @click="confirmDelete = false"
                    class="rcmi-button-secondary px-4 py-2 text-sm">Cancel</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { api } from '../api.js';
import ReactionBar from './ReactionBar.vue';
import Modal from './Modal.vue';
import Icon from './Icon.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    comment:       { type: Object, required: true },
    depth:         { type: Number, default: 0 },
    currentUserId: { type: Number, required: true },
    canPin:        { type: Boolean, default: false },
    canManage:     { type: Boolean, default: false },
    userNames:     { type: Object, default: () => ({}) },
});
const emit = defineEmits(['pin', 'edit', 'delete', 'reply', 'update-reactions']);

const toast = useToast();
const editing = ref(false);
const editBody = ref('');
const editSaving = ref(false);
const confirmDelete = ref(false);

const isEdited = computed(() => props.comment.created_at !== props.comment.updated_at);

const canEdit = computed(() =>
    props.currentUserId === props.comment.user_id || props.canManage
);
const canDelete = computed(() =>
    props.currentUserId === props.comment.user_id || props.canManage
);

function startEdit() {
    editBody.value = props.comment.body;
    editing.value = true;
}

async function saveEdit() {
    if (!editBody.value.trim()) return;
    editSaving.value = true;
    try {
        const updated = await api(`/comments/${props.comment.id}`, {
            method: 'PUT',
            body: { body: editBody.value },
        });
        emit('edit', { id: props.comment.id, body: updated.body, updated_at: updated.updated_at });
        editing.value = false;
        toast.success('Comment saved');
    } catch (e) {
        console.error('Failed to save comment:', e);
        toast.error('Failed to save comment');
    } finally {
        editSaving.value = false;
    }
}

function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function formatTime(d) {
    if (!d) return '';
    return new Date(d).toLocaleString('en-US', {
        month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit',
    });
}

function formatSize(bytes) {
    if (!bytes) return '';
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function downloadUrl(id) {
    const config = window.rcmiTickets || {};
    return `${config.apiBase}/attachments/${id}/download`;
}

function userName(uid) {
    return props.userNames[uid] || `User #${uid}`;
}
</script>
