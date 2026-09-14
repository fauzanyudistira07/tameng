<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '../services/api'

interface Role {
  id: number
  name: string
  display_name: string
  description?: string
}

interface ProjectItem {
  id: number
  name: string
  code: string
  access_level?: string
}

interface UserPreferenceItem {
  alert_email: boolean
  alert_telegram: boolean
  telegram_chat_id?: string | null
  alert_min_severity: string
}

interface UserItem {
  id: number
  role_id: number
  name: string
  username?: string | null
  email: string
  phone?: string | null
  department?: string | null
  avatar_path?: string | null
  auth_provider?: string | null
  provider_id?: string | null
  status: 'active' | 'inactive'
  failed_login_attempts?: number
  locked_until?: string | null
  two_factor_enabled?: boolean
  last_login_at?: string | null
  last_login_ip?: string | null
  created_at?: string
  role?: Role | null
  projects?: ProjectItem[]
  preference?: UserPreferenceItem | null
}

const users = ref<UserItem[]>([])
const roles = ref<Role[]>([])
const availableProjects = ref<{ id: number; name: string; code: string }[]>([])
const isLoading = ref(true)
const searchQuery = ref('')
const filterRole = ref('')
const filterStatus = ref('')
const alertMessage = ref<{ type: 'success' | 'danger'; text: string } | null>(null)

// Modal state
const isModalOpen = ref(false)
const modalMode = ref<'create' | 'edit'>('create')
const activeTab = ref<'identity' | 'projects' | 'alerts'>('identity')
const isSubmitting = ref(false)
const formError = ref<string | null>(null)

const formData = ref({
  id: 0,
  role_id: 1,
  name: '',
  username: '',
  email: '',
  phone: '',
  department: '',
  auth_provider: 'local',
  password: '',
  status: 'active' as 'active' | 'inactive',
  project_ids: [] as number[],
  project_access_level: 'analyst',
  alert_email: true,
  alert_telegram: false,
  telegram_chat_id: '',
  alert_min_severity: 'high'
})

function showAlert(text: string, type: 'success' | 'danger' = 'success') {
  alertMessage.value = { type, text }
  setTimeout(() => {
    if (alertMessage.value?.text === text) {
      alertMessage.value = null
    }
  }, 5000)
}

async function loadData() {
  isLoading.value = true
  try {
    const [usersRes, rolesRes, projectsRes] = await Promise.all([
      apiFetch('/api/users'),
      apiFetch('/api/roles'),
      apiFetch('/api/projects')
    ])
    if (usersRes?.users) {
      users.value = usersRes.users
    }
    if (rolesRes?.roles) {
      roles.value = rolesRes.roles
    }
    if (projectsRes?.projects) {
      availableProjects.value = projectsRes.projects
    }
  } catch (err: any) {
    showAlert(err.message || 'Gagal memuat data pengguna', 'danger')
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  const total = users.value.length
  const active = users.value.filter(u => u.status === 'active' && !isUserLocked(u)).length
  const locked = users.value.filter(u => isUserLocked(u)).length
  const twoFactor = users.value.filter(u => u.two_factor_enabled).length
  return { total, active, locked, twoFactor }
})

function isUserLocked(user: UserItem): boolean {
  if (!user.locked_until) return false
  return new Date(user.locked_until).getTime() > Date.now()
}

const filteredUsers = computed(() => {
  return users.value.filter(u => {
    const query = searchQuery.value.toLowerCase().trim()
    const matchesQuery = !query ||
      u.name.toLowerCase().includes(query) ||
      (u.username && u.username.toLowerCase().includes(query)) ||
      u.email.toLowerCase().includes(query) ||
      (u.department && u.department.toLowerCase().includes(query))

    const matchesRole = !filterRole.value || u.role_id === Number(filterRole.value)
    
    let matchesStatus = true
    if (filterStatus.value === 'locked') {
      matchesStatus = isUserLocked(u)
    } else if (filterStatus.value === 'active') {
      matchesStatus = u.status === 'active' && !isUserLocked(u)
    } else if (filterStatus.value === 'inactive') {
      matchesStatus = u.status === 'inactive'
    }

    return matchesQuery && matchesRole && matchesStatus
  })
})

