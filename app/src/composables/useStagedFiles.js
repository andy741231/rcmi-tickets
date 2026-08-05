import { ref } from 'vue';

const STORAGE_KEY = 'rcmi_tickets_staged_files_meta';
const MAX_SIZE = 10 * 1024 * 1024; // 10MB, matches server

/**
 * Composable for staging files client-side (no server storage until ticket submit).
 * Blobs live in memory; metadata (name/size/mime) persists to sessionStorage so
 * the list survives Vue route changes within the session. A full page refresh
 * loses the blobs — the UI warns the user about this.
 *
 * @param {string[]} allowedMimes  Allowed MIME types from /meta
 */
export function useStagedFiles(allowedMimes = []) {
    const files = ref([]);
    const dragging = ref(false);

    // Restore metadata list from sessionStorage on init (blobs will be gone
    // after a refresh, but the list reminds the user what they had selected)
    try {
        const saved = JSON.parse(sessionStorage.getItem(STORAGE_KEY) || '[]');
        if (Array.isArray(saved) && saved.length > 0) {
            // Mark as "lost" so the UI can prompt re-selection
            files.value = saved.map(m => ({ ...m, file: null, previewUrl: null, lost: true }));
        }
    } catch { /* ignore */ }

    function persistMeta() {
        const meta = files.value.map(f => ({
            id: f.id, name: f.name, size: f.size, mime: f.mime, lost: !f.file,
        }));
        try { sessionStorage.setItem(STORAGE_KEY, JSON.stringify(meta)); } catch { /* ignore */ }
    }

    function genId() {
        return 'f_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 8);
    }

    /**
     * Validate + add files from a FileList or array.
     * Returns { accepted: [], rejected: [] }.
     */
    function addFiles(fileList) {
        const arr = Array.from(fileList || []);
        const accepted = [];
        const rejected = [];

        for (const file of arr) {
            if (file.size > MAX_SIZE) {
                rejected.push({ name: file.name, reason: 'Exceeds 10MB' });
                continue;
            }
            if (allowedMimes.length > 0 && !allowedMimes.includes(file.type)) {
                rejected.push({ name: file.name, reason: 'File type not allowed' });
                continue;
            }
            const entry = {
                id: genId(),
                file,
                name: file.name,
                size: file.size,
                mime: file.type,
                previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
                lost: false,
            };
            files.value.push(entry);
            accepted.push(entry);
        }
        persistMeta();
        return { accepted, rejected };
    }

    function removeFile(id) {
        const idx = files.value.findIndex(f => f.id === id);
        if (idx >= 0) {
            const f = files.value[idx];
            if (f.previewUrl) URL.revokeObjectURL(f.previewUrl);
            files.value.splice(idx, 1);
            persistMeta();
        }
    }

    function clear() {
        for (const f of files.value) {
            if (f.previewUrl) URL.revokeObjectURL(f.previewUrl);
        }
        files.value = [];
        try { sessionStorage.removeItem(STORAGE_KEY); } catch { /* ignore */ }
    }

    /** Files that actually have a blob (not "lost" from a refresh). */
    function uploadableFiles() {
        return files.value.filter(f => f.file && !f.lost);
    }

    return {
        files,
        dragging,
        addFiles,
        removeFile,
        clear,
        uploadableFiles,
        MAX_SIZE,
    };
}
