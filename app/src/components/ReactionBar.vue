<template>
    <div class="flex flex-wrap items-center gap-1">
        <!-- Existing reactions -->
        <button v-for="(r, type) in reactions" :key="type"
            @click="toggle(type)"
            :class="hasReacted(type) ? 'border-red-300 bg-red-50 text-red-700' : 'border-gray-200 bg-gray-50 text-gray-600 hover:bg-gray-100'"
            class="inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium transition"
            :aria-label="hasReacted(type) ? 'Remove ' + type + ' reaction' : 'Add ' + type + ' reaction'">
            <span class="text-sm">{{ type }}</span>
            <span>{{ r.count }}</span>
        </button>

        <!-- Add reaction -->
        <div ref="pickerRoot" class="relative">
            <button @click="showPicker = !showPicker" ref="pickerBtn" aria-label="Add reaction"
                class="inline-flex items-center rounded-full border border-gray-200 bg-gray-50 px-2.5 py-0.5 text-xs text-gray-500 hover:bg-gray-100">
                <Icon name="plus" />
            </button>
            <div v-if="showPicker"
                class="absolute bottom-full left-0 z-10 mb-1 flex gap-1 rounded-lg border border-gray-200 bg-white p-2 shadow-lg">
                <button v-for="emoji in emojis" :key="emoji" @click="toggle(emoji); showPicker = false"
                    class="rounded px-1.5 py-1 text-lg hover:bg-gray-100" :aria-label="'React with ' + emoji">
                    {{ emoji }}
                </button>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { api } from '../api.js';
import Icon from './Icon.vue';

const props = defineProps({
    commentId:     { type: Number, required: true },
    reactions:     { type: Object, default: () => ({}) },
    currentUserId: { type: Number, required: true },
});
const emit = defineEmits(['update:reactions']);

const showPicker = ref(false);
const pickerRoot = ref(null);
const emojis = ['👍', '👎', '❤️', '🎉', '😄', '😕'];

function hasReacted(type) {
    const r = props.reactions[type];
    return r && r.user_ids && r.user_ids.includes(props.currentUserId);
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
    if (pickerRoot.value && !pickerRoot.value.contains(e.target)) {
        showPicker.value = false;
    }
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
