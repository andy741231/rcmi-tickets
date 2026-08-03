import TicketList from './pages/TicketList.vue';
import TicketCreate from './pages/TicketCreate.vue';
import TicketDetail from './pages/TicketDetail.vue';
import TicketEdit from './pages/TicketEdit.vue';

export const routes = [
    { path: '/', name: 'ticket-list', component: TicketList },
    { path: '/create', name: 'ticket-create', component: TicketCreate },
    { path: '/ticket/:id', name: 'ticket-detail', component: TicketDetail, props: true },
    { path: '/ticket/:id/edit', name: 'ticket-edit', component: TicketEdit, props: true },
];
