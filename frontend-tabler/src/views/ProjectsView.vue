<script setup lang="ts">
import { ref, computed, onMounted, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { apiFetch } from '../services/api'
import { useAuth } from '../composables/useAuth'

const router = useRouter()
const { currentUser } = useAuth()

interface ProjectOwner {
  id: number
  name: string
  email: string
}

interface Project {
  id: number
  code: string
  name: string
  description?: string
  criticality: 'critical' | 'high' | 'medium' | 'low'
  status: 'active' | 'archived'
  owner_id?: number
  owner?: ProjectOwner
  created_at?: string
  updated_at?: string
  repositories_count?: number
  targets_count?: number
}

interface UserOption {
  id: number
  name: string
  email: string
  role?: { name: string }
}

const projects = ref<Project[]>([])
const users = ref<UserOption[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const searchQuery = ref('')
const filterCriticality = ref<string>('all')
const filterStatus = ref<string>('all')

function resetFilters() {
  filterCriticality.value = 'all'
  filterStatus.value = 'all'
  searchQuery.value = ''
}

// Modal state
const isModalOpen = ref(false)
const editingProjectId = ref<number | null>(null)
const formError = ref<string | null>(null)
const formSuccess = ref<string | null>(null)

const projectForm = reactive({
  name: '',
  code: '',
  description: '',
  criticality: 'medium' as 'critical' | 'high' | 'medium' | 'low',
  status: 'active' as 'active' | 'archived',
  owner_id: '' as string | number
})

const canManage = computed(() => {
  const role = currentUser.value?.role?.name || ''
  return ['super_admin', 'security_admin'].includes(role)
})

// Auto-generate slug/code from name if not manually modified
function onNameInput() {
  if (!editingProjectId.value) {
    const slug = projectForm.name
      .toUpperCase()
      .replace(/[^A-Z0-9]/g, '-')
      .replace(/-+/g, '-')
      .replace(/^-|-$/g, '')
    if (slug) {
      projectForm.code = `PRJ-${slug}`.slice(0, 30)
    }
  }
}

async function loadData() {
  isLoading.value = true
  try {
    const [projRes, userRes] = await Promise.all([
      apiFetch('/api/projects'),
      apiFetch('/api/users').catch(() => ({ users: [] }))
    ])
    projects.value = Array.isArray(projRes?.projects) ? projRes.projects : (Array.isArray(projRes) ? projRes : [])
    users.value = Array.isArray(userRes?.users) ? userRes.users : []
  } catch (err: any) {
    console.error('Gagal memuat daftar proyek:', err)
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  const list = projects.value
  const total = list.length
  const critical = list.filter(p => p.criticality === 'critical').length
  const high = list.filter(p => p.criticality === 'high').length
  const active = list.filter(p => p.status === 'active').length
  return { total, critical, high, active }
})

const filteredProjects = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  const list = projects.value.filter(p => {
    // Filter criticality
    if (filterCriticality.value !== 'all' && p.criticality !== filterCriticality.value) {
      return false
    }
    // Filter status
    if (filterStatus.value !== 'all' && p.status !== filterStatus.value) {
      return false
    }
    // Search query
    if (!query) return true
    const nameMatch = (p.name || '').toLowerCase().includes(query)
    const codeMatch = (p.code || '').toLowerCase().includes(query)
    const descMatch = (p.description || '').toLowerCase().includes(query)
    const ownerMatch = (p.owner?.name || '').toLowerCase().includes(query)
    return nameMatch || codeMatch || descMatch || ownerMatch
  })

  return list.sort((a, b) => {
    const timeA = a.created_at ? new Date(a.created_at).getTime() : a.id
    const timeB = b.created_at ? new Date(b.created_at).getTime() : b.id
    return timeB - timeA
  })
})

function openCreateModal() {
  editingProjectId.value = null
  projectForm.name = ''
  projectForm.code = ''
  projectForm.description = ''
  projectForm.criticality = 'medium'
  projectForm.status = 'active'
  projectForm.owner_id = currentUser.value?.id || users.value[0]?.id || ''
  formError.value = null
  formSuccess.value = null
  isModalOpen.value = true
}

