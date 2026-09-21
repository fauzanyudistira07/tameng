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

  const roleName = computed(() => {
    return currentUser.value?.role?.name || ''
  })

  const roleDisplayName = computed(() => {
    return currentUser.value?.role?.display_name || 'Personel SOC'
  })

  const isAdmin = computed(() => {
    return ['super_admin', 'security_admin'].includes(roleName.value)
  })

  const isSuperAdmin = computed(() => {
    return roleName.value === 'super_admin'
  })

  const isSecurityAdmin = computed(() => {
    return roleName.value === 'security_admin'
  })

  const isSecurityAnalyst = computed(() => {
    return roleName.value === 'security_analyst'
  })

  const isAnalystOrAdmin = computed(() => {
    return ['super_admin', 'security_admin', 'security_analyst'].includes(roleName.value)
  })

  const isDeveloper = computed(() => {
    return roleName.value === 'developer'
  })

  const isAuditor = computed(() => {
    return roleName.value === 'auditor'
  })

  const isViewer = computed(() => {
    return roleName.value === 'viewer'
  })

  const isNonAdmin = computed(() => {
    return !['super_admin', 'security_admin'].includes(roleName.value)
  })

  const roleBadgeClass = computed(() => {
    switch (roleName.value) {
      case 'super_admin':
        return 'bg-purple-lt text-purple border border-purple-subtle'
      case 'security_admin':
        return 'bg-blue-lt text-blue border border-blue-subtle'
      case 'security_analyst':
        return 'bg-cyan-lt text-cyan border border-cyan-subtle'
      case 'developer':
        return 'bg-teal-lt text-teal border border-teal-subtle'
      case 'auditor':
        return 'bg-orange-lt text-orange border border-orange-subtle'
      case 'viewer':
        return 'bg-secondary-lt text-secondary border border-secondary-subtle'
      default:
        return 'bg-secondary-lt text-secondary'
    }
  })

  const assignedProjects = computed(() => {
    return currentUser.value?.projects || []
  })

  const assignedProjectNames = computed(() => {
    return (currentUser.value?.projects || []).map((p) => p.name).join(', ')
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
    roleName,
    roleDisplayName,
    isAdmin,
    isSuperAdmin,
    isSecurityAdmin,
    isSecurityAnalyst,
    isAnalystOrAdmin,
    isDeveloper,
    isAuditor,
    isViewer,
    isNonAdmin,
    roleBadgeClass,
    assignedProjects,
    assignedProjectNames,
    loadUser,
    updateProfile,
    handleLogout
  }
}
