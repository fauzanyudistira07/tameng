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
import ForbiddenView from '../views/ForbiddenView.vue'
import UserWorkspaceHomeView from '../views/user/UserWorkspaceHomeView.vue'
import UserTicketsView from '../views/user/UserTicketsView.vue'
import UserFilesView from '../views/user/UserFilesView.vue'
import UserProfileView from '../views/user/UserProfileView.vue'
import { checkAuth, getIsAuthenticated } from '../services/api'
import { useAuth } from '../composables/useAuth'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { layout: 'blank' }
  },
  {
    path: '/403',
    name: 'forbidden',
    component: ForbiddenView,
    meta: { layout: 'blank' }
  },
  // Dedicated User Portal (Personal Workspace) Routes
  {
    path: '/workspace',
    name: 'workspace',
    component: UserWorkspaceHomeView,
    meta: { layout: 'user', roles: ['developer', 'viewer', 'super_admin', 'security_admin', 'security_analyst'] }
  },
  {
    path: '/workspace/tickets',
    name: 'workspace-tickets',
    component: UserTicketsView,
    meta: { layout: 'user', roles: ['developer', 'viewer', 'super_admin', 'security_admin', 'security_analyst'] }
  },
  {
    path: '/workspace/files',
    name: 'workspace-files',
    component: UserFilesView,
    meta: { layout: 'user', roles: ['developer', 'viewer', 'super_admin', 'security_admin', 'security_analyst'] }
  },
  {
    path: '/workspace/scans',
    name: 'workspace-scans',
    component: MyScanRequestsView,
    meta: { layout: 'user', roles: ['developer', 'super_admin', 'security_admin', 'security_analyst'] }
  },
  {
    path: '/workspace/reports',
    name: 'workspace-reports',
    component: ReportsView,
    meta: { layout: 'user', roles: ['developer', 'viewer', 'auditor', 'super_admin', 'security_admin', 'security_analyst'] }
  },
  {
    path: '/workspace/profile',
    name: 'workspace-profile',
    component: UserProfileView,
    meta: { layout: 'user', roles: ['developer', 'viewer', 'auditor', 'super_admin', 'security_admin', 'security_analyst'] }
  },
  // Admin SOC Routes (Preserved 100%)
  {
    path: '/',
    name: 'dashboard',
    component: DashboardView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst'] }
  },
  {
    path: '/scan-jobs',
    name: 'scan-jobs',
    component: ScanJobsView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst'] }
  },
  {
    path: '/scan-mandiri',
    name: 'scan-mandiri',
    component: MyScanRequestsView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst', 'developer'] }
  },
  {
    path: '/scan-saya',
    redirect: '/scan-mandiri'
  },
  {
    path: '/projects',
    name: 'projects',
    component: ProjectsView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst', 'developer', 'auditor', 'viewer'] }
  },
  {
    path: '/repositories',
    name: 'repositories',
    component: RepositoriesView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst', 'developer', 'auditor', 'viewer'] }
  },
  {
    path: '/targets',
    name: 'targets',
    component: TargetsView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst', 'developer', 'auditor', 'viewer'] }
  },
  {
    path: '/scopes',
    name: 'scopes',
    component: ScopesView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst', 'auditor'] }
  },
  {
    path: '/authorizations',
    name: 'authorizations',
    component: AuthorizationsView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst', 'auditor'] }
  },
  {
    path: '/engines',
    name: 'engines',
    component: EnginesView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst', 'auditor'] }
  },
  {
    path: '/findings',
    name: 'findings',
    component: FindingsView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst', 'developer', 'auditor', 'viewer'] }
  },
  {
    path: '/reports',
    name: 'reports',
    component: ReportsView,
    meta: { roles: ['super_admin', 'security_admin', 'security_analyst', 'developer', 'auditor', 'viewer'] }
  },
  {
    path: '/audit-logs',
    name: 'audit-logs',
    component: AuditLogsView,
    meta: { roles: ['super_admin', 'security_admin', 'auditor'] }
  },
  {
    path: '/users',
    name: 'users',
    component: UsersView,
    meta: { roles: ['super_admin', 'security_admin'] }
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
      const { loadUser } = useAuth()
      const user = await loadUser()
      const role = user?.role?.name || ''
      if (role === 'developer' || role === 'viewer') return next({ name: 'workspace' })
      if (role === 'auditor') return next({ name: 'reports' })
      return next({ name: 'dashboard' })
    }
    return next()
  }

  if (!isAuthed) {
    return next({ name: 'login' })
  }

  // Load user data untuk verifikasi wewenang role
  const { loadUser } = useAuth()
  const user = await loadUser()
  const userRole = user?.role?.name || ''

  // Jika developer atau viewer mengakses root '/', alihkan langsung ke ruang kerja personal
  if (to.path === '/') {
    if (userRole === 'developer' || userRole === 'viewer') {
      return next({ name: 'workspace' })
    }
    if (userRole === 'auditor') {
      return next({ name: 'reports' })
    }
  }

  // Jika developer mengakses rute admin findings/projects/repositori lama, alihkan ke workspace counterpart
  if (userRole === 'developer' || userRole === 'viewer') {
    if (to.path === '/findings') {
      return next({ name: 'workspace-tickets' })
    }
    if (to.path === '/projects' || to.path === '/repositories' || to.path === '/targets') {
      return next({ name: 'workspace-files' })
    }
    if (to.path === '/scan-mandiri') {
      return next({ name: 'workspace-scans' })
    }
  }

  // Cek otorisasi rute berdasarkan meta.roles
  const allowedRoles = to.meta?.roles as string[] | undefined
  if (allowedRoles && Array.isArray(allowedRoles)) {
    if (!allowedRoles.includes(userRole)) {
      return next({ name: 'forbidden' })
    }
  }

  next()
})

export default router