function getInitials(name: string): string {
  const parts = name.trim().split(' ')
  if (parts.length >= 2) {
    return (parts[0][0] + parts[1][0]).toUpperCase()
  }
  return name.slice(0, 2).toUpperCase()
}

function formatDate(dateStr?: string | null): string {
  if (!dateStr) return '-'
  try {
    const d = new Date(dateStr)
    return d.toLocaleString('id-ID', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    })
  } catch {
    return dateStr
  }
}

function getRoleBadgeClass(roleName?: string): string {
  switch (roleName) {
    case 'super_admin': return 'bg-purple-lt'
    case 'security_admin': return 'bg-blue-lt'
    case 'security_analyst': return 'bg-cyan-lt'
    case 'developer': return 'bg-teal-lt'
    case 'auditor': return 'bg-orange-lt'
    default: return 'bg-secondary-lt'
  }
}

function openCreateModal() {
  modalMode.value = 'create'
  activeTab.value = 'identity'
  formData.value = {
    id: 0,
    role_id: roles.value[0]?.id || 1,
    name: '',
    username: '',
    email: '',
    phone: '',
    department: '',
    auth_provider: 'local',
    password: '',
    status: 'active',
    project_ids: [],
    project_access_level: 'analyst',
    alert_email: true,
    alert_telegram: false,
    telegram_chat_id: '',
    alert_min_severity: 'high'
  }
  formError.value = null
  isModalOpen.value = true
}

function openEditModal(user: UserItem) {
  modalMode.value = 'edit'
  activeTab.value = 'identity'
  formData.value = {
    id: user.id,
    role_id: user.role_id,
    name: user.name,
    username: user.username || '',
    email: user.email,
    phone: user.phone || '',
    department: user.department || '',
    auth_provider: user.auth_provider || 'local',
    password: '',
    status: user.status,
    project_ids: (user.projects || []).map(p => p.id),
    project_access_level: user.projects?.[0]?.access_level || 'analyst',
    alert_email: user.preference?.alert_email ?? true,
    alert_telegram: user.preference?.alert_telegram ?? false,
    telegram_chat_id: user.preference?.telegram_chat_id || '',
    alert_min_severity: user.preference?.alert_min_severity || 'high'
  }
  formError.value = null
  isModalOpen.value = true
}

function toggleProjectSelection(projectId: number) {
  const index = formData.value.project_ids.indexOf(projectId)
  if (index > -1) {
    formData.value.project_ids.splice(index, 1)
  } else {
    formData.value.project_ids.push(projectId)
  }
}

function closeModal() {
  isModalOpen.value = false
  formError.value = null
}

async function handleSave() {
  formError.value = null
  isSubmitting.value = true

  try {
    const payload: Record<string, any> = {
      role_id: formData.value.role_id,
      name: formData.value.name.trim(),
      username: formData.value.username.trim() || null,
      email: formData.value.email.trim(),
      phone: formData.value.phone.trim() || null,
      department: formData.value.department.trim() || null,
      auth_provider: formData.value.auth_provider,
      status: formData.value.status,
      project_ids: formData.value.project_ids,
      project_access_level: formData.value.project_access_level,
      alert_email: formData.value.alert_email,
      alert_telegram: formData.value.alert_telegram,
      telegram_chat_id: formData.value.telegram_chat_id.trim() || null,
      alert_min_severity: formData.value.alert_min_severity
    }

    if (modalMode.value === 'create') {
      if (!formData.value.password || formData.value.password.length < 10) {
        throw new Error('Kata sandi wajib diisi minimal 10 karakter.')
      }
      payload.password = formData.value.password
      await apiFetch('/api/users', {
        method: 'POST',
        body: JSON.stringify(payload)
      })
      showAlert(`Personel ${payload.name} berhasil didaftarkan.`)
    } else {
      if (formData.value.password && formData.value.password.length > 0) {
        if (formData.value.password.length < 10) {
          throw new Error('Kata sandi baru minimal 10 karakter.')
        }
        payload.password = formData.value.password
      }
      await apiFetch(`/api/users/${formData.value.id}`, {
        method: 'PUT',
        body: JSON.stringify(payload)
      })
      showAlert(`Data dan hak akses ${payload.name} berhasil diperbarui.`)
    }

    closeModal()
    await loadData()
  } catch (err: any) {
    formError.value = err.data?.message || err.message || 'Terjadi kesalahan saat menyimpan data.'
  } finally {
    isSubmitting.value = false
  }
}

