<script setup lang="ts">
import { ref, computed, onMounted, reactive, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch } from '../services/api'
import { useAuth } from '../composables/useAuth'

const route = useRoute()
const router = useRouter()
const { currentUser } = useAuth()

interface ProjectRef {
  id: number
  name: string
  code: string
}

interface RepositoryMetadata {
  is_private?: boolean
  has_access_token?: boolean
  local_path?: string
  cloned_at?: string
  commit_hash?: string
  last_synced_at?: string
}

interface Repository {
  id: number
  project_id: number
  project?: ProjectRef
  provider: 'github' | 'gitlab' | 'bitbucket' | 'git' | string
  name: string
  url: string
  default_branch: string
  verification_status?: 'verified' | 'pending' | 'rejected'
  verified_at?: string
  verified_by?: number
  verifier?: { id: number; name: string }
  metadata?: RepositoryMetadata
  created_at?: string
  updated_at?: string
}

const repositories = ref<Repository[]>([])
const projects = ref<ProjectRef[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const syncingRepoId = ref<number | null>(null)
const clearingRepoId = ref<number | null>(null)
const scanningRepoId = ref<number | null>(null)
// Toast Alert (Floating, seperti Scan Mandiri)
const alertMessage = ref<{ type: 'success' | 'danger' | 'info'; text: string; scanJobCode?: string } | null>(null)

function showAlert(type: 'success' | 'danger' | 'info', text: string, scanJobCode?: string) {
  alertMessage.value = { type, text, scanJobCode }
  setTimeout(() => {
    if (alertMessage.value?.text === text) {
      alertMessage.value = null
    }
  }, 6000)
}

function showFeedback(type: 'success' | 'danger' | 'info', text: string, scanJobCode?: string) {
  showAlert(type, text, scanJobCode)
}

// Modal Konfirmasi & Sukses Scan SAST (mirip Scan Mandiri)
const scanModalRepo = ref<Repository | null>(null)
const scanSuccessJob = ref<any | null>(null)
const isScanning = ref(false)
const scanError = ref<string | null>(null)

// Filters
const searchQuery = ref('')
const filterProject = ref<string>('all')
const filterProvider = ref<string>('all')
const filterWorkspace = ref<string>('all')

function resetFilters() {
  filterProject.value = 'all'
  filterProvider.value = 'all'
  filterWorkspace.value = 'all'
  searchQuery.value = ''
}

// Modal state
const isModalOpen = ref(false)
const editingRepoId = ref<number | null>(null)
const formError = ref<string | null>(null)
const showPassword = ref(false)

const repoForm = reactive({
  project_id: '' as string | number,
  provider: 'github',
  name: '',
  url: '',
  default_branch: 'main',
  is_private: false,
  access_token: ''
})

const canManage = computed(() => {
  const role = currentUser.value?.role?.name || ''
  return ['super_admin', 'security_admin'].includes(role)
})

// Salin URL Git ke Clipboard (sangat praktis di mobile)
const copiedUrl = ref<string | null>(null)
function copyToClipboard(url: string) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(url)
    copiedUrl.value = url
    setTimeout(() => {
      if (copiedUrl.value === url) copiedUrl.value = null
    }, 2000)
  }
}

// Auto-detect provider from URL
function onUrlInput() {
  const url = repoForm.url.toLowerCase()
  if (url.includes('github.com')) {
    repoForm.provider = 'github'
  } else if (url.includes('gitlab')) {
    repoForm.provider = 'gitlab'
  } else if (url.includes('bitbucket')) {
    repoForm.provider = 'bitbucket'
  }

  // Auto-fill name from git url
  if (!editingRepoId.value && !repoForm.name) {
    const parts = repoForm.url.replace(/\.git$/, '').split('/')
    const lastPart = parts[parts.length - 1]
    if (lastPart) {
      repoForm.name = lastPart
    }
  }
}

async function loadData() {
  isLoading.value = true
  try {
    const [repoRes, projRes] = await Promise.all([
      apiFetch('/api/repositories'),
      apiFetch('/api/projects')
    ])
    repositories.value = Array.isArray(repoRes?.repositories) ? repoRes.repositories : (Array.isArray(repoRes) ? repoRes : [])
    projects.value = Array.isArray(projRes?.projects) ? projRes.projects : (Array.isArray(projRes) ? projRes : [])

    // If route query has project_id, filter by it
    if (route.query.project_id) {
      filterProject.value = String(route.query.project_id)
    }
  } catch (err: any) {
    console.error('Gagal memuat repositori:', err)
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  const list = repositories.value
  const total = list.length
  const synced = list.filter(r => !!r.metadata?.local_path).length
  const privateCount = list.filter(r => r.metadata?.is_private || r.metadata?.has_access_token).length
  const verified = list.filter(r => r.verification_status === 'verified').length
  return { total, synced, privateCount, verified }
})

const filteredRepositories = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  const list = repositories.value.filter(r => {
    // Project filter
    if (filterProject.value !== 'all' && String(r.project_id) !== filterProject.value) {
      return false
    }
    // Provider filter
    if (filterProvider.value !== 'all' && r.provider !== filterProvider.value) {
      return false
    }
    // Workspace filter
    if (filterWorkspace.value === 'synced' && !r.metadata?.local_path) return false
    if (filterWorkspace.value === 'unsynced' && r.metadata?.local_path) return false

    // Search query
    if (!query) return true
    const nameMatch = (r.name || '').toLowerCase().includes(query)
    const urlMatch = (r.url || '').toLowerCase().includes(query)
    const projName = (r.project?.name || '').toLowerCase().includes(query)
    const projCode = (r.project?.code || '').toLowerCase().includes(query)
    const branchMatch = (r.default_branch || '').toLowerCase().includes(query)
    return nameMatch || urlMatch || projName || projCode || branchMatch
  })

  return list.sort((a, b) => {
    const timeA = a.created_at ? new Date(a.created_at).getTime() : a.id
    const timeB = b.created_at ? new Date(b.created_at).getTime() : b.id
    return timeB - timeA
  })
})

