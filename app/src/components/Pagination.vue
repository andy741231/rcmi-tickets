<template>
    <div v-if="totalPages > 1" class="flex flex-col items-center gap-3 border-t border-gray-200 px-4 py-3 sm:flex-row sm:justify-between sm:px-6">
        <p class="text-sm text-gray-700">
            Showing <span class="font-semibold">{{ (page - 1) * perPage + 1 }}</span>
            to <span class="font-semibold">{{ Math.min(page * perPage, total) }}</span>
            of <span class="font-semibold">{{ total }}</span> results
        </p>
        <nav class="isolate inline-flex -space-x-px rounded-md shadow-sm" aria-label="Pagination">
            <button @click="go(page - 1)" :disabled="page <= 1"
                class="relative inline-flex items-center rounded-l-md border border-gray-300 bg-white px-2.5 py-2 text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                aria-label="Previous page">
                <Icon name="chevron-left" />
            </button>
            <template v-for="(p, i) in visiblePages" :key="i">
                <span v-if="p === '...'" class="relative inline-flex items-center border border-gray-300 bg-white px-3 py-2 text-sm text-gray-400 sm:px-4">…</span>
                <button v-else @click="go(p)"
                    :class="p === page ? 'z-10 border-red-500 bg-red-50 text-red-700' : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'"
                    class="relative inline-flex items-center border px-3 py-2 text-sm font-semibold sm:px-4"
                    :aria-current="p === page ? 'page' : undefined">
                    {{ p }}
                </button>
            </template>
            <button @click="go(page + 1)" :disabled="page >= totalPages"
                class="relative inline-flex items-center rounded-r-md border border-gray-300 bg-white px-2.5 py-2 text-gray-500 hover:bg-gray-50 disabled:opacity-50"
                aria-label="Next page">
                <Icon name="chevron-right" />
            </button>
        </nav>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    page:       { type: Number, required: true },
    perPage:    { type: Number, required: true },
    total:      { type: Number, required: true },
    totalPages: { type: Number, required: true },
});
const emit = defineEmits(['change']);

function go(p) {
    if (p >= 1 && p <= props.totalPages && p !== props.page) {
        emit('change', p);
    }
}

const visiblePages = computed(() => {
    const pages = [];
    const tp = props.totalPages;
    const cur = props.page;

    if (tp <= 7) {
        for (let i = 1; i <= tp; i++) pages.push(i);
        return pages;
    }

    // Always show first page
    pages.push(1);

    if (cur > 3) pages.push('...');

    // Show pages around current
    const start = Math.max(2, cur - 1);
    const end = Math.min(tp - 1, cur + 1);
    for (let i = start; i <= end; i++) pages.push(i);

    if (cur < tp - 2) pages.push('...');

    // Always show last page
    pages.push(tp);

    return pages;
});
</script>