function openEditModal(project: Project) {
  editingProjectId.value = project.id
  projectForm.name = project.name
  projectForm.code = project.code
  projectForm.description = project.description || ''
  projectForm.criticality = project.criticality
  projectForm.status = project.status
  projectForm.owner_id = project.owner_id || ''
  formError.value = null
  formSuccess.value = null
  isModalOpen.value = true
}

function closeModal() {
  isModalOpen.value = false
  editingProjectId.value = null
  formError.value = null
  formSuccess.value = null
}

async function saveProject() {
  if (!projectForm.name.trim()) {
    formError.value = 'Nama proyek wajib diisi.'
    return
  }
  if (!projectForm.code.trim()) {
    formError.value = 'Kode proyek unik wajib diisi.'
    return
  }

  isSaving.value = true
  formError.value = null
  formSuccess.value = null

  try {
    const payload = {
      name: projectForm.name.trim(),
      code: projectForm.code.trim().toUpperCase(),
      description: projectForm.description.trim() || null,
      criticality: projectForm.criticality,
      status: projectForm.status,
      owner_id: projectForm.owner_id ? Number(projectForm.owner_id) : null
    }

    if (editingProjectId.value) {
      await apiFetch(`/api/projects/${editingProjectId.value}`, {
        method: 'PUT',
        body: JSON.stringify(payload)
      })
      formSuccess.value = 'Proyek berhasil diperbarui.'
    } else {
      await apiFetch('/api/projects', {
        method: 'POST',
        body: JSON.stringify(payload)
      })
      formSuccess.value = 'Proyek baru berhasil ditambahkan.'
    }

    await loadData()
    setTimeout(() => {
      closeModal()
    }, 600)
  } catch (err: any) {
    console.error('Gagal menyimpan proyek:', err)
    formError.value = err?.data?.message || err?.message || 'Gagal menyimpan proyek. Pastikan kode unik belum digunakan.'
  } finally {
    isSaving.value = false
  }
}

function goToRepositories(project: Project) {
  router.push({ path: '/repositories', query: { project_id: project.id } })
}

function goToTargets(project: Project) {
  router.push({ path: '/targets', query: { project_id: project.id } })
}

function getCriticalityBadgeClass(crit: string) {
  switch (crit) {
    case 'critical': return 'bg-danger text-danger-fg'
    case 'high': return 'bg-warning text-warning-fg'
    case 'medium': return 'bg-yellow text-yellow-fg'
    case 'low': return 'bg-info text-info-fg'
    default: return 'bg-secondary text-secondary-fg'
  }
}

