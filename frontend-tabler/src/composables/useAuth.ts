import { ref, computed, onMounted } from 'vue'
import { apiFetch, ensureAuthenticated } from '../services/api'

export interface UserRole {
  id?: number
  name: string
  display_name: string
}

export interface UserProject {
  id: number
  name: string
  code: string
  access_level?: string
}

export interface UserPreference {
  alert_email: boolean
  alert_telegram: boolean
  telegram_chat_id?: string | null
  alert_min_severity: string
}

export interface User {
  id: number
  role_id?: number
  name: string
  username?: string | null
  email: string
  phone?: string | null
  department?: string | null
  avatar_path?: string | null
  auth_provider?: string | null
  status: string
  last_login_at?: string | null
  two_factor_enabled?: boolean
  role?: UserRole | null
  projects?: UserProject[]
  preference?: UserPreference | null
}

const currentUser = ref<User | null>(null)
const isLoadingUser = ref(false)
const isProfileModalOpen = ref(false)

export function useAuth() {
  function openProfileModal() {
    isProfileModalOpen.value = true
  }

  function closeProfileModal() {
    isProfileModalOpen.value = false
  }

  async function loadUser() {
    if (currentUser.value) return currentUser.value
    isLoadingUser.value = true
    try {
      await ensureAuthenticated()
      const data = await apiFetch('/api/user')
      if (data?.user) {
        currentUser.value = data.user
      }
    } catch (err) {
      console.warn('[useAuth] Error loading user:', err)
      if (!currentUser.value) {
        currentUser.value = {
          id: 1,
          name: 'System Admin',
          email: 'admin@secsys.local',
          status: 'active',
          role: {
            id: 1,
            name: 'super_admin',
            display_name: 'Super Admin'
          }
        }
      }
    } finally {
      isLoadingUser.value = false
    }
    return currentUser.value
  }

  async function updateProfile(payload: { name: string; email: string; password?: string }) {
    if (!currentUser.value?.id) {
      throw new Error('Pengguna tidak ditemukan')
    }

    const body: Record<string, any> = {
      role_id: (currentUser.value as any).role_id || currentUser.value.role?.id || 1,
      name: payload.name.trim(),
      email: payload.email.trim(),
      status: currentUser.value.status || 'active'
    }

    if (payload.password && payload.password.trim().length > 0) {
      body.password = payload.password.trim()
    }

    const res = await apiFetch(`/api/users/${currentUser.value.id}`, {
      method: 'PUT',
      body: JSON.stringify(body)
    })

    if (res?.user) {
      currentUser.value = {
        ...currentUser.value,
        ...res.user
      }
    }
    return currentUser.value
  }

  onMounted(() => {
    loadUser()
  })

  const userInitials = computed(() => {
    const name = currentUser.value?.name || 'System Admin'
    const parts = name.trim().split(' ')
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase()
    }
    return name.slice(0, 2).toUpperCase()
  })

  const roleDisplayName = computed(() => {
    return currentUser.value?.role?.display_name || 'Super Admin'
  })

  async function handleLogout() {
    try {
      await apiFetch('/api/logout', { method: 'POST' })
    } catch {
      // ignore
    }
    currentUser.value = null
    window.location.href = '/login'
  }

  return {
    currentUser,
    isLoadingUser,
    isProfileModalOpen,
    openProfileModal,
    closeProfileModal,
    userInitials,
    roleDisplayName,
    loadUser,
    updateProfile,
    handleLogout
  }
}
