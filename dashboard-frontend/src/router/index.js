import { h, resolveComponent } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import PublicLayout from '@/layouts/PublicLayout'
import DefaultLayout from '@/layouts/DefaultLayout'

const routes = [
  {
    path: '/',
    name: 'PublicHome',
    component: PublicLayout,
    children: [
      {
        path: '',
        name: 'PublicDashboard',
        component: () => import('@/views/dashboard/Dashboard.vue'),
      },
    ],
  },
  {
    path: '/admin',
    name: 'Home',
    component: DefaultLayout,
    redirect: '/dashboard',
    children: [
      {
        path: '/dashboard',
        name: 'Dashboard',
        alias: '/admin-dashboard',
        component: () => import('@/views/dashboard/AdminDashboard.vue'),
      },
      {
        path: '/reports',
        name: 'Reports',
        component: () => import('@/views/reports/Reports.vue'),
      },
      {
        path: '/charts',
        name: 'Charts',
        component: () => import('@/views/charts/Charts.vue'),
      },
    ],
  },
  {
    path: '/login',
    name: 'Login',
    component: () => import('@/views/pages/Login.vue'),
  },
  {
    path: '/profile',
    name: 'Profile',
    component: () => import('@/views/pages/Profile.vue'),
  },
  {
    path: '/logout',
    name: 'Logout',
    beforeEnter: (to, from, next) => {
      // Clear auth data
      localStorage.removeItem('mnh_token')
      localStorage.removeItem('mnh_user')
      // Redirect to login
      next({ name: 'Login' })
    },
  },
]

const router = createRouter({
  history: createWebHashHistory(import.meta.env.BASE_URL),
  routes,
  scrollBehavior() {
    // always scroll to top
    return { top: 0 }
  },
})

router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('mnh_token')
  const publicRoutes = ['Login', 'PublicDashboard']

  const authRequired = !publicRoutes.includes(to.name)

  if (authRequired && !token) {
    return next({ name: 'Login' })
  }

  // Redirect authenticated users away from login
  if (to.name === 'Login' && token) {
    return next({ name: 'Dashboard' })
  }

  next()
})

export default router
