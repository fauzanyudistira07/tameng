import api from './api'

export async function getTargets(config = {}) {
  const response = await api.get('/api/targets', config)
  return response.data.targets
}

export async function createTarget(payload) {
  const response = await api.post('/api/targets', payload)
  return response.data.target
}

export async function updateTarget(id, payload) {
  const response = await api.put(`/api/targets/${id}`, payload)
  return response.data.target
}

export async function verifyTarget(id, verification_status) {
  const response = await api.post(`/api/targets/${id}/verify`, { verification_status })
  return response.data.target
}
