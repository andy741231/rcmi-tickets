<template>
    <div v-if="loading" class="rcmi-card p-8 text-center text-sm text-gray-600">Loading form fields…</div>
    <div v-else-if="error" class="rcmi-card p-8 text-center">
        <p class="text-sm text-red-700">{{ error }}</p>
        <router-link to="/create" class="rcmi-button-secondary mt-4 inline-flex px-3 py-2 text-sm">Back to ticket form</router-link>
    </div>
    <FormBuilderPanel v-else :initial-fields="fields" :initial-success="successMessage" @updated="loadFields" />
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { api } from '../api.js';
import FormBuilderPanel from '../components/FormBuilderPanel.vue';

const fields = ref([]);
const successMessage = ref({ heading: '', message: '' });
const loading = ref(true);
const error = ref('');

async function loadFields() {
    try {
        const data = await api('/meta');
        fields.value = data.form_fields || [];
        successMessage.value = data.public_success || { heading: '', message: '' };
    } catch (e) {
        error.value = e.message || 'Unable to load the ticket form.';
    } finally {
        loading.value = false;
    }
}

onMounted(loadFields);
</script>
