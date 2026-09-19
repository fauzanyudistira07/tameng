<script setup lang="ts">
import { ref, computed, onMounted, reactive } from 'vue'
import { apiFetch } from '../services/api'
import { useAuth } from '../composables/useAuth'

const { currentUser } = useAuth()

interface ScopeProject {
  id: number
  name: string
  code: string
}

interface ScopeTarget {
  id: number
  name: string
  hostname: string
  type: string
}

interface ScopeCreator {
  id: number
  name: string
}

interface ScopeItem {
  id: number
  project_id: number
  target_id: number | null
  type: 'url' | 'hostname' | 'path' | 'api_route'
  pattern: string
  effect: 'allow' | 'deny'
  status: 'active' | 'inactive'
  reason: string | null
  created_at?: string
  project?: ScopeProject
  target?: ScopeTarget | null
  creator?: ScopeCreator
}

interface ProjectOption {
  id: number
  name: string
  code: string
}

interface TargetOption {
  id: number
  project_id: number
  name: string
  hostname?: string
  type: string
}

const scopes = ref<ScopeItem[]>([])
const projects = ref<ProjectOption[]>([])
const targets = ref<TargetOption[]>([])
const isLoading = ref(true)
const isSaving = ref(false)

// Filter states
const searchQuery = ref('')
const filterEffect = ref<string>('all')
const filterStatus = ref<string>('all')
const filterType = ref<string>('all')
const filterProjectId = ref<string>('all')

function resetFilters() {
  searchQuery.value = ''
  filterEffect.value = 'all'
  filterStatus.value = 'all'
  filterType.value = 'all'
  filterProjectId.value = 'all'
}

// Modal state
const isModalOpen = ref(false)
const editingScopeId = ref<number | null>(null)
const formError = ref<string | null>(null)
const formSuccess = ref<string | null>(null)

const scopeForm = reactive({
  project_id: '' as string | number,
  target_id: '' as string | number,
  type: 'url' as 'url' | 'hostname' | 'path' | 'api_route',
  pattern: '',
  effect: 'allow' as 'allow' | 'deny',
  status: 'active' as 'active' | 'inactive',
  reason: ''
})

const canManage = computed(() => {
  const role = currentUser.value?.role?.name || ''
  return ['super_admin', 'security_admin'].includes(role)
})

const filteredTargetsForProject = computed(() => {
  if (!scopeForm.project_id) return []
  return targets.value.filter(t => t.project_id === Number(scopeForm.project_id))
})

async function loadData() {
  isLoading.value = true
  try {
    const [scopeRes, projRes, targetRes] = await Promise.all([
      apiFetch('/api/scopes'),
      apiFetch('/api/projects').catch(() => ({ projects: [] })),
      apiFetch('/api/targets').catch(() => ({ targets: [] }))
    ])
    scopes.value = Array.isArray(scopeRes?.scopes) ? scopeRes.scopes : (Array.isArray(scopeRes) ? scopeRes : [])
    projects.value = Array.isArray(projRes?.projects) ? projRes.projects : (Array.isArray(projRes) ? projRes : [])
    targets.value = Array.isArray(targetRes?.targets) ? targetRes.targets : (Array.isArray(targetRes) ? targetRes : [])
  } catch (err: any) {
    console.error('Gagal memuat data scope:', err)
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  const list = scopes.value
  const total = list.length
  const allowed = list.filter(s => s.effect === 'allow').length
  const denied = list.filter(s => s.effect === 'deny').length
  const active = list.filter(s => s.status === 'active').length
  return { total, allowed, denied, active }
})

const filteredScopes = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  return scopes.value.filter(s => {
    if (filterEffect.value !== 'all' && s.effect !== filterEffect.value) return false
    if (filterStatus.value !== 'all' && s.status !== filterStatus.value) return false
    if (filterType.value !== 'all' && s.type !== filterType.value) return false
    if (filterProjectId.value !== 'all' && String(s.project_id) !== filterProjectId.value) return false

    if (!query) return true
    const pName = s.project?.name?.toLowerCase() || ''
    const pCode = s.project?.code?.toLowerCase() || ''
    const tName = s.target?.name?.toLowerCase() || ''
    const pattern = s.pattern.toLowerCase()
    const reason = s.reason?.toLowerCase() || ''
    return pName.includes(query) || pCode.includes(query) || tName.includes(query) || pattern.includes(query) || reason.includes(query)
  })
})

