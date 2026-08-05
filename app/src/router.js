import TicketList from './pages/TicketList.vue';
import TicketCreate from './pages/TicketCreate.vue';
import TicketDetail from './pages/TicketDetail.vue';
import TicketEdit from './pages/TicketEdit.vue';
import ApprovalCenter from './pages/ApprovalCenter.vue';
import ApprovalChainEditor from './pages/ApprovalChainEditor.vue';
import FormBuilderPage from './pages/FormBuilderPage.vue';

const config = window.rcmiTickets || {};
const isPublic = !config.isLoggedIn;

export const routes = [
    { path: '/', name: 'ticket-list', component: TicketList },
    { path: '/create', name: 'ticket-create', component: TicketCreate },
    { path: '/ticket/:id', name: 'ticket-detail', component: TicketDetail, props: true },
    { path: '/ticket/:id/edit', name: 'ticket-edit', component: TicketEdit, props: true },
    { path: '/approvals', name: 'approval-center', component: ApprovalCenter },
    { path: '/approval-edit', name: 'approval-chain-editor', component: ApprovalChainEditor },
    { path: '/form-builder', name: 'form-builder', component: FormBuilderPage },
];

export function publicGuard(router) {
    if (!isPublic) return;
    // In public mode, only /create is allowed; everything else redirects to /create
    router.beforeEach((to) => {
        if (to.name !== 'ticket-create') {
            return { name: 'ticket-create' };
        }
    });
}
