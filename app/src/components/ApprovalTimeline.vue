<template>
    <div class="space-y-1">
        <!-- Chain header -->
        <div v-if="chain" class="mb-3 flex flex-wrap items-center gap-2 text-xs text-gray-500">
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
                        step.status === 'rejected' ? 'border-red-100 bg-red-50/50' : 'border-gray-100 bg-gray-50/50']">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold" :class="step.status === 'upcoming' ? 'text-gray-500' : 'text-gray-800'">
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

        <!-- Assignee row — appears below the approval steps. The assignee
             only becomes active once all approvers have approved (i.e. the
             ticket transitions to In Progress). Before that, the row is
             "Waiting". After completion, it shows "Completed". Reads the
             live ticket assignee list so it stays in sync when a manager
             changes assignees from the Details card. -->
        <div v-if="chain" class="space-y-4">
            <ol class="relative space-y-4 border-l-2 border-gray-100 pl-6">
                <li class="relative">
                    <span :class="['absolute -left-[1.97rem] flex h-5 w-5 items-center justify-center rounded-full text-white ring-4 ring-white',
                        assigneeState === 'active' ? 'bg-indigo-500' :
                        assigneeState === 'completed' ? 'bg-emerald-500' : 'bg-gray-300']">
                        <Icon v-if="assigneeState === 'completed'" name="check" />
                        <Icon v-else name="user-check" />
                    </span>
                    <div :class="['rounded-md border px-3 py-2.5',
                        assigneeState === 'active' ? 'border-indigo-100 bg-indigo-50/50' :
                        assigneeState === 'completed' ? 'border-emerald-100 bg-emerald-50/50' : 'border-gray-100 bg-gray-50/50']">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-800">Assignee</p>
                            <span :class="['rcmi-timeline-status',
                                assigneeState === 'active' ? 'text-indigo-700 bg-indigo-100' :
                                assigneeState === 'completed' ? 'text-emerald-700 bg-emerald-100' : 'text-gray-500 bg-gray-100']">
                                {{ assigneeStateLabel }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500">
                            <strong class="text-gray-700">{{ assigneeNames || 'Unassigned' }}</strong>
                        </p>
                    </div>
                </li>
            </ol>
        </div>

        <!-- Post-approval status entries (In Progress, Completed, etc.) -->
        <div v-if="postApprovalEntries.length > 0" class="space-y-4">
            <ol class="relative space-y-4 border-l-2 border-gray-100 pl-6">
                <li v-for="entry in postApprovalEntries" :key="'status-' + entry.id" class="relative">
                    <!-- Status dot -->
                    <span :class="['absolute -left-[1.97rem] flex h-5 w-5 items-center justify-center rounded-full ring-4 ring-white',
                        entry.new_status === 'Completed' ? 'bg-emerald-500 text-white' :
                        entry.new_status === 'In Progress' ? 'bg-blue-500 text-white' : 'bg-gray-400 text-white']">
                        <Icon v-if="entry.new_status === 'Completed'" name="check" />
                        <Icon v-else-if="entry.new_status === 'In Progress'" name="arrow-right" />
                        <span v-else class="text-[10px] font-bold">·</span>
                    </span>

                    <div :class="['rounded-md border px-3 py-2.5',
                        entry.new_status === 'Completed' ? 'border-emerald-100 bg-emerald-50/50' :
                        entry.new_status === 'In Progress' ? 'border-blue-100 bg-blue-50/50' : 'border-gray-100']">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-800">{{ entry.new_status }}</p>
                            <span :class="['rcmi-timeline-status', statusEntryClass(entry.new_status)]">
                                {{ entry.new_status }}
                            </span>
                        </div>
                        <p class="mt-0.5 text-xs text-gray-500">
                            By:
                            <strong class="text-gray-700">{{ entry.changed_by_name || assigneeName(entry.changed_by) || '—' }}</strong>
                        </p>
                        <p v-if="entry.changed_at" class="mt-0.5 text-xs text-gray-400">
                            {{ formatDateTime(entry.changed_at) }}
                        </p>
                        <p v-if="entry.message" class="mt-2 rounded bg-white/70 px-2 py-1.5 text-xs text-gray-700 whitespace-pre-wrap">
                            "{{ entry.message }}"
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
    statusHistory: { type: Array, default: () => [] },
    assignees: { type: Array, default: () => [] },
});

// Current assignee(s) — reads the live ticket assignee list, so it stays
// in sync when a manager changes assignees from the Details card. The
// chain's default assignee is applied to this list at chain init.
const assigneeNames = computed(() => {
    return (props.assignees || []).map(a => a.display_name).join(', ');
});

