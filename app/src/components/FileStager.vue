<template>
    <div>
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
            <p class="mt-1 text-xs text-amber-700">Files stay in your browser until you submit — refreshing the page clears them.</p>
        </div>

        <!-- Rejected files toast (inline) -->
        <div v-if="rejected.length > 0" class="mt-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            <p v-for="(r, i) in rejected" :key="i">{{ r.name }}: {{ r.reason }}</p>
            <button @click="rejected = []" class="mt-1 font-semibold underline">Dismiss</button>
        </div>

        <!-- Staged file list -->
        <ul v-if="files.length > 0" class="mt-3 space-y-2">
            <li v-for="f in files" :key="f.id"
                class="flex items-center justify-between rounded-md border border-gray-200 px-3 py-2">
                <div class="flex min-w-0 items-center gap-2">
                    <!-- Thumbnail for images -->
                    <img v-if="f.previewUrl" :src="f.previewUrl" :alt="f.name"
                        class="h-10 w-10 flex-shrink-0 rounded object-cover" />
                    <span v-else class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-md bg-gray-100 text-gray-500">
                        <Icon :name="fileIcon(f.mime)" />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-gray-700">{{ f.name }}</p>
                        <p class="text-xs text-gray-500">
                            {{ formatSize(f.size) }}
                            <span v-if="f.lost" class="text-amber-700"> · lost on refresh, re-select</span>
                        </p>
                    </div>
                </div>
                <button type="button" @click="removeFile(f.id)"
                    class="rcmi-button-ghost flex-shrink-0 px-2 py-1 text-xs text-red-700 hover:text-red-800">Remove</button>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    staged: { type: Object, required: true }, // useStagedFiles() return
});
const rejected = ref([]);

// Unwrap the files ref from the composable for template use
const files = computed(() => props.staged.files.value);

function onFileSelect(e) {
    const { rejected: rej } = props.staged.addFiles(e.target.files);
    if (rej.length) rejected.value = rej;
    e.target.value = '';
}

function onDrop(e) {
    props.staged.dragging.value = false;
    const { rejected: rej } = props.staged.addFiles(e.dataTransfer.files);
    if (rej.length) rejected.value = rej;
}

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
</script>
