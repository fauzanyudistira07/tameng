import api from './api'

export async function getFindings(config = {}) {
  const response = await api.get('/api/findings', config)
  return response.data
}

export async function getFindingAiRemediation(id) {
  const response = await api.get(`/api/findings/${id}/ai-remediation`)
  return response.data.remediation
}

export async function updateFindingStatus(id, payload) {
  const body = typeof payload === 'string' ? { status: payload } : payload
  const response = await api.put(`/api/findings/${id}`, body)
  return response.data.finding
}
