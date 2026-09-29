<template>
    <!-- Single continuous spine: cycle steps → assignee → post-approval statuses -->
    <ol class="relative space-y-3 border-l border-slate-200 pl-5">
        <template v-for="group in cycleGroups" :key="group.cycle">
            <!-- Cycle divider (only show for cycle 2+) -->
            <li v-if="group.cycle > 1" class="relative -ml-5 w-[calc(100%+1.25rem)]">
                <div class="flex items-center gap-2 py-1 pl-5">
                    <span class="h-px flex-1 bg-gray-200"></span>
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-400">Resubmission {{ group.cycle }}</span>
                    <span class="h-px flex-1 bg-gray-200"></span>
                </div>
            </li>

            <!-- Historical cycles: collapsed summary row -->
            <li v-if="isHistoricalCycle(group) && !expandedCycles.includes(group.cycle)" class="relative">
                <span class="absolute -left-[1.63rem] z-10 flex h-5 w-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full border-2 border-slate-300 bg-white text-slate-400 ring-4 ring-white">
                    <Icon name="rotate-ccw" />
                </span>
                <button type="button" @click="expandedCycles.push(group.cycle)"
                    class="w-full rounded-lg border border-slate-200 bg-slate-50 px-3.5 py-2.5 text-left text-xs text-slate-600 transition hover:border-slate-300 hover:bg-slate-100">
                    <span class="font-semibold">{{ group.cycle === 1 ? 'First submission' : 'Resubmission ' + group.cycle }} — {{ cycleSummary(group) }}</span>
                    <span class="ml-1 text-slate-400">Show history</span>
                </button>
            </li>

            <!-- Steps -->
            <template v-else>
                <li v-for="(step, si) in group.steps" :key="step.id" class="relative">
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
                            <span v-if="step.status === 'pending' && waitingLabel(group, si)" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800">{{ waitingLabel(group, si) }}</span>
                        </p>
                        <p v-if="step.decided_at" class="mt-0.5 text-xs text-gray-400">
                            {{ formatDateTime(step.decided_at) }}
                            <span v-if="step.decided_by_name"> · by {{ step.decided_by_name }}</span>
                            <span v-if="durationLabel(group, si)" class="text-slate-400"> · {{ durationLabel(group, si) }}</span>
                        </p>
                        <p v-if="step.comment" class="mt-2 rounded bg-white/70 px-2 py-1.5 text-xs text-gray-700 whitespace-pre-wrap">
                            "{{ step.comment }}"
                        </p>
                    </div>
                </li>
            </template>
        </template>

        <!-- Assignee row — appears below the approval steps. The assignee
             only becomes active once all approvers have approved (i.e. the
             ticket transitions to In Progress). Before that, the row is
             "Waiting". After completion, it shows "Completed". Reads the
             live ticket assignee list so it stays in sync when a manager
             changes assignees from the Details card. -->
        <li v-if="chain" class="relative">
                    <span :class="['absolute -left-[1.63rem] z-10 flex h-5 w-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-white ring-4 ring-white',
                        assigneeState === 'active' ? 'bg-cyan-500 text-white' :
                        assigneeState === 'assigned' ? 'bg-indigo-500 text-white' :
                        assigneeState === 'completed' ? 'bg-emerald-500 text-white' : 'border-2 border-dashed border-slate-300 text-slate-500']">
                        <Icon v-if="assigneeState === 'completed'" name="check" />
                        <Icon v-else name="user-check" />
                    </span>
                    <div :class="['rounded-lg border border-l-4 bg-white px-3.5 py-3 shadow-sm', assigneeCardClass]">
                        <p class="text-sm font-semibold" :class="assigneeState === 'waiting' ? 'text-slate-500' : 'text-slate-800'">
                            {{ assigneeTitle }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            <span class="font-medium text-slate-600">Assignee</span>
                            <span class="mx-1 text-slate-300">·</span>
                            <strong class="text-slate-700">{{ assigneeNames || 'Unassigned' }}</strong>
                        </p>
                        <p v-if="assigneeState !== 'waiting' && assigneeSince" class="mt-0.5 text-xs text-gray-400">
                            Assigned {{ formatDateTime(assigneeSince) }}
                        </p>
                        <p v-if="startedEntry" class="mt-0.5 text-xs text-gray-400">
                            Started {{ formatDateTime(startedEntry.changed_at) }}
                            <span v-if="startedEntry.changed_by_name"> · by {{ startedEntry.changed_by_name }}</span>
                        </p>
                        <p v-if="completedEntry" class="mt-0.5 text-xs text-gray-400">
                            Completed {{ formatDateTime(completedEntry.changed_at) }}
                            <span v-if="completedEntry.changed_by_name"> · by {{ completedEntry.changed_by_name }}</span>
                        </p>
                    </div>
        </li>

        <!-- Post-approval status entries (In Progress, Completed, etc.) -->
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
                        <p class="text-sm font-semibold text-gray-800">{{ statusEntryLabel(entry.new_status) }}</p>
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
</template>

<script setup>
import { computed, ref } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    steps: { type: Array, default: () => [] },
    chain: { type: Object, default: null },
    statusHistory: { type: Array, default: () => [] },
    assignees: { type: Array, default: () => [] },
    startAt: { type: String, default: '' }, // ticket created_at — start of cycle 1
});

