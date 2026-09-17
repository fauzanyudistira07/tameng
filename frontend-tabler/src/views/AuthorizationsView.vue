<script setup lang="ts">
import { ref, computed, onMounted, reactive } from 'vue'
import { apiFetch } from '../services/api'
import { useAuth } from '../composables/useAuth'

const { currentUser } = useAuth()

interface AuthProject {
  id: number
  name: string
  code: string
}

interface AuthRepository {
  id: number
  name: string
  verification_status: string
}

interface AuthTarget {
  id: number
  name: string
  type: string
  verification_status: string
}

interface AuthScanProfile {
  id: number
  key: string
  name: string
}

interface AuthorizationItem {
  id: number
  code: string
  project_id: number
  repository_id: number | null
  target_id: number | null
  scan_profile_id: number
  valid_from: string
  valid_until: string
  max_concurrency: number
  rate_limit_per_minute: number
  status: 'active' | 'inactive'
  notes: string | null
  allowed_engines?: string[]
  allowed_scope_snapshot?: any[]
  denied_scope_snapshot?: any[]
  policy_snapshot?: any
  created_at?: string
  project?: AuthProject
  repository?: AuthRepository | null
  target?: AuthTarget | null
  scan_profile?: AuthScanProfile | null
  scanProfile?: AuthScanProfile | null
}

interface ProjectOption {
  id: number
  name: string
  code: string
  status: string
}

interface RepoOption {
  id: number
  project_id: number
  name: string
  verification_status: string
}

interface TargetOption {
  id: number
  project_id: number
  name: string
  type: string
  verification_status: string
}

interface ScanProfileOption {
  id: number
  key: string
  name: string
  is_active?: boolean
  allowed_target_types?: string[]
}

const authorizations = ref<AuthorizationItem[]>([])
const projects = ref<ProjectOption[]>([])
const repositories = ref<RepoOption[]>([])
const targets = ref<TargetOption[]>([])
const scanProfiles = ref<ScanProfileOption[]>([])

const isLoading = ref(true)
const isSaving = ref(false)

// Filter states
const searchQuery = ref('')
const filterStatus = ref<string>('all')
const filterProfile = ref<string>('all')

function resetFilters() {
  searchQuery.value = ''
  filterStatus.value = 'all'
  filterProfile.value = 'all'
}

// Modal Create state
const isCreateModalOpen = ref(false)
const formError = ref<string | null>(null)
const formSuccess = ref<string | null>(null)

// Modal Inspect Snapshot state
const isInspectModalOpen = ref(false)
const inspectingAuth = ref<AuthorizationItem | null>(null)

const authForm = reactive({
  project_id: '' as string | number,
  asset_type: 'target' as 'target' | 'repository',
  target_id: '' as string | number,
  repository_id: '' as string | number,
  scan_profile_id: '' as string | number,
  valid_from: '',
  valid_until: '',
  max_concurrency: 2,
  rate_limit_per_minute: 60,
  status: 'active' as 'active' | 'inactive',
  notes: ''
})

const canManage = computed(() => {
  const role = currentUser.value?.role?.name || ''
  return ['super_admin', 'security_admin'].includes(role)
})

const availableTargets = computed(() => {
  if (!authForm.project_id) return []
  return targets.value.filter(t => t.project_id === Number(authForm.project_id) && t.verification_status === 'verified')
})

const availableRepos = computed(() => {
  if (!authForm.project_id) return []
  return repositories.value.filter(r => r.project_id === Number(authForm.project_id) && r.verification_status === 'verified')
})

async function loadData() {
  isLoading.value = true
  try {
    const [authRes, projRes, repoRes, targetRes, profRes] = await Promise.all([
      apiFetch('/api/authorizations'),
      apiFetch('/api/projects').catch(() => ({ projects: [] })),
      apiFetch('/api/repositories').catch(() => ({ repositories: [] })),
      apiFetch('/api/targets').catch(() => ({ targets: [] })),
      apiFetch('/api/scan-profiles').catch(() => ({ scan_profiles: [] }))
    ])

    authorizations.value = Array.isArray(authRes?.authorizations) ? authRes.authorizations : (Array.isArray(authRes) ? authRes : [])
    projects.value = Array.isArray(projRes?.projects) ? projRes.projects : (Array.isArray(projRes) ? projRes : [])
    repositories.value = Array.isArray(repoRes?.repositories) ? repoRes.repositories : (Array.isArray(repoRes) ? repoRes : [])
    targets.value = Array.isArray(targetRes?.targets) ? targetRes.targets : (Array.isArray(targetRes) ? targetRes : [])
    scanProfiles.value = Array.isArray(profRes?.scan_profiles) ? profRes.scan_profiles : (Array.isArray(profRes) ? profRes : [])
  } catch (err: any) {
    console.error('Gagal memuat daftar otorisasi scan:', err)
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  const list = authorizations.value
  const total = list.length
  const active = list.filter(a => a.status === 'active').length
  const uniqueProfiles = new Set(list.map(a => a.scan_profile_id)).size
  const avgRateLimit = total > 0 ? Math.round(list.reduce((acc, a) => acc + (a.rate_limit_per_minute || 0), 0) / total) : 0
  return { total, active, uniqueProfiles, avgRateLimit }
})

