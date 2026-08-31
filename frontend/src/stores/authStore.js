import { reactive } from 'vue'
import api from '../services/api'
import { getCurrentUser, login, logout } from '../services/authService'
import { prefetchAppData } from '../services/prefetchService'

export const authState = reactive({
  user: null,
  isLoading: false,
  isInitialized: false,
})

export async function initializeAuth() {
  if (authState.isInitialized) {
    return authState.user
  }

  authState.isLoading = true

  try {
    authState.user = await getCurrentUser()
    if (authState.user?.role?.name) {
      prefetchAppData(authState.user.role.name)
    }
  } catch {
    authState.user = null
  } finally {
    authState.isLoading = false
    authState.isInitialized = true
  }

  return authState.user
}

export async function loginUser(credentials) {
  authState.isLoading = true

  try {
    authState.user = await login(credentials)
    authState.isInitialized = true
    if (authState.user?.role?.name) {
      prefetchAppData(authState.user.role.name)
    }

    return authState.user
  } finally {
    authState.isLoading = false
  }
}

export async function logoutUser() {
  authState.isLoading = true

  try {
    await logout()
    authState.user = null
    authState.isInitialized = true
    api.clearCache()
  } finally {
    authState.isLoading = false
  }
}
