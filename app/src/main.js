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

const mountEl = document.getElementById('rcmi-tickets-app');

if (mountEl) {
    createApp(App).use(router).mount(mountEl);
}
