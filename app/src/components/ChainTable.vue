/**
 * Table of approval chains used by the Approval Chain list page.
 * Columns: Chain (name + description), Trigger, Steps, On reject,
 * Status (toggle), Updated, Actions (Edit / Duplicate / Delete).
 */
<template>
    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white shadow-sm">
        <table class="w-full text-left">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-2.5 pl-4 pr-3 text-xs font-bold uppercase tracking-wide text-gray-500">Chain</th>
                    <th class="py-2.5 pr-3 text-xs font-bold uppercase tracking-wide text-gray-500">Trigger</th>
                    <th class="py-2.5 pr-3 text-xs font-bold uppercase tracking-wide text-gray-500">Steps</th>
                    <th class="py-2.5 pr-3 text-xs font-bold uppercase tracking-wide text-gray-500">On reject</th>
                    <th class="py-2.5 pr-3 text-xs font-bold uppercase tracking-wide text-gray-500">Status</th>
                    <th class="py-2.5 pr-3 text-xs font-bold uppercase tracking-wide text-gray-500">Updated</th>
                    <th class="py-2.5 pr-4 text-right text-xs font-bold uppercase tracking-wide text-gray-500">Actions</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in chains" :key="c.id"
                    :class="['border-b border-gray-100 transition last:border-0 hover:bg-gray-50', !c.is_active ? 'opacity-60' : '']">
                    <!-- Name -->
                    <td class="py-3 pl-4 pr-3 align-top">
                        <div class="flex items-center gap-2">
                            <router-link :to="`/approval-edit/${c.id}`"
                                class="font-semibold text-gray-900 hover:text-red-700">
                                {{ c.name }}
                            </router-link>
                            <span v-if="!c.is_active"
                                class="rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-600">
                                Inactive
                            </span>
                        </div>
                        <p v-if="c.description" class="mt-0.5 line-clamp-1 text-xs text-gray-500">{{ c.description }}</p>
                    </td>
                    <!-- Trigger -->
                    <td class="whitespace-nowrap py-3 pr-3">
                        <span v-if="c.trigger_field_key"
                            class="inline-flex max-w-[16rem] items-center gap-1 rounded-md bg-purple-50 px-2 py-1 text-xs font-medium text-purple-700 ring-1 ring-purple-200">
                            <span class="truncate">{{ triggerLabel(c) }} = {{ c.trigger_value }}</span>
                        </span>
                        <span v-else class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-500">
                            Default
                        </span>
                    </td>
                    <!-- Steps -->
                    <td class="whitespace-nowrap py-3 pr-3 text-sm text-gray-600">{{ stepText(c) }}</td>
                    <!-- On reject -->
                    <td class="whitespace-nowrap py-3 pr-3 text-sm text-gray-600">{{ rejectLabel(c.on_reject) }}</td>
                    <!-- Status toggle -->
                    <td class="whitespace-nowrap py-3 pr-3">
                        <button type="button" :disabled="togglingId === c.id"
                            @click="$emit('toggle-active', c)"
                            :title="c.is_active ? 'Click to deactivate' : 'Click to activate'"
                            :aria-label="c.is_active ? `Deactivate ${c.name}` : `Activate ${c.name}`"
                            :aria-pressed="c.is_active"
                            :class="['relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full transition-colors disabled:cursor-wait disabled:opacity-50', c.is_active ? 'bg-emerald-500' : 'bg-gray-300']">
                            <span :class="['pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform', c.is_active ? 'translate-x-[18px]' : 'translate-x-0.5']"></span>
                        </button>
                    </td>
                    <!-- Updated -->
                    <td class="whitespace-nowrap py-3 pr-3 text-xs text-gray-500">{{ formatUpdated(c.updated_at) }}</td>
                    <!-- Actions -->
                    <td class="whitespace-nowrap py-3 pr-4 text-right">
                        <div class="inline-flex items-center gap-1">
                            <router-link :to="`/approval-edit/${c.id}`"
                                class="rcmi-button-ghost inline-flex items-center gap-1 px-2.5 py-1.5 text-xs"
                                title="Edit" aria-label="Edit">
                                <Icon name="edit" />
                            </router-link>
                            <button type="button" @click="$emit('duplicate', c)"
                                class="rcmi-button-ghost inline-flex items-center gap-1 px-2.5 py-1.5 text-xs"
                                title="Duplicate" aria-label="Duplicate">
                                <Icon name="copy" />
                            </button>
                            <button type="button" @click="$emit('delete', c)"
                                class="rcmi-button-ghost inline-flex items-center gap-1 px-2.5 py-1.5 text-xs text-red-700 hover:bg-red-50"
                                title="Delete" aria-label="Delete">
                                <Icon name="trash" />
                            </button>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<script setup>
import Icon from './Icon.vue';

const props = defineProps({
    chains: { type: Array, required: true },
    fieldLabels: { type: Object, required: true },
    togglingId: { type: Number, default: null },
});

defineEmits(['toggle-active', 'delete', 'duplicate']);

const rejectLabels = {
    restart: 'Restart from step 1',
    back_one: 'Back one step',
    terminal: 'Terminal reject',
};

function triggerLabel(c) {
    return (props.fieldLabels && props.fieldLabels[c.trigger_field_key]) ? props.fieldLabels[c.trigger_field_key] : c.trigger_field_key;
}

function rejectLabel(v) {
    return rejectLabels[v] || v || '—';
}

function stepText(c) {
    const n = c.steps?.length || 0;
    return n + (n === 1 ? ' step' : ' steps');
}

function formatUpdated(d) {
    if (!d) return '—';
    const date = new Date(d);
    const now = new Date();
    const diffDays = Math.floor((now - date) / 86400000);
    if (diffDays < 1) return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>