// Assignee workflow state. The assignee only becomes active once all
// approvers in the latest cycle have approved — before that they're
// waiting; after the ticket is marked Completed they're done.
const assigneeState = computed(() => {
    // Completed takes precedence if a Completed entry exists in history.
    if ((props.statusHistory || []).some(e => e.new_status === 'Completed')) {
        return 'completed';
    }
    // Flatten the latest cycle's merged steps.
    const groups = cycleGroups.value;
    if (!groups.length) return 'waiting';
    const latest = groups[groups.length - 1];
    const steps = latest.steps || [];
    // If any step is still pending or upcoming, the assignee isn't active yet.
    const hasPending = steps.some(s => s.status === 'pending' || s.status === 'upcoming');
    if (hasPending) return 'waiting';
    // If any step was rejected, the chain didn't complete — assignee waits.
    if (steps.some(s => s.status === 'rejected')) return 'waiting';
    // All approved → assignee is active.
    return 'active';
});
const assigneeStateLabel = computed(() => {
    return { waiting: 'Waiting', active: 'Current', completed: 'Completed' }[assigneeState.value] || 'Waiting';
});

// Group steps by cycle, sorted by cycle then sort_order.
// The latest cycle is merged with the chain's CURRENT definition so the
// complete timeline always shows: every chain step appears (even ones
// added to the chain after the ticket entered it), the current step is
// labeled "Pending", and not-yet-reached steps are labeled "Upcoming".
// Older cycles render their rows as-is (historical record).
const cycleGroups = computed(() => {
    const groups = {};
    for (const step of props.steps) {
        const cycle = step.cycle || 1;
        if (!groups[cycle]) groups[cycle] = { cycle, steps: [] };
        groups[cycle].steps.push(step);
    }
    const sorted = Object.values(groups).sort((a, b) => a.cycle - b.cycle);
    if (!sorted.length) return sorted;
    const maxCycle = sorted[sorted.length - 1].cycle;
    const chainSteps = (props.chain && Array.isArray(props.chain.steps) ? [...props.chain.steps] : [])
        .sort((a, b) => a.sort_order - b.sort_order);

    return sorted.map(group => {
        if (group.cycle !== maxCycle || !chainSteps.length) {
            return group; // historical cycles: rows as-is
        }

        const rowsByOrder = {};
        for (const s of group.steps) rowsByOrder[Number(s.sort_order)] = s;

        // Current step = first pending row in this cycle
        const currentRow = group.steps.find(s => s.status === 'pending');
        const currentOrder = currentRow ? Number(currentRow.sort_order) : null;

        const merged = chainSteps.map(cs => {
            const row = rowsByOrder[cs.sort_order];
            if (row) {
                const isCurrent = row.status === 'pending' && Number(row.sort_order) === currentOrder;
                return {
                    ...row,
                    name: cs.name || row.name,
                    status: row.status === 'pending' && !isCurrent ? 'upcoming' : row.status,
                    approver_name: row.approver_name || cs.approver_name || '',
                    approver_role: row.approver_role || cs.approver_role,
                };
            }
            // Step has no approval row (added to the chain after entry)
            return {
                id: 'upcoming-' + cs.sort_order,
                sort_order: cs.sort_order,
                cycle: group.cycle,
                name: cs.name,
                status: 'upcoming',
                approver_name: cs.approver_name || '',
                approver_role: cs.approver_role,
                decided_at: null,
                comment: null,
            };
        });

        // Rows beyond the current chain definition (steps removed from the
        // chain after entry) — keep them as historical entries.
        for (const row of group.steps) {
            if (!chainSteps.some(cs => Number(cs.sort_order) === Number(row.sort_order))) {
                merged.push(row);
            }
        }
        merged.sort((a, b) => a.sort_order - b.sort_order);
        return { cycle: group.cycle, steps: merged };
    });
});

// Post-approval status entries: In Progress, Completed (and any other
// non-approval-chain statuses). Approval/rejection transitions are already
// represented by the approval step rows, so we filter those out.
const postApprovalEntries = computed(() => {
    const approvalStatuses = ['Approved', 'Rejected', 'Rejected: Pending Revision', 'Pending Approval', 'Received'];
    return (props.statusHistory || []).filter(e => !approvalStatuses.includes(e.new_status));
});

function assigneeName(userId) {
    if (!userId) return '';
    const a = props.assignees.find(a => a.id === userId);
    return a ? a.display_name : '';
}

function statusLabel(s) {
    return { approved: 'Approved', rejected: 'Rejected', pending: 'Pending', upcoming: 'Upcoming', skipped: 'Skipped' }[s] || s;
}
function statusClass(s) {
    return {
        approved: 'text-emerald-700 bg-emerald-100',
        rejected: 'text-red-700 bg-red-100',
        pending: 'text-amber-700 bg-amber-100',
        upcoming: 'text-gray-500 bg-gray-100',
        skipped: 'text-gray-500 bg-gray-100',
    }[s] || 'text-gray-600 bg-gray-100';
}
function statusEntryClass(s) {
    return {
        'Completed': 'text-emerald-700 bg-emerald-100',
        'In Progress': 'text-blue-700 bg-blue-100',
    }[s] || 'text-gray-600 bg-gray-100';
}
function formatDateTime(d) {
    if (!d) return '';
    return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}
</script>
