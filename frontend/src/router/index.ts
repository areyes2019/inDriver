import { createRouter, createWebHistory } from 'vue-router'
import HomeView from '../views/HomeView.vue'
import { useAdminAuthStore } from '@/stores/adminAuth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      name: 'home',
      component: HomeView,
    },
    {
      path: '/about',
      name: 'about',
      // route level code-splitting
      // this generates a separate chunk (About.[hash].js) for this route
      // which is lazy-loaded when the route is visited.
      component: () => import('../views/AboutView.vue'),
    },
    {
      path: '/admin/login',
      name: 'admin-login',
      component: () => import('../views/admin/LoginView.vue'),
    },
    {
      path: '/admin/forgot-password',
      name: 'admin-forgot-password',
      component: () => import('../views/admin/ForgotPasswordView.vue'),
    },
    {
      path: '/admin/reset-password/:token',
      name: 'admin-reset-password',
      component: () => import('../views/admin/ResetPasswordView.vue'),
    },
    {
      path: '/admin',
      name: 'admin-dashboard',
      component: () => import('../views/admin/DashboardView.vue'),
      meta: { requiresAdminAuth: true },
    },
    ...(import.meta.env.DEV
      ? [
          {
            path: '/admin/style-guide',
            name: 'admin-style-guide',
            component: () => import('../views/admin/StyleGuideView.vue'),
          },
        ]
      : []),
  ],
})

router.beforeEach(async (to) => {
  if (!to.meta.requiresAdminAuth) return true

  const auth = useAdminAuthStore()
  if (!auth.checked) {
    await auth.fetchMe()
  }

  if (!auth.isAuthenticated) {
    return { name: 'admin-login' }
  }

  return true
})

export default router
