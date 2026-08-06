<template>
    <div class="mx-auto max-w-5xl">
        <!-- Header -->
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <nav v-if="!isPublic" class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                    <router-link to="/">Tickets</router-link>
                    <span class="rcmi-breadcrumb-sep">/</span>
                    <span class="font-semibold text-gray-700">New</span>
                </nav>
                <h2 class="text-xl font-bold text-gray-900">{{ isPublic ? 'Submit a Request' : 'Create New Ticket' }}</h2>
                <p class="mt-1 text-sm text-gray-600">{{ isPublic ? 'Fill in the form below and we\'ll get back to you by email.' : 'Fill in the details below. Files upload after you submit.' }}</p>
            </div>
            <router-link v-if="!isPublic && meta.caps.manage" to="/form-builder"
                class="rcmi-button-secondary inline-flex items-center gap-1.5 px-3.5 py-2 text-sm">
                <Icon name="settings" /> Edit Form
            </router-link>
        </div>

        <!-- Success message (public mode) -->
        <div v-if="publicSuccess" class="rcmi-card p-8 text-center">
            <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-teal-100">
                <Icon name="check" />
            </div>
            <h3 class="text-lg font-bold text-gray-900">Thank you for your submission</h3>
            <p class="mt-2 text-sm text-gray-600">{{ publicSuccessMessage }}</p>
            <button @click="resetPublicForm" class="rcmi-button-secondary mt-6 inline-flex items-center gap-1.5 px-4 py-2 text-sm">
                <Icon name="plus" /> Submit another request
            </button>
        </div>

        <!-- Form -->
        <div v-else>
            <!-- Main form -->
            <form @submit.prevent="submit" class="rcmi-card space-y-6 p-6 sm:p-8">
                <!-- Public: name + email fields -->
                <div v-if="isPublic" class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="submitter-name" class="rcmi-field-label">Your Name <span class="text-red-700">*</span></label>
                        <input id="submitter-name" v-model="publicForm.submitter_name" type="text" required
                            class="rcmi-input" placeholder="Jane Doe" />
                    </div>
                    <div>
                        <label for="submitter-email" class="rcmi-field-label">Email <span class="text-red-700">*</span></label>
                        <input id="submitter-email" v-model="publicForm.submitter_email" type="email" required
                            class="rcmi-input" placeholder="jane@example.com" />
                    </div>
                </div>

                <!-- Custom fields (DynamicForm) -->
                <div v-if="meta.form_fields && meta.form_fields.length > 0">
                    <h3 class="rcmi-section-label mb-4">Ticket Details</h3>
                    <DynamicForm :fields="meta.form_fields" v-model="form.form_answers" />
                </div>

                <!-- Priority (logged-in only, public defaults to Medium) -->
                <div v-if="!isPublic" class="border-t border-gray-100 grid grid-cols-1 gap-5 pt-5 sm:grid-cols-2">
                    <div>
                        <label for="ticket-priority" class="rcmi-field-label">Priority</label>
                        <select id="ticket-priority" v-model="form.priority" class="rcmi-input">
                            <option v-for="p in meta.priorities" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="ticket-due-date" class="rcmi-field-label">Due Date</label>
                        <input id="ticket-due-date" v-model="form.due_date" type="date" class="rcmi-input" />
                    </div>
                </div>

                <!-- Attachments (FileStager — client-side only until submit) -->
                <div class="border-t border-gray-100 pt-5">
                    <span class="rcmi-field-label">Attachments</span>
                    <div class="mt-2">
                        <FileStager :staged="staged" />
                    </div>
                </div>

                <!-- Honeypot (public only — hidden from humans) -->
                <div v-if="isPublic" class="rcmi-honeypot" aria-hidden="true">
                    <label>Website (leave empty)</label>
                    <input v-model="publicForm.website" type="text" tabindex="-1" autocomplete="off" />
                </div>

                <!-- Error -->
                <div v-if="error" role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    <span class="inline-flex items-center gap-2"><Icon name="alert" /> {{ error }}</span>
                </div>

                <!-- Upload progress -->
                <div v-if="uploadProgress" class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700">
                    <span class="inline-flex items-center gap-2"><Icon name="save" /> {{ uploadProgress }}</span>
                </div>

                <!-- Submit -->
                <div class="flex items-center gap-3 border-t border-gray-100 pt-5">
                    <button type="submit" :disabled="submitting"
                        class="rcmi-button-primary inline-flex items-center gap-2 px-5 py-2.5 text-sm disabled:opacity-50">
                        <Icon name="plus" />
                        {{ submitting ? 'Submitting…' : (isPublic ? 'Submit Request' : 'Create Ticket') }}
                    </button>
                    <router-link v-if="!isPublic" to="/" class="rcmi-button-ghost px-3 py-2.5 text-sm">Cancel</router-link>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api.js';
