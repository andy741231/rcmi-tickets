import TicketList from './pages/TicketList.vue';
import TicketCreate from './pages/TicketCreate.vue';
import TicketDetail from './pages/TicketDetail.vue';
import TicketEdit from './pages/TicketEdit.vue';
import TicketRevision from './pages/TicketRevision.vue';
import ApprovalCenter from './pages/ApprovalCenter.vue';
import ApprovalChainEditor from './pages/ApprovalChainEditor.vue';
import FormBuilderPage from './pages/FormBuilderPage.vue';
import TagRulesPage from './pages/TagRulesPage.vue';
import TicketHeaven from './pages/TicketHeaven.vue';
import LoginPage from './pages/LoginPage.vue';
import TicketPublicView from './pages/TicketPublicView.vue';

const config = window.rcmiTickets || {};
const isPublic = !config.isLoggedIn;

export const routes = [
    { path: '/', name: 'ticket-list', component: TicketList },
    { path: '/create', name: 'ticket-create', component: TicketCreate },
    { path: '/ticket/:id', name: 'ticket-detail', component: TicketDetail, props: true },
    { path: '/ticket/:id/edit', name: 'ticket-edit', component: TicketEdit, props: true },
    { path: '/revision/:id', name: 'ticket-revision', component: TicketRevision, props: true },
    { path: '/approvals', name: 'approval-center', component: ApprovalCenter },
    { path: '/approval-edit', name: 'approval-chain-editor', component: ApprovalChainEditor },
    { path: '/form-builder', name: 'form-builder', component: FormBuilderPage },
    { path: '/tag-rules', name: 'tag-rules', component: TagRulesPage },
    { path: '/ticket-heaven', name: 'ticket-heaven', component: TicketHeaven },
    { path: '/login', name: 'login', component: LoginPage },
    { path: '/ticket/:id/view', name: 'ticket-public-view', component: TicketPublicView, props: true },
];

export function publicGuard(router) {
    // Logged-in user on /login → redirect to ticket list
    if (!isPublic) {
        router.beforeEach((to) => {
            if (to.name === 'login') {
                return { name: 'ticket-list' };
            }
        });
        return;
    }
    // In public mode, /create, /login, /revision, and /ticket/:id/view are
    // allowed. Any other internal route (e.g. /ticket/:id from an approval
    // email) redirects to /login with a redirect param so the user can
    // sign in and land on the page they originally requested.
    router.beforeEach((to) => {
        if (['ticket-create', 'ticket-revision', 'ticket-public-view', 'login'].includes(to.name)) {
            return;
        }
        // Preserve the full path (including query/hash) for post-login redirect
        const redirect = to.fullPath || to.path;
        return { name: 'login', query: { redirect } };
    });
}
