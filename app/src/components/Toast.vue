<template>
    <Teleport to="body">
        <div class="rcmi-toast-container fixed bottom-4 right-4 z-[60] flex w-full max-w-sm flex-col gap-2 sm:bottom-6 sm:right-6" :class="{'bottom-4 left-4 right-4 max-w-none sm:left-6': false}">
            <transition-group name="rcmi-toast" tag="div" class="flex flex-col gap-2">
                <div v-for="toast in state.toasts" :key="toast.id"
                    :role="toast.type === 'error' ? 'alert' : 'status'"
                    class="rcmi-toast pointer-events-auto flex items-start gap-3 rounded-lg border px-4 py-3 shadow-lg"
                    :class="toastClass(toast.type)">
                    <span class="flex-shrink-0 pt-0.5">
                        <Icon :name="iconFor(toast.type)" />
                    </span>
                    <p class="flex-1 text-sm font-medium">{{ toast.message }}</p>
                    <button @click="dismiss(toast.id)" class="flex-shrink-0 rounded p-0.5 opacity-70 hover:opacity-100" aria-label="Dismiss notification">
                        <Icon name="x" />
                    </button>
                </div>
            </transition-group>
        </div>
    </Teleport>
</template>

<script setup>
import { useToast } from '../composables/useToast.js';
import Icon from './Icon.vue';

const { state, dismiss } = useToast();

function toastClass(type) {
    return {
        success: 'border-emerald-200 bg-emerald-50 text-emerald-900',
        error:   'border-red-200 bg-red-50 text-red-900',
        warning: 'border-amber-200 bg-amber-50 text-amber-900',
        info:    'border-gray-200 bg-white text-gray-900',
    }[type] || 'border-gray-200 bg-white text-gray-900';
}

function iconFor(type) {
    return {
        success: 'check-circle',
        error:   'x-circle',
        warning: 'alert',
        info:    'check-circle',
    }[type] || 'check-circle';
}
</script>

<style>
.rcmi-toast-enter-active,
.rcmi-toast-leave-active {
    transition: all 250ms ease;
}
.rcmi-toast-enter-from {
    opacity: 0;
    transform: translateX(1rem);
}
.rcmi-toast-leave-to {
    opacity: 0;
    transform: translateX(1rem);
}
</style>
