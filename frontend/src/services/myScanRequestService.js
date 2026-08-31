import api from './api'

export async function getMyScanRequests(config = {}) {
  const response = await api.get('/api/my/scan-requests', config)
  return response.data.scan_requests
}

export async function createMyScanRequest(payload) {
  const response = await api.post('/api/my/scan-requests', payload)
  return response.data.scan_request
}

export async function rerunMyScanRequest(id) {
  const response = await api.post(`/api/my/scan-requests/${id}/rerun`)
  return response.data.scan_request
}
