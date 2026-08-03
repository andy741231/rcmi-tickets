<template>
    <div v-if="visible && filteredUsers.length > 0" class="absolute z-20 rounded-lg border border-gray-200 bg-white py-1 shadow-lg"
        :style="position" role="listbox" aria-label="Mentionable users">
        <button v-for="(u, i) in filteredUsers" :key="u.id" type="button"
            @click="select(u)"
            @mouseenter="highlighted = i"
            :class="i === highlighted ? 'bg-red-50 text-red-700' : 'text-gray-700'"
            class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-red-50"
            role="option" :aria-selected="i === highlighted">
            <span class="font-semibold">{{ u.display_name }}</span>
            <span class="text-xs text-gray-500">{{ u.user_login }}</span>
        </button>
    </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    visible: { type: Boolean, default: false },
    query:   { type: String, default: '' },
    users:   { type: Array, default: () => [] },
    position: { type: Object, default: () => ({ left: '0px', top: '100%' }) },
});
const emit = defineEmits(['select', 'close']);

const highlighted = ref(0);

const filteredUsers = computed(() => {
    if (!props.query) return props.users.slice(0, 8);
    const q = props.query.toLowerCase();
    return props.users.filter(u =>
        u.display_name.toLowerCase().includes(q) ||
        u.user_login.toLowerCase().includes(q) ||
        (u.first_name && u.first_name.toLowerCase().includes(q)) ||
        (u.last_name && u.last_name.toLowerCase().includes(q))
    ).slice(0, 8);
});

watch(() => props.query, () => {
    highlighted.value = 0;
});

function select(user) {
    emit('select', user);
}
</script>
