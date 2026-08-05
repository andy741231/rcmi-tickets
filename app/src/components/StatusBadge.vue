<template>
    <span class="rcmi-status-badge inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="badgeClass">
        <Icon :name="iconName" />
        {{ status }}
    </span>
</template>

<script setup>
import { computed } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    status: { type: String, required: true },
});

const classes = {
    'Received':          'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-200',
    'Pending Approval':  'bg-purple-50 text-purple-800 ring-1 ring-inset ring-purple-200',
    'Approved':          'bg-blue-50 text-blue-800 ring-1 ring-inset ring-blue-200',
    'Rejected':          'bg-red-50 text-red-800 ring-1 ring-inset ring-red-200',
    'Completed':         'bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200',
};

const icons = {
    'Received':          'clock',
    'Pending Approval':  'bell',
    'Approved':          'check-circle',
    'Rejected':          'x-circle',
    'Completed':         'check-badge',
};

const badgeClass = computed(() => classes[props.status] || 'bg-gray-100 text-gray-800');
const iconName = computed(() => icons[props.status] || 'check');
</script>
