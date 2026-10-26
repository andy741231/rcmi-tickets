<template>
    <div v-if="loading" class="rcmi-card p-8 text-center text-sm text-gray-600">Loading form fields…</div>
    <div v-else-if="error" class="rcmi-card p-8 text-center">
        <p class="text-sm text-red-700">{{ error }}</p>
        <router-link to="/create" class="rcmi-button-secondary mt-4 inline-flex px-3 py-2 text-sm">Back to ticket form</router-link>
    </div>
    <FormBuilderPanel v-else :initial-fields="fields" @updated="loadFields">
        <template #tabs>
            <div class="rcmi-builder-tabs" role="tablist" aria-label="Form builder sections">
                <button v-for="t in tabs" :key="t.key" type="button" role="tab"
                    :aria-selected="tab === t.key"
                    class="rcmi-builder-tab"
                    :class="{ 'rcmi-builder-tab-active': tab === t.key }"
                    @click="setTab(t.key)">
                    {{ t.label }}
                </button>
            </div>
        </template>
        <template v-if="tab !== 'questions'" #workspace>
            <!-- Settings tab = the Messages & Notifications settings. -->
            <MessagesPage v-if="tab === 'settings'" />
        </template>
    </FormBuilderPanel>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api.js';
import FormBuilderPanel from '../components/FormBuilderPanel.vue';
import MessagesPage from './MessagesPage.vue';

const route = useRoute();
const router = useRouter();

const fields = ref([]);
const loading = ref(true);
const error = ref('');

// Same tabs UI as the survey builder — persisted in ?tab=.
const tabs = [
    { key: 'questions', label: 'Questions' },
    { key: 'settings', label: 'Settings' },
];
const TAB_KEYS = tabs.map(t => t.key);
const tab = ref(TAB_KEYS.includes(route.query.tab) ? route.query.tab : 'questions');

function setTab(key) {
    if (!TAB_KEYS.includes(key)) return;
    tab.value = key;
    const query = { ...route.query };
    if (key === 'questions') delete query.tab;
    else query.tab = key;
    router.replace({ query });
}

watch(() => route.query.tab, (t) => {
    tab.value = TAB_KEYS.includes(t) ? t : 'questions';
});

async function loadFields() {
    try {
        const data = await api('/meta');
        fields.value = data.form_fields || [];
    } catch (e) {
        error.value = e.message || 'Unable to load the ticket form.';
    } finally {
        loading.value = false;
    }
}

onMounted(loadFields);
</script>
