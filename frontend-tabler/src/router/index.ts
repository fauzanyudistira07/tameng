import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'
import UsersView from '../views/UsersView.vue'
import LoginView from '../views/LoginView.vue'
import { checkAuth, getIsAuthenticated } from '../services/api'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { layout: 'blank' }
  },
  {
    path: '/',
    name: 'dashboard',
    component: DashboardView
  },
  {
    path: '/users',
    name: 'users',
    component: UsersView
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/'
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

router.beforeEach(async (to, from, next) => {
  const isAuthed = getIsAuthenticated() ? true : await checkAuth()

  if (to.name === 'login') {
    if (isAuthed) {
      return next({ name: 'dashboard' })
    }
    return next()
  }

  if (!isAuthed) {
    return next({ name: 'login' })
  }
  next()
})

export default router
