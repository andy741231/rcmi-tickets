<template>
    <div class="rcmi-tickets mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <!-- Public mode: minimal header with sign-in link -->
        <template v-if="isPublic">
            <header v-if="$route.name !== 'login'" class="mb-6 border-b border-gray-200 pb-4">
                <p class="rcmi-eyebrow mb-1">RCMI</p>
                <h1 class="rcmi-page-title">Tickets</h1>
            </header>
            <main id="ticket-content">
                <router-view />
            </main>
        </template>
        <!-- Logged-in mode: full header + nav -->
        <template v-else>
            <header class="mb-8 flex flex-col gap-5 border-b border-gray-200 pb-6 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="rcmi-eyebrow mb-2">RCMI</p>
                    <h1 class="rcmi-page-title">Tickets</h1>
                    <p class="mt-2 max-w-xl text-sm text-gray-600">Track Requests</p>
                </div>
                <nav class="flex flex-wrap items-center gap-1" aria-label="Ticket navigation">
                    <router-link to="/" class="rcmi-nav-link" active-class="rcmi-nav-link-active" exact>
                        <Icon name="list" /> All Tickets
                    </router-link>
                    <router-link to="/approvals" class="rcmi-nav-link" active-class="rcmi-nav-link-active">
                        <Icon name="bell" /> Approvals
                        <span v-if="pendingCount > 0" class="rcmi-nav-badge">{{ pendingCount }}</span>
                    </router-link>
                    <router-link v-if="meta.caps.manage" to="/ticket-heaven" class="rcmi-nav-link" active-class="rcmi-nav-link-active">
                        <Icon name="archive" /> Ticket Heaven
                    </router-link>
                    <div v-if="meta.caps.manage" ref="settingsRoot" class="relative">
                        <button type="button" class="rcmi-nav-link" :class="{ 'rcmi-nav-link-active': settingsActive }"
                            aria-haspopup="true" :aria-expanded="settingsOpen"
                            @click="settingsOpen = !settingsOpen" @keydown.escape="closeSettings">
                            <Icon name="settings" /> Settings
                            <Icon name="chevron-down" class="rcmi-nav-caret" :class="{ 'rcmi-nav-caret-open': settingsOpen }" />
                        </button>
                        <div v-if="settingsOpen" class="rcmi-nav-menu" role="menu" aria-label="Settings" @keydown.escape="closeSettings(true)">
                            <router-link to="/approval-edit" role="menuitem" class="rcmi-nav-menu-item" active-class="rcmi-nav-menu-item-active" @click="settingsOpen = false">
                                <Icon name="flow" /> Chains
                            </router-link>
                            <router-link to="/tag-rules" role="menuitem" class="rcmi-nav-menu-item" active-class="rcmi-nav-menu-item-active" @click="settingsOpen = false">
                                <Icon name="tag" /> Tag Rules
                            </router-link>
                            <router-link to="/messages" role="menuitem" class="rcmi-nav-menu-item" active-class="rcmi-nav-menu-item-active" @click="settingsOpen = false">
                                <Icon name="inbox" /> Messages
                            </router-link>
                        </div>
                    </div>
                    <router-link to="/create" class="rcmi-button-primary inline-flex items-center gap-1.5 px-4 py-2 text-sm shadow-sm">
                        <Icon name="plus" /> New Ticket
                    </router-link>
                    <button type="button" @click="handleLogout"
                        class="rcmi-button-secondary inline-flex items-center gap-1.5 px-4 py-2 text-sm"
                        :disabled="loggingOut">
                        <Icon name="arrow-right" />
                        {{ loggingOut ? 'Signing out…' : 'Sign out' }}
                    </button>
                </nav>
            </header>
            <main id="ticket-content">
                <router-view />
            </main>
        </template>
        <Toast />
    </div>
</template>

<script setup>
import { reactive, onMounted, onUnmounted, ref, computed, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { api } from './api.js';
import Toast from './components/Toast.vue';
import Icon from './components/Icon.vue';

const config = window.rcmiTickets || {};
const isPublic = computed(() => !config.isLoggedIn);
const router = useRouter();
const route = useRoute();

const meta = reactive({ caps: {} });
const pendingCount = ref(0);
const loggingOut = ref(false);

const SETTINGS_PATHS = ['/approval-edit', '/tag-rules', '/messages'];
const settingsOpen = ref(false);
const settingsRoot = ref(null);
const settingsActive = computed(() => SETTINGS_PATHS.some((p) => route.path.startsWith(p)));

function closeSettings(refocus = false) {
    settingsOpen.value = false;
    if (refocus) settingsRoot.value?.querySelector('button')?.focus();
}

function onDocumentClick(e) {
    if (settingsRoot.value && !settingsRoot.value.contains(e.target)) settingsOpen.value = false;
}

watch(() => route.path, () => { settingsOpen.value = false; });

async function loadMeta() {
    if (isPublic.value) return; // public mode doesn't need full meta
    try {
        const data = await api('/meta');
        Object.assign(meta, data);
        pendingCount.value = data.pending_approval_count || 0;
    } catch {
        // ignore — meta is non-critical for header
    }
}

function handleLogout() {
    loggingOut.value = true;
    // Full navigation through wp-login.php?action=logout so the OIDC
    // plugin's logout_redirect filter sends the browser through the
    // Entra end_session endpoint — ending the Microsoft session too.
    window.location.href = config.logoutUrl || '/wp-login.php?action=logout';
}

onMounted(() => {
    loadMeta();
    document.addEventListener('click', onDocumentClick);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
});
</script>