function openCreateModal() {
  editingRepoId.value = null
  repoForm.project_id = (filterProject.value !== 'all' ? filterProject.value : (projects.value[0]?.id || ''))
  repoForm.provider = 'github'
  repoForm.name = ''
  repoForm.url = ''
  repoForm.default_branch = 'main'
  repoForm.is_private = false
  repoForm.access_token = ''
  showPassword.value = false
  formError.value = null
  isModalOpen.value = true
}

function openEditModal(repo: Repository) {
  editingRepoId.value = repo.id
  repoForm.project_id = repo.project_id
  repoForm.provider = repo.provider || 'github'
  repoForm.name = repo.name
  repoForm.url = repo.url
  repoForm.default_branch = repo.default_branch || 'main'
  repoForm.is_private = Boolean(repo.metadata?.is_private || repo.metadata?.has_access_token)
  repoForm.access_token = ''
  showPassword.value = false
  formError.value = null
  isModalOpen.value = true
}

function closeModal() {
  isModalOpen.value = false
  editingRepoId.value = null
  formError.value = null
}

async function saveRepository() {
  if (!repoForm.project_id) {
    formError.value = 'Proyek induk wajib dipilih.'
    return
  }
  if (!repoForm.name.trim()) {
    formError.value = 'Nama repositori wajib diisi.'
    return
  }
  if (!repoForm.url.trim()) {
    formError.value = 'URL Git repositori wajib diisi.'
    return
  }

  isSaving.value = true
  formError.value = null

  try {
    const payload: any = {
      project_id: Number(repoForm.project_id),
      provider: repoForm.provider,
      name: repoForm.name.trim(),
      url: repoForm.url.trim(),
      default_branch: repoForm.default_branch.trim() || 'main',
      is_private: repoForm.is_private
    }

    if (repoForm.access_token.trim()) {
      payload.access_token = repoForm.access_token.trim()
    }

    if (editingRepoId.value) {
      await apiFetch(`/api/repositories/${editingRepoId.value}`, {
        method: 'PUT',
        body: JSON.stringify(payload)
      })
      showFeedback('success', `Repositori "${payload.name}" berhasil diperbarui.`)
    } else {
      await apiFetch('/api/repositories', {
        method: 'POST',
        body: JSON.stringify(payload)
      })
      showFeedback('success', `Repositori baru "${payload.name}" berhasil didaftarkan.`)
    }

    await loadData()
    closeModal()
  } catch (err: any) {
    console.error('Gagal menyimpan repositori:', err)
    formError.value = err?.data?.message || err?.message || 'Gagal menyimpan repositori. Periksa kembali URL dan token.'
  } finally {
    isSaving.value = false
  }
}

// Clone or Sync workspace to worker storage
async function syncWorkspace(repo: Repository) {
  syncingRepoId.value = repo.id
  try {
    const res = await apiFetch(`/api/repositories/${repo.id}/clone-workspace`, {
      method: 'POST'
    })
    showFeedback('success', res?.message || `Kode repositori "${repo.name}" berhasil ditarik ke server worker.`)
    await loadData()
  } catch (err: any) {
    console.error('Gagal sinkronisasi workspace:', err)
    showFeedback('danger', err?.data?.message || err?.message || `Gagal melakukan git clone/pull untuk "${repo.name}".`)
  } finally {
    syncingRepoId.value = null
  }
}

// Modal Konfirmasi Bersihkan Workspace
const cleanModalRepo = ref<Repository | null>(null)
const isClearingWorkspace = ref(false)

function clearWorkspace(repo: Repository) {
  cleanModalRepo.value = repo
}

function closeCleanModal() {
  cleanModalRepo.value = null
  isClearingWorkspace.value = false
}

async function confirmClearWorkspace() {
  if (!cleanModalRepo.value) return
  const repo = cleanModalRepo.value
  isClearingWorkspace.value = true
  clearingRepoId.value = repo.id
  try {
    await apiFetch(`/api/repositories/${repo.id}/workspace`, {
      method: 'DELETE'
    })
    showFeedback('success', `File workspace di server untuk "${repo.name}" telah berhasil dibersihkan.`)
    closeCleanModal()
    await loadData()
  } catch (err: any) {
    console.error('Gagal membersihkan workspace:', err)
    showFeedback('danger', err?.data?.message || err?.message || 'Gagal membersihkan workspace lokal.')
  } finally {
    isClearingWorkspace.value = false
    clearingRepoId.value = null
  }
}

// Modal Konfirmasi Hapus Repositori (Permanent Delete)
const deleteModalRepo = ref<Repository | null>(null)
const isDeletingRepo = ref(false)

function openDeleteModal(repo: Repository) {
  deleteModalRepo.value = repo
}

function closeDeleteModal() {
  deleteModalRepo.value = null
  isDeletingRepo.value = false
}

async function confirmDeleteRepo() {
  if (!deleteModalRepo.value) return
  const repo = deleteModalRepo.value
  isDeletingRepo.value = true
  try {
    const res = await apiFetch<any>(`/api/repositories/${repo.id}`, {
      method: 'DELETE'
    })
    showFeedback('success', res?.message || `Repositori "${repo.name}" berhasil dihapus dari sistem.`)
    closeDeleteModal()
    await loadData()
  } catch (err: any) {
    console.error('Gagal menghapus repositori:', err)
    showFeedback('danger', err?.data?.message || err?.message || 'Gagal menghapus repositori dari sistem.')
  } finally {
    isDeletingRepo.value = false
  }
}

// Modal Scan SAST (seperti Scan Mandiri)
function openScanModal(repo: Repository) {
  scanModalRepo.value = repo
  scanSuccessJob.value = null
  scanError.value = null
}

function closeScanModal() {
  scanModalRepo.value = null
  scanSuccessJob.value = null
  scanError.value = null
}

