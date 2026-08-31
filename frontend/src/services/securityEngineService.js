import api from './api'

export async function getSecurityEngines(params = {}) {
  const response = await api.get('/api/security/engines', { params })
  return response.data
}

export async function getSecurityEngine(id) {
  const response = await api.get(`/api/security/engines/${id}`)
  return response.data?.data
}

export async function toggleSecurityEngine(id) {
  const response = await api.post(`/api/security/engines/${id}/toggle`)
  return response.data
}

export async function checkSecurityEngineHealth(id) {
  const response = await api.post(`/api/security/engines/${id}/health-check`)
  return response.data
}

export async function getScanProfiles() {
  const response = await api.get('/api/security/scan-profiles')
  return response.data?.data
}