function openCreateModal() {
  editingScopeId.value = null
  formError.value = null
  formSuccess.value = null
  scopeForm.project_id = projects.value[0]?.id || ''
  scopeForm.target_id = ''
  scopeForm.type = 'url'
  scopeForm.pattern = ''
  scopeForm.effect = 'allow'
  scopeForm.status = 'active'
  scopeForm.reason = ''
  isModalOpen.value = true
}

function openEditModal(scope: ScopeItem) {
  editingScopeId.value = scope.id
  formError.value = null
  formSuccess.value = null
  scopeForm.project_id = scope.project_id
  scopeForm.target_id = scope.target_id || ''
  scopeForm.type = scope.type
  scopeForm.pattern = scope.pattern
  scopeForm.effect = scope.effect
  scopeForm.status = scope.status
  scopeForm.reason = scope.reason || ''
  isModalOpen.value = true
}

function closeModal() {
  isModalOpen.value = false
  editingScopeId.value = null
  formError.value = null
  formSuccess.value = null
}

async function saveScope() {
  if (!scopeForm.project_id) {
    formError.value = 'Proyek wajib dipilih.'
    return
  }
  if (!scopeForm.pattern.trim()) {
    formError.value = 'Pola batasan (pattern) tidak boleh kosong.'
    return
  }

  isSaving.value = true
  formError.value = null
  formSuccess.value = null

  try {
    const payload = {
      project_id: Number(scopeForm.project_id),
      target_id: scopeForm.target_id ? Number(scopeForm.target_id) : null,
      type: scopeForm.type,
      pattern: scopeForm.pattern.trim(),
      effect: scopeForm.effect,
      status: scopeForm.status,
      reason: scopeForm.reason.trim() || null
    }

    if (editingScopeId.value) {
      await apiFetch(`/api/scopes/${editingScopeId.value}`, {
        method: 'PUT',
        body: JSON.stringify(payload)
      })
      formSuccess.value = 'Aturan scope berhasil diperbarui.'
    } else {
      await apiFetch('/api/scopes', {
        method: 'POST',
        body: JSON.stringify(payload)
      })
      formSuccess.value = 'Aturan scope baru berhasil ditambahkan.'
    }

    await loadData()
    setTimeout(() => {
      closeModal()
    }, 600)
  } catch (err: any) {
    console.error('Gagal menyimpan scope:', err)
    formError.value = err?.data?.message || err?.message || 'Gagal menyimpan aturan scope.'
  } finally {
    isSaving.value = false
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
          <div class="col">
            <div class="page-pretitle text-secondary">
              Tata Kelola & Batasan Scan
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M12 11m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 12l0 2.5" /></svg>
              <span>Ruang Lingkup Pemindaian (Scope Guardrails)</span>
            </h2>
          </div>
          <div class="col-auto ms-auto d-print-none d-flex align-items-center gap-2">
            <button
              v-if="canManage"
              type="button"
              class="btn btn-primary d-flex align-items-center gap-1"
              @click="openCreateModal"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
              <span>Tambah Aturan Scope</span>
            </button>
          </div>
        </div>
      </div>

      <!-- KPI Metric Cards -->
      <div class="row row-cards mb-3">
        <div class="col-sm-6 col-lg-3">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-primary-lt text-primary avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4">{{ stats.total }}</div>
                  <div class="text-secondary small">Total Aturan Scope</div>
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
                  <span class="bg-teal-lt text-teal avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-teal">{{ stats.allowed }}</div>
                  <div class="text-secondary small">Batasan Diizinkan (Allow)</div>
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
                  <span class="bg-danger-lt text-danger avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-danger">{{ stats.denied }}</div>
                  <div class="text-secondary small">Batasan Dilarang (Deny)</div>
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
                  <span class="bg-success-lt text-success avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M9 12l2 2l4 -4" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-success">{{ stats.active }}</div>
                  <div class="text-secondary small">Aturan Status Aktif</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Main Table Card -->
      <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
          <h3 class="card-title m-0 d-flex align-items-center gap-2">
            <span>Daftar Kebijakan Scope</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{ filteredScopes.length }}</span>
          </h3>

          <!-- Filter Toolbar -->
          <div class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2">
            <!-- Filter Effect -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 12l2 2l4 -4" /><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
              </span>
              <select
                v-model="filterEffect"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterEffect !== 'all' }"
                title="Filter berdasarkan Efek Scope"
              >
                <option value="all">Semua Efek</option>
                <option value="allow">Diizinkan (Allow)</option>
                <option value="deny">Dilarang (Deny)</option>
              </select>
            </div>

            <!-- Filter Type -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-warning">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h10" /></svg>
              </span>
              <select
                v-model="filterType"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterType !== 'all' }"
                title="Filter berdasarkan Jenis Scope"
              >
                <option value="all">Semua Tipe</option>
                <option value="url">URL Pattern</option>
                <option value="hostname">Hostname</option>
                <option value="path">Path Directory</option>
                <option value="api_route">API Route</option>
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
                title="Filter Status"
              >
                <option value="all">Semua Status</option>
                <option value="active">Aktif</option>
                <option value="inactive">Nonaktif</option>
              </select>
            </div>

            <!-- Search Box -->
            <div class="search-box-wrapper position-relative">
              <div class="input-icon">
                <span class="input-icon-addon text-primary">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                </span>
                <input
                  v-model="searchQuery"
                  type="text"
                  class="form-control form-control-sm modern-search-input"
                  placeholder="Cari pola scope, proyek, alasan..."
                  @keydown.esc="searchQuery = ''"
                />
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

            <!-- Reset Button -->
            <button
              v-if="searchQuery || filterStatus !== 'all' || filterEffect !== 'all' || filterType !== 'all'"
              type="button"
              class="btn btn-sm btn-secondary d-flex align-items-center gap-1 filter-reset-btn"
              @click="resetFilters"
              title="Reset seluruh filter"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
              <span>Reset Filter</span>
            </button>
          </div>
        </div>

        <!-- Table Loading State -->
        <div v-if="isLoading" class="card-body text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
          <div class="text-secondary mt-2">Memuat data aturan scope...</div>
        </div>

        <!-- Empty State -->
        <div v-else-if="filteredScopes.length === 0" class="card-body text-center py-5">
          <div class="empty">
            <div class="empty-icon">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-secondary" width="32" height="32" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
            </div>
            <p class="empty-title">Tidak ada aturan scope yang sesuai</p>
            <p class="empty-subtitle text-secondary">
              Cobalah ubah filter pencarian atau buat aturan ruang lingkup baru untuk membatasi scan.
            </p>
            <div v-if="canManage" class="empty-action">
              <button class="btn btn-primary" @click="openCreateModal">
                Tambah Aturan Scope
              </button>
            </div>
          </div>
        </div>

        <!-- Data Table (Desktop & Tablet >= 768px) -->
        <div v-else class="table-responsive d-none d-md-block">
          <table class="table table-vcenter card-table table-hover">
            <thead>
              <tr>
                <th style="width: 100px;">Efek</th>
                <th>Tipe & Pola (Pattern)</th>
                <th>Proyek & Target Terkait</th>
                <th>Alasan / Justifikasi</th>
                <th style="width: 90px;">Status</th>
                <th style="width: 120px;">Dibuat Oleh</th>
                <th v-if="canManage" class="w-1 text-end">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="scope in filteredScopes" :key="scope.id">
                <td>
                  <span
                    class="badge"
                    :class="scope.effect === 'allow' ? 'bg-teal text-teal-fg' : 'bg-danger text-danger-fg'"
                  >
                    {{ scope.effect.toUpperCase() }}
                  </span>
                </td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary-lt font-monospace text-uppercase" style="font-size: 0.75rem;">
                      {{ scope.type }}
                    </span>
                    <code class="text-primary font-monospace fs-5 fw-bold">{{ scope.pattern }}</code>
                  </div>
                </td>
                <td>
                  <div class="fw-medium">{{ scope.project?.name || '-' }}</div>
                  <div class="text-secondary small d-flex align-items-center gap-1">
                    <span class="badge bg-blue-lt font-monospace">{{ scope.project?.code || 'GLOBAL' }}</span>
                    <span v-if="scope.target" class="text-muted">• Target: {{ scope.target.name }}</span>
                    <span v-else class="text-muted">• Seluruh Target Proyek</span>
                  </div>
                </td>
                <td>
                  <span v-if="scope.reason" class="text-secondary">{{ scope.reason }}</span>
                  <span v-else class="text-muted fst-italic">-</span>
                </td>
                <td>
                  <span
                    class="badge"
                    :class="scope.status === 'active' ? 'bg-success-lt text-success' : 'bg-secondary-lt text-secondary'"
                  >
                    {{ scope.status === 'active' ? 'Aktif' : 'Nonaktif' }}
                  </span>
                </td>
                <td>
                  <div class="small text-secondary">{{ scope.creator?.name || 'Sistem' }}</div>
                </td>
                <td v-if="canManage" class="text-end">
                  <button
                    class="btn btn-sm btn-ghost-primary"
                    @click="openEditModal(scope)"
                    title="Edit aturan scope"
                  >
                    Edit
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Card List (< 768px) -->
        <div class="d-md-none list-group list-group-flush">
          <div
            v-for="scope in filteredScopes"
            :key="scope.id"
            class="list-group-item px-3 py-2 cursor-pointer"
            style="cursor: pointer;"
            @click="canManage ? openEditModal(scope) : null"
          >
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span
                class="badge"
                :class="scope.effect === 'allow' ? 'bg-teal text-teal-fg' : 'bg-danger text-danger-fg'"
              >
                {{ scope.effect.toUpperCase() }}
              </span>
              <span
                class="badge"
                :class="scope.status === 'active' ? 'bg-success-lt text-success' : 'bg-secondary-lt text-secondary'"
              >
                {{ scope.status === 'active' ? 'Aktif' : 'Nonaktif' }}
              </span>
            </div>
            <div class="my-1">
              <span class="badge bg-secondary-lt font-monospace text-uppercase me-1" style="font-size: 0.72rem;">
                {{ scope.type }}
              </span>
              <code class="text-primary font-monospace fw-bold">{{ scope.pattern }}</code>
            </div>
            <div class="text-secondary small mb-1">
              {{ scope.project?.name || '-' }}
              <span v-if="scope.target" class="text-muted"> &middot; {{ scope.target.name }}</span>
            </div>
            <div v-if="scope.reason" class="text-secondary small fst-italic mb-1">
              "{{ scope.reason }}"
            </div>
            <div class="d-flex align-items-center justify-content-between text-secondary small mt-2 pt-1 border-top">
              <span>Oleh: {{ scope.creator?.name || 'Sistem' }}</span>
            </div>
            <div v-if="canManage" class="pt-2">
              <button
                type="button"
                class="btn btn-sm btn-primary flex-fill w-100"
                @click.stop="openEditModal(scope)"
              >
                Edit Aturan
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Form Scope -->
    <div
      v-if="isModalOpen"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">
              {{ editingScopeId ? 'Edit Aturan Ruang Lingkup' : 'Tambah Aturan Ruang Lingkup Baru' }}
            </h5>
            <button
              type="button"
              class="btn-close"
              :disabled="isSaving"
              @click="closeModal"
            ></button>
          </div>
          <form @submit.prevent="saveScope">
            <div class="modal-body">
              <div v-if="formError" class="alert alert-danger alert-dismissible mb-3">
                {{ formError }}
              </div>
              <div v-if="formSuccess" class="alert alert-success mb-3">
                {{ formSuccess }}
              </div>

              <!-- Proyek -->
              <div class="mb-3">
                <label class="form-label required">Proyek Aplikasi</label>
                <select
                  v-model="scopeForm.project_id"
                  class="form-select"
                  required
                >
                  <option value="" disabled>Pilih Proyek...</option>
                  <option v-for="proj in projects" :key="proj.id" :value="proj.id">
                    {{ proj.name }} ({{ proj.code }})
                  </option>
                </select>
              </div>

              <!-- Target Spesifik (Opsional) -->
              <div class="mb-3">
                <label class="form-label">Target Spesifik (Opsional)</label>
                <select
                  v-model="scopeForm.target_id"
                  class="form-select"
                >
                  <option value="">Semua Target dalam Proyek Ini</option>
                  <option
                    v-for="target in filteredTargetsForProject"
                    :key="target.id"
                    :value="target.id"
                  >
                    {{ target.name }} ({{ target.hostname || target.type }})
                  </option>
                </select>
                <small class="form-hint">Biarkan kosong jika aturan berlaku untuk seluruh target di proyek ini.</small>
              </div>

              <!-- Tipe & Efek -->
              <div class="row g-2 mb-3">
                <div class="col-sm-6">
                  <label class="form-label required">Tipe Pola</label>
                  <select v-model="scopeForm.type" class="form-select" required>
                    <option value="url">URL Pattern</option>
                    <option value="hostname">Hostname / Domain</option>
                    <option value="path">Path Directory</option>
                    <option value="api_route">API Route</option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label required">Efek Batasan</label>
                  <select v-model="scopeForm.effect" class="form-select" required>
                    <option value="allow">ALLOW (Diizinkan)</option>
                    <option value="deny">DENY (Dilarang / Blacklist)</option>
                  </select>
                </div>
              </div>

              <!-- Pattern -->
              <div class="mb-3">
                <label class="form-label required">Pola Batasan (Pattern)</label>
                <input
                  v-model="scopeForm.pattern"
                  type="text"
                  class="form-control font-monospace"
                  placeholder="Contoh: https://app.example.com/* atau /api/v1/*"
                  required
                />
                <small class="form-hint">Mendukung wildcard asterik (*) untuk mencocokkan subdomain atau path rute.</small>
              </div>

              <!-- Status -->
              <div class="mb-3">
                <label class="form-label required">Status Aturan</label>
                <div class="form-selectgroup">
                  <label class="form-selectgroup-item">
                    <input
                      type="radio"
                      v-model="scopeForm.status"
                      value="active"
                      class="form-selectgroup-input"
                    />
                    <span class="form-selectgroup-label text-success">Aktif</span>
                  </label>
                  <label class="form-selectgroup-item">
                    <input
                      type="radio"
                      v-model="scopeForm.status"
                      value="inactive"
                      class="form-selectgroup-input"
                    />
                    <span class="form-selectgroup-label text-secondary">Nonaktif</span>
                  </label>
                </div>
              </div>

              <!-- Reason -->
              <div class="mb-3">
                <label class="form-label">Alasan / Catatan Justifikasi</label>
                <textarea
                  v-model="scopeForm.reason"
                  class="form-control"
                  rows="2"
                  placeholder="Misal: Endpoint pembayaran sensitif dikecualikan dari scan agresif."
                ></textarea>
              </div>
            </div>

            <div class="modal-footer">
              <button
                type="button"
                class="btn btn-link link-secondary"
                :disabled="isSaving"
                @click="closeModal"
              >
                Batal
              </button>
              <button
                type="submit"
                class="btn btn-primary ms-auto"
                :disabled="isSaving"
              >
                <span v-if="isSaving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <span>{{ isSaving ? 'Menyimpan...' : 'Simpan Aturan Scope' }}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.custom-filter-select {
  padding-left: 2rem !important;
  border-radius: 9999px !important;
  font-size: 0.8125rem !important;
  font-weight: 500 !important;
}
.select-prefix-icon {
  position: absolute;
  left: 0.65rem;
  top: 50%;
  transform: translateY(-50%);
  pointer-events: none;
  z-index: 5;
}
.modern-search-input {
  border-radius: 9999px !important;
  padding-left: 2rem !important;
  padding-right: 2rem !important;
  min-width: 240px;
}
.search-clear-btn {
  position: absolute;
  right: 0.65rem;
  top: 50%;
  transform: translateY(-50%);
  z-index: 5;
}
.filter-reset-btn {
  border-radius: 9999px !important;
}
</style>
