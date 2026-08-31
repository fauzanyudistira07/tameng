import api from './api'

export async function getRepositories(config = {}) {
  const response = await api.get('/api/repositories', config)
  return response.data.repositories
}

export async function createRepository(payload) {
  const response = await api.post('/api/repositories', payload)
  return response.data.repository
}

export async function updateRepository(id, payload) {
  const response = await api.put(`/api/repositories/${id}`, payload)
  return response.data.repository
}

export async function verifyRepository(id, verification_status) {
  const response = await api.post(`/api/repositories/${id}/verify`, { verification_status })
  return response.data.repository
}

export async function attachRepositoryWorkspace(id, local_path) {
  const response = await api.post(`/api/repositories/${id}/workspace`, { local_path })
  return response.data
}

export async function cloneRepositoryWorkspace(id) {
  const response = await api.post(`/api/repositories/${id}/clone-workspace`)
  return response.data
}

export async function clearRepositoryWorkspace(id) {
  const response = await api.delete(`/api/repositories/${id}/workspace`)
  return response.data
}
