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
                    <div ref="userMenuRoot" class="relative">
                        <button type="button" class="rcmi-nav-link rcmi-nav-user" :class="{ 'rcmi-nav-user-open': userMenuOpen }"
                            aria-haspopup="true" :aria-expanded="userMenuOpen" :aria-label="'Account: ' + currentUserName"
                            @click="userMenuOpen = !userMenuOpen" @keydown.escape="closeUserMenu">
                            <img v-if="currentUser.photo" :src="currentUser.photo" :alt="currentUserName"
                                class="h-7 w-7 rounded-full object-cover" />
                            <span v-else class="rcmi-avatar h-7 w-7 text-xs">{{ initials(currentUserName) }}</span>
                            <span class="hidden max-w-40 truncate sm:inline-block">{{ currentUserName }}</span>
                            <Icon name="chevron-down" class="rcmi-nav-caret" :class="{ 'rcmi-nav-caret-open': userMenuOpen }" />
                        </button>
                        <div v-if="userMenuOpen" class="rcmi-nav-menu min-w-56" role="menu" aria-label="Account" @keydown.escape="closeUserMenu(true)">
                            <div class="rcmi-nav-menu-identity" role="presentation">
                                <img v-if="currentUser.photo" :src="currentUser.photo" alt=""
                                    class="h-9 w-9 rounded-full object-cover" />
                                <span v-else class="rcmi-avatar h-9 w-9 text-sm">{{ initials(currentUserName) }}</span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold text-gray-800">{{ currentUserName }}</p>
                                    <p class="truncate text-xs text-gray-500">{{ currentUser.user_email }}</p>
                                </div>
                            </div>
                            <div class="rcmi-nav-menu-divider" role="separator"></div>
                            <button type="button" role="menuitem" class="rcmi-nav-menu-item w-full text-left"
                                :disabled="loggingOut" @click="handleLogout">
                                <Icon name="arrow-right" />
                                {{ loggingOut ? 'Signing out…' : 'Sign out' }}
                            </button>
                        </div>
                    </div>
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

const userMenuOpen = ref(false);
const userMenuRoot = ref(null);
const currentUser = computed(() => meta.current_user || {});
const currentUserName = computed(() => currentUser.value.display_name || currentUser.value.user_login || 'Account');

function closeUserMenu(refocus = false) {
    userMenuOpen.value = false;
    if (refocus) userMenuRoot.value?.querySelector('button')?.focus();
}

// Same initials fallback as the requestor avatar in TicketDetail.vue.
function initials(name) {
    if (!name) return '?';
    return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2);
}

function onDocumentClick(e) {
    if (settingsRoot.value && !settingsRoot.value.contains(e.target)) settingsOpen.value = false;
    if (userMenuRoot.value && !userMenuRoot.value.contains(e.target)) userMenuOpen.value = false;
}

watch(() => route.path, () => { settingsOpen.value = false; userMenuOpen.value = false; });

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
