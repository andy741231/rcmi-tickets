<template>
    <div class="mx-auto max-w-2xl">
        <!-- Header -->
        <div class="mb-6">
            <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                <router-link to="/">Tickets</router-link>
                <span class="rcmi-breadcrumb-sep">/</span>
                <span class="font-semibold text-gray-700">New</span>
            </nav>
            <h2 class="text-xl font-bold text-gray-900">Create New Ticket</h2>
            <p class="mt-1 text-sm text-gray-600">Fill in the details below. You can attach files after saving.</p>
        </div>

        <form @submit.prevent="submit" class="rcmi-card space-y-6 p-6 sm:p-8">
            <!-- Title -->
            <div>
                <label for="ticket-title" class="rcmi-field-label">Title<span class="rcmi-field-required">*</span></label>
                <input id="ticket-title" v-model="form.title" type="text" required
                    class="rcmi-input" placeholder="Short summary of the request" />
            </div>

            <!-- Description -->
            <div>
                <label for="ticket-description" class="rcmi-field-label">Description<span class="rcmi-field-required">*</span></label>
                <textarea id="ticket-description" v-model="form.description" required rows="6"
                    class="rcmi-input resize-y" placeholder="Describe the issue or request in detail…"
                    @keydown.meta.enter.prevent="submit" @keydown.ctrl.enter.prevent="submit"></textarea>
                <p class="rcmi-field-help">Basic HTML allowed. Server-sanitized on save. <span class="text-gray-500">⌘/Ctrl + Enter to submit.</span></p>
            </div>

            <!-- Priority + Due Date -->
            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
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

            <!-- Assignees -->
            <div>
                <span class="rcmi-field-label">Assignees</span>
                <div class="mt-2 flex flex-wrap gap-2">
                    <label v-for="u in meta.assignable_users" :key="u.id"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm transition hover:border-gray-300 has-[:checked]:border-red-300 has-[:checked]:bg-red-50">
                        <input type="checkbox" :value="u.id" v-model="form.assignee_ids"
                            class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                        <span class="font-medium text-gray-700">{{ u.display_name }}</span>
                    </label>
                </div>
                <p v-if="meta.assignable_users.length === 0" class="rcmi-field-help">No assignable users found.</p>
            </div>

            <!-- Tags -->
            <div>
                <label for="ticket-tags" class="rcmi-field-label">Tags</label>
                <TagInput v-model="selectedTags" :available-tags="meta.tags" />
            </div>

            <!-- File uploader (shows info message until ticket is saved) -->
            <div>
                <span class="rcmi-field-label">Attachments</span>
                <div class="mt-2">
                    <FileUploader :ticket-id="createdTicketId" :existing-attachments="[]" />
                </div>
            </div>

            <!-- Error -->
            <div v-if="error" role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <span class="inline-flex items-center gap-2"><Icon name="alert" /> {{ error }}</span>
            </div>

            <!-- Submit -->
            <div class="flex items-center gap-3 border-t border-gray-100 pt-5">
                <button type="submit" :disabled="submitting"
                    class="rcmi-button-primary inline-flex items-center gap-2 px-5 py-2.5 text-sm disabled:opacity-50">
                    <Icon name="plus" />
                    {{ submitting ? 'Creating…' : 'Create Ticket' }}
                </button>
                <router-link to="/" class="rcmi-button-ghost px-3 py-2.5 text-sm">Cancel</router-link>
            </div>
        </form>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api.js';
import TagInput from '../components/TagInput.vue';
import FileUploader from '../components/FileUploader.vue';
import Icon from '../components/Icon.vue';
import { useToast } from '../composables/useToast.js';

const router = useRouter();
const toast = useToast();
const meta = reactive({ priorities: [], tags: [], assignable_users: [] });
const selectedTags = ref([]);
const submitting = ref(false);
const error = ref('');
const createdTicketId = ref(null);

const form = reactive({
    title: '',
    description: '',
    priority: 'Medium',
    due_date: '',
    assignee_ids: [],
    tag_ids: [],
});

async function loadMeta() {
    try {
        const data = await api('/meta');
        Object.assign(meta, data);
    } catch (e) {
        error.value = 'Failed to load form data.';
    }
}

async function submit() {
    submitting.value = true;
    error.value = '';
    try {
        form.tag_ids = selectedTags.value.map(t => t.id);
        const ticket = await api('/tickets', { method: 'POST', body: form });
        createdTicketId.value = ticket.id;
        toast.success('Ticket created');
        router.push(`/ticket/${ticket.id}`);
    } catch (e) {
        error.value = e.message || 'Failed to create ticket.';
        toast.error(error.value);
    } finally {
        submitting.value = false;
    }
}

onMounted(loadMeta);
</script>
