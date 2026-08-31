// @ts-nocheck
import { createRouter, createWebHistory } from 'vue-router'
import { authState, initializeAuth } from '../stores/authStore'
import AuditLogsView from '../views/AuditLogsView.vue'
import AuthorizationsView from '../views/AuthorizationsView.vue'
import DashboardView from '../views/DashboardView.vue'
import EnginesView from '../views/EnginesView.vue'
import FindingsView from '../views/FindingsView.vue'
import ForbiddenView from '../views/ForbiddenView.vue'
import LoginView from '../views/LoginView.vue'
import MyScanRequestsView from '../views/MyScanRequestsView.vue'
import ProjectsView from '../views/ProjectsView.vue'
import RepositoriesView from '../views/RepositoriesView.vue'
import ReportsView from '../views/ReportsView.vue'
import ScopesView from '../views/ScopesView.vue'
import ScanJobsView from '../views/ScanJobsView.vue'
import TargetsView from '../views/TargetsView.vue'
import UsersView from '../views/UsersView.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/scan-saya',
      name: 'my-scan-requests',
      component: MyScanRequestsView,
      meta: {
        requiresAuth: true,
        allowedRoles: ['super_admin', 'security_admin', 'security_analyst', 'developer'],
      },
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { guestOnly: true },
    },
    {
      path: '/',
      name: 'dashboard',
      component: DashboardView,
      meta: {
        requiresAuth: true,
        allowedRoles: [
          'super_admin',
          'security_admin',
          'security_analyst',
          'developer',
          'auditor',
          'viewer',
        ],
      },
    },
    {
      path: '/users',
      name: 'users',
      component: UsersView,
      meta: {
        requiresAuth: true,
        allowedRoles: ['super_admin', 'security_admin'],
      },
    },
    {
      path: '/projects',
      name: 'projects',
      component: ProjectsView,
      meta: {
        requiresAuth: true,
        allowedRoles: [
          'super_admin',
          'security_admin',
          'security_analyst',
          'developer',
          'auditor',
          'viewer',
        ],
      },
    },
    {
      path: '/repositories',
      name: 'repositories',
      component: RepositoriesView,
      meta: {
        requiresAuth: true,
        allowedRoles: [
          'super_admin',
          'security_admin',
          'security_analyst',
          'developer',
          'auditor',
          'viewer',
        ],
      },
    },
    {
      path: '/targets',
      name: 'targets',
      component: TargetsView,
      meta: {
        requiresAuth: true,
        allowedRoles: [
          'super_admin',
          'security_admin',
          'security_analyst',
          'developer',
          'auditor',
          'viewer',
        ],
      },
    },
    {
      path: '/scopes',
      name: 'scopes',
      component: ScopesView,
      meta: {
        requiresAuth: true,
        allowedRoles: ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'],
      },
    },
    {
      path: '/authorizations',
      name: 'authorizations',
      component: AuthorizationsView,
      meta: {
        requiresAuth: true,
        allowedRoles: ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'],
      },
    },
    {
      path: '/scan-jobs',
      name: 'scan-jobs',
      component: ScanJobsView,
      meta: {
        requiresAuth: true,
        allowedRoles: ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'],
      },
    },
    {
      path: '/engines',
      name: 'engines',
      component: EnginesView,
      meta: {
        requiresAuth: true,
        allowedRoles: ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'],
      },
    },
    {
      path: '/findings',
      name: 'findings',
      component: FindingsView,
      meta: {
        requiresAuth: true,
        allowedRoles: [
          'super_admin',
          'security_admin',
          'security_analyst',
          'developer',
          'auditor',
          'viewer',
        ],
      },
    },
    {
      path: '/reports',
      name: 'reports',
      component: ReportsView,
      meta: {
        requiresAuth: true,
        allowedRoles: ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'],
      },
    },
    {
      path: '/audit-logs',
      name: 'audit-logs',
      component: AuditLogsView,
      meta: {
        requiresAuth: true,
        allowedRoles: ['super_admin', 'security_admin', 'auditor'],
      },
    },
    {
      path: '/forbidden',
      name: 'forbidden',
      component: ForbiddenView,
      meta: { requiresAuth: true },
    },
  ],
})

router.beforeEach(async (to) => {
  if (!authState.isInitialized) {
    await initializeAuth()
  }

  if (to.meta.requiresAuth && !authState.user) {
    return { name: 'login' }
  }

  if (to.meta.allowedRoles && !to.meta.allowedRoles.includes(authState.user?.role?.name)) {
    return { name: 'forbidden' }
  }

  if (to.meta.guestOnly && authState.user) {
    return { name: authState.user.role?.name === 'developer' ? 'my-scan-requests' : 'dashboard' }
  }

  return true
})

export default router