function getCriticalityLabel(crit: string) {
  switch (crit) {
    case 'critical': return 'Kritis (Critical)'
    case 'high': return 'Tinggi (High)'
    case 'medium': return 'Sedang (Medium)'
    case 'low': return 'Rendah (Low)'
    default: return crit
  }
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <div class="page-body mt-0">
    <div class="container-fluid">
      <!-- Page Header -->
      <div class="page-header d-print-none mb-3">
        <div class="row g-2 align-items-center">
          <div class="col-12 col-sm">
            <div class="page-pretitle text-secondary">
              Inventaris & Tata Kelola Aset
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v8h-6z" /><path d="M4 16h6v4h-6z" /><path d="M14 12h6v8h-6z" /><path d="M14 4h6v4h-6z" /></svg>
              <span>Manajemen Proyek Aplikasi</span>
            </h2>
          </div>
          <div class="col-12 col-sm-auto ms-sm-auto d-print-none d-flex align-items-center gap-2">
            <button
              v-if="canManage"
              type="button"
              class="btn btn-primary d-flex align-items-center justify-content-center gap-1 w-100 w-sm-auto"
              @click="openCreateModal"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
              <span>Tambah Proyek</span>
            </button>
          </div>
        </div>
      </div>

      <!-- KPI Metric Cards (Responsive 2-col on mobile, 4-col on tablet/desktop) -->
      <div class="row row-cards mb-3">
        <div class="col-6 col-md-3">
          <div class="card card-sm">
            <div class="card-body p-2 p-sm-3">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-primary-lt text-primary avatar avatar-sm avatar-sm-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M8 7v-2a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v2" /><path d="M12 12l0 .01" /><path d="M3 13a20 20 0 0 0 18 0" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.total }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Total Proyek</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <div class="card card-sm">
            <div class="card-body p-2 p-sm-3">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-success-lt text-success avatar avatar-sm avatar-sm-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.active }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Status Aktif</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <div class="card card-sm">
            <div class="card-body p-2 p-sm-3">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-danger-lt text-danger avatar avatar-sm avatar-sm-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.871l-8.106 -13.534a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.critical }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Kritis (Critical)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="col-6 col-md-3">
          <div class="card card-sm">
            <div class="card-body p-2 p-sm-3">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-warning-lt text-warning avatar avatar-sm avatar-sm-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 4v16" /><path d="M18 10l-6 -6l-6 6" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.high }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Tinggi (High)</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Card with Filter, Table, and Mobile Cards -->
      <div class="card">
        <div class="card-header d-flex flex-column flex-xl-row align-items-stretch align-items-xl-center justify-content-between gap-2 py-2">
          <h3 class="card-title m-0 d-flex align-items-center gap-2">
            <span>Daftar Proyek</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{ filteredProjects.length }}</span>
          </h3>

          <!-- Restyled Modern Filter & Search Controls -->
          <div class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2">
            <!-- Filter Kritikalitas -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-warning">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
              </span>
              <select
                v-model="filterCriticality"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterCriticality !== 'all' }"
                title="Filter berdasarkan tingkat kritikalitas proyek"
              >
                <option value="all">Semua Kritikalitas</option>
                <option value="critical">Kritis (Critical)</option>
                <option value="high">Tinggi (High)</option>
                <option value="medium">Sedang (Medium)</option>
                <option value="low">Rendah (Low)</option>
              </select>
            </div>

            <!-- Filter Status -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-teal">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h4l3 8l4 -16l3 8h4" /></svg>
              </span>
              <select
                v-model="filterStatus"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterStatus !== 'all' }"
                title="Filter berdasarkan status proyek"
              >
                <option value="all">Semua Status</option>
                <option value="active">Aktif</option>
                <option value="archived">Arsip</option>
              </select>
            </div>

            <!-- Modern Search Box -->
            <div class="search-box-wrapper position-relative">
              <div class="input-icon">
                <span class="input-icon-addon text-primary">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                </span>
                <input
                  v-model="searchQuery"
                  type="text"
                  class="form-control form-control-sm modern-search-input"
                  :class="{ 'has-query': searchQuery }"
                  placeholder="Cari proyek / kode / PIC..."
                  @keydown.esc="searchQuery = ''"
                />
                <!-- Tombol Clear X -->
                <button
                  v-if="searchQuery"
                  type="button"
                  class="btn btn-sm btn-link p-0 text-muted search-clear-btn"
                  @click="searchQuery = ''"
                  title="Hapus pencarian (Esc)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                </button>
              </div>
            </div>

            <!-- Reset Filter Button (Muncul jika ada filter aktif) -->
            <button
              v-if="filterCriticality !== 'all' || filterStatus !== 'all'"
              type="button"
              class="btn btn-sm btn-danger d-flex align-items-center gap-1 filter-reset-btn"
              @click="resetFilters"
              title="Reset semua filter ke kondisi awal"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
              <span>Reset Filter</span>
            </button>
          </div>
        </div>

        <!-- Table (Tampil pada Desktop & Tablet >= 768px) -->
        <div class="table-responsive d-none d-md-block">
          <table class="table table-vcenter card-table table-hover">
            <thead>
              <tr>
                <th class="col-index text-white" style="width: 40px;">#</th>
                <th>Proyek & Kode</th>
                <th>Deskripsi</th>
                <th style="width: 140px;">Kritikalitas</th>
                <th style="width: 110px;">Status</th>
                <th>Penanggung Jawab (PIC)</th>
                <th class="text-center" style="width: 240px;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <!-- Loading -->
              <tr v-if="isLoading">
                <td colspan="7" class="text-center py-4 text-secondary">
                  <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                  Memuat data proyek...
                </td>
              </tr>

              <!-- Empty state -->
              <tr v-else-if="filteredProjects.length === 0">
                <td colspan="7" class="text-center py-5">
                  <div class="empty">
                    <div class="empty-icon text-muted">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v8h-6z" /><path d="M4 16h6v4h-6z" /><path d="M14 12h6v8h-6z" /><path d="M14 4h6v4h-6z" /></svg>
                    </div>
                    <p class="empty-title">Tidak ada proyek yang ditemukan</p>
                    <p class="empty-subtitle text-secondary">
                      {{ searchQuery ? 'Tidak ada proyek yang cocok dengan kata kunci pencarian.' : 'Belum ada proyek yang terdaftar di dalam sistem TAMENG.' }}
                    </p>
                    <div v-if="canManage && !searchQuery" class="empty-action">
                      <button type="button" class="btn btn-primary" @click="openCreateModal">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                        Tambah Proyek Pertama
                      </button>
                    </div>
                  </div>
                </td>
              </tr>

              <!-- Rows -->
              <tr v-else v-for="(project, idx) in filteredProjects" :key="project.id">
                <td class="col-index text-white small fw-bold">{{ idx + 1 }}</td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <span class="avatar avatar-sm bg-primary-lt text-primary fw-bold rounded">
                      {{ (project.name || 'P').charAt(0).toUpperCase() }}
                    </span>
                    <div>
                      <div class="fw-bold text-reset">{{ project.name }}</div>
                      <div class="text-secondary font-monospace small" style="font-size: 0.75rem;">
                        {{ project.code }}
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="text-secondary small text-truncate" style="max-width: 250px;">
                    {{ project.description || '-' }}
                  </div>
                </td>
                <td>
                  <span class="badge" :class="getCriticalityBadgeClass(project.criticality)">
                    {{ getCriticalityLabel(project.criticality) }}
                  </span>
                </td>
                <td>
                  <span v-if="project.status === 'active'" class="badge bg-success-lt d-inline-flex align-items-center gap-1">
                    <span class="status-dot status-dot-animated bg-success"></span>
                    <span>Aktif</span>
                  </span>
                  <span v-else class="badge bg-secondary-lt d-inline-flex align-items-center gap-1">
                    <span class="status-dot bg-secondary"></span>
                    <span>Arsip</span>
                  </span>
                </td>
                <td>
                  <div v-if="project.owner" class="d-flex align-items-center gap-2">
                    <span class="avatar avatar-xs bg-azure-lt text-azure rounded-circle">
                      {{ project.owner.name.charAt(0).toUpperCase() }}
                    </span>
                    <div>
                      <div class="small fw-medium">{{ project.owner.name }}</div>
                      <div class="text-muted small" style="font-size: 0.72rem;">{{ project.owner.email }}</div>
                    </div>
                  </div>
                  <span v-else class="text-muted small fst-italic">Belum di-assign</span>
                </td>
                <td class="text-center">
                  <div class="d-flex align-items-center justify-content-center gap-2">
                    <button
                      type="button"
                      class="btn btn-sm btn-secondary d-flex align-items-center gap-1"
                      @click="goToRepositories(project)"
                      title="Lihat Repositori Proyek Ini"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
                      <span>Repo</span>
                    </button>
                    <button
                      type="button"
                      class="btn btn-sm btn-secondary d-flex align-items-center gap-1"
                      @click="goToTargets(project)"
                      title="Lihat Target Endpoint Proyek Ini"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /><path d="M11.5 3a17 17 0 0 0 0 18" /><path d="M12.5 3a17 17 0 0 1 0 18" /></svg>
                      <span>Target</span>
                    </button>
                    <button
                      v-if="canManage"
                      type="button"
                      class="btn btn-sm btn-primary d-flex align-items-center gap-1"
                      @click="openEditModal(project)"
                      title="Ubah Rincian Proyek"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" /><path d="M16 5l3 3" /></svg>
                      <span>Edit</span>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Card List View (Tampil Otomatis pada Layar Ponsel < 768px) -->
        <div class="d-md-none p-2 p-sm-3">
          <!-- Loading state -->
          <div v-if="isLoading" class="text-center py-4 text-secondary">
            <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
            Memuat data proyek...
          </div>

          <!-- Empty state -->
          <div v-else-if="filteredProjects.length === 0" class="empty py-4">
            <div class="empty-icon text-muted">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v8h-6z" /><path d="M4 16h6v4h-6z" /><path d="M14 12h6v8h-6z" /><path d="M14 4h6v4h-6z" /></svg>
            </div>
            <p class="empty-title">Tidak ada proyek yang ditemukan</p>
            <p class="empty-subtitle text-secondary">
              {{ searchQuery ? 'Tidak ada proyek yang cocok dengan kata kunci pencarian.' : 'Belum ada proyek yang terdaftar di dalam sistem TAMENG.' }}
            </p>
            <div v-if="canManage && !searchQuery" class="empty-action">
              <button type="button" class="btn btn-primary btn-sm" @click="openCreateModal">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                Tambah Proyek
              </button>
            </div>
          </div>

          <!-- Cards List -->
          <div v-else class="d-flex flex-column gap-3">
            <div
              v-for="project in filteredProjects"
              :key="project.id"
              class="card shadow-none border mb-0"
              style="border-radius: 12px; overflow: hidden;"
            >
              <div class="card-body p-3">
                <!-- Header: Project name, avatar, status -->
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                  <div class="d-flex align-items-center gap-2 min-width-0">
                    <span class="avatar avatar-xs bg-primary-lt text-primary fw-bold rounded flex-shrink-0">
                      {{ (project.name || 'P').charAt(0).toUpperCase() }}
                    </span>
                    <div class="min-width-0">
                      <div class="fw-bold text-reset fs-4 lh-1 text-truncate">{{ project.name }}</div>
                      <div class="text-secondary font-monospace small mt-1" style="font-size: 0.72rem;">{{ project.code }}</div>
                    </div>
                  </div>
                  <span v-if="project.status === 'active'" class="badge bg-success-lt d-inline-flex align-items-center gap-1 flex-shrink-0">
                    <span class="status-dot status-dot-animated bg-success"></span>
                    <span>Aktif</span>
                  </span>
                  <span v-else class="badge bg-secondary-lt d-inline-flex align-items-center gap-1 flex-shrink-0">
                    <span class="status-dot bg-secondary"></span>
                    <span>Arsip</span>
                  </span>
                </div>

                <!-- Description -->
                <div v-if="project.description" class="text-secondary small mb-2 text-truncate-2" style="font-size: 0.78rem;">
                  {{ project.description }}
                </div>

                <!-- Meta row: Criticality + PIC -->
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-2 bg-body-tertiary rounded border mb-3" style="font-size: 0.75rem;">
                  <div class="d-flex align-items-center gap-1">
                    <span class="text-secondary small">Kritikalitas:</span>
                    <span class="badge py-0 px-1" :class="getCriticalityBadgeClass(project.criticality)">
                      {{ getCriticalityLabel(project.criticality) }}
                    </span>
                  </div>
                  <div class="d-flex align-items-center gap-1">
                    <span class="text-secondary small">PIC:</span>
                    <span class="fw-medium text-reset">{{ project.owner?.name || '-' }}</span>
                  </div>
                </div>

                <!-- Actions: Repo, Target, Edit -->
                <div class="d-flex align-items-center gap-2">
                  <button
                    type="button"
                    class="btn btn-sm btn-secondary flex-fill d-flex align-items-center justify-content-center gap-1"
                    @click="goToRepositories(project)"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
                    <span>Repo</span>
                  </button>
                  <button
                    type="button"
                    class="btn btn-sm btn-secondary flex-fill d-flex align-items-center justify-content-center gap-1"
                    @click="goToTargets(project)"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /><path d="M11.5 3a17 17 0 0 0 0 18" /><path d="M12.5 3a17 17 0 0 1 0 18" /></svg>
                    <span>Target</span>
                  </button>
                  <button
                    v-if="canManage"
                    type="button"
                    class="btn btn-sm btn-primary flex-fill d-flex align-items-center justify-content-center gap-1"
                    @click="openEditModal(project)"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" /><path d="M16 5l3 3" /></svg>
                    <span>Edit</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Form Tambah / Edit Proyek -->
    <div
      v-if="isModalOpen"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.6);"
      @click.self="closeModal"
    >
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-primary text-white py-2">
            <h5 class="modal-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-white" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v8h-6z" /><path d="M4 16h6v4h-6z" /><path d="M14 12h6v8h-6z" /><path d="M14 4h6v4h-6z" /></svg>
              <span>{{ editingProjectId ? 'Ubah Informasi Proyek' : 'Tambah Proyek Baru' }}</span>
            </h5>
            <button type="button" class="btn-close btn-close-white" aria-label="Close" @click="closeModal"></button>
          </div>

          <form @submit.prevent="saveProject">
            <div class="modal-body p-3">
              <!-- Alerts -->
              <div v-if="formError" class="alert alert-danger py-2 px-3 small mb-3">
                {{ formError }}
              </div>
              <div v-if="formSuccess" class="alert alert-success py-2 px-3 small mb-3">
                {{ formSuccess }}
              </div>

              <!-- Form fields -->
              <div class="mb-3">
                <label class="form-label required small fw-bold">Nama Proyek</label>
                <input
                  v-model="projectForm.name"
                  type="text"
                  class="form-control"
                  placeholder="Contoh: Core Banking Platform"
                  required
                  @input="onNameInput"
                />
              </div>

              <div class="mb-3">
                <label class="form-label required small fw-bold">Kode Proyek (Slug Unik)</label>
                <input
                  v-model="projectForm.code"
                  type="text"
                  class="form-control font-monospace"
                  placeholder="PRJ-CORE-BANKING"
                  required
                />
                <div class="form-text small">Kode unik kapital tanpa spasi untuk identifikasi aset & laporan.</div>
              </div>

              <div class="mb-3">
                <label class="form-label small fw-bold">Deskripsi Sistem</label>
                <textarea
                  v-model="projectForm.description"
                  class="form-control"
                  rows="2"
                  placeholder="Keterangan singkat lingkup aplikasi dan peran bisnis..."
                ></textarea>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-sm-6">
                  <label class="form-label required small fw-bold">Tingkat Kritikalitas</label>
                  <select v-model="projectForm.criticality" class="form-select">
                    <option value="critical">Kritis (Critical)</option>
                    <option value="high">Tinggi (High)</option>
                    <option value="medium">Sedang (Medium)</option>
                    <option value="low">Rendah (Low)</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label required small fw-bold">Status Operasional</label>
                  <select v-model="projectForm.status" class="form-select">
                    <option value="active">Aktif (Active)</option>
                    <option value="archived">Arsip (Archived)</option>
                  </select>
                </div>
              </div>

              <div class="mb-2">
                <label class="form-label small fw-bold">Penanggung Jawab Teknis (PIC)</label>
                <select v-model="projectForm.owner_id" class="form-select">
                  <option value="">-- Pilih Pengguna Pemilik --</option>
                  <option v-for="user in users" :key="user.id" :value="user.id">
                    {{ user.name }} ({{ user.email }})
                  </option>
                </select>
              </div>
            </div>

            <div class="modal-footer bg-body-tertiary py-2 d-flex flex-column-reverse flex-sm-row align-items-stretch align-items-sm-center justify-content-sm-between gap-2">
              <button type="button" class="btn btn-secondary w-100 w-sm-auto justify-content-center" @click="closeModal" :disabled="isSaving">
                Batal
              </button>
              <button type="submit" class="btn btn-primary d-flex align-items-center justify-content-center gap-1 w-100 w-sm-auto py-2" :disabled="isSaving">
                <span v-if="isSaving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <span>{{ editingProjectId ? 'Simpan Perubahan' : 'Buat Proyek' }}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.rotate-spinner {
  animation: spin 1s linear infinite;
}
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