async function confirmUnlock(user: UserItem) {
  try {
    const res = await apiFetch(`/api/users/${user.id}/unlock`, {
      method: 'POST'
    })
    showAlert(res.message || `Akun ${user.name} berhasil dibuka kembali.`)
    await loadData()
  } catch (err: any) {
    showAlert(err.message || 'Gagal membuka kunci akun.', 'danger')
  }
}

async function handleDeactivate(user: UserItem) {
  if (!confirm(`Apakah Anda yakin ingin menonaktifkan akun ${user.name}?`)) return
  try {
    const res = await apiFetch(`/api/users/${user.id}`, {
      method: 'DELETE'
    })
    showAlert(res.message || `Akun ${user.name} telah dinonaktifkan.`)
    await loadData()
  } catch (err: any) {
    showAlert(err.message || 'Gagal menonaktifkan pengguna.', 'danger')
  }
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <div class="container-fluid py-4">
    <!-- Feedback Alert -->
    <div
      v-if="alertMessage"
      class="alert alert-dismissible fade show mb-3"
      :class="alertMessage.type === 'success' ? 'alert-success' : 'alert-danger'"
      role="alert"
    >
      <div class="d-flex align-items-center gap-2">
        <svg
          v-if="alertMessage.type === 'success'"
          xmlns="http://www.w3.org/2000/svg"
          class="icon alert-icon"
          width="24"
          height="24"
          viewBox="0 0 24 24"
          stroke-width="2"
          stroke="currentColor"
          fill="none"
        >
          <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
          <path d="M5 12l5 5l10 -10"/>
        </svg>
        <svg
          v-else
          xmlns="http://www.w3.org/2000/svg"
          class="icon alert-icon"
          width="24"
          height="24"
          viewBox="0 0 24 24"
          stroke-width="2"
          stroke="currentColor"
          fill="none"
        >
          <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
          <path d="M12 9v4"/>
          <path d="M12 16v.01"/>
        </svg>
        <div>{{ alertMessage.text }}</div>
      </div>
      <button type="button" class="btn-close" @click="alertMessage = null" aria-label="Close"></button>
    </div>

    <!-- Page Header -->
    <div class="page-header d-print-none mb-4">
      <div class="row g-2 align-items-center">
        <div class="col">
          <div class="page-pretitle text-secondary">
            Administrasi & Tata Kelola Keamanan
          </div>
          <h2 class="page-title">
            Manajemen Personel & Akun SOC
          </h2>
        </div>
        <div class="col-auto ms-auto d-print-none">
          <div class="btn-list">
            <button
              type="button"
              class="btn btn-primary d-inline-flex align-items-center gap-1"
              @click="openCreateModal"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="icon"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M12 5l0 14"/>
                <path d="M5 12l14 0"/>
              </svg>
              Tambah Personel
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Overview Stats Row -->
    <div class="row row-cards mb-4">
      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-auto">
                <span class="bg-primary text-white avatar">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0"/>
                    <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    <path d="M21 21v-2a4 4 0 0 0 -3 -3.85"/>
                  </svg>
                </span>
              </div>
              <div class="col">
                <div class="font-weight-medium">Total Personel</div>
                <div class="text-secondary">{{ stats.total }} Pengguna</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-auto">
                <span class="bg-success text-white avatar">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M5 12l5 5l10 -10"/>
                  </svg>
                </span>
              </div>
              <div class="col">
                <div class="font-weight-medium">Akun Aktif</div>
                <div class="text-secondary">{{ stats.active }} Terverifikasi</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-auto">
                <span class="bg-danger text-white avatar">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z"/>
                    <path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0"/>
                    <path d="M8 11v-4a4 4 0 1 1 8 0v4"/>
                  </svg>
                </span>
              </div>
              <div class="col">
                <div class="font-weight-medium">Terkunci (Lockout)</div>
                <div class="text-secondary">{{ stats.locked }} Akun</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm">
          <div class="card-body">
            <div class="row align-items-center">
              <div class="col-auto">
                <span class="bg-info text-white avatar">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"/>
                    <path d="M12 11m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0"/>
                    <path d="M12 12l0 2.5"/>
                  </svg>
                </span>
              </div>
              <div class="col">
                <div class="font-weight-medium">Proteksi 2FA</div>
                <div class="text-secondary">{{ stats.twoFactor }} Terproteksi</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters & Table Card -->
    <div class="card">
      <div class="card-header py-3">
        <div class="row w-100 g-2 align-items-center">
          <div class="col-md-6">
            <div class="input-icon">
              <span class="input-icon-addon">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                  <path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0"/>
                  <path d="M21 21l-6 -6"/>
                </svg>
              </span>
              <input
                type="text"
                v-model="searchQuery"
                class="form-control"
                placeholder="Cari nama, username, email, departemen..."
              />
            </div>
          </div>
          <div class="col-md-3">
            <select v-model="filterRole" class="form-select">
              <option value="">Semua Peran (Role)</option>
              <option v-for="r in roles" :key="r.id" :value="r.id">
                {{ r.display_name }}
              </option>
            </select>
          </div>
          <div class="col-md-3">
            <select v-model="filterStatus" class="form-select">
              <option value="">Semua Status Akun</option>
              <option value="active">Aktif Normal</option>
              <option value="locked">Terkunci (Lockout)</option>
              <option value="inactive">Nonaktif</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="table-responsive">
        <table class="table table-vcenter card-table table-striped">
          <thead>
            <tr>
              <th>Personel</th>
              <th>Unit Kerja / Departemen</th>
              <th>Peran SOC</th>
              <th>Scope Proyek</th>
              <th>Status Akun</th>
              <th>Aktivitas Terakhir</th>
              <th>Notifikasi & Keamanan</th>
              <th class="w-1 text-end">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="isLoading">
              <td colspan="8" class="text-center py-5 text-secondary">
                <div class="spinner-border text-primary me-2" role="status"></div>
                Memuat data personel SOC...
              </td>
            </tr>
            <tr v-else-if="filteredUsers.length === 0">
              <td colspan="8" class="text-center py-5 text-secondary">
                Tidak ada personel yang sesuai dengan kriteria pencarian.
              </td>
            </tr>
            <tr v-for="u in filteredUsers" :key="u.id">
              <!-- Personel & Auth Provider -->
              <td>
                <div class="d-flex align-items-center gap-3">
                  <span class="avatar avatar-md bg-primary-lt text-primary fw-bold rounded">
                    {{ getInitials(u.name) }}
                  </span>
                  <div class="d-flex flex-column text-truncate">
                    <div class="d-flex align-items-center gap-2">
                      <span class="fw-bold text-body text-truncate">{{ u.name }}</span>
                      <span
                        class="badge"
                        :class="u.auth_provider === 'keycloak' ? 'bg-teal-lt' : 'bg-secondary-subtle text-secondary'"
                        style="font-size: 0.65rem;"
                      >
                        {{ (u.auth_provider || 'local').toUpperCase() }}
                      </span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <span v-if="u.username" class="badge bg-secondary-subtle text-secondary small px-1">
                        @{{ u.username }}
                      </span>
                      <span class="text-secondary small text-truncate">{{ u.email }}</span>
                    </div>
                  </div>
                </div>
              </td>

              <!-- Departemen -->
              <td>
                <div class="text-body font-weight-medium">
                  {{ u.department || '-' }}
                </div>
                <div v-if="u.phone" class="text-secondary small">
                  {{ u.phone }}
                </div>
              </td>

              <!-- Peran -->
              <td>
                <span class="badge" :class="getRoleBadgeClass(u.role?.name)">
                  {{ u.role?.display_name || 'User' }}
                </span>
              </td>

              <!-- Scope Proyek (Fase 2) -->
              <td>
                <div v-if="u.role?.name === 'super_admin'">
                  <span class="badge bg-purple-lt">Global (Semua Proyek)</span>
                </div>
                <div v-else-if="u.projects && u.projects.length > 0" class="d-flex flex-wrap gap-1">
                  <span
                    v-for="p in u.projects"
                    :key="p.id"
                    class="badge bg-azure-lt"
                    :title="p.code"
                  >
                    {{ p.name }} ({{ p.access_level || 'analyst' }})
                  </span>
                </div>
                <div v-else class="text-secondary small">
                  Belum Ditugaskan
                </div>
              </td>

              <!-- Status -->
              <td>
                <span v-if="isUserLocked(u)" class="badge bg-danger-lt d-inline-flex align-items-center gap-1">
                  <span class="status-dot status-dot-animated bg-danger"></span>
                  TERKUNCI
                </span>
                <span v-else-if="u.status === 'active'" class="badge bg-success-lt d-inline-flex align-items-center gap-1">
                  <span class="status-dot status-dot-animated bg-success"></span>
                  AKTIF
                </span>
                <span v-else class="badge bg-secondary-lt">
                  NONAKTIF
                </span>
              </td>

              <!-- Aktivitas Terakhir -->
              <td>
                <div class="text-body small">
                  {{ formatDate(u.last_login_at) }}
                </div>
                <div v-if="u.last_login_ip" class="mt-1">
                  <span class="badge bg-dark-lt small" style="font-family: monospace;">
                    {{ u.last_login_ip }}
                  </span>
                </div>
                <div v-else class="text-muted small">-</div>
              </td>

              <!-- Notifikasi & Keamanan (Fase 3) -->
              <td>
                <div class="d-flex flex-column gap-1">
                  <div class="d-flex align-items-center gap-1">
                    <span v-if="u.two_factor_enabled" class="badge bg-info-lt">2FA</span>
                    <span v-if="u.preference?.alert_email" class="badge bg-success-lt" title="Alert Email Aktif">Email</span>
                    <span v-if="u.preference?.alert_telegram" class="badge bg-cyan-lt" title="Alert Telegram Aktif">TG</span>
                  </div>
                  <div v-if="(u.failed_login_attempts ?? 0) > 0" class="text-danger small">
                    Gagal: {{ u.failed_login_attempts }}x
                  </div>
                </div>
              </td>

              <!-- Aksi -->
              <td class="text-end">
                <div class="btn-list justify-content-end flex-nowrap">
                  <button
                    v-if="isUserLocked(u)"
                    type="button"
                    class="btn btn-sm btn-outline-warning"
                    title="Buka Kunci Akun"
                    @click="confirmUnlock(u)"
                  >
                    Buka Kunci
                  </button>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary"
                    title="Edit Profil & Akses Proyek"
                    @click="openEditModal(u)"
                  >
                    Edit
                  </button>
                  <button
                    v-if="u.id !== 1 && u.status === 'active'"
                    type="button"
                    class="btn btn-sm btn-outline-danger"
                    title="Nonaktifkan Pengguna"
                    @click="handleDeactivate(u)"
                  >
                    Nonaktifkan
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal Tambah / Edit Personel SOC -->
    <div
      v-if="isModalOpen"
      class="modal-backdrop fade show"
      style="z-index: 1050;"
      @click="closeModal"
    ></div>

    <div
      class="modal modal-blur fade"
      :class="{ 'show d-block': isModalOpen }"
      id="modal-user-form"
      tabindex="-1"
      role="dialog"
      :aria-hidden="!isModalOpen"
      style="z-index: 1055;"
      @click.self="closeModal"
    >
      <div class="modal-dialog modal-dialog-centered" style="max-width: 640px;" role="document">
        <div class="modal-content shadow border-0">
          <div class="modal-header border-bottom py-3 px-4">
            <h5 class="modal-title fw-bold text-uppercase">
              {{ modalMode === 'create' ? 'Tambah Personel SOC Baru' : 'Konfigurasi Akun & Hak Akses' }}
            </h5>
            <button type="button" class="btn-close" @click="closeModal" aria-label="Close"></button>
          </div>

          <!-- Navigation Tabs in Modal (Tabler) -->
          <ul class="nav nav-tabs nav-fill border-bottom px-3 pt-2" role="tablist">
            <li class="nav-item">
              <a
                class="nav-link cursor-pointer"
                :class="{ active: activeTab === 'identity' }"
                @click="activeTab = 'identity'"
              >
                1. Profil & Akun
              </a>
            </li>
            <li class="nav-item">
              <a
                class="nav-link cursor-pointer"
                :class="{ active: activeTab === 'projects' }"
                @click="activeTab = 'projects'"
              >
                2. Scope Proyek (Fase 2)
              </a>
            </li>
            <li class="nav-item">
              <a
                class="nav-link cursor-pointer"
                :class="{ active: activeTab === 'alerts' }"
                @click="activeTab = 'alerts'"
              >
                3. Notifikasi Alert (Fase 3)
              </a>
            </li>
          </ul>

          <form @submit.prevent="handleSave">
            <div class="modal-body p-4">
              <!-- Error Alert -->
              <div v-if="formError" class="alert alert-danger mb-3" role="alert">
                {{ formError }}
              </div>

              <!-- TAB 1: Identity & Credentials -->
              <div v-show="activeTab === 'identity'" class="row g-3">
                <div class="col-md-7">
                  <label class="form-label required">Nama Lengkap</label>
                  <input
                    type="text"
                    v-model="formData.name"
                    class="form-control"
                    required
                    placeholder="Contoh: Ahmad Fauzi"
                  />
                </div>
                <div class="col-md-5">
                  <label class="form-label">Username</label>
                  <input
                    type="text"
                    v-model="formData.username"
                    class="form-control"
                    placeholder="Contoh: afauzi"
                  />
                </div>

                <div class="col-md-7">
                  <label class="form-label required">Alamat Email</label>
                  <input
                    type="email"
                    v-model="formData.email"
                    class="form-control"
                    required
                    placeholder="nama@instansi.go.id"
                  />
                </div>
                <div class="col-md-5">
                  <label class="form-label">Nomor Telepon</label>
                  <input
                    type="text"
                    v-model="formData.phone"
                    class="form-control"
                    placeholder="+62 812-xxxx-xxxx"
                  />
                </div>

                <div class="col-md-7">
                  <label class="form-label">Unit Kerja / Departemen</label>
                  <input
                    type="text"
                    v-model="formData.department"
                    class="form-control"
                    placeholder="Contoh: Puslitbang / Tim Insiden Siber"
                  />
                </div>
                <div class="col-md-5">
                  <label class="form-label required">Metode Autentikasi</label>
                  <select v-model="formData.auth_provider" class="form-select" required>
                    <option value="local">Local Database</option>
                    <option value="keycloak">SSO Keycloak</option>
                    <option value="google">Google Workspace</option>
                    <option value="active_directory">Active Directory / Entra</option>
                  </select>
                </div>

                <div class="col-md-6">
                  <label class="form-label required">Peran (Role SOC)</label>
                  <select v-model="formData.role_id" class="form-select" required>
                    <option v-for="r in roles" :key="r.id" :value="r.id">
                      {{ r.display_name }}
                    </option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label required">Status Akun</label>
                  <select v-model="formData.status" class="form-select" required>
                    <option value="active">Aktif</option>
                    <option value="inactive">Nonaktif</option>
                  </select>
                </div>

                <div class="col-12">
                  <label class="form-label" :class="{ required: modalMode === 'create' }">
                    {{ modalMode === 'create' ? 'Kata Sandi Akun' : 'Ganti Kata Sandi (Kosongkan jika tetap)' }}
                  </label>
                  <input
                    type="password"
                    v-model="formData.password"
                    class="form-control"
                    :required="modalMode === 'create'"
                    placeholder="Minimal 10 karakter"
                  />
                  <small class="form-hint">
                    Kebijakan Keamanan: Minimal 10 karakter dan tidak boleh menggunakan salah satu dari 3 kata sandi terakhir yang pernah digunakan.
                  </small>
                </div>
              </div>

              <!-- TAB 2: Project Scoping (Fase 2) -->
              <div v-show="activeTab === 'projects'" class="space-y">
                <div class="mb-3">
                  <label class="form-label">Tingkat Akses Proyek Terpilih</label>
                  <select v-model="formData.project_access_level" class="form-select">
                    <option value="lead">Project Lead (Akses Penuh & Otorisasi)</option>
                    <option value="analyst">Security Analyst (Review Temuan & Scan)</option>
                    <option value="developer">Developer (Remediasi & Verifikasi)</option>
                    <option value="viewer">Viewer (Read-Only Temuan)</option>
                  </select>
                  <small class="form-hint">
                    Tingkat hak akses personel untuk proyek-proyek yang dicentang di bawah ini.
                  </small>
                </div>

                <label class="form-label">Daftar Proyek yang Didelegasikan</label>
                <div class="card card-sm border">
                  <div class="card-body p-2" style="max-height: 240px; overflow-y: auto;">
                    <div v-if="availableProjects.length === 0" class="text-secondary text-center py-3">
                      Tidak ada proyek terdaftar.
                    </div>
                    <div
                      v-for="proj in availableProjects"
                      :key="proj.id"
                      class="d-flex align-items-center justify-content-between p-2 rounded hover-bg-surface cursor-pointer"
                      @click="toggleProjectSelection(proj.id)"
                    >
                      <div class="d-flex align-items-center gap-2">
                        <input
                          type="checkbox"
                          class="form-check-input m-0"
                          :checked="formData.project_ids.includes(proj.id)"
                          @click.stop="toggleProjectSelection(proj.id)"
                        />
                        <div>
                          <div class="fw-bold text-body small">{{ proj.name }}</div>
                          <div class="text-secondary" style="font-size: 0.7rem; font-family: monospace;">
                            {{ proj.code }}
                          </div>
                        </div>
                      </div>
                      <span v-if="formData.project_ids.includes(proj.id)" class="badge bg-primary-lt">
                        Terpilih
                      </span>
                    </div>
                  </div>
                </div>
                <div class="text-secondary small mt-2">
                  Total {{ formData.project_ids.length }} proyek dipilih untuk personel ini.
                </div>
              </div>

              <!-- TAB 3: Incident Alert Preferences (Fase 3) -->
              <div v-show="activeTab === 'alerts'" class="space-y">
                <div class="mb-3">
                  <label class="form-label">Kanal Notifikasi Insiden</label>
                  <div class="form-selectgroup form-selectgroup-boxes d-flex flex-column gap-2">
                    <label class="form-selectgroup-item flex-fill">
                      <input
                        type="checkbox"
                        v-model="formData.alert_email"
                        class="form-selectgroup-input"
                      />
                      <div class="form-selectgroup-label d-flex align-items-center p-3">
                        <div class="me-3">
                          <span class="form-selectgroup-check"></span>
                        </div>
                        <div>
                          <span class="form-selectgroup-title fw-bold">Notifikasi Email Darurat</span>
                          <span class="d-block text-secondary small">
                            Kirimkan rincian celah keamanan instan ke alamat email akun.
                          </span>
                        </div>
                      </div>
                    </label>

                    <label class="form-selectgroup-item flex-fill">
                      <input
                        type="checkbox"
                        v-model="formData.alert_telegram"
                        class="form-selectgroup-input"
                      />
                      <div class="form-selectgroup-label d-flex align-items-center p-3">
                        <div class="me-3">
                          <span class="form-selectgroup-check"></span>
                        </div>
                        <div>
                          <span class="form-selectgroup-title fw-bold">Notifikasi Telegram Bot CSIRT</span>
                          <span class="d-block text-secondary small">
                            Kirimkan peringatan langsung ke Telegram Chat ID analis.
                          </span>
                        </div>
                      </div>
                    </label>
                  </div>
                </div>

                <div v-if="formData.alert_telegram" class="mb-3">
                  <label class="form-label">Telegram Chat ID / Username</label>
                  <input
                    type="text"
                    v-model="formData.telegram_chat_id"
                    class="form-control"
                    placeholder="Contoh: @analyst_csirt atau 123456789"
                  />
                </div>

                <div class="mb-3">
                  <label class="form-label">Ambang Batas Celah (Minimal Severity)</label>
                  <select v-model="formData.alert_min_severity" class="form-select">
                    <option value="critical">Hanya CRITICAL (Remote Code Execution, SQLi Kritis)</option>
                    <option value="high">HIGH & CRITICAL (Standar Rekomendasi)</option>
                    <option value="medium">MEDIUM ke atas</option>
                    <option value="all">Semua Temuan Kerentanan</option>
                  </select>
                  <small class="form-hint">
                    Personel hanya akan menerima notifikasi jika celah yang ditemukan memenuhi ambang batas ini.
                  </small>
                </div>
              </div>
            </div>

            <div class="modal-footer border-top py-3 px-4">
              <button type="button" class="btn btn-link link-secondary" @click="closeModal">
                Batal
              </button>
              <button type="submit" class="btn btn-primary" :disabled="isSubmitting">
                <span v-if="isSubmitting" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ modalMode === 'create' ? 'Simpan Personel & Akses' : 'Perbarui Data & Scope' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>
