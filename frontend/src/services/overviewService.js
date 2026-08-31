import api from './api'

export async function getOverview(config = {}) {
  const response = await api.get('/api/overview', config)
  return response.data
}