const filteredAuthorizations = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  return authorizations.value.filter(a => {
    if (filterStatus.value !== 'all' && a.status !== filterStatus.value) return false
    if (filterProfile.value !== 'all' && String(a.scan_profile_id) !== filterProfile.value) return false

    if (!query) return true
    const code = a.code?.toLowerCase() || ''
    const pName = a.project?.name?.toLowerCase() || ''
    const pCode = a.project?.code?.toLowerCase() || ''
    const tName = a.target?.name?.toLowerCase() || ''
    const rName = a.repository?.name?.toLowerCase() || ''
    const prof = (a.scanProfile?.name || a.scan_profile?.name || '').toLowerCase()
    return code.includes(query) || pName.includes(query) || pCode.includes(query) || tName.includes(query) || rName.includes(query) || prof.includes(query)
  })
})

function openCreateModal() {
  formError.value = null
  formSuccess.value = null
  authForm.project_id = projects.value[0]?.id || ''
  authForm.asset_type = 'target'
  authForm.target_id = ''
  authForm.repository_id = ''
  authForm.scan_profile_id = scanProfiles.value[0]?.id || ''

  // Defaults: valid_from now, valid_until +30 days
  const now = new Date()
  const later = new Date()
  later.setDate(later.getDate() + 30)

  authForm.valid_from = now.toISOString().slice(0, 16)
  authForm.valid_until = later.toISOString().slice(0, 16)
  authForm.max_concurrency = 2
  authForm.rate_limit_per_minute = 60
  authForm.status = 'active'
  authForm.notes = ''
  isCreateModalOpen.value = true
}

function closeCreateModal() {
  isCreateModalOpen.value = false
  formError.value = null
  formSuccess.value = null
}

function openInspectModal(item: AuthorizationItem) {
  inspectingAuth.value = item
  isInspectModalOpen.value = true
}

function closeInspectModal() {
  isInspectModalOpen.value = false
  inspectingAuth.value = null
}

function formatDate(dt?: string) {
  if (!dt) return '-'
  try {
    return new Date(dt).toLocaleString('id-ID', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    })
  } catch {
    return dt
  }
}

