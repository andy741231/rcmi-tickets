<template>
    <div>
        <!-- Comments header -->
        <div class="mb-5 flex items-center justify-between">
            <h3 class="rcmi-section-label">
                Comments <span v-if="commentCount > 0" class="ml-1 text-gray-500">({{ commentCount }})</span>
            </h3>
        </div>

        <!-- Loading skeleton -->
        <div v-if="loading" class="space-y-3" aria-busy="true" aria-live="polite">
            <div v-for="n in 2" :key="n" class="rounded-lg border border-gray-100 p-4">
                <div class="flex items-center gap-2">
                    <div class="h-7 w-7 animate-pulse rounded-full bg-gray-100"></div>
                    <div class="h-3 w-24 animate-pulse rounded bg-gray-100"></div>
                </div>
                <div class="mt-3 h-3 w-full animate-pulse rounded bg-gray-100"></div>
                <div class="mt-2 h-3 w-4/5 animate-pulse rounded bg-gray-100"></div>
            </div>
        </div>

        <template v-else>
            <!-- Comment list -->
            <div class="space-y-3">
                <CommentItem v-for="comment in comments" :key="comment.id"
                    :comment="comment"
                    :current-user-id="currentUserId"
                    :can-pin="canManage"
                    :can-manage="canManage"
                    :user-names="userNames"
                    @pin="onPin"
                    @edit="onEdit"
                    @delete="onDelete"
                    @reply="onReply"
                    @update-reactions="onUpdateReactions"
                />

                <div v-if="comments.length === 0" class="rounded-lg border border-dashed border-gray-200 py-10 text-center">
                    <div class="mx-auto flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                        <Icon name="reply" />
                    </div>
                    <p class="mt-3 text-sm font-medium text-gray-600">No comments yet</p>
                    <p class="mt-1 text-xs text-gray-500">Be the first to start the conversation.</p>
                </div>
            </div>

            <!-- Reply composer -->
            <div v-if="replyTarget" class="mt-4">
                <CommentComposer
                    :ticket-id="ticketId"
                    :reply-to="replyTarget"
                    :mentionable-users="mentionableUsers"
                    :public-token="publicToken"
                    @posted="onPosted"
                    @cancel-reply="replyTarget = null"
                />
            </div>

            <!-- Main composer -->
            <div v-else class="mt-4">
                <CommentComposer
                    :ticket-id="ticketId"
                    :mentionable-users="mentionableUsers"
                    :public-token="publicToken"
                    @posted="onPosted"
                />
            </div>
        </template>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { api } from '../api.js';
import CommentItem from './CommentItem.vue';
import CommentComposer from './CommentComposer.vue';
import Icon from './Icon.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({
    ticketId:      { type: Number, required: true },
    currentUserId: { type: Number, required: true },
    canManage:     { type: Boolean, default: false },
    publicToken:   { type: String, default: '' },
});

const toast = useToast();
const comments = ref([]);
const loading = ref(true);
const replyTarget = ref(null);
const mentionableUsers = ref([]);

const commentCount = computed(() => {
    let count = 0;
    function countTree(items) {
        items.forEach(item => {
            count++;
            if (item.replies) countTree(item.replies);
        });
    }
    countTree(comments.value);
    return count;
});

const userNames = computed(() => {
    const map = {};
    function collect(items) {
        items.forEach(item => {
            map[item.user_id] = item.user_name;
            if (item.replies) collect(item.replies);
        });
    }
    collect(comments.value);
    mentionableUsers.value.forEach(u => { map[u.id] = u.display_name; });
    return map;
});

async function loadComments() {
    try {
        const params = new URLSearchParams();
        if (props.publicToken) params.set('token', props.publicToken);
        const path = props.publicToken
            ? `/public/tickets/${props.ticketId}/comments`
            : `/tickets/${props.ticketId}/comments`;
        const data = await api(path, { params });
        comments.value = data.items || [];
    } catch (e) {
        console.error('Failed to load comments:', e);
    } finally {
        loading.value = false;
    }
}

async function loadMentionableUsers() {
    // Public users don't have mentionable users
    if (props.publicToken) {
        mentionableUsers.value = [];
        return;
    }
    try {
        mentionableUsers.value = await api(`/tickets/${props.ticketId}/mentionable-users`);
    } catch (e) {
        console.error('Failed to load mentionable users:', e);
    }
}

function findComment(id, items = comments.value) {
    for (const item of items) {
        if (item.id === id) return item;
        if (item.replies) {
            const found = findComment(id, item.replies);
            if (found) return found;
        }
    }
    return null;
}

async function onPin(commentId) {
    try {
        await api(`/comments/${commentId}/pin`, { method: 'POST' });
        await loadComments();
        toast.success('Comment pin updated');
    } catch (e) {
        console.error('Failed to pin comment:', e);
        toast.error('Failed to update pin');
    }
}

function onEdit({ id, body, updated_at }) {
    const comment = findComment(id);
    if (comment) {
        comment.body = body;
        comment.updated_at = updated_at;
    }
}

async function onDelete(commentId) {
    try {
        await api(`/comments/${commentId}`, { method: 'DELETE' });
        await loadComments();
        toast.success('Comment deleted');
    } catch (e) {
        console.error('Failed to delete comment:', e);
        toast.error('Failed to delete comment');
    }
}

function onReply(comment) {
    replyTarget.value = comment;
}

function onUpdateReactions(commentId, reactions) {
    const comment = findComment(commentId);
    if (comment) {
        comment.reactions = reactions;
    }
}

async function onPosted(comment) {
    replyTarget.value = null;
    await loadComments();
    toast.success('Comment posted');
}

onMounted(() => {
    loadComments();
    loadMentionableUsers();
});
</script>
