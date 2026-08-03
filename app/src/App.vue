<template>
    <div class="rcmi-tickets mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-8">
        <header class="mb-8 flex flex-col gap-5 border-b border-gray-200 pb-6 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="rcmi-eyebrow mb-2">Research Capacity &amp; Mentoring Institute</p>
                <h1 class="rcmi-page-title">Tickets</h1>
                <p class="mt-2 max-w-xl text-sm text-gray-600">Track requests, collaborate with your team, and keep work moving forward.</p>
            </div>
            <nav class="flex items-center gap-2" aria-label="Ticket navigation">
                <router-link to="/" class="rounded-md px-3 py-2 text-sm font-semibold text-gray-600 hover:bg-gray-100 hover:text-gray-900">All Tickets</router-link>
                <router-link to="/create" class="rcmi-button-primary inline-flex items-center px-4 py-2 text-sm shadow-sm">New Ticket</router-link>
            </nav>
        </header>
        <main id="ticket-content">
            <router-view />
        </main>
        <Toast />
    </div>
</template>

<script setup>
import Toast from './components/Toast.vue';
</script>
