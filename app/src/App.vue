<template>
    <div class="rcmi-tickets mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <!-- Public mode: minimal header with sign-in link -->
        <template v-if="isPublic">
            <header v-if="$route.name !== 'login'" class="mb-6 flex items-center justify-between border-b border-gray-200 pb-4">
                <div>
                    <p class="rcmi-eyebrow mb-1">RCMI</p>
                    <h1 class="rcmi-page-title">Tickets</h1>
                </div>
                <router-link to="/login" class="rcmi-button-secondary inline-flex items-center gap-1.5 px-4 py-2 text-sm">
                    <Icon name="user-check" /> Sign in
                </router-link>
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
                    <router-link v-if="meta.caps.manage" to="/approval-edit" class="rcmi-nav-link" active-class="rcmi-nav-link-active">
                        <Icon name="flow" /> Chains
                    </router-link>
                    <router-link v-if="meta.caps.manage" to="/tag-rules" class="rcmi-nav-link" active-class="rcmi-nav-link-active">
                        <Icon name="tag" /> Tag Rules
                    </router-link>
                    <router-link v-if="meta.caps.manage" to="/ticket-heaven" class="rcmi-nav-link" active-class="rcmi-nav-link-active">
                        <Icon name="archive" /> Ticket Heaven
                    </router-link>
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
import { reactive, onMounted, ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { api } from './api.js';
import Toast from './components/Toast.vue';
import Icon from './components/Icon.vue';

const config = window.rcmiTickets || {};
const isPublic = computed(() => !config.isLoggedIn);
const router = useRouter();

const meta = reactive({ caps: {} });
const pendingCount = ref(0);
const loggingOut = ref(false);

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

async function handleLogout() {
    loggingOut.value = true;
    try {
        const formData = new FormData();
        formData.append('action', 'rcmi_tickets_ajax_logout');
        formData.append('nonce', config.nonce);
        await fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        });
    } catch (e) {
        // proceed to redirect even if the AJAX call fails
    }
    // Force a full page reload so PHP re-evaluates is_user_logged_in()
    // and the SPA re-initializes in public mode. The public guard will
    // redirect to /login?redirect=<currentPath>.
    // Use window.location.reload() to guarantee a fresh page load, then
    // the hash stays the same so the guard can pick up the redirect target.
    window.location.reload();
}

onMounted(loadMeta);
</script>
