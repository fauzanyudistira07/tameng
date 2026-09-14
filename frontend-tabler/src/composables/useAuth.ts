import { ref, computed, onMounted } from 'vue'
import { apiFetch, ensureAuthenticated, logoutUser } from '../services/api'

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

export interface UpdateProfilePayload {
  name: string
  username?: string
  email: string
  phone?: string
  department?: string
  password?: string
  password_confirmation?: string
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

  async function loadUser(force = false) {
    if (currentUser.value && !force) return currentUser.value
    isLoadingUser.value = true
    try {
      const isAuthed = await ensureAuthenticated()
      if (!isAuthed) {
        currentUser.value = null
        return null
      }
      const data = await apiFetch('/api/user')
      if (data?.user) {
        currentUser.value = data.user
      }
    } catch (err) {
      console.warn('[useAuth] Error loading user:', err)
      currentUser.value = null
    } finally {
      isLoadingUser.value = false
    }
    return currentUser.value
  }

  async function updateProfile(payload: UpdateProfilePayload) {
    const body: Record<string, any> = {
      name: payload.name.trim(),
      email: payload.email.trim(),
    }

    if (payload.username !== undefined) {
      body.username = payload.username.trim()
    }
    if (payload.phone !== undefined) {
      body.phone = payload.phone.trim()
    }
    if (payload.department !== undefined) {
      body.department = payload.department.trim()
    }
    if (payload.password && payload.password.trim().length > 0) {
      body.password = payload.password.trim()
      if (payload.password_confirmation) {
        body.password_confirmation = payload.password_confirmation.trim()
      }
    }

    const res = await apiFetch('/api/user', {
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
    const name = currentUser.value?.name || 'TAMENG'
    const parts = name.trim().split(' ')
    if (parts.length >= 2) {
      return (parts[0][0] + parts[1][0]).toUpperCase()
    }
    return name.slice(0, 2).toUpperCase()
  })

  const roleDisplayName = computed(() => {
    return currentUser.value?.role?.display_name || 'Personel SOC'
  })

  async function handleLogout() {
    try {
      await logoutUser()
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
