import api from './api'

export async function getCsrfCookie() {
  await api.get('/sanctum/csrf-cookie')
}

export async function login(credentials) {
  await getCsrfCookie()

  const response = await api.post('/api/login', credentials)

  return response.data.user
}

export async function logout() {
  const response = await api.post('/api/logout')

  return response.data
}

export async function getCurrentUser() {
  const response = await api.get('/api/user')

  return response.data.user
}