/* Filter & Search Toolbar Styling */
.filter-toolbar-group {
  padding: 4px 6px;
  border: 1px solid rgba(var(--tblr-border-color-rgb), 0.75);
  backdrop-filter: blur(8px);
}

.filter-select-wrapper {
  position: relative;
  display: inline-flex;
  align-items: center;
}

.select-prefix-icon {
  position: absolute;
  left: 10px;
  pointer-events: none;
  z-index: 2;
  display: flex;
  align-items: center;
  opacity: 0.75;
  transition: opacity 0.2s ease;
}

.filter-select-wrapper:hover .select-prefix-icon,
.filter-select-wrapper:focus-within .select-prefix-icon {
  opacity: 1;
}

.custom-filter-select {
  padding-left: 34px;
  padding-right: 36px;
  height: 38px;
  border-radius: 10px;
  font-size: 0.8rem;
  font-weight: 500;
  letter-spacing: 0.01em;
  background-color: var(--tblr-bg-surface);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236c7a94' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6l6-6'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 10px center;
  background-size: 14px;
  -webkit-appearance: none;
  -moz-appearance: none;
  appearance: none;
  border: 1.5px solid var(--tblr-border-color);
  color: var(--tblr-body-color);
  transition: border-color 0.2s ease, box-shadow 0.2s ease, background-color 0.2s ease;
  cursor: pointer;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  min-width: 150px;
}

