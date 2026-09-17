import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from '../views/DashboardView.vue'
import ScanJobsView from '../views/ScanJobsView.vue'
import UsersView from '../views/UsersView.vue'
import LoginView from '../views/LoginView.vue'
import MyScanRequestsView from '../views/MyScanRequestsView.vue'
import ProjectsView from '../views/ProjectsView.vue'
import RepositoriesView from '../views/RepositoriesView.vue'
import TargetsView from '../views/TargetsView.vue'
import ScopesView from '../views/ScopesView.vue'
import AuthorizationsView from '../views/AuthorizationsView.vue'
import EnginesView from '../views/EnginesView.vue'
import FindingsView from '../views/FindingsView.vue'
import ReportsView from '../views/ReportsView.vue'
import AuditLogsView from '../views/AuditLogsView.vue'
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
    path: '/scan-jobs',
    name: 'scan-jobs',
    component: ScanJobsView
  },
  {
    path: '/scan-mandiri',
    name: 'scan-mandiri',
    component: MyScanRequestsView
  },
  {
    path: '/scan-saya',
    redirect: '/scan-mandiri'
  },
  {
    path: '/projects',
    name: 'projects',
    component: ProjectsView
  },
  {
    path: '/repositories',
    name: 'repositories',
    component: RepositoriesView
  },
  {
    path: '/targets',
    name: 'targets',
    component: TargetsView
  },
  {
    path: '/scopes',
    name: 'scopes',
    component: ScopesView
  },
  {
    path: '/authorizations',
    name: 'authorizations',
    component: AuthorizationsView
  },
  {
    path: '/engines',
    name: 'engines',
    component: EnginesView
  },
  {
    path: '/findings',
    name: 'findings',
    component: FindingsView
  },
  {
    path: '/reports',
    name: 'reports',
    component: ReportsView
  },
  {
    path: '/audit-logs',
    name: 'audit-logs',
    component: AuditLogsView
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
