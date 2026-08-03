<template>
    <Teleport to="body">
        <div v-if="open" class="rcmi-modal-root fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.esc="close" role="dialog" aria-modal="true" :aria-label="title">
            <!-- Scrim -->
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="onScrimClick"></div>

            <!-- Panel -->
            <div ref="panel" class="rcmi-modal-panel relative w-full max-w-md rounded-xl bg-white shadow-2xl" @click.stop>
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-gray-200 px-5 py-4">
                    <h3 class="text-base font-semibold text-gray-900">{{ title }}</h3>
                    <button @click="close" class="rcmi-button-ghost rounded-md p-1.5" aria-label="Close dialog">
                        <Icon name="x" />
                    </button>
                </div>

                <!-- Body -->
                <div class="px-5 py-4">
                    <slot></slot>
                </div>

                <!-- Footer -->
                <div v-if="$slots.footer" class="flex items-center justify-end gap-2 border-t border-gray-200 px-5 py-4">
                    <slot name="footer"></slot>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { ref, watch, nextTick, onUnmounted } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    title: { type: String, default: '' },
    closeOnScrim: { type: Boolean, default: true },
});
const emit = defineEmits(['close']);

const open = ref(true);
const panel = ref(null);
let previousFocus = null;

function close() {
    open.value = false;
    emit('close');
}

function onScrimClick() {
    if (props.closeOnScrim) close();
}

watch(open, async (isOpen) => {
    if (isOpen) {
        previousFocus = document.activeElement;
        document.body.style.overflow = 'hidden';
        await nextTick();
        const focusable = panel.value?.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        focusable?.focus();
    } else {
        document.body.style.overflow = '';
        previousFocus?.focus?.();
    }
});

onUnmounted(() => {
    document.body.style.overflow = '';
});
</script>