.custom-filter-select:hover {
  border-color: rgba(var(--tblr-primary-rgb), 0.55);
  background-color: rgba(var(--tblr-primary-rgb), 0.03);
  box-shadow: 0 2px 8px rgba(var(--tblr-primary-rgb), 0.08);
}

.custom-filter-select:focus {
  border-color: var(--tblr-primary);
  box-shadow: 0 0 0 3.5px rgba(var(--tblr-primary-rgb), 0.18);
  outline: none;
  background-color: var(--tblr-bg-surface);
}

.custom-filter-select.filter-active {
  border-color: rgba(var(--tblr-primary-rgb), 0.8);
  background-color: rgba(var(--tblr-primary-rgb), 0.07);
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%232c7be5' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6l6-6'/%3E%3C/svg%3E");
  color: var(--tblr-primary);
  font-weight: 600;
  box-shadow: 0 0 0 2px rgba(var(--tblr-primary-rgb), 0.14);
}

.search-box-wrapper {
  position: relative;
}

.modern-search-input {
  width: 250px;
  height: 36px;
  border-radius: 8px;
  padding-left: 34px;
  padding-right: 32px;
  font-size: 0.8125rem;
  background-color: var(--tblr-bg-surface);
  border: 1px solid var(--tblr-border-color);
  color: var(--tblr-body-color);
  transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}

