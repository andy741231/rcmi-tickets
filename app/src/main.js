import { createApp } from 'vue';
import { createRouter, createWebHashHistory } from 'vue-router';
import App from './App.vue';
import { routes, publicGuard } from './router.js';
import './style.css';

const router = createRouter({
    history: createWebHashHistory(),
    routes,
});

// In public mode, restrict all routes to /create only
publicGuard(router);

// After a login redirect, navigate to the stashed target once the SPA
// is back in logged-in mode.
router.isReady().then(() => {
    try {
        const redirect = sessionStorage.getItem('rcmi_redirect');
        if (redirect) {
            sessionStorage.removeItem('rcmi_redirect');
            router.push(redirect);
        }
    } catch (e) {}
});

const mountEl = document.getElementById('rcmi-tickets-app');

if (mountEl) {
    createApp(App).use(router).mount(mountEl);
}
