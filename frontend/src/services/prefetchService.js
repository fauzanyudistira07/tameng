import api from './api'

const routeEndpointMap = {
  dashboard: ['/api/overview'],
  'my-scan-requests': ['/api/my/scan-requests'],
  'scan-jobs': ['/api/authorizations', '/api/scan-jobs'],
  projects: ['/api/projects', '/api/users'],
  repositories: ['/api/projects', '/api/repositories'],
  targets: ['/api/projects', '/api/targets'],
  scopes: ['/api/projects', '/api/targets', '/api/scopes'],
  authorizations: ['/api/projects', '/api/repositories', '/api/targets', '/api/scan-profiles', '/api/authorizations'],
  engines: ['/api/engines'],
  findings: ['/api/findings', '/api/projects'],
  reports: ['/api/reports', '/api/scan-jobs'],
  'audit-logs': ['/api/audit-logs'],
  users: ['/api/users', '/api/roles'],
}

const rolePrefetchMap = {
  developer: [
    '/api/my/scan-requests',
    '/api/projects',
    '/api/repositories',
    '/api/targets',
    '/api/findings',
  ],
  super_admin: [
    '/api/overview',
    '/api/scan-jobs',
    '/api/projects',
    '/api/repositories',
    '/api/targets',
    '/api/scopes',
    '/api/authorizations',
    '/api/scan-profiles',
    '/api/findings',
    '/api/reports',
    '/api/audit-logs',
    '/api/engines',
    '/api/users',
    '/api/roles',
  ],
  security_admin: [
    '/api/overview',
    '/api/scan-jobs',
    '/api/projects',
    '/api/repositories',
    '/api/targets',
    '/api/scopes',
    '/api/authorizations',
    '/api/scan-profiles',
    '/api/findings',
    '/api/reports',
    '/api/audit-logs',
    '/api/engines',
    '/api/users',
  ],
  security_analyst: [
    '/api/overview',
    '/api/scan-jobs',
    '/api/projects',
    '/api/repositories',
    '/api/targets',
    '/api/scopes',
    '/api/authorizations',
    '/api/scan-profiles',
    '/api/findings',
    '/api/reports',
    '/api/engines',
  ],
  auditor: [
    '/api/overview',
    '/api/scan-jobs',
    '/api/projects',
    '/api/findings',
    '/api/reports',
    '/api/audit-logs',
    '/api/engines',
  ],
  viewer: [
    '/api/overview',
    '/api/scan-jobs',
    '/api/projects',
    '/api/findings',
    '/api/reports',
    '/api/engines',
  ],
}

let isPrefetching = false

export function prefetchAppData(roleName) {
  if (!roleName || isPrefetching) return
  isPrefetching = true

  const endpoints = rolePrefetchMap[roleName] || ['/api/overview']
  
  // Use requestIdleCallback or setTimeout to prefetch smoothly without blocking the main thread
  const scheduler = window.requestIdleCallback || ((cb) => setTimeout(cb, 50))
  
  scheduler(() => {
    endpoints.forEach((url, index) => {
      setTimeout(() => {
        api.prefetch(url)
      }, index * 40)
    })
    setTimeout(() => {
      isPrefetching = false
    }, endpoints.length * 40 + 500)
  })
}

export function prefetchRouteData(routeName) {
  const endpoints = routeEndpointMap[routeName]
  if (!endpoints || !endpoints.length) return

  endpoints.forEach((url) => {
    api.prefetch(url)
  })
}