async function executeScan() {
  if (!scanModalRepo.value || isScanning.value) return
  isScanning.value = true
  scanError.value = null
  try {
    const repo = scanModalRepo.value
    const res = await apiFetch(`/api/repositories/${repo.id}/scan`, {
      method: 'POST'
    })
    const job = res?.scan_job
    scanSuccessJob.value = job || { code: 'BARU' }
    showAlert(
      'success',
      `Pemindaian SAST untuk repositori "${repo.name}" berhasil didaftarkan (#${job?.code || 'Baru'}).`,
      job?.code
    )
  } catch (err: any) {
    console.error('Gagal memulai pemindaian repositori:', err)
    scanError.value = err?.data?.message || err?.message || 'Gagal memulai pemindaian repositori.'
  } finally {
    isScanning.value = false
  }
}

onMounted(() => {
  loadData()
})

watch(() => route.query.project_id, (newVal) => {
  if (newVal) {
    filterProject.value = String(newVal)
  }
})
</script>

<template>
  <div class="page-body mt-0">
    <div class="container-fluid">
      <!-- Floating Toast Notification (seperti Scan Mandiri) -->
      <transition name="toast-slide">
        <div
          v-if="alertMessage"
          class="toast-container position-fixed end-0 p-2 p-sm-3"
          style="top: 72px; z-index: 1070; max-width: min(480px, calc(100vw - 1.5rem));"
        >
          <div
            class="alert alert-dismissible shadow-lg border-0 d-flex align-items-start gap-2 mb-0 py-3"
            :class="`alert-${alertMessage.type}`"
            role="alert"
            style="backdrop-filter: blur(8px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4) !important;"
          >
            <div class="alert-icon pt-0">
              <svg
                v-if="alertMessage.type === 'success'"
                xmlns="http://www.w3.org/2000/svg"
                class="icon text-success"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M5 12l5 5l10 -10" />
              </svg>
              <svg
                v-else-if="alertMessage.type === 'danger'"
                xmlns="http://www.w3.org/2000/svg"
                class="icon text-danger"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M12 9v4" />
                <path d="M12 16h.01" />
              </svg>
              <svg
                v-else
                xmlns="http://www.w3.org/2000/svg"
                class="icon text-info"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                <path d="M12 8l.01 0" />
                <path d="M11 12l1 0l0 4l1 0" />
              </svg>
            </div>
            <div class="flex-grow-1 pe-2">
              <div class="fw-bold fs-5 mb-1">{{ alertMessage.type === 'success' ? 'Berhasil!' : (alertMessage.type === 'info' ? 'Informasi' : 'Terjadi Kesalahan') }}</div>
              <div class="text-secondary small lh-sm">{{ alertMessage.text }}</div>
              <div v-if="alertMessage.scanJobCode" class="mt-2">
                <router-link
                  to="/pekerjaan-scan"
                  class="btn btn-sm btn-success py-0 px-2 fw-bold text-decoration-none"
                  style="font-size: 0.75rem;"
                >
                  Lihat di Pekerjaan Scan &rarr;
                </router-link>
              </div>
            </div>
            <button type="button" class="btn-close" aria-label="Close" @click="alertMessage = null"></button>
          </div>
        </div>
      </transition>

      <!-- Page Header -->
      <div class="page-header d-print-none mb-3">
        <div class="row g-2 align-items-center">
          <div class="col-12 col-sm">
            <div class="page-pretitle text-secondary">
              Aset Statis & Analisis SAST
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <!-- Git icon -->
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
              <span>Repositori Kode Sumber</span>
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
              <span>Hubungkan Repositori</span>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 19c-4.3 1.4 -4.3 -2.5 -6 -3m12 5v-3.5c0 -1 .1 -1.4 -.5 -2c2.8 -.3 5.5 -1.4 5.5 -6a4.6 4.6 0 0 0 -1.3 -3.2a4.2 4.2 0 0 0 -.1 -3.2s-1.1 -.3 -3.5 1.3a12.3 12.3 0 0 0 -6.2 0c-2.4 -1.6 -3.5 -1.3 -3.5 -1.3a4.2 4.2 0 0 0 -.1 3.2a4.6 4.6 0 0 0 -1.3 3.2c0 4.6 2.7 5.7 5.5 6c-.6 .6 -.6 1.2 -.5 2v3.5" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.total }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Total Repositori</div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.synced }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Workspace Sync</div>
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
                  <span class="bg-purple-lt text-purple avatar avatar-sm avatar-sm-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2z" /><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.privateCount }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Repo Privat (PAT)</div>
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
                  <span class="bg-azure-lt text-azure avatar avatar-sm avatar-sm-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ projects.length }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Proyek Terafiliasi</div>
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
            <span>Daftar Repositori Kode Sumber</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{ filteredRepositories.length }}</span>
          </h3>

          <!-- Restyled Modern Filter & Search Controls (Sama seperti Proyek & Target) -->
          <div class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2">
            <!-- Filter Proyek -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M8 7v-2a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v2" /></svg>
              </span>
              <select
                v-model="filterProject"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterProject !== 'all' }"
                style="max-width: 180px;"
                title="Filter berdasarkan proyek"
              >
                <option value="all">Semua Proyek</option>
                <option v-for="proj in projects" :key="proj.id" :value="String(proj.id)">
                  {{ proj.name }}
                </option>
              </select>
            </div>

            <!-- Filter Provider -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-purple">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M9 8l0 8" /><path d="M11 18h6a2 2 0 0 0 2 -2v-5" /><path d="M16 13l3 -3l3 3" /></svg>
              </span>
              <select
                v-model="filterProvider"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterProvider !== 'all' }"
                title="Filter berdasarkan provider git"
              >
                <option value="all">Semua Provider</option>
                <option value="github">GitHub</option>
                <option value="gitlab">GitLab</option>
                <option value="bitbucket">Bitbucket</option>
                <option value="git">Git Server</option>
              </select>
            </div>

            <!-- Filter Workspace -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-teal">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M7 2v5" /><path d="M17 2v5" /><path d="M3 12h18" /><path d="M9 16l2 2l4 -4" /></svg>
              </span>
              <select
                v-model="filterWorkspace"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterWorkspace !== 'all' }"
                title="Filter berdasarkan status workspace lokal"
              >
                <option value="all">Semua Workspace</option>
                <option value="synced">Tersinkron (Cloned)</option>
                <option value="unsynced">Belum Ada Workspace</option>
              </select>
            </div>

            <!-- Search -->
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
                  placeholder="Cari repo / url / branch..."
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

            <!-- Reset Filter Button (Muncul jika ada filter dropdown aktif) -->
            <button
              v-if="filterProject !== 'all' || filterProvider !== 'all' || filterWorkspace !== 'all'"
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
                <th>Repositori & Provider</th>
                <th>Proyek Induk</th>
                <th>URL Clone Git</th>
                <th style="width: 130px;">Default Branch</th>
                <th style="width: 170px;">Status Workspace</th>
                <th class="text-center" style="width: 330px;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <!-- Loading -->
              <tr v-if="isLoading">
                <td colspan="7" class="text-center py-4 text-secondary">
                  <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                  Memuat data repositori...
                </td>
              </tr>

              <!-- Empty state -->
              <tr v-else-if="filteredRepositories.length === 0">
                <td colspan="7" class="text-center py-5">
                  <div class="empty">
                    <div class="empty-icon text-muted">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
                    </div>
                    <p class="empty-title">Tidak ada repositori yang ditemukan</p>
                    <p class="empty-subtitle text-secondary">
                      {{ searchQuery || filterProject !== 'all' ? 'Tidak ada repositori yang cocok dengan filter pencarian.' : 'Belum ada repositori kode yang dihubungkan ke TAMENG.' }}
                    </p>
                    <div v-if="canManage && !searchQuery" class="empty-action">
                      <button type="button" class="btn btn-primary" @click="openCreateModal">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                        Hubungkan Repositori Pertama
                      </button>
                    </div>
                  </div>
                </td>
              </tr>

              <!-- Rows -->
              <tr v-else v-for="(repo, idx) in filteredRepositories" :key="repo.id">
                <td class="col-index text-white small fw-bold">{{ idx + 1 }}</td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <span class="avatar avatar-sm bg-dark text-white rounded">
                      <!-- GitHub / GitLab / Git Icon -->
                      <svg v-if="repo.provider === 'github'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 19c-4.3 1.4 -4.3 -2.5 -6 -3m12 5v-3.5c0 -1 .1 -1.4 -.5 -2c2.8 -.3 5.5 -1.4 5.5 -6a4.6 4.6 0 0 0 -1.3 -3.2a4.2 4.2 0 0 0 -.1 -3.2s-1.1 -.3 -3.5 1.3a12.3 12.3 0 0 0 -6.2 0c-2.4 -1.6 -3.5 -1.3 -3.5 -1.3a4.2 4.2 0 0 0 -.1 3.2a4.6 4.6 0 0 0 -1.3 3.2c0 4.6 2.7 5.7 5.5 6c-.6 .6 -.6 1.2 -.5 2v3.5" /></svg>
                      <svg v-else-if="repo.provider === 'gitlab'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-warning" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M21 14l-9 7l-9 -7l3 -11l3 7h6l3 -7z" /></svg>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-azure" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
                    </span>
                    <div>
                      <div class="fw-bold text-reset d-flex align-items-center gap-1">
                        <span>{{ repo.name }}</span>
                        <span v-if="repo.metadata?.is_private || repo.metadata?.has_access_token" class="badge bg-purple-lt px-1 py-0" title="Private Repository">
                          <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2z" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
                        </span>
                      </div>
                      <div class="text-secondary small" style="font-size: 0.75rem;">
                        {{ repo.provider.toUpperCase() }}
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <span v-if="repo.project" class="badge bg-blue-lt text-blue font-monospace">
                    {{ repo.project.name }}
                  </span>
                  <span v-else class="text-muted small">-</span>
                </td>
                <td>
                  <div class="text-secondary small font-monospace text-truncate" style="max-width: 260px;" :title="repo.url">
                    {{ repo.url }}
                  </div>
                </td>
                <td>
                  <span class="badge bg-secondary-lt d-inline-flex align-items-center gap-1 font-monospace">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
                    <span>{{ repo.default_branch || 'main' }}</span>
                  </span>
                </td>
                <td>
                  <div v-if="repo.metadata?.local_path">
                    <span class="badge bg-success-lt d-inline-flex align-items-center gap-1 mb-1">
                      <span class="status-dot status-dot-animated bg-success"></span>
                      <span>Tersinkron di Worker</span>
                    </span>
                    <div class="text-muted small font-monospace text-truncate" style="font-size: 0.7rem; max-width: 180px;" :title="repo.metadata.local_path">
                      {{ repo.metadata.local_path }}
                    </div>
                  </div>
                  <div v-else>
                    <span class="badge bg-warning-lt d-inline-flex align-items-center gap-1">
                      <span class="status-dot bg-warning"></span>
                      <span>Belum di-clone</span>
                    </span>
                  </div>
                </td>
                <td class="text-center">
                  <div class="d-flex align-items-center justify-content-center gap-2">
                    <!-- Sync/Clone button -->
                    <button
                      type="button"
                      class="btn btn-sm d-flex align-items-center gap-1"
                      :class="repo.metadata?.local_path ? 'btn-secondary' : 'btn-success'"
                      :disabled="syncingRepoId === repo.id"
                      @click="syncWorkspace(repo)"
                      :title="repo.metadata?.local_path ? 'Tarik Kode Terbaru (Git Pull)' : 'Clone Repositori ke Worker'"
                    >
                      <span v-if="syncingRepoId === repo.id" class="spinner-border spinner-border-sm me-1" role="status"></span>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                      <span>{{ repo.metadata?.local_path ? 'Pull' : 'Clone' }}</span>
                    </button>

                    <!-- Trigger SAST Scan -->
                    <button
                      type="button"
                      class="btn btn-sm btn-primary d-flex align-items-center gap-1"
                      @click="openScanModal(repo)"
                      title="Pindai SAST (Semgrep, Gitleaks, Trivy)"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                      <span>Pindai</span>
                    </button>

                    <!-- Clear Workspace button if cloned -->
                    <button
                      v-if="repo.metadata?.local_path && canManage"
                      type="button"
                      class="btn btn-sm btn-outline-warning d-flex align-items-center gap-1"
                      :disabled="clearingRepoId === repo.id"
                      @click="clearWorkspace(repo)"
                      title="Bersihkan File Clone di Server Worker (Menghemat Disk)"
                    >
                      <span v-if="clearingRepoId === repo.id" class="spinner-border spinner-border-sm" role="status"></span>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M19 20h-10.5l-4.21 -4.3a1 1 0 0 1 0 -1.41l10 -10a1 1 0 0 1 1.41 0l5 5a1 1 0 0 1 0 1.41l-9.2 9.3" /><path d="M18 13.3l-6.3 -6.3" /></svg>
                      <span>Bersihkan File</span>
                    </button>

                    <!-- Edit button -->
                    <button
                      v-if="canManage"
                      type="button"
                      class="btn btn-sm btn-secondary d-flex align-items-center gap-1"
                      @click="openEditModal(repo)"
                      title="Ubah Konfigurasi Repositori"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" /><path d="M16 5l3 3" /></svg>
                      <span>Edit</span>
                    </button>

                    <!-- Delete button -->
                    <button
                      v-if="canManage"
                      type="button"
                      class="btn btn-sm btn-outline-danger d-flex align-items-center gap-1"
                      @click="openDeleteModal(repo)"
                      title="Hapus Repositori dari Sistem"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
                      <span>Hapus</span>
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
            Memuat data repositori...
          </div>

          <!-- Empty state -->
          <div v-else-if="filteredRepositories.length === 0" class="empty py-4">
            <div class="empty-icon text-muted">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
            </div>
            <p class="empty-title">Tidak ada repositori yang ditemukan</p>
            <p class="empty-subtitle text-secondary">
              {{ searchQuery || filterProject !== 'all' ? 'Tidak ada repositori yang cocok dengan filter pencarian.' : 'Belum ada repositori kode yang dihubungkan ke TAMENG.' }}
            </p>
            <div v-if="canManage && !searchQuery" class="empty-action">
              <button type="button" class="btn btn-primary btn-sm" @click="openCreateModal">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                Hubungkan Repositori
              </button>
            </div>
          </div>

          <!-- Cards List -->
          <div v-else class="d-flex flex-column gap-3">
            <div
              v-for="repo in filteredRepositories"
              :key="repo.id"
              class="card shadow-none border mb-0"
              style="border-radius: 12px; overflow: hidden;"
            >
              <div class="card-body p-3">
                <!-- Top Header: Name, Provider, Project Badge -->
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                  <div class="d-flex align-items-center gap-2 min-width-0">
                    <span class="avatar avatar-xs bg-dark text-white rounded flex-shrink-0">
                      <svg v-if="repo.provider === 'github'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 19c-4.3 1.4 -4.3 -2.5 -6 -3m12 5v-3.5c0 -1 .1 -1.4 -.5 -2c2.8 -.3 5.5 -1.4 5.5 -6a4.6 4.6 0 0 0 -1.3 -3.2a4.2 4.2 0 0 0 -.1 -3.2s-1.1 -.3 -3.5 1.3a12.3 12.3 0 0 0 -6.2 0c-2.4 -1.6 -3.5 -1.3 -3.5 -1.3a4.2 4.2 0 0 0 -.1 3.2a4.6 4.6 0 0 0 -1.3 3.2c0 4.6 2.7 5.7 5.5 6c-.6 .6 -.6 1.2 -.5 2v3.5" /></svg>
                      <svg v-else-if="repo.provider === 'gitlab'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-warning" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M21 14l-9 7l-9 -7l3 -11l3 7h6l3 -7z" /></svg>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-azure" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
                    </span>
                    <div class="min-width-0">
                      <div class="fw-bold text-reset fs-4 lh-1 d-flex align-items-center gap-1 text-truncate">
                        <span class="text-truncate">{{ repo.name }}</span>
                        <span v-if="repo.metadata?.is_private || repo.metadata?.has_access_token" class="badge bg-purple-lt px-1 py-0 flex-shrink-0" title="Private">
                          <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2z" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
                        </span>
                      </div>
                      <div class="text-secondary small mt-1" style="font-size: 0.72rem;">{{ repo.provider.toUpperCase() }}</div>
                    </div>
                  </div>
                  <span v-if="repo.project" class="badge bg-blue-lt text-blue font-monospace flex-shrink-0">
                    {{ repo.project.name }}
                  </span>
                </div>

                <!-- URL & Branch Box with Copy button -->
                <div class="bg-body-tertiary rounded p-2 mb-2 border" style="font-size: 0.78rem;">
                  <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                    <span class="text-secondary small fw-medium">Git URL:</span>
                    <div class="d-flex align-items-center gap-1">
                      <span class="badge bg-secondary-lt d-inline-flex align-items-center gap-1 font-monospace py-0 px-1">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
                        <span>{{ repo.default_branch || 'main' }}</span>
                      </span>
                      <button
                        type="button"
                        class="btn btn-sm btn-secondary p-0 px-2"
                        style="height: 22px; font-size: 0.7rem;"
                        @click="copyToClipboard(repo.url)"
                        title="Salin URL"
                      >
                        <span v-if="copiedUrl === repo.url" class="text-success fw-bold">Tersalin!</span>
                        <span v-else>Salin</span>
                      </button>
                    </div>
                  </div>
                  <div class="text-break font-monospace text-secondary" style="font-size: 0.73rem;">
                    {{ repo.url }}
                  </div>
                </div>

                <!-- Workspace Status -->
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <span class="text-secondary small">Status Workspace:</span>
                  <div v-if="repo.metadata?.local_path">
                    <span class="badge bg-success-lt d-inline-flex align-items-center gap-1">
                      <span class="status-dot status-dot-animated bg-success"></span>
                      <span>Tersinkron di Worker</span>
                    </span>
                  </div>
                  <div v-else>
                    <span class="badge bg-warning-lt d-inline-flex align-items-center gap-1">
                      <span class="status-dot bg-warning"></span>
                      <span>Belum di-clone</span>
                    </span>
                  </div>
                </div>

                <!-- Actions Button Grid (Thumb Friendly on Mobile) -->
                <div class="row g-2">
                  <div class="col-6">
                    <button
                      type="button"
                      class="btn btn-sm w-100 d-flex align-items-center justify-content-center gap-1"
                      :class="repo.metadata?.local_path ? 'btn-secondary' : 'btn-success'"
                      :disabled="syncingRepoId === repo.id"
                      @click="syncWorkspace(repo)"
                    >
                      <span v-if="syncingRepoId === repo.id" class="spinner-border spinner-border-sm" role="status"></span>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
                      <span>{{ repo.metadata?.local_path ? 'Pull' : 'Clone' }}</span>
                    </button>
                  </div>
                  <div class="col-6">
                    <button
                      type="button"
                      class="btn btn-sm btn-primary w-100 d-flex align-items-center justify-content-center gap-1"
                      @click="openScanModal(repo)"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                      <span>Pindai</span>
                    </button>
                  </div>
                  <div v-if="repo.metadata?.local_path && canManage" class="col-12">
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-warning w-100 d-flex align-items-center justify-content-center gap-1"
                      :disabled="clearingRepoId === repo.id"
                      @click="clearWorkspace(repo)"
                      title="Bersihkan File Clone di Server Worker"
                    >
                      <span v-if="clearingRepoId === repo.id" class="spinner-border spinner-border-sm" role="status"></span>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M19 20h-10.5l-4.21 -4.3a1 1 0 0 1 0 -1.41l10 -10a1 1 0 0 1 1.41 0l5 5a1 1 0 0 1 0 1.41l-9.2 9.3" /><path d="M18 13.3l-6.3 -6.3" /></svg>
                      <span>Bersihkan File Server</span>
                    </button>
                  </div>
                  <div v-if="canManage" class="col-6">
                    <button
                      type="button"
                      class="btn btn-sm btn-secondary w-100 d-flex align-items-center justify-content-center gap-1"
                      @click="openEditModal(repo)"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" /><path d="M16 5l3 3" /></svg>
                      <span>Edit</span>
                    </button>
                  </div>
                  <div v-if="canManage" class="col-6">
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-danger w-100 d-flex align-items-center justify-content-center gap-1"
                      @click="openDeleteModal(repo)"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
                      <span>Hapus</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Form Hubungkan / Edit Repositori -->
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
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-white" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 18m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M17 6m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
              <span>{{ editingRepoId ? 'Ubah Repositori Kode' : 'Hubungkan Repositori Baru' }}</span>
            </h5>
            <button type="button" class="btn-close btn-close-white" aria-label="Close" @click="closeModal"></button>
          </div>

          <form @submit.prevent="saveRepository">
            <div class="modal-body p-3">
              <!-- Error alert -->
              <div v-if="formError" class="alert alert-danger py-2 px-3 small mb-3">
                {{ formError }}
              </div>

              <!-- Project Selection -->
              <div class="mb-3">
                <label class="form-label required small fw-bold">Proyek Induk</label>
                <select v-model="repoForm.project_id" class="form-select" required>
                  <option value="" disabled>-- Pilih Proyek Terkait --</option>
                  <option v-for="proj in projects" :key="proj.id" :value="proj.id">
                    {{ proj.name }} ({{ proj.code }})
                  </option>
                </select>
              </div>

              <!-- Provider & Name -->
              <div class="row g-2 mb-3">
                <div class="col-sm-5">
                  <label class="form-label required small fw-bold">Penyedia Git</label>
                  <select v-model="repoForm.provider" class="form-select">
                    <option value="github">GitHub</option>
                    <option value="gitlab">GitLab</option>
                    <option value="bitbucket">Bitbucket</option>
                    <option value="git">Git Server (Custom)</option>
                  </select>
                </div>
                <div class="col-sm-7">
                  <label class="form-label required small fw-bold">Nama Repositori</label>
                  <input
                    v-model="repoForm.name"
                    type="text"
                    class="form-control"
                    placeholder="Contoh: backend-service"
                    required
                  />
                </div>
              </div>

              <!-- Git URL -->
              <div class="mb-3">
                <label class="form-label required small fw-bold">URL Clone Git (HTTPS)</label>
                <input
                  v-model="repoForm.url"
                  type="url"
                  class="form-control font-monospace"
                  placeholder="https://github.com/organization/repo.git"
                  required
                  @input="onUrlInput"
                />
              </div>

              <!-- Default branch -->
              <div class="mb-3">
                <label class="form-label required small fw-bold">Default Branch</label>
                <input
                  v-model="repoForm.default_branch"
                  type="text"
                  class="form-control font-monospace"
                  placeholder="main"
                  required
                />
              </div>

              <!-- Private Repo Switch -->
              <div class="mb-3">
                <label class="form-check form-switch mb-1">
                  <input v-model="repoForm.is_private" class="form-check-input" type="checkbox" />
                  <span class="form-check-label fw-medium small">Repositori Privat (Membutuhkan Token Akses)</span>
                </label>
                <div class="text-muted small" style="font-size: 0.75rem;">
                  Aktifkan jika repositori tidak dapat diakses publik dan membutuhkan Personal Access Token (PAT).
                </div>
              </div>

              <!-- Access token (if private) -->
              <div v-if="repoForm.is_private" class="mb-2 p-3 bg-body-tertiary border rounded">
                <label class="form-label small fw-bold d-flex align-items-center justify-content-between">
                  <span>Personal Access Token (PAT)</span>
                  <button type="button" class="btn btn-xs btn-secondary py-0 px-2" @click="showPassword = !showPassword">
                    {{ showPassword ? 'Sembunyikan' : 'Tampilkan' }}
                  </button>
                </label>
                <input
                  v-model="repoForm.access_token"
                  :type="showPassword ? 'text' : 'password'"
                  class="form-control font-monospace form-control-sm"
                  :placeholder="editingRepoId ? 'Kosongkan jika tidak ingin mengubah token' : 'ghp_xxxxxxxxxxxxxxxxxxxx'"
                />
                <div class="form-text small" style="font-size: 0.72rem;">
                  Token akan disimpan dengan enkripsi standar AES-256 pada server TAMENG.
                </div>
              </div>
            </div>

            <div class="modal-footer bg-body-tertiary py-2 d-flex flex-column-reverse flex-sm-row align-items-stretch align-items-sm-center justify-content-sm-between gap-2">
              <button type="button" class="btn btn-secondary w-100 w-sm-auto justify-content-center" @click="closeModal" :disabled="isSaving">
                Batal
              </button>
              <button type="submit" class="btn btn-primary d-flex align-items-center justify-content-center gap-1 w-100 w-sm-auto py-2" :disabled="isSaving">
                <span v-if="isSaving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <span>{{ editingRepoId ? 'Simpan Perubahan' : 'Hubungkan' }}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL KONFIRMASI PEMINDAIAN SAST (SEPERTI SCAN MANDIRI)   -->
    <!-- ========================================================= -->
    <div
      v-if="scanModalRepo"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.65); z-index: 1060;"
      @click.self="closeScanModal"
    >
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0">
          <button type="button" class="btn-close" aria-label="Close" @click="closeScanModal"></button>
          
          <!-- STATUS BAR -->
          <div class="modal-status" :class="scanSuccessJob ? 'bg-success' : 'bg-primary'"></div>

          <!-- BODY SEBELUM SUKSES -->
          <div v-if="!scanSuccessJob" class="modal-body text-center py-4">
            <div class="avatar avatar-lg bg-primary-lt rounded-circle mx-auto mb-3 shadow-sm">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-primary" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 8l-4 4l4 4" /><path d="M17 8l4 4l-4 4" /><path d="M14 4l-4 16" /></svg>
            </div>
            <h3 class="modal-title mb-1">Mulai Pemindaian SAST?</h3>
            <div class="text-secondary small mb-3">
              Pekerjaan akan didaftarkan ke antrean sandbox dengan profil analisis keamanan kode sumber (SAST).
            </div>

            <div class="card card-sm bg-body text-start border mb-0">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Repositori:</span>
                  <span class="fw-bold small text-reset text-truncate" style="max-width: 160px;" :title="scanModalRepo.name">{{ scanModalRepo.name }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Provider:</span>
                  <span class="badge bg-purple-lt px-1 py-0 font-monospace" style="font-size: 0.7rem;">{{ scanModalRepo.provider?.toUpperCase() || 'GIT' }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Proyek:</span>
                  <span class="fw-medium small text-truncate" style="max-width: 160px;">{{ scanModalRepo.project?.name || '-' }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Git URL:</span>
                  <span class="font-monospace small text-truncate text-secondary" style="max-width: 160px;" :title="scanModalRepo.url">{{ scanModalRepo.url || '-' }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-secondary small">Engine SAST:</span>
                  <span class="small text-primary fw-medium">Semgrep, Gitleaks, Trivy</span>
                </div>
              </div>
            </div>

            <div v-if="scanError" class="alert alert-danger py-1 px-2 mt-3 mb-0 small">
              {{ scanError }}
            </div>
          </div>

          <!-- BODY SETELAH SUKSES -->
          <div v-else class="modal-body text-center py-4">
            <div class="avatar avatar-lg bg-success-lt rounded-circle mx-auto mb-3 shadow-sm">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-success" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
            </div>
            <h3 class="modal-title mb-1 text-success">Pemindaian Berhasil Dimulai!</h3>
            <div class="text-secondary small mb-3">
              Permintaan scan repositori telah diterima dan sedang diproses oleh engine di latar belakang.
            </div>

            <div class="card card-sm bg-body text-start border mb-0">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Kode Scan:</span>
                  <span class="font-monospace fw-bold small text-primary">#{{ scanSuccessJob.code }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Repositori:</span>
                  <span class="fw-medium small text-truncate" style="max-width: 160px;">{{ scanModalRepo.name }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-secondary small">Status:</span>
                  <span class="badge bg-info-lt d-inline-flex align-items-center gap-1">
                    <span class="status-dot status-dot-animated bg-info"></span>
                    <span>Antrean Sandbox</span>
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- FOOTER -->
          <div class="modal-footer bg-transparent border-top-0 pt-0">
            <div class="w-100">
              <!-- FOOTER SEBELUM SUKSES -->
              <div v-if="!scanSuccessJob" class="row g-2">
                <div class="col-6">
                  <button
                    type="button"
                    class="btn btn-secondary w-100"
                    :disabled="isScanning"
                    @click="closeScanModal"
                  >
                    Batal
                  </button>
                </div>
                <div class="col-6">
                  <button
                    type="button"
                    class="btn btn-primary w-100 d-flex align-items-center justify-content-center gap-1"
                    :disabled="isScanning"
                    @click="executeScan"
                  >
                    <span v-if="isScanning" class="spinner-border spinner-border-sm me-1" role="status"></span>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                    <span>{{ isScanning ? 'Memproses...' : 'Mulai Pindai' }}</span>
                  </button>
                </div>
              </div>

              <!-- FOOTER SETELAH SUKSES -->
              <div v-else class="row g-2">
                <div class="col-6">
                  <button
                    type="button"
                    class="btn btn-secondary w-100"
                    @click="closeScanModal"
                  >
                    Tutup
                  </button>
                </div>
                <div class="col-6">
                  <router-link
                    to="/pekerjaan-scan"
                    class="btn btn-success w-100 d-flex align-items-center justify-content-center gap-1"
                  >
                    <span>Lihat Scan &rarr;</span>
                  </router-link>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL KONFIRMASI BERSIHKAN FILE WORKSPACE -->
    <div
      v-if="cleanModalRepo"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.65); z-index: 1060;"
      @click.self="closeCleanModal"
    >
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0">
          <button type="button" class="btn-close" aria-label="Close" @click="closeCleanModal"></button>
          
          <!-- Status Warning Bar -->
          <div class="modal-status bg-warning"></div>

          <div class="modal-body text-center py-4">
            <div class="avatar avatar-lg bg-warning-lt rounded-circle mx-auto mb-3 shadow-sm">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-warning" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M19 20h-10.5l-4.21 -4.3a1 1 0 0 1 0 -1.41l10 -10a1 1 0 0 1 1.41 0l5 5a1 1 0 0 1 0 1.41l-9.2 9.3" />
                <path d="M18 13.3l-6.3 -6.3" />
              </svg>
            </div>
            <h3 class="modal-title mb-1 text-body fw-bold">Bersihkan File di Server?</h3>
            <div class="text-secondary small mb-3">
              File hasil clone repositori <strong>{{ cleanModalRepo.name }}</strong> di disk server worker akan dibersihkan untuk menghemat ruang penyimpanan.
            </div>

            <div class="card card-sm bg-body text-start border p-3 mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1 pb-1 border-bottom">
                <span class="text-secondary small">Repositori:</span>
                <span class="fw-bold small text-truncate" style="max-width: 150px;">{{ cleanModalRepo.name }}</span>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-secondary small">Proyek:</span>
                <span class="small text-truncate" style="max-width: 150px;">{{ cleanModalRepo.project?.name || '-' }}</span>
              </div>
            </div>

            <div class="alert alert-info py-2 px-3 small text-start mb-0">
              <div class="d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm text-info flex-shrink-0" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><circle cx="12" cy="12" r="9" /><line x1="12" y1="8" x2="12.01" y2="8" /><polyline points="11 12 12 12 12 16 13 16" /></svg>
                <span><strong>Catatan:</strong> Repositori tidak terhapus dari sistem Tameng. Anda dapat melakukan Clone/Pull kembali kapan saja.</span>
              </div>
            </div>
          </div>

          <div class="modal-footer py-2 d-flex align-items-center justify-content-between">
            <button type="button" class="btn btn-secondary" :disabled="isClearingWorkspace" @click="closeCleanModal">
              Batal
            </button>
            <button
              type="button"
              class="btn btn-warning d-flex align-items-center gap-1"
              :disabled="isClearingWorkspace"
              @click="confirmClearWorkspace"
            >
              <span v-if="isClearingWorkspace" class="spinner-border spinner-border-sm me-1" role="status"></span>
              <span>Bersihkan File Server</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- MODAL KONFIRMASI HAPUS REPOSITORI (PERMANENT) -->
    <div
      v-if="deleteModalRepo"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.65); z-index: 1060;"
      @click.self="closeDeleteModal"
    >
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0">
          <button type="button" class="btn-close" aria-label="Close" @click="closeDeleteModal"></button>
          
          <!-- Status Danger Bar -->
          <div class="modal-status bg-danger"></div>

          <div class="modal-body text-center py-4">
            <div class="avatar avatar-lg bg-danger-lt rounded-circle mx-auto mb-3 shadow-sm">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-danger" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M4 7l16 0" />
                <path d="M10 11l0 6" />
                <path d="M14 11l0 6" />
                <path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" />
                <path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" />
              </svg>
            </div>
            <h3 class="modal-title mb-1 text-body fw-bold">Hapus Repositori?</h3>
            <div class="text-secondary small mb-3">
              Apakah Anda yakin ingin menghapus repositori <strong>{{ deleteModalRepo.name }}</strong> secara permanen dari sistem Tameng?
            </div>

            <div class="card card-sm bg-body text-start border p-3 mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1 pb-1 border-bottom">
                <span class="text-secondary small">Repositori:</span>
                <span class="fw-bold small text-truncate" style="max-width: 150px;">{{ deleteModalRepo.name }}</span>
              </div>
              <div class="d-flex justify-content-between align-items-center mb-1 pb-1 border-bottom">
                <span class="text-secondary small">Proyek:</span>
                <span class="small text-truncate" style="max-width: 150px;">{{ deleteModalRepo.project?.name || '-' }}</span>
              </div>
              <div class="d-flex justify-content-between align-items-center">
                <span class="text-secondary small">Tipe:</span>
                <span class="badge bg-blue-lt text-uppercase">{{ deleteModalRepo.repository_type || 'git' }}</span>
              </div>
            </div>

            <div class="alert alert-danger py-2 px-3 small text-start mb-0">
              <div class="d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm text-danger flex-shrink-0" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>
                <span>Tindakan ini permanen. File clone di server dan riwayat verifikasi repositori ini akan ikut dihapus.</span>
              </div>
            </div>
          </div>

          <div class="modal-footer py-2 d-flex align-items-center justify-content-between">
            <button type="button" class="btn btn-secondary" :disabled="isDeletingRepo" @click="closeDeleteModal">
              Batal
            </button>
            <button
              type="button"
              class="btn btn-danger d-flex align-items-center gap-1"
              :disabled="isDeletingRepo"
              @click="confirmDeleteRepo"
            >
              <span v-if="isDeletingRepo" class="spinner-border spinner-border-sm me-1" role="status"></span>
              <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
              <span>Hapus Permanen</span>
            </button>
          </div>
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

/* Transitions Toast */
.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
}

.toast-slide-enter-from {
  opacity: 0;
  transform: translateX(40px) scale(0.96);
}

.toast-slide-leave-to {
  opacity: 0;
  transform: translateX(40px) scale(0.96);
}

/* Modern Filter & Search Controls (Identik dengan Proyek & Target) */
.filter-toolbar-group {
  gap: 0.5rem;
}

.filter-select-wrapper {
  display: inline-flex;
  align-items: center;
  position: relative;
}

.select-prefix-icon {
  position: absolute;
  left: 11px;
  top: 50%;
  transform: translateY(-50%);
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
    flex: 1 1 calc(33.333% - 0.5rem);
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
    flex: 1 1 calc(50% - 0.25rem);
    min-width: 130px;
  }
  .custom-filter-select {
    height: 36px;
    font-size: 0.78rem;
    padding-left: 30px;
    padding-right: 28px;
    background-position: right 8px center;
  }
  .select-prefix-icon {
    left: 8px;
  }
  .search-box-wrapper {
    flex: 1 1 100%;
  }
  .filter-reset-btn {
    flex: 1 1 100%;
    justify-content: center;
  }
}

@media (max-width: 575.98px) {
  .filter-select-wrapper {
    flex: 1 1 100%;
  }
}
</style>
