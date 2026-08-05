<template>
    <div class="rcmi-reaction-toolbar" aria-label="Comment reactions">
        <span class="rcmi-reaction-toolbar-label">React</span>
        <div class="flex flex-wrap items-center gap-1.5">
            <!-- Existing reactions -->
            <div v-for="(r, type) in reactions" :key="type" class="relative" @mouseenter="hoveredType = type" @mouseleave="hoveredType = null">
                <button @click="toggle(type)"
                    :class="hasReacted(type) ? 'border-red-300 bg-red-50 text-red-700 shadow-sm' : 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50'"
                    class="inline-flex min-h-7 items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition focus:outline-none focus:ring-2 focus:ring-red-200"
                    :aria-label="hasReacted(type) ? 'Remove ' + type + ' reaction' : 'Add ' + type + ' reaction'">
                    <span class="text-base leading-none" aria-hidden="true">{{ type }}</span>
                    <span>{{ r.count }}</span>
                </button>
                <!-- Tooltip showing who reacted -->
                <div v-if="hoveredType === type && r.user_ids && r.user_ids.length > 0"
                    class="absolute bottom-full left-0 z-20 mb-1 max-w-[12rem] rounded-lg border border-gray-200 bg-gray-900 px-3 py-2 text-xs text-white shadow-lg"
                    role="tooltip">
                    <p class="font-semibold mb-0.5">{{ r.count }} {{ r.count === 1 ? 'person' : 'people' }} reacted with {{ type }}</p>
                    <p class="text-gray-300 leading-snug">{{ namesFor(type, r.user_ids) }}</p>
                </div>
            </div>

            <!-- Add/Update reaction -->
            <div ref="pickerRoot" class="relative">
                <button @click="showPicker = !showPicker" ref="pickerBtn"
                    :aria-label="hasAnyReaction ? 'Update reaction' : 'Add reaction'"
                    :aria-expanded="showPicker"
                    class="inline-flex min-h-7 items-center gap-1.5 rounded-full border border-dashed border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-500 transition hover:border-red-300 hover:bg-red-50 hover:text-red-700 focus:outline-none focus:ring-2 focus:ring-red-200">
                    <Icon name="plus" /> <span>{{ hasAnyReaction ? 'Update reaction' : 'Add reaction' }}</span>
                </button>
                <div v-if="showPicker"
                    class="absolute bottom-full left-0 z-10 mb-2 flex max-w-[min(18rem,calc(100vw-2rem))] flex-wrap gap-1 rounded-xl border border-gray-200 bg-white p-2 shadow-xl"
                    role="menu" aria-label="Choose a reaction">
                    <button v-for="emoji in emojis" :key="emoji" @click="toggle(emoji); showPicker = false"
                        class="flex h-9 w-9 items-center justify-center rounded-lg text-lg transition hover:bg-red-50 focus:bg-red-50 focus:outline-none"
                        :aria-label="'React with ' + emoji" role="menuitem">
                        {{ emoji }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { api } from '../api.js';
import Icon from './Icon.vue';

const props = defineProps({
    commentId:     { type: Number, required: true },
    reactions:     { type: Object, default: () => ({}) },
    currentUserId: { type: Number, required: true },
    userNames:     { type: Object, default: () => ({}) },
});
const emit = defineEmits(['update:reactions']);

const showPicker = ref(false);
const pickerRoot = ref(null);
const hoveredType = ref(null);
const emojis = ['👍', '👎', '❤️', '🎉', '😄', '😕'];

function hasReacted(type) {
    const r = props.reactions[type];
    return r && r.user_ids && r.user_ids.includes(props.currentUserId);
}

const hasAnyReaction = computed(() => {
    return Object.values(props.reactions).some(
        r => r.user_ids && r.user_ids.includes(props.currentUserId)
    );
});

function namesFor(type, userIds) {
    const names = (userIds || []).map(uid => {
        const name = props.userNames[uid];
        if (name) return name;
        if (uid === props.currentUserId) return 'You';
        return `User #${uid}`;
    });
    if (names.length <= 3) return names.join(', ');
    return names.slice(0, 3).join(', ') + ` and ${names.length - 3} more`;
}

async function toggle(type) {
    try {
        const data = await api(`/comments/${props.commentId}/reactions`, {
            method: 'POST',
            body: { type },
        });
        emit('update:reactions', data.reactions);
    } catch (e) {
        console.error('Failed to toggle reaction:', e);
    }
}

function onDocClick(e) {
    if (pickerRoot.value && !pickerRoot.value.contains(e.target)) showPicker.value = false;
}

function onKeydown(e) {
    if (e.key === 'Escape') showPicker.value = false;
}

onMounted(() => {
    document.addEventListener('click', onDocClick);
    document.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocClick);
    document.removeEventListener('keydown', onKeydown);
});
</script>
