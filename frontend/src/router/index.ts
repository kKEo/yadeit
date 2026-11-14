import { createRouter, createWebHistory } from 'vue-router'
import { setUnauthorizedHandler } from '@/api/client'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { public: true },
    },
    {
      path: '/',
      redirect: '/items',
    },
    {
      path: '/items',
      name: 'items',
      component: () => import('@/views/WorkItemListView.vue'),
    },
    {
      path: '/items/new',
      name: 'create-item',
      component: () => import('@/views/CreateWorkItemView.vue'),
    },
    {
      path: '/items/:key',
      name: 'item-detail',
      component: () => import('@/views/WorkItemDetailView.vue'),
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/items',
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  // One initial session probe; every later navigation reuses the settled promise.
  await auth.ensureInitialized()
  if (!to.meta.public && !auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath === '/items' ? {} : { redirect: to.fullPath } }
  }
  if (to.name === 'login' && auth.isAuthenticated) {
    return { name: 'items' }
  }
})

// Session expired mid-use: drop local state and send the user back to login.
setUnauthorizedHandler(() => {
  const auth = useAuthStore()
  if (!auth.isAuthenticated) return
  auth.reset()
  const current = router.currentRoute.value
  if (current.name !== 'login') {
    void router.push({ name: 'login', query: { redirect: current.fullPath } })
  }
})

export default router
