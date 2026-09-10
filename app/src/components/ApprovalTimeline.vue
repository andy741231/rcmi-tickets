<template>
    <div class="space-y-4">
        <!-- Chain header -->
        <div v-if="chain" class="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-3">
            <div class="flex min-w-0 items-center gap-3">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-slate-800 text-white">
                    <Icon name="flow" />
                </span>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-slate-800">{{ chain.name }}</p>
                    <p class="mt-0.5 text-xs text-slate-500">{{ approvalSummary }}</p>
                </div>
            </div>
            <span v-if="chain.on_reject" class="shrink-0 rounded-full bg-white px-2 py-1 text-[11px] font-medium text-slate-500 ring-1 ring-inset ring-slate-200">
                On reject: {{ chain.on_reject }}
            </span>
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
            <ol class="relative space-y-3 border-l border-slate-200 pl-5">
                <li v-for="step in group.steps" :key="step.id" class="relative">
                    <!-- Numbered marker: the sequence remains visible in every state. -->
                    <span :class="['absolute -left-[1.63rem] z-10 flex h-5 w-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full border-2 text-[10px] font-bold leading-none ring-4 ring-white',
                        step.status === 'approved' ? 'border-emerald-500 bg-emerald-500 text-white' :
                        step.status === 'rejected' ? 'border-red-500 bg-red-500 text-white' :
                        step.status === 'pending' ? 'border-amber-400 bg-amber-50 text-amber-800 shadow-[0_0_0_3px_rgba(245,158,11,0.16)]' :
                        'border-slate-300 bg-white text-slate-500']">
                        {{ step.sort_order }}
                    </span>

                    <div :class="['rounded-lg border border-l-4 bg-white px-3.5 py-3 shadow-sm', stepCardClass(step.status)]">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold" :class="step.status === 'upcoming' ? 'text-gray-500' : 'text-gray-800'">
                                {{ step.name || ('Step ' + step.sort_order) }}
                            </p>
                            <span :class="['rcmi-timeline-status rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide', statusClass(step.status)]">
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
            <ol class="relative space-y-3 border-l border-slate-200 pl-5">
                <li class="relative">
                    <span :class="['absolute -left-[1.63rem] z-10 flex h-5 w-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-white ring-4 ring-white',
                        assigneeState === 'active' ? 'bg-cyan-500 text-white' :
                        assigneeState === 'assigned' ? 'bg-indigo-500 text-white' :
                        assigneeState === 'completed' ? 'bg-emerald-500 text-white' : 'border-2 border-dashed border-slate-300 text-slate-500']">
                        <Icon v-if="assigneeState === 'completed'" name="check" />
                        <Icon v-else name="user-check" />
                    </span>
                    <div :class="['rounded-lg border border-l-4 bg-white px-3.5 py-3 shadow-sm', assigneeCardClass]">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-slate-800">Assigned work</p>
                            <span :class="['rcmi-timeline-status rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide', assigneeStatusClass]">
                                {{ assigneeStateLabel }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">
                            <span class="font-medium text-slate-600">Assignee</span>
                            <span class="mx-1 text-slate-300">·</span>
                            <strong class="text-slate-700">{{ assigneeNames || 'Unassigned' }}</strong>
                        </p>
                        <p v-if="assigneeState !== 'waiting' && assigneeSince" class="mt-0.5 text-xs text-gray-400">
                            {{ formatDateTime(assigneeSince) }}
                        </p>
                    </div>
                </li>
            </ol>
        </div>

        <!-- Post-approval status entries (In Progress, Completed, etc.) -->
        <div v-if="postApprovalEntries.length > 0" class="space-y-4">
            <ol class="relative space-y-3 border-l border-slate-200 pl-5">
                <li v-for="entry in postApprovalEntries" :key="'status-' + entry.id" class="relative">
                    <span :class="['absolute -left-[1.63rem] z-10 flex h-5 w-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-white ring-4 ring-white',
                        entry.new_status === 'Completed' ? 'bg-emerald-500 text-white' :
                        entry.new_status === 'In Progress' ? 'bg-cyan-500 text-white' : 'border-2 border-slate-300 text-slate-500']">
                        <Icon v-if="entry.new_status === 'Completed'" name="check" />
                        <Icon v-else-if="entry.new_status === 'In Progress'" name="arrow-right" />
                        <span v-else class="text-[10px] font-bold leading-none">·</span>
                    </span>

                    <div :class="['rounded-lg border border-l-4 bg-white px-3.5 py-3 shadow-sm',
                        entry.new_status === 'Completed' ? 'border-emerald-200' :
                        entry.new_status === 'In Progress' ? 'border-cyan-200' : 'border-slate-200']">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-gray-800">{{ entry.new_status }}</p>
                            <span :class="['rcmi-timeline-status rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide', statusEntryClass(entry.new_status)]">
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
// waiting. Once the chain clears the work is "assigned"; when the
// assignee clicks Start work (In Progress entry in history) they're
// actively working; after the ticket is marked Completed they're done.
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
    // Chain cleared: assigned until the assignee clicks Start work.
    if ((props.statusHistory || []).some(e => e.new_status === 'In Progress')) {
        return 'active';
    }
    return 'assigned';
});
const assigneeStateLabel = computed(() => {
    return { waiting: 'Waiting', assigned: 'Project assigned', active: 'In progress', completed: 'Completed' }[assigneeState.value] || 'Waiting';
});

// When the work was assigned — the timestamp of the last approved action
// in the latest cycle (the moment the chain cleared and the assignee
// took over). Null while the assignee is still waiting.
const assigneeSince = computed(() => {
    const groups = cycleGroups.value;
    if (!groups.length) return null;
    const steps = groups[groups.length - 1].steps || [];
    const approved = steps.filter(s => s.status === 'approved' && s.decided_at);
    return approved.length ? approved[approved.length - 1].decided_at : null;
});
const assigneeStatusClass = computed(() => ({
    waiting: 'bg-slate-100 text-slate-500',
    assigned: 'bg-indigo-100 text-indigo-700',
    active: 'bg-cyan-100 text-cyan-700',
    completed: 'bg-emerald-100 text-emerald-700',
}[assigneeState.value] || 'bg-slate-100 text-slate-500'));
const assigneeCardClass = computed(() => ({
    waiting: 'border-slate-200',
    assigned: 'border-indigo-200',
    active: 'border-cyan-200',
    completed: 'border-emerald-200',
}[assigneeState.value] || 'border-slate-200'));

const approvalSummary = computed(() => {
    const groups = cycleGroups.value;
    const steps = groups.length ? groups[groups.length - 1].steps || [] : [];
    const approved = steps.filter(step => step.status === 'approved').length;
    return `${approved} of ${steps.length} approval${steps.length === 1 ? '' : 's'} complete`;
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

function stepCardClass(s) {
    return {
        approved: 'border-l-emerald-500 border-slate-200',
        rejected: 'border-l-red-500 border-slate-200',
        pending: 'border-l-amber-500 border-amber-200 bg-amber-50/30',
        upcoming: 'border-l-slate-300 border-slate-200',
        skipped: 'border-l-slate-300 border-slate-200',
    }[s] || 'border-l-slate-300 border-slate-200';
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
        'In Progress': 'text-cyan-700 bg-cyan-100',
    }[s] || 'text-gray-600 bg-gray-100';
}
function formatDateTime(d) {
    if (!d) return '';
    return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}
</script>
