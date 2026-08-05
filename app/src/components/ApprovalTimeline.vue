<template>
    <div class="space-y-1">
        <!-- Chain header -->
        <div v-if="chain" class="mb-3 flex items-center gap-2 text-xs text-gray-500">
            <Icon name="flow" />
            <span class="font-semibold">{{ chain.name }}</span>
            <span v-if="chain.on_reject" class="text-gray-400">· reject: {{ chain.on_reject }}</span>
        </div>

        <!-- Cycle groups -->
        <div v-for="(group, idx) in cycleGroups" :key="group.cycle" class="space-y-4">
            <!-- Cycle divider (only show for cycle 2+) -->
            <div v-if="group.cycle > 1" class="flex items-center gap-2 pt-2">
                <span class="h-px flex-1 bg-gray-200"></span>
                <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Resubmission {{ group.cycle }}</span>
                <span class="h-px flex-1 bg-gray-200"></span>
            </div>

            <!-- Steps -->
            <ol class="relative space-y-4 border-l-2 border-gray-100 pl-6">
                <li v-for="step in group.steps" :key="step.id" class="relative">
                    <!-- Status dot -->
                    <span :class="['absolute -left-[1.97rem] flex h-5 w-5 items-center justify-center rounded-full ring-4 ring-white',
                        step.status === 'approved' ? 'bg-emerald-500 text-white' :
                        step.status === 'rejected' ? 'bg-red-500 text-white' :
                        step.status === 'pending' ? 'bg-amber-400 text-white' : 'bg-gray-300 text-white']">
                        <Icon v-if="step.status === 'approved'" name="check" />
                        <Icon v-else-if="step.status === 'rejected'" name="x" />
                        <span v-else class="text-[10px] font-bold">{{ step.sort_order }}</span>
                    </span>

                    <div :class="['rounded-md border px-3 py-2.5',
                        step.status === 'pending' ? 'border-amber-200 bg-amber-50' :
                        step.status === 'approved' ? 'border-emerald-100 bg-emerald-50/50' :
                        step.status === 'rejected' ? 'border-red-100 bg-red-50/50' : 'border-gray-100']">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-800">
                                {{ step.name || ('Step ' + step.sort_order) }}
                            </p>
                            <span :class="['rcmi-timeline-status', statusClass(step.status)]">
                                {{ statusLabel(step.status) }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Approver:
                            <strong class="text-gray-700">{{ step.approver_name || step.approver_role || '—' }}</strong>
                        </p>
                        <p v-if="step.decided_at" class="mt-0.5 text-xs text-gray-400">
                            {{ formatDateTime(step.decided_at) }}
                            <span v-if="step.decided_by_name"> · by {{ step.decided_by_name }}</span>
                        </p>
                        <p v-if="step.comment" class="mt-2 rounded bg-white/70 px-2 py-1.5 text-xs text-gray-700 whitespace-pre-wrap">
                            "{{ step.comment }}"
                        </p>
                    </div>
                </li>
            </ol>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    steps: { type: Array, default: () => [] },
    chain: { type: Object, default: null },
});

// Group steps by cycle, sorted by cycle then sort_order
const cycleGroups = computed(() => {
    const groups = {};
    for (const step of props.steps) {
        const cycle = step.cycle || 1;
        if (!groups[cycle]) groups[cycle] = { cycle, steps: [] };
        groups[cycle].steps.push(step);
    }
    return Object.values(groups).sort((a, b) => a.cycle - b.cycle);
});

function statusLabel(s) {
    return { approved: 'Approved', rejected: 'Rejected', pending: 'Pending', skipped: 'Skipped' }[s] || s;
}
function statusClass(s) {
    return {
        approved: 'text-emerald-700 bg-emerald-100',
        rejected: 'text-red-700 bg-red-100',
        pending: 'text-amber-700 bg-amber-100',
        skipped: 'text-gray-500 bg-gray-100',
    }[s] || 'text-gray-600 bg-gray-100';
}
function formatDateTime(d) {
    if (!d) return '';
    return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}
</script>
