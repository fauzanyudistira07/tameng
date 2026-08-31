import api from './api'

export async function getRoles(config = {}) {
  const response = await api.get('/api/roles', config)
  return response.data.roles
}

export async function getUsers(config = {}) {
  const response = await api.get('/api/users', config)
  return response.data.users
}

export async function createUser(payload) {
  const response = await api.post('/api/users', payload)
  return response.data.user
}

export async function updateUser(id, payload) {
  const response = await api.put(`/api/users/${id}`, payload)
  return response.data.user
}