import DynamicForm from '../components/DynamicForm.vue';
import FileStager from '../components/FileStager.vue';
import Icon from '../components/Icon.vue';
import { useToast } from '../composables/useToast.js';
import { useStagedFiles } from '../composables/useStagedFiles.js';

const config = window.rcmiTickets || {};
const isPublic = computed(() => !config.isLoggedIn);

const router = useRouter();
const toast = useToast();
const meta = reactive({ priorities: [], tags: [], assignable_users: [], caps: {}, form_fields: [], allowed_mime_types: [] });
const submitting = ref(false);
const error = ref('');
const uploadProgress = ref('');
const publicSuccess = ref(false);
const publicSuccessMessage = ref('');

const staged = useStagedFiles([]);
const stagedFileCount = computed(() => staged.files.value.length);

const publicForm = reactive({
    submitter_name: '',
    submitter_email: '',
    website: '', // honeypot
});

const form = reactive({
    title: '',
    description: '',
    priority: 'Medium',
    due_date: '',
    assignee_ids: [],
    tag_ids: [],
    form_answers: {},
});

async function loadMeta() {
    try {
        // Public mode uses the public meta endpoint; logged-in uses the full meta
        const path = isPublic.value ? '/public/meta' : '/meta';
        const data = await api(path);
        Object.assign(meta, data);
        staged.allowedMimes = data.allowed_mime_types || [];
    } catch (e) {
        error.value = 'Failed to load form data.';
    }
}

function resetPublicForm() {
    publicSuccess.value = false;
    publicSuccessMessage.value = '';
    publicForm.submitter_name = '';
    publicForm.submitter_email = '';
    publicForm.website = '';
    form.form_answers = {};
    error.value = '';
}

async function uploadStagedFiles(ticketId) {
    const files = staged.uploadableFiles();
    if (files.length === 0) return;

    let ok = 0;
    let failed = 0;
    const uploadPath = isPublic.value
        ? `${config.apiBase}/public/attachments/${ticketId}`
        : `${config.apiBase}/tickets/${ticketId}/attachments`;

    for (let i = 0; i < files.length; i++) {
        const f = files[i];
        uploadProgress.value = `Uploading ${i + 1}/${files.length}: ${f.name}`;
        try {
            const formData = new FormData();
            formData.append('file', f.file);
            const res = await fetch(uploadPath, {
                method: 'POST',
                headers: isPublic.value ? {} : { 'X-WP-Nonce': config.nonce },
                credentials: 'same-origin',
                body: formData,
            });
            if (!res.ok) throw new Error(`Upload failed (${res.status})`);
            ok++;
        } catch (e) {
            console.error('Upload failed for', f.name, e);
            failed++;
        }
    }

    uploadProgress.value = '';
    if (ok > 0) toast.success(`${ok} file(s) attached`);
    if (failed > 0) toast.error(`${failed} file(s) failed to upload`);
    staged.clear();
}

async function submit() {
    submitting.value = true;
    error.value = '';
    try {
        // Auto-generate title from form answers
        const answers = form.form_answers || {};
        let autoTitle = '';
        if (meta.form_fields && meta.form_fields.length > 0) {
            for (const f of meta.form_fields) {
                if (['text', 'longtext', 'dropdown', 'radio'].includes(f.type)) {
                    const val = answers[f.field_key];
                    if (val && String(val).trim()) {
                        autoTitle = String(val).trim().slice(0, 100);
                        break;
                    }
                }
            }
        }
        form.title = autoTitle || 'New Ticket ' + new Date().toLocaleDateString();
        form.description = form.description || '';

        if (isPublic.value) {
            // Public submission
            const body = {
                submitter_name: publicForm.submitter_name,
                submitter_email: publicForm.submitter_email,
                title: form.title,
                description: form.description,
                priority: 'Medium',
                form_answers: form.form_answers,
                website: publicForm.website, // honeypot
            };
            const result = await api('/public/submit', { method: 'POST', body });
            await uploadStagedFiles(result.id);
            publicSuccess.value = true;
            publicSuccessMessage.value = result.message || 'Your ticket has been submitted. A confirmation has been sent to your email.';
            toast.success('Request submitted');
        } else {
            // Logged-in submission
            const body = { ...form };
            const ticket = await api('/tickets', { method: 'POST', body });
            await uploadStagedFiles(ticket.id);
            toast.success('Ticket created');
            router.push(`/ticket/${ticket.id}`);
        }
    } catch (e) {
        error.value = e.message || 'Failed to submit ticket.';
        toast.error(error.value);
    } finally {
        submitting.value = false;
    }
}

onMounted(loadMeta);
</script>
