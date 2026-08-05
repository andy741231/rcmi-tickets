<template>
    <div class="mx-auto max-w-2xl">
        <!-- Loading skeleton -->
        <div v-if="loading" class="rcmi-card space-y-5 p-8" aria-busy="true">
            <div class="h-6 w-40 animate-pulse rounded bg-gray-100"></div>
            <div class="h-10 w-full animate-pulse rounded bg-gray-100"></div>
            <div class="h-24 w-full animate-pulse rounded bg-gray-100"></div>
            <div class="h-10 w-full animate-pulse rounded bg-gray-100"></div>
        </div>

        <template v-else>
            <!-- Header -->
            <div class="mb-6">
                <nav class="rcmi-breadcrumb mb-3" aria-label="Breadcrumb">
                    <router-link to="/">Tickets</router-link>
                    <span class="rcmi-breadcrumb-sep">/</span>
                    <router-link :to="`/ticket/${id}`">#{{ id }}</router-link>
                    <span class="rcmi-breadcrumb-sep">/</span>
                    <span class="font-semibold text-gray-700">Edit</span>
                </nav>
                <h2 class="text-xl font-bold text-gray-900">Edit Ticket #{{ id }}</h2>
                <p class="mt-1 text-sm text-gray-600">Update the ticket details below.</p>
            </div>

            <form @submit.prevent="submit" class="rcmi-card space-y-6 p-6 sm:p-8">
                <!-- Custom fields -->
                <div v-if="fieldDefinitions && fieldDefinitions.length > 0">
                    <span class="rcmi-field-label">Ticket Details</span>
                    <div class="mt-2">
                        <DynamicForm :fields="fieldDefinitions" v-model="form.field_answers" />
                    </div>
                </div>

                <!-- Priority + Due Date -->
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                    <div>
                        <label for="edit-priority" class="rcmi-field-label">Priority</label>
                        <select id="edit-priority" v-model="form.priority" class="rcmi-input">
                            <option v-for="p in meta.priorities" :key="p" :value="p">{{ p }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="edit-due-date" class="rcmi-field-label">Due Date</label>
                        <input id="edit-due-date" v-model="form.due_date" type="date" class="rcmi-input" />
                    </div>
                </div>

                <!-- File uploader (ticket already exists, so uploads work) -->
                <div>
                    <span class="rcmi-field-label">Attachments</span>
                    <div class="mt-2">
                        <FileUploader :ticket-id="parseInt(id)" v-model:existing-attachments="form.attachments" />
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
                        <Icon name="check" />
                        {{ submitting ? 'Saving…' : 'Save Changes' }}
                    </button>
                    <router-link :to="`/ticket/${id}`" class="rcmi-button-ghost px-3 py-2.5 text-sm">Cancel</router-link>
                </div>
            </form>
        </template>
    </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { api } from '../api.js';
import DynamicForm from '../components/DynamicForm.vue';
import FileUploader from '../components/FileUploader.vue';
import Icon from '../components/Icon.vue';
import { useToast } from '../composables/useToast.js';

const props = defineProps({ id: { type: String, required: true } });
const router = useRouter();
const toast = useToast();
const meta = reactive({ priorities: [], field_definitions: [] });
const fieldDefinitions = ref([]);
const loading = ref(true);
const submitting = ref(false);
const error = ref('');

const form = reactive({
    priority: 'Medium',
    due_date: '',
    field_answers: {},
    attachments: [],
});

async function loadMeta() {
    try {
        const data = await api('/meta');
        Object.assign(meta, data);
        if (data.field_definitions) fieldDefinitions.value = data.field_definitions;
    } catch (e) {
        error.value = 'Failed to load form data.';
    }
}

async function loadTicket() {
    try {
        const ticket = await api(`/tickets/${props.id}`);
        form.priority = ticket.priority || 'Medium';
        form.due_date = ticket.due_date || '';
        form.field_answers = ticket.field_answers || {};
        form.attachments = ticket.attachments || [];
        if (ticket.field_definitions) fieldDefinitions.value = ticket.field_definitions;
    } catch (e) {
        error.value = e.message || 'Failed to load ticket.';
    }
}

async function submit() {
    submitting.value = true;
    error.value = '';
    try {
        const body = { ...form };
        delete body.attachments;
        await api(`/tickets/${props.id}`, { method: 'PUT', body });
        toast.success('Ticket saved');
        router.push(`/ticket/${props.id}`);
    } catch (e) {
        error.value = e.message || 'Failed to save ticket.';
        toast.error(error.value);
    } finally {
        submitting.value = false;
    }
}

onMounted(async () => {
    await Promise.all([loadMeta(), loadTicket()]);
    loading.value = false;
});
</script>