.modern-search-input:hover {
  border-color: rgba(var(--tblr-primary-rgb), 0.5);
}

.modern-search-input:focus {
  width: 290px;
  border-color: var(--tblr-primary);
  box-shadow: 0 0 0 3px rgba(var(--tblr-primary-rgb), 0.18);
  background-color: var(--tblr-bg-surface);
  outline: none;
}

.modern-search-input.has-query {
  border-color: rgba(var(--tblr-primary-rgb), 0.6);
}

.search-clear-btn {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: rgba(var(--tblr-body-color-rgb), 0.12);
  color: var(--tblr-body-color);
  z-index: 5;
  transition: all 0.15s ease;
  border: none;
}

.search-clear-btn:hover {
  background: rgba(214, 57, 57, 0.25);
  color: var(--tblr-danger) !important;
  transform: translateY(-50%) scale(1.1);
}

.filter-reset-btn {
  height: 36px;
  border-radius: 8px;
  font-size: 0.8125rem;
  font-weight: 500;
  transition: all 0.15s ease;
}

@media (max-width: 1199.98px) {
  .filter-toolbar-group {
    width: 100%;
    margin-top: 0.25rem;
  }
}

@media (max-width: 991.98px) {
  .filter-select-wrapper {
    flex: 1 1 calc(50% - 0.5rem);
    min-width: 140px;
  }
  .custom-filter-select {
    width: 100% !important;
    max-width: 100% !important;
  }
  .search-box-wrapper {
    flex: 1 1 100%;
  }
  .modern-search-input,
  .modern-search-input:focus {
    width: 100% !important;
  }
}

@media (max-width: 767.98px) {
  .filter-select-wrapper {
    flex: 1 1 100%;
  }
  .custom-filter-select {
    height: 36px;
    font-size: 0.78rem;
    padding-left: 30px;
    padding-right: 28px;
  }
  .search-box-wrapper {
    flex: 1 1 100%;
  }
  .filter-reset-btn {
    flex: 1 1 100%;
    justify-content: center;
  }
}
</style>
