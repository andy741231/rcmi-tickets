<template>
    <div class="rcmi-login-page">
        <div class="rcmi-login-card">
            <!-- Branding -->
            <div class="rcmi-login-brand">
                <p class="rcmi-eyebrow">RCMI</p>
                <h1 class="rcmi-login-title">Tickets</h1>
            </div>

            <!-- Login form -->
            <form v-if="!showReset" @submit.prevent="handleLogin" class="rcmi-login-form">
                <h2 class="rcmi-login-heading">Sign in</h2>
                <p class="rcmi-login-subtitle">Enter your credentials to access the ticket system.</p>

                <div class="rcmi-login-field">
                    <label for="rcmi-login-user" class="rcmi-field-label">Username or Email</label>
                    <input id="rcmi-login-user" v-model="creds.user_login" type="text" required
                        autocomplete="username" :disabled="busy"
                        class="rcmi-input" placeholder="username" />
                </div>

                <div class="rcmi-login-field">
                    <label for="rcmi-login-pass" class="rcmi-field-label">Password</label>
                    <input id="rcmi-login-pass" v-model="creds.user_password" type="password" required
                        autocomplete="current-password" :disabled="busy"
                        class="rcmi-input" placeholder="••••••••" />
                </div>

                <label class="rcmi-login-remember">
                    <input v-model="creds.rememberme" type="checkbox" :disabled="busy"
                        class="h-4 w-4 rounded border-gray-400 text-red-700 focus:ring-red-700" />
                    <span>Remember me on this device</span>
                </label>

                <div v-if="error" role="alert" class="rcmi-login-error">
                    <Icon name="alert" /> {{ error }}
                </div>

                <button type="submit" :disabled="busy"
                    class="rcmi-button-primary inline-flex w-full items-center justify-center gap-2 px-5 py-2.5 text-sm disabled:opacity-50">
                    <Icon name="arrow-right" />
                    {{ busy ? 'Signing in…' : 'Sign in' }}
                </button>

                <button type="button" @click="showReset = true"
                    class="rcmi-login-link">
                    Forgot password?
                </button>
            </form>

            <!-- Reset password form -->
            <form v-else @submit.prevent="handleReset" class="rcmi-login-form">
                <h2 class="rcmi-login-heading">Reset password</h2>
                <p class="rcmi-login-subtitle">Enter your username or email and we'll send a reset link.</p>

                <div class="rcmi-login-field">
                    <label for="rcmi-reset-user" class="rcmi-field-label">Username or Email</label>
                    <input id="rcmi-reset-user" v-model="resetUserLogin" type="text" required
                        :disabled="resetBusy" class="rcmi-input" placeholder="username or email" />
                </div>

                <div v-if="resetMessage" role="alert" class="rcmi-login-success">
                    <Icon name="check-circle" /> {{ resetMessage }}
                </div>
                <div v-if="resetError" role="alert" class="rcmi-login-error">
                    <Icon name="alert" /> {{ resetError }}
                </div>

                <button type="submit" :disabled="resetBusy"
                    class="rcmi-button-primary inline-flex w-full items-center justify-center gap-2 px-5 py-2.5 text-sm disabled:opacity-50">
                    <Icon name="arrow-right" />
                    {{ resetBusy ? 'Sending…' : 'Send reset link' }}
                </button>

                <button type="button" @click="showReset = false; resetMessage = ''; resetError = ''"
                    class="rcmi-login-link">
                    Back to sign in
                </button>
            </form>

            <!-- Public submission link -->
            <div class="rcmi-login-footer">
                <p>Don't have an account?</p>
                <router-link to="/create" class="rcmi-login-link">
                    Submit a public request →
                </router-link>
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import Icon from '../components/Icon.vue';
import { useToast } from '../composables/useToast.js';

const config = window.rcmiTickets || {};
const router = useRouter();
const route = useRoute();
const toast = useToast();

const busy = ref(false);
const error = ref('');
const creds = reactive({
    user_login: '',
    user_password: '',
    rememberme: false,
});

const showReset = ref(false);
const resetBusy = ref(false);
const resetUserLogin = ref('');
const resetMessage = ref('');
const resetError = ref('');

async function handleLogin() {
    busy.value = true;
    error.value = '';
    try {
        // WordPress AJAX login endpoint
        const formData = new FormData();
        formData.append('action', 'rcmi_tickets_ajax_login');
        formData.append('user_login', creds.user_login);
        formData.append('user_password', creds.user_password);
        formData.append('rememberme', creds.rememberme ? 'forever' : '');
        formData.append('nonce', config.nonce);

        const res = await fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        });

        const data = await res.json().catch(() => null);

        if (data && data.success) {
            // Reload the page so WordPress re-evaluates is_user_logged_in()
            // and re-localizes the script data with a fresh nonce.
            toast.success('Welcome back! Redirecting…');
            // Stash the redirect target so the SPA can navigate to it
            // after the page reloads in logged-in mode.
            const redirect = route.query.redirect;
            if (redirect) {
                try { sessionStorage.setItem('rcmi_redirect', String(redirect)); } catch (e) {}
            }
            setTimeout(() => {
                window.location.href = config.appUrl || window.location.pathname;
            }, 600);
        } else {
            error.value = (data && data.data && data.data.message)
                || 'Invalid username or password.';
        }
    } catch (e) {
        error.value = e.message || 'Login failed. Please try again.';
    } finally {
        busy.value = false;
    }
}

async function handleReset() {
    resetBusy.value = true;
    resetError.value = '';
    resetMessage.value = '';
    try {
        const formData = new FormData();
        formData.append('action', 'rcmi_tickets_ajax_reset');
        formData.append('user_login', resetUserLogin.value);
        formData.append('nonce', config.nonce);

        const res = await fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        });

        const data = await res.json().catch(() => null);

        if (data && data.success) {
            resetMessage.value = 'If an account exists for that email/username, a reset link has been sent.';
            resetUserLogin.value = '';
        } else {
            resetError.value = (data && data.data && data.data.message)
                || 'Could not process the request.';
        }
    } catch (e) {
        resetError.value = e.message || 'Request failed. Please try again.';
    } finally {
        resetBusy.value = false;
    }
}
</script>
