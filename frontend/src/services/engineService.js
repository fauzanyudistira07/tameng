import api from './api'

export async function getEngines(config = {}) {
  const response = await api.get('/api/engines', config)
  return response.data.engines
}
