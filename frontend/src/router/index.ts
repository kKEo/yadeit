import { createRouter, createWebHistory, type RouteLocationRaw } from 'vue-router'
import { setUnauthorizedHandler } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    // ── Public: reachable signed out ─────────────────────────────────────────
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { public: true, guestOnly: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { public: true, guestOnly: true },
    },
    {
      path: '/forgot-password',
      name: 'forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
      meta: { public: true },
    },
    {
      path: '/reset-password',
      name: 'reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
      meta: { public: true },
    },
    {
      path: '/verify-email',
      name: 'verify-email',
      component: () => import('@/views/VerifyEmailView.vue'),
      meta: { public: true },
    },
    {
      path: '/invitations/:token',
      name: 'invitation',
      component: () => import('@/views/InvitationView.vue'),
      meta: { public: true },
    },

    {
      path: '/p/:token',
      name: 'public-page',
      component: () => import('@/views/PublicPageView.vue'),
      meta: { public: true },
    },

    {
      path: '/f/:token',
      name: 'public-feedback',
      component: () => import('@/views/PublicFeedbackView.vue'),
      meta: { public: true },
    },

    // ── Signed in ────────────────────────────────────────────────────────────
    {
      // Never rendered: the guard below sends the user to a workspace or to onboarding.
      path: '/',
      name: 'home',
      component: { render: () => null },
    },
    {
      path: '/welcome',
      name: 'welcome',
      component: () => import('@/views/CreateWorkspaceView.vue'),
    },
    {
      path: '/workspaces/new',
      name: 'workspace-new',
      component: () => import('@/views/CreateWorkspaceView.vue'),
    },
    {
      path: '/w/:slug',
      component: () => import('@/layouts/WorkspaceLayout.vue'),
      children: [
        // The items page is the landing page of a workspace.
        { path: '', name: 'workspace', redirect: (to) => ({ name: 'items', params: to.params }) },
        { path: 'projects', name: 'projects', component: () => import('@/views/ProjectListView.vue') },
        { path: 'inbox', name: 'inbox', component: () => import('@/views/InboxView.vue') },
        { path: 'members', name: 'workspace-members', component: () => import('@/views/MembersView.vue') },
        { path: 'feedback', name: 'feedback', component: () => import('@/views/FeedbackView.vue') },
        { path: 'docs', name: 'docs', component: () => import('@/views/DocsView.vue') },
        { path: 'docs/:id(\\d+)', name: 'doc', component: () => import('@/views/DocView.vue') },
        { path: 'agents', name: 'agents', component: () => import('@/views/AgentsView.vue') },
        { path: 'settings', name: 'workspace-settings', component: () => import('@/views/WorkspaceSettingsView.vue') },
        { path: 'items', name: 'items', component: () => import('@/views/ItemsView.vue') },
        { path: 'items/:key', name: 'item-detail', component: () => import('@/views/ItemDetailView.vue') },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

router.beforeEach(async (to): Promise<RouteLocationRaw | undefined> => {
  const auth = useAuthStore()
  // One initial session probe; every later navigation reuses the settled promise.
  await auth.ensureInitialized()

  if (!auth.user) {
    if (to.meta.public) return undefined
    return { name: 'login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } }
  }
  if (to.meta.guestOnly) return { name: 'home' }
  if (to.name === 'home') {
    const first = auth.user.workspaces[0]
    return first ? { name: 'workspace', params: { slug: first.slug } } : { name: 'welcome' }
  }
  return undefined
})

// Session expired mid-use: drop local state and send the user back to login.
setUnauthorizedHandler(() => {
  const auth = useAuthStore()
  if (!auth.isAuthenticated) return
  auth.reset()
  const current = router.currentRoute.value
  if (!current.meta.public) {
    void router.push({ name: 'login', query: { redirect: current.fullPath } })
  }
})

export default router
