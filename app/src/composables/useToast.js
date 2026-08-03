import { reactive } from 'vue';

const state = reactive({
    toasts: [],
});

let nextId = 1;

function addToast(message, type = 'info', timeout = 4000) {
    const id = nextId++;
    state.toasts.push({ id, message, type });
    if (timeout > 0 && type !== 'error') {
        setTimeout(() => dismiss(id), timeout);
    }
    return id;
}

function dismiss(id) {
    const idx = state.toasts.findIndex(t => t.id === id);
    if (idx >= 0) state.toasts.splice(idx, 1);
}

export function useToast() {
    return {
        state,
        success: (msg, timeout) => addToast(msg, 'success', timeout ?? 3500),
        error:   (msg) => addToast(msg, 'error', 0),
        warning: (msg, timeout) => addToast(msg, 'warning', timeout ?? 5000),
        info:    (msg, timeout) => addToast(msg, 'info', timeout ?? 4000),
        dismiss,
    };
}
