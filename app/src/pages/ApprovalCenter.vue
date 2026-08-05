<template>
    <div class="mx-auto max-w-4xl">
        <!-- Header -->
        <div class="mb-6">
            <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">Approvals</span>
            </nav>
            <h2 class="text-xl font-bold text-gray-900">Awaiting Your Approval</h2>
            <p class="mt-1 text-sm text-gray-600">Tickets where you are the current-step approver.</p>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="rcmi-card space-y-3 p-6" aria-busy="true">
            <div v-for="i in 3" :key="i" class="h-20 w-full animate-pulse rounded bg-gray-100"></div>
        </div>

        <!-- Empty -->
        <div v-else-if="tickets.length === 0" class="rcmi-card p-12 text-center">
            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                <Icon name="check-circle" />
            </div>
            <h3 class="mt-4 text-lg font-semibold text-gray-700">All caught up</h3>
            <p class="mt-1 text-sm text-gray-500">No tickets are waiting for your approval right now.</p>
        </div>

        <!-- Ticket list -->
        <ul v-else class="space-y-3">
            <li v-for="t in tickets" :key="t.id"
                class="rcmi-card flex flex-col gap-3 p-5 transition hover:shadow-md sm:flex-row sm:items-center sm:justify-between">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-gray-400">#{{ t.id }}</span>
                        <h3 class="truncate text-base font-bold text-gray-900">{{ t.title }}</h3>
                    </div>
                    <div class="mt-1 flex flex-wrap items-center gap-3 text-xs text-gray-500">
                        <span>Submitted by <strong class="text-gray-700">{{ t.author_name }}</strong></span>
                        <span aria-hidden="true">·</span>
                        <span>{{ formatDate(t.created_at) }}</span>
                        <span aria-hidden="true">·</span>
                        <span class="inline-flex items-center gap-1 font-semibold text-amber-700">
                            <Icon name="clock" /> Step {{ t.pending_step_order }}
                        </span>
                        <span v-if="t.approval_chain" aria-hidden="true">·</span>
                        <span v-if="t.approval_chain" class="text-gray-600">{{ t.approval_chain.name }}</span>
                    </div>
                </div>
                <router-link :to="`/ticket/${t.id}`"
                    class="rcmi-button-primary inline-flex items-center gap-1.5 px-4 py-2 text-sm sm:flex-shrink-0">
                    <Icon name="arrow-right" /> Open
                </router-link>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { api } from '../api.js';
import Icon from '../components/Icon.vue';

const tickets = ref([]);
const loading = ref(true);

function formatDate(d) {
    if (!d) return '';
    return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

async function load() {
    try {
        const data = await api('/approvals/pending');
        tickets.value = data.items || [];
    } catch (e) {
        console.error('Failed to load pending approvals', e);
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>
