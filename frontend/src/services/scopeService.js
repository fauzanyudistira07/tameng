import api from './api'

export async function getScopes(config = {}) {
  const response = await api.get('/api/scopes', config)
  return response.data.scopes
}

export async function createScope(payload) {
  const response = await api.post('/api/scopes', payload)
  return response.data.scope
}

export async function updateScope(id, payload) {
  const response = await api.put(`/api/scopes/${id}`, payload)
  return response.data.scope
}
