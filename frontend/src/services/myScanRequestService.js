import api from './api'

export async function getMyScanRequests(config = {}) {
  const response = await api.get('/api/my/scan-requests', config)
  return response.data.scan_requests
}

export async function createMyScanRequest(payload) {
  const isFormData = typeof FormData !== 'undefined' && payload instanceof FormData
  const config = isFormData ? { headers: { 'Content-Type': 'multipart/form-data' } } : {}
  const response = await api.post('/api/my/scan-requests', payload, config)
  return response.data.scan_request
}

export async function rerunMyScanRequest(id) {
  const response = await api.post(`/api/my/scan-requests/${id}/rerun`)
  return response.data.scan_request
}
