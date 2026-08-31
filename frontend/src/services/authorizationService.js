import api from './api'

export async function getScanProfiles(config = {}) {
  const response = await api.get('/api/scan-profiles', config)
  return response.data.scan_profiles
}

export async function getAuthorizations(config = {}) {
  const response = await api.get('/api/authorizations', config)
  return response.data.authorizations
}

export async function createAuthorization(payload) {
  const response = await api.post('/api/authorizations', payload)
  return response.data.authorization
}
