import api from './api'

export async function getProjects(config = {}) {
  const response = await api.get('/api/projects', config)
  return response.data.projects
}

export async function createProject(payload) {
  const response = await api.post('/api/projects', payload)
  return response.data.project
}

export async function updateProject(id, payload) {
  const response = await api.put(`/api/projects/${id}`, payload)
  return response.data.project
}