async function saveAuthorization() {
  if (!authForm.project_id) {
    formError.value = 'Proyek wajib dipilih.'
    return
  }
  if (!authForm.scan_profile_id) {
    formError.value = 'Profil scan wajib dipilih.'
    return
  }
  if (authForm.asset_type === 'target' && !authForm.target_id) {
    formError.value = 'Target terverifikasi wajib dipilih.'
    return
  }
  if (authForm.asset_type === 'repository' && !authForm.repository_id) {
    formError.value = 'Repositori terverifikasi wajib dipilih.'
    return
  }
  if (!authForm.valid_from || !authForm.valid_until) {
    formError.value = 'Rentang waktu masa berlaku (valid from & until) wajib diisi.'
    return
  }
  if (new Date(authForm.valid_until) <= new Date(authForm.valid_from)) {
    formError.value = 'Waktu berakhir (Valid Until) harus lebih besar dari waktu mulai.'
    return
  }

  isSaving.value = true
  formError.value = null
  formSuccess.value = null

  try {
    const payload: any = {
      project_id: Number(authForm.project_id),
      scan_profile_id: Number(authForm.scan_profile_id),
      valid_from: new Date(authForm.valid_from).toISOString(),
      valid_until: new Date(authForm.valid_until).toISOString(),
      max_concurrency: Number(authForm.max_concurrency),
      rate_limit_per_minute: Number(authForm.rate_limit_per_minute),
      status: authForm.status,
      notes: authForm.notes.trim() || null
    }

    if (authForm.asset_type === 'target') {
      payload.target_id = Number(authForm.target_id)
      payload.repository_id = null
    } else {
      payload.repository_id = Number(authForm.repository_id)
      payload.target_id = null
    }

    await apiFetch('/api/authorizations', {
      method: 'POST',
      body: JSON.stringify(payload)
    })

    formSuccess.value = 'Tiket otorisasi scan berhasil diterbitkan.'
    await loadData()
    setTimeout(() => {
      closeCreateModal()
    }, 600)
  } catch (err: any) {
    console.error('Gagal menerbitkan otorisasi:', err)
    formError.value = err?.data?.message || err?.message || 'Gagal menerbitkan otorisasi. Pastikan proyek memiliki scope allow aktif dan aset sudah terverifikasi.'
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
              Tata Kelola & Kepatuhan Legal
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 12l2 2l4 -4" /><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
              <span>Otorisasi Pemindaian (Scan Authorization Guardrails)</span>
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
              <span>Terbitkan Otorisasi Baru</span>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 15l2 2l4 -4" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4">{{ stats.total }}</div>
                  <div class="text-secondary small">Total Tiket Otorisasi</div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-success">{{ stats.active }}</div>
                  <div class="text-secondary small">Otorisasi Aktif Berlaku</div>
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
                  <span class="bg-indigo-lt text-indigo avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v6h-6z" /><path d="M14 4h6v6h-6z" /><path d="M4 14h6v6h-6z" /><path d="M17 17m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-indigo">{{ stats.uniqueProfiles }}</div>
                  <div class="text-secondary small">Profil Keamanan Terpetakan</div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M13 3l0 7l6 0l-8 11l0 -7l-6 0z" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-teal">{{ stats.avgRateLimit }} /mnt</div>
                  <div class="text-secondary small">Rata-rata Batas Rate Limit</div>
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
            <span>Daftar Otorisasi Scan</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{ filteredAuthorizations.length }}</span>
          </h3>

          <!-- Filter Toolbar -->
          <div class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2">
            <!-- Filter Status -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-success">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>
              </span>
              <select
                v-model="filterStatus"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterStatus !== 'all' }"
                title="Filter Status Otorisasi"
              >
                <option value="all">Semua Status</option>
                <option value="active">Aktif</option>
                <option value="inactive">Nonaktif</option>
              </select>
            </div>

            <!-- Filter Profile -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-indigo">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v6h-6z" /><path d="M14 4h6v6h-6z" /><path d="M4 14h6v6h-6z" /><path d="M17 17m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /></svg>
              </span>
              <select
                v-model="filterProfile"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterProfile !== 'all' }"
                title="Filter Profil Scan"
              >
                <option value="all">Semua Profil Scan</option>
                <option v-for="prof in scanProfiles" :key="prof.id" :value="String(prof.id)">
                  {{ prof.name }}
                </option>
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
                  placeholder="Cari kode AUTH, proyek, target..."
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
              v-if="searchQuery || filterStatus !== 'all' || filterProfile !== 'all'"
              type="button"
              class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1 filter-reset-btn"
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
          <div class="text-secondary mt-2">Memuat data otorisasi pemindaian...</div>
        </div>

        <!-- Empty State -->
        <div v-else-if="filteredAuthorizations.length === 0" class="card-body text-center py-5">
          <div class="empty">
            <div class="empty-icon">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-secondary" width="32" height="32" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 12l2 2l4 -4" /><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
            </div>
            <p class="empty-title">Tidak ada tiket otorisasi ditemukan</p>
            <p class="empty-subtitle text-secondary">
              Setiap pekerjaan scan memerlukan tiket otorisasi legal yang sah sebelum engine dijalankan.
            </p>
            <div v-if="canManage" class="empty-action">
              <button class="btn btn-primary" @click="openCreateModal">
                Terbitkan Otorisasi Baru
              </button>
            </div>
          </div>
        </div>

        <!-- Data Table -->
        <div v-else class="table-responsive">
          <table class="table table-vcenter card-table table-hover">
            <thead>
              <tr>
                <th>Kode Otorisasi</th>
                <th>Proyek & Target/Repo</th>
                <th>Profil Scan Terpilih</th>
                <th>Masa Berlaku</th>
                <th>Guardrails (Rate & Concurrency)</th>
                <th style="width: 80px;">Status</th>
                <th class="w-1 text-end">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="auth in filteredAuthorizations" :key="auth.id">
                <td>
                  <div class="d-flex align-items-center gap-1">
                    <span class="badge bg-indigo-lt text-indigo font-monospace fw-bold">
                      {{ auth.code }}
                    </span>
                  </div>
                  <div class="text-muted small mt-1">Dibuat {{ formatDate(auth.created_at) }}</div>
                </td>
                <td>
                  <div class="fw-bold">{{ auth.project?.name || '-' }}</div>
                  <div class="small d-flex align-items-center gap-1 text-secondary">
                    <span class="badge bg-blue-lt font-monospace">{{ auth.project?.code || 'PRJ' }}</span>
                    <span v-if="auth.target" class="text-teal fw-medium d-inline-flex align-items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-teal" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 12m-5 0a5 5 0 1 0 10 0a5 5 0 1 0 -10 0" /><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
                      <span>{{ auth.target.name }} ({{ auth.target.type }})</span>
                    </span>
                    <span v-else-if="auth.repository" class="text-indigo fw-medium d-inline-flex align-items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-indigo" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" /><path d="M12 12l8 -4.5" /><path d="M12 12l0 9" /><path d="M12 12l-8 -4.5" /></svg>
                      <span>{{ auth.repository.name }}</span>
                    </span>
                    <span v-else class="text-muted fst-italic">Semua Aset Proyek</span>
                  </div>
                </td>
                <td>
                  <div class="badge bg-purple-lt text-purple font-monospace">
                    {{ auth.scanProfile?.name || auth.scan_profile?.name || 'Standard Audit' }}
                  </div>
                </td>
                <td>
                  <div class="small text-secondary">
                    <div><strong>Dari:</strong> {{ formatDate(auth.valid_from) }}</div>
                    <div><strong>Hingga:</strong> {{ formatDate(auth.valid_until) }}</div>
                  </div>
                </td>
                <td>
                  <div class="small">
                    <span class="badge bg-secondary-lt font-monospace me-1">
                      Max: {{ auth.max_concurrency }} worker
                    </span>
                    <span class="badge bg-secondary-lt font-monospace">
                      {{ auth.rate_limit_per_minute }} req/mnt
                    </span>
                  </div>
                </td>
                <td>
                  <span
                    class="badge"
                    :class="auth.status === 'active' ? 'bg-success text-success-fg' : 'bg-secondary text-secondary-fg'"
                  >
                    {{ auth.status === 'active' ? 'Aktif' : 'Nonaktif' }}
                  </span>
                </td>
                <td class="text-end">
                  <button
                    class="btn btn-sm btn-ghost-primary d-inline-flex align-items-center gap-1"
                    @click="openInspectModal(auth)"
                    title="Inspeksi Snapshot Kebijakan & Scope"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                    <span>Snapshot</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal Form Create Authorization -->
    <div
      v-if="isCreateModalOpen"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Terbitkan Tiket Otorisasi Scan Baru</h5>
            <button
              type="button"
              class="btn-close"
              :disabled="isSaving"
              @click="closeCreateModal"
            ></button>
          </div>
          <form @submit.prevent="saveAuthorization">
            <div class="modal-body">
              <div v-if="formError" class="alert alert-danger alert-dismissible mb-3">
                {{ formError }}
              </div>
              <div v-if="formSuccess" class="alert alert-success mb-3">
                {{ formSuccess }}
              </div>

              <!-- Proyek & Profil Scan -->
              <div class="row g-2 mb-3">
                <div class="col-sm-6">
                  <label class="form-label required">Proyek Aplikasi</label>
                  <select
                    v-model="authForm.project_id"
                    class="form-select"
                    required
                  >
                    <option value="" disabled>Pilih Proyek...</option>
                    <option v-for="p in projects" :key="p.id" :value="p.id">
                      {{ p.name }} ({{ p.code }})
                    </option>
                  </select>
                </div>
                <div class="col-sm-6">
                  <label class="form-label required">Profil Pemindaian (Scan Profile)</label>
                  <select
                    v-model="authForm.scan_profile_id"
                    class="form-select"
                    required
                  >
                    <option value="" disabled>Pilih Profil Keamanan...</option>
                    <option v-for="prof in scanProfiles" :key="prof.id" :value="prof.id">
                      {{ prof.name }}
                    </option>
                  </select>
                </div>
              </div>

              <!-- Pilihan Tipe Aset (Target vs Repo) -->
              <div class="mb-3">
                <label class="form-label required">Jenis Aset Terverifikasi</label>
                <div class="btn-group w-100" role="group">
                  <input
                    type="radio"
                    class="btn-check"
                    name="btnAssetType"
                    id="btnTarget"
                    value="target"
                    v-model="authForm.asset_type"
                  />
                  <label class="btn btn-outline-primary d-inline-flex align-items-center justify-content-center gap-1" for="btnTarget">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 12m-5 0a5 5 0 1 0 10 0a5 5 0 1 0 -10 0" /><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
                    <span>Target (Web, API, Mobile APK)</span>
                  </label>

                  <input
                    type="radio"
                    class="btn-check"
                    name="btnAssetType"
                    id="btnRepo"
                    value="repository"
                    v-model="authForm.asset_type"
                  />
                  <label class="btn btn-outline-primary d-inline-flex align-items-center justify-content-center gap-1" for="btnRepo">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" /><path d="M12 12l8 -4.5" /><path d="M12 12l0 9" /><path d="M12 12l-8 -4.5" /></svg>
                    <span>Repositori Kode Sumber</span>
                  </label>
                </div>
              </div>

              <!-- Target Selection -->
              <div v-if="authForm.asset_type === 'target'" class="mb-3">
                <label class="form-label required">Target Terverifikasi</label>
                <select
                  v-model="authForm.target_id"
                  class="form-select"
                  required
                >
                  <option value="" disabled>Pilih Target...</option>
                  <option v-for="t in availableTargets" :key="t.id" :value="t.id">
                    {{ t.name }} ({{ t.type }})
                  </option>
                </select>
                <small v-if="availableTargets.length === 0" class="text-danger small mt-1 d-flex align-items-center gap-1">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
                  <span>Tidak ada target terverifikasi pada proyek ini. Verifikasi target terlebih dahulu di menu Target.</span>
                </small>
              </div>

              <!-- Repo Selection -->
              <div v-else class="mb-3">
                <label class="form-label required">Repositori Terverifikasi</label>
                <select
                  v-model="authForm.repository_id"
                  class="form-select"
                  required
                >
                  <option value="" disabled>Pilih Repositori...</option>
                  <option v-for="r in availableRepos" :key="r.id" :value="r.id">
                    {{ r.name }}
                  </option>
                </select>
                <small v-if="availableRepos.length === 0" class="text-danger small mt-1 d-flex align-items-center gap-1">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
                  <span>Tidak ada repositori terverifikasi pada proyek ini. Verifikasi repo terlebih dahulu di Repositori Kode.</span>
                </small>
              </div>

              <!-- Masa Berlaku (valid_from & valid_until) -->
              <div class="row g-2 mb-3">
                <div class="col-sm-6">
                  <label class="form-label required">Berlaku Mulai (Valid From)</label>
                  <input
                    v-model="authForm.valid_from"
                    type="datetime-local"
                    class="form-control"
                    required
                  />
                </div>
                <div class="col-sm-6">
                  <label class="form-label required">Berlaku Hingga (Valid Until)</label>
                  <input
                    v-model="authForm.valid_until"
                    type="datetime-local"
                    class="form-control"
                    required
                  />
                </div>
              </div>

              <!-- Guardrails (Concurrency & Rate Limit) -->
              <div class="row g-2 mb-3">
                <div class="col-sm-6">
                  <label class="form-label required">
                    Max Concurrency: <strong class="text-primary">{{ authForm.max_concurrency }} worker</strong>
                  </label>
                  <input
                    v-model.number="authForm.max_concurrency"
                    type="range"
                    class="form-range"
                    min="1"
                    max="10"
                  />
                  <small class="form-hint">Jumlah engine yang diizinkan jalan paralel (1-10).</small>
                </div>
                <div class="col-sm-6">
                  <label class="form-label required">
                    Batas Permintaan: <strong class="text-teal">{{ authForm.rate_limit_per_minute }} req/mnt</strong>
                  </label>
                  <input
                    v-model.number="authForm.rate_limit_per_minute"
                    type="number"
                    class="form-control"
                    min="1"
                    max="600"
                    required
                  />
                  <small class="form-hint">Maksimal request per menit ke server target (1-600).</small>
                </div>
              </div>

              <!-- Notes -->
              <div class="mb-3">
                <label class="form-label">Catatan Kepatuhan / Dasar Legalitas</label>
                <textarea
                  v-model="authForm.notes"
                  class="form-control"
                  rows="2"
                  placeholder="Contoh: Tiket persetujuan CISO No: SEC-2026/09/Q3-AUDIT"
                ></textarea>
              </div>
            </div>

            <div class="modal-footer">
              <button
                type="button"
                class="btn btn-link link-secondary"
                :disabled="isSaving"
                @click="closeCreateModal"
              >
                Batal
              </button>
              <button
                type="submit"
                class="btn btn-primary ms-auto"
                :disabled="isSaving"
              >
                <span v-if="isSaving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <span>{{ isSaving ? 'Menerbitkan...' : 'Terbitkan Otorisasi' }}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal Inspect Policy Snapshot -->
    <div
      v-if="isInspectModalOpen && inspectingAuth"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title d-flex align-items-center gap-2">
              <span class="badge bg-indigo text-indigo-fg font-monospace">{{ inspectingAuth.code }}</span>
              <span>Snapshot Kebijakan & Guardrail Keamanan</span>
            </h5>
            <button
              type="button"
              class="btn-close"
              @click="closeInspectModal"
            ></button>
          </div>
          <div class="modal-body">
            <!-- Summary Info -->
            <div class="card mb-3 bg-body-tertiary border-0">
              <div class="card-body py-2">
                <div class="row g-2 small">
                  <div class="col-sm-6">
                    <div><strong>Proyek:</strong> {{ inspectingAuth.project?.name }} ({{ inspectingAuth.project?.code }})</div>
                    <div><strong>Profil Scan:</strong> {{ inspectingAuth.scanProfile?.name || inspectingAuth.scan_profile?.name || '-' }}</div>
                  </div>
                  <div class="col-sm-6">
                    <div><strong>Masa Berlaku:</strong> {{ formatDate(inspectingAuth.valid_from) }} s/d {{ formatDate(inspectingAuth.valid_until) }}</div>
                    <div><strong>Status:</strong> <span class="badge bg-success-lt">{{ inspectingAuth.status }}</span></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Allowed Engines -->
            <div class="mb-3">
              <label class="form-label fw-bold text-secondary">Mesin Keamanan Yang Diizinkan (Allowed Engines)</label>
              <div v-if="inspectingAuth.allowed_engines && inspectingAuth.allowed_engines.length > 0" class="d-flex flex-wrap gap-1">
                <span
                  v-for="eng in inspectingAuth.allowed_engines"
                  :key="eng"
                  class="badge bg-blue-lt font-monospace text-uppercase"
                >
                  {{ eng }}
                </span>
              </div>
              <div v-else class="text-muted small fst-italic">Bawaan konfigurasi profil scan.</div>
            </div>

            <!-- Scope Snapshots -->
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label fw-bold text-teal">Snapshot Scope Diizinkan (Allow)</label>
                <div class="bg-body-secondary p-2 rounded" style="max-height: 180px; overflow-y: auto;">
                  <div v-if="inspectingAuth.allowed_scope_snapshot && inspectingAuth.allowed_scope_snapshot.length > 0">
                    <div v-for="(sc, idx) in inspectingAuth.allowed_scope_snapshot" :key="idx" class="small mb-1 font-monospace">
                      <span class="badge bg-teal-lt me-1">{{ sc.type }}</span> {{ sc.pattern }}
                    </div>
                  </div>
                  <div v-else class="text-muted small fst-italic">Semua rute aset diizinkan.</div>
                </div>
              </div>

              <div class="col-sm-6">
                <label class="form-label fw-bold text-danger">Snapshot Scope Pengecualian (Deny)</label>
                <div class="bg-body-secondary p-2 rounded" style="max-height: 180px; overflow-y: auto;">
                  <div v-if="inspectingAuth.denied_scope_snapshot && inspectingAuth.denied_scope_snapshot.length > 0">
                    <div v-for="(sc, idx) in inspectingAuth.denied_scope_snapshot" :key="idx" class="small mb-1 font-monospace">
                      <span class="badge bg-danger-lt me-1">{{ sc.type }}</span> {{ sc.pattern }}
                    </div>
                  </div>
                  <div v-else class="text-muted small fst-italic">Tidak ada batasan pengecualian aktif.</div>
                </div>
              </div>
            </div>

            <!-- Policy Snapshot JSON -->
            <div>
              <label class="form-label fw-bold text-secondary">Rincian JSON Policy Snapshot</label>
              <pre class="bg-dark text-light p-2 rounded font-monospace small" style="max-height: 160px; overflow-y: auto;">{{ JSON.stringify(inspectingAuth.policy_snapshot, null, 2) }}</pre>
            </div>
          </div>
          <div class="modal-footer">
            <button
              type="button"
              class="btn btn-secondary ms-auto"
              @click="closeInspectModal"
            >
              Tutup
            </button>
          </div>
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