// Historical cycles are collapsed by default; expand on click.
const expandedCycles = ref([]);
const maxCycle = computed(() => {
    const groups = cycleGroups.value;
    return groups.length ? groups[groups.length - 1].cycle : 1;
});
function isHistoricalCycle(group) {
    return group.cycle !== maxCycle.value;
}
function cycleSummary(group) {
    const steps = group.steps || [];
    const parts = [];
    const approved = steps.filter(s => s.status === 'approved').length;
    const rejected = steps.filter(s => s.status === 'rejected').length;
    if (approved) parts.push(`${approved} approved`);
    if (rejected) parts.push(`${rejected} rejected`);
    return parts.join(', ') || `${steps.length} steps`;
}

// ── Step timing ──────────────────────────────────────────────────────
// Duration between consecutive decided steps, and waiting time on the
// pending step (since the previous decision, resubmission, or ticket
// creation for the very first step).
const now = Date.now();

function fmtDuration(ms) {
    const mins = Math.floor(ms / 60000);
    if (mins < 1) return '<1m';
    if (mins < 60) return `${mins}m`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h`;
    const days = Math.floor(hrs / 24);
    return `${days}d`;
}

// When this step's wait started: the previous decided step in the same
// cycle, else the last decision of an earlier cycle, else ticket creation.
function stepStartTime(group, idx) {
    const steps = group.steps || [];
    for (let i = idx - 1; i >= 0; i--) {
        if (steps[i].decided_at) return new Date(steps[i].decided_at).getTime();
    }
    // First step of the cycle — look back to earlier cycles
    for (const g of cycleGroups.value) {
        if (g.cycle >= group.cycle) break;
        const decided = (g.steps || []).filter(s => s.decided_at);
        if (decided.length) return new Date(decided[decided.length - 1].decided_at).getTime();
    }
    return props.startAt ? new Date(props.startAt).getTime() : null;
}

function durationLabel(group, idx) {
    const step = group.steps[idx];
    if (!step?.decided_at) return '';
    const start = stepStartTime(group, idx);
    if (!start) return '';
    return 'took ' + fmtDuration(new Date(step.decided_at).getTime() - start);
}

function waitingLabel(group, idx) {
    const step = group.steps[idx];
    if (step?.status !== 'pending') return '';
    const start = stepStartTime(group, idx);
    if (!start) return '';
    return 'waiting ' + fmtDuration(now - start);
}

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
const assigneeTitle = computed(() => {
    return { waiting: 'Awaiting assignment', assigned: 'Project Assigned', active: 'Project In Progress', completed: 'Project Completed' }[assigneeState.value] || 'Awaiting assignment';
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
const assigneeCardClass = computed(() => ({
    waiting: 'border-slate-200',
    assigned: 'border-indigo-200',
    active: 'border-cyan-200',
    completed: 'border-emerald-200',
}[assigneeState.value] || 'border-slate-200'));

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

// Work lifecycle timestamps on the assignee card — pulled from the status
// log so they carry the actor. Shown when the card reaches that state.
const startedEntry = computed(() => {
    if (!['active', 'completed'].includes(assigneeState.value)) return null;
    return (props.statusHistory || []).find(e => e.new_status === 'In Progress') || null;
});
const completedEntry = computed(() => {
    if (assigneeState.value !== 'completed') return null;
    return (props.statusHistory || []).find(e => e.new_status === 'Completed') || null;
});

// Post-approval status entries. In Progress and Completed are omitted —
// they're already represented by the assignee card's dynamic title and
// workEntry line, so rendering them again would duplicate the card.
const postApprovalEntries = computed(() => {
    const skip = ['Approved', 'Rejected', 'Rejected: Pending Revision', 'Pending Approval', 'Received', 'In Progress', 'Completed'];
    return (props.statusHistory || []).filter(e => !skip.includes(e.new_status));
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
function statusEntryLabel(s) {
    return {
        'In Progress': 'Project In Progress',
        'Completed': 'Project Completed',
    }[s] || s;
}
function formatDateTime(d) {
    if (!d) return '';
    return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}
</script>
