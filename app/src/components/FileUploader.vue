<template>
    <div>
        <div v-if="!ticketId" class="rounded-md border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <span class="inline-flex items-center gap-2"><Icon name="alert" /> Files can be uploaded after the ticket is created. Save the ticket first, then attach files.</span>
        </div>

        <div v-else>
            <!-- Drop zone -->
            <div @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop"
                :class="dragging ? 'border-red-400 bg-red-50' : 'border-gray-300 bg-gray-50'"
                class="rounded-lg border-2 border-dashed p-6 text-center transition">
                <p class="text-sm text-gray-600">Drag files here or</p>
                <button type="button" @click="$refs.fileInput.click()"
                    class="rcmi-button-secondary mt-2 inline-flex px-4 py-2 text-sm">
                    Browse Files
                </button>
                <input ref="fileInput" type="file" multiple class="hidden" @change="onFileSelect" />
                <p class="mt-2 text-xs text-gray-500">Max 10MB. Images, PDF, Office docs, ZIP allowed.</p>
            </div>

            <!-- Upload progress -->
            <ul v-if="uploads.length > 0" class="mt-3 space-y-2">
                <li v-for="(u, i) in uploads" :key="i" class="flex items-center justify-between rounded-md border border-gray-200 px-3 py-2">
                    <div class="flex min-w-0 items-center gap-2">
                        <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500">
                            <Icon :name="fileIcon(u.file.type)" />
                        </span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-700">{{ u.file.name }}</p>
                            <p class="text-xs text-gray-500">{{ formatSize(u.file.size) }}</p>
                        </div>
                    </div>
                    <div class="flex flex-shrink-0 items-center gap-2">
                        <span v-if="u.status === 'uploading'" class="text-xs font-medium text-blue-600">Uploading…</span>
                        <span v-else-if="u.status === 'done'" class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700">
                            <Icon name="check" /> Done
                        </span>
                        <span v-else-if="u.status === 'error'" class="inline-flex items-center gap-1 text-xs font-medium text-red-700">
                            <Icon name="alert" /> {{ u.error }}
                        </span>
                        <button v-if="u.status === 'done'" type="button" @click="removeUpload(i, u.attachmentId)"
                            class="rcmi-button-ghost px-2 py-1 text-xs">Remove</button>
                    </div>
                </li>
            </ul>

            <!-- Existing attachments (for edit mode) -->
            <div v-if="existingAttachments.length > 0" class="mt-3">
                <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Current attachments</p>
                <ul class="space-y-1.5">
                    <li v-for="a in existingAttachments" :key="a.id" class="flex items-center justify-between rounded-md border border-gray-100 bg-gray-50 px-3 py-2">
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded bg-gray-200 text-gray-500">
                                <Icon :name="fileIcon(a.mime_type)" />
                            </span>
                            <span class="truncate text-sm font-medium text-gray-700">{{ a.original_name }}</span>
                        </div>
                        <button type="button" @click="removeExisting(a.id)"
                            class="rcmi-button-ghost flex-shrink-0 px-2 py-1 text-xs text-red-700 hover:text-red-800">Remove</button>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { api } from '../api.js';
import Icon from './Icon.vue';

const props = defineProps({
    ticketId:          { type: Number, default: null },
    existingAttachments: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:existingAttachments']);

const dragging = ref(false);
const uploads = ref([]);
const fileInput = ref(null);

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

function fileIcon(mime) {
    if (!mime) return 'file-generic';
    if (mime.startsWith('image/')) return 'file-image';
    if (mime === 'application/pdf') return 'file-text';
    if (mime.includes('zip')) return 'file-archive';
    if (mime.includes('word') || mime.includes('document')) return 'file-word';
    if (mime.includes('sheet') || mime.includes('excel')) return 'file-spreadsheet';
    return 'file-generic';
}

function onFileSelect(e) {
    const files = Array.from(e.target.files);
    files.forEach(uploadFile);
    e.target.value = '';
}

function onDrop(e) {
    dragging.value = false;
    const files = Array.from(e.dataTransfer.files);
    files.forEach(uploadFile);
}

async function uploadFile(file) {
    if (file.size > 10 * 1024 * 1024) {
        uploads.value.push({ file, status: 'error', error: 'Exceeds 10MB', attachmentId: null });
        return;
    }

    const entry = reactive({ file, status: 'uploading', error: null, attachmentId: null });
    uploads.value.push(entry);

    try {
        const formData = new FormData();
        formData.append('file', file);

        const config = window.rcmiTickets || {};
        const res = await fetch(`${config.apiBase}/tickets/${props.ticketId}/attachments`, {
            method: 'POST',
            headers: { 'X-WP-Nonce': config.nonce },
            credentials: 'same-origin',
            body: formData,
        });

        if (!res.ok) {
            const data = await res.json().catch(() => null);
            throw new Error(data?.message || `Upload failed (${res.status})`);
        }

        const data = await res.json();
        entry.status = 'done';
        entry.attachmentId = data.id;
    } catch (e) {
        entry.status = 'error';
        entry.error = e.message;
    }
}

async function removeUpload(index, attachmentId) {
    if (attachmentId) {
        try {
            await api(`/attachments/${attachmentId}`, { method: 'DELETE' });
        } catch (e) {
            console.error('Failed to delete attachment:', e);
        }
    }
    uploads.value.splice(index, 1);
}

async function removeExisting(attachmentId) {
    try {
        await api(`/attachments/${attachmentId}`, { method: 'DELETE' });
        emit('update:existingAttachments', props.existingAttachments.filter(a => a.id !== attachmentId));
    } catch (e) {
        console.error('Failed to delete attachment:', e);
    }
}
</script>
