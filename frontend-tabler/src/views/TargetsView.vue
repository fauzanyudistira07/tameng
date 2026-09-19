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

interface Target {
  id: number
  project_id: number
  project?: ProjectRef
  type: 'web' | 'api' | 'container' | string
  name: string
  base_url?: string
  hostname?: string
  verification_status?: 'verified' | 'pending' | 'rejected'
  verified_at?: string
  verified_by?: number
  verifier?: { id: number; name: string }
  created_at?: string
  updated_at?: string
}

const targets = ref<Target[]>([])
const projects = ref<ProjectRef[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const verifyingTargetId = ref<number | null>(null)
const scanningTargetId = ref<number | null>(null)
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

function getTargetTypeBadge(type?: string) {
  if (type === 'api') return { label: 'REST/API', class: 'bg-purple-lt text-purple' }
  if (type === 'container') return { label: 'CONTAINER', class: 'bg-teal-lt text-teal' }
  if (type === 'mobile' || type === 'app') return { label: 'APLIKASI', class: 'bg-green-lt text-green' }
  if (type === 'web') return { label: 'WEB APP', class: 'bg-azure-lt text-azure' }
  return { label: (type || 'ASSET').toUpperCase(), class: 'bg-secondary-lt text-secondary' }
}

function showFeedback(type: 'success' | 'danger' | 'info', text: string, scanJobCode?: string) {
  showAlert(type, text, scanJobCode)
}

// Salin URL ke clipboard
const copiedUrl = ref<string | null>(null)
function copyToClipboard(text?: string) {
  if (!text) return
  navigator.clipboard.writeText(text).then(() => {
    copiedUrl.value = text
    setTimeout(() => {
      if (copiedUrl.value === text) {
        copiedUrl.value = null
      }
    }, 2500)
  })
}

// Modal Konfirmasi & Sukses Scan (mirip Scan Mandiri)
const scanModalTarget = ref<Target | null>(null)
const scanSuccessJob = ref<any | null>(null)
const isScanning = ref(false)
const scanError = ref<string | null>(null)

// Filters
const searchQuery = ref('')
const filterProject = ref<string>('all')
const filterType = ref<string>('all')
const filterVerification = ref<string>('all')

function resetFilters() {
  filterProject.value = 'all'
  filterType.value = 'all'
  filterVerification.value = 'all'
  searchQuery.value = ''
}

// Modal state
const isModalOpen = ref(false)
const editingTargetId = ref<number | null>(null)
const formError = ref<string | null>(null)

const targetForm = reactive({
  project_id: '' as string | number,
  type: 'web' as 'web' | 'api' | 'container' | 'mobile',
  name: '',
  base_url: '',
  hostname: ''
})

// Mobile File Upload state
const mobileFile = ref<File | null>(null)
const isDragging = ref(false)
const fileInputRef = ref<HTMLInputElement | null>(null)
const useManualIdentifier = ref(false)

function handleFileInput(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (files && files[0]) {
    setMobileFile(files[0])
  }
}

function handleFileDrop(e: DragEvent) {
  isDragging.value = false
  const files = e.dataTransfer?.files
  if (files && files[0]) {
    setMobileFile(files[0])
  }
}

function setMobileFile(file: File) {
  mobileFile.value = file
  if (!targetForm.name || !editingTargetId.value) {
    targetForm.name = file.name.replace(/\.[^/.]+$/, '')
  }
  targetForm.hostname = file.name
  targetForm.base_url = `file://${file.name}`
}

function removeMobileFile() {
  mobileFile.value = null
  if (fileInputRef.value) fileInputRef.value.value = ''
  if (targetForm.base_url.startsWith('file://')) {
    targetForm.base_url = ''
    targetForm.hostname = ''
  }
}

// Detail Modal state
const isDetailModalOpen = ref(false)
const isLoadingDetail = ref(false)
const targetDetail = ref<any | null>(null)
const activeDetailTab = ref<'scans' | 'findings' | 'scopes'>('scans')

// Delete Modal state
const isDeleteModalOpen = ref(false)
const deletingTarget = ref<Target | null>(null)
const isDeleting = ref(false)

const canManage = computed(() => {
  const role = currentUser.value?.role?.name || ''
  return ['super_admin', 'security_admin'].includes(role)
})

// Auto-extract hostname when URL is entered
function onBaseUrlInput() {
  if (targetForm.type === 'container' || targetForm.type === 'mobile') {
    if (!targetForm.name && !editingTargetId.value && targetForm.base_url) {
      targetForm.name = targetForm.base_url
    }
    targetForm.hostname = targetForm.base_url
    return
  }
  try {
    if (targetForm.base_url.includes('://')) {
      const parsed = new URL(targetForm.base_url)
      targetForm.hostname = parsed.hostname
      if (!targetForm.name && !editingTargetId.value) {
        targetForm.name = parsed.hostname
      }
    }
  } catch {}
}

async function openDetailModal(target: Target) {
  isDetailModalOpen.value = true
  isLoadingDetail.value = true
  activeDetailTab.value = 'scans'
  targetDetail.value = null
  try {
    const res = await apiFetch(`/api/targets/${target.id}`)
    targetDetail.value = res
  } catch (err: any) {
    console.error('Gagal memuat detail target:', err)
    targetDetail.value = { target, scan_jobs: [], scopes: [], findings_summary: { total: 0, critical: 0, high: 0, medium: 0, low: 0, recent: [] } }
  } finally {
    isLoadingDetail.value = false
  }
}

function closeDetailModal() {
  isDetailModalOpen.value = false
  targetDetail.value = null
}

function openDeleteModal(target: Target) {
  deletingTarget.value = target
  isDeleteModalOpen.value = true
}

function closeDeleteModal() {
  deletingTarget.value = null
  isDeleteModalOpen.value = false
}

async function confirmDelete() {
  if (!deletingTarget.value) return
  isDeleting.value = true
  try {
    await apiFetch(`/api/targets/${deletingTarget.value.id}`, {
      method: 'DELETE'
    })
    showFeedback('success', `Target "${deletingTarget.value.name}" berhasil dihapus.`)
    closeDeleteModal()
    if (isDetailModalOpen.value && targetDetail.value?.target?.id === deletingTarget.value?.id) {
      closeDetailModal()
    }
    await loadData()
  } catch (err: any) {
    console.error('Gagal menghapus target:', err)
    showFeedback('danger', err?.data?.message || err?.message || 'Gagal menghapus target.')
  } finally {
    isDeleting.value = false
  }
}

async function loadData() {
  isLoading.value = true
  try {
    const [targetRes, projRes] = await Promise.all([
      apiFetch('/api/targets'),
      apiFetch('/api/projects')
    ])
    targets.value = Array.isArray(targetRes?.targets) ? targetRes.targets : (Array.isArray(targetRes) ? targetRes : [])
    projects.value = Array.isArray(projRes?.projects) ? projRes.projects : (Array.isArray(projRes) ? projRes : [])

    if (route.query.project_id) {
      filterProject.value = String(route.query.project_id)
    }
  } catch (err: any) {
    console.error('Gagal memuat target:', err)
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  const list = targets.value
  const total = list.length
  const webCount = list.filter(t => t.type === 'web').length
  const apiCount = list.filter(t => t.type === 'api').length
  const containerCount = list.filter(t => t.type === 'container').length
  const mobileCount = list.filter(t => t.type === 'mobile' || t.type === 'app').length
  const verified = list.filter(t => t.verification_status === 'verified').length
  return { total, webCount, apiCount, containerCount, mobileCount, verified }
})

const filteredTargets = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  const list = targets.value.filter(t => {
    // Project filter
    if (filterProject.value !== 'all' && String(t.project_id) !== filterProject.value) {
      return false
    }
    // Type filter
    if (filterType.value !== 'all' && t.type !== filterType.value) {
      return false
    }
    // Verification filter
    if (filterVerification.value !== 'all') {
      const isVer = t.verification_status === 'verified'
      if (filterVerification.value === 'verified' && !isVer) return false
      if (filterVerification.value === 'pending' && isVer) return false
    }

    // Search query
    if (!query) return true
    const nameMatch = (t.name || '').toLowerCase().includes(query)
    const urlMatch = (t.base_url || '').toLowerCase().includes(query)
    const hostMatch = (t.hostname || '').toLowerCase().includes(query)
    const projName = (t.project?.name || '').toLowerCase().includes(query)
    const projCode = (t.project?.code || '').toLowerCase().includes(query)
    return nameMatch || urlMatch || hostMatch || projName || projCode
  })

  return list.sort((a, b) => {
    const timeA = a.created_at ? new Date(a.created_at).getTime() : a.id
    const timeB = b.created_at ? new Date(b.created_at).getTime() : b.id
    return timeB - timeA
  })
})

function openCreateModal() {
  editingTargetId.value = null
  targetForm.project_id = (filterProject.value !== 'all' ? filterProject.value : (projects.value[0]?.id || ''))
  targetForm.type = 'web'
  targetForm.name = ''
  targetForm.base_url = ''
  targetForm.hostname = ''
  mobileFile.value = null
  useManualIdentifier.value = false
  formError.value = null
  isModalOpen.value = true
}

function openEditModal(target: Target) {
  editingTargetId.value = target.id
  targetForm.project_id = target.project_id
  targetForm.type = target.type || 'web'
  targetForm.name = target.name
  targetForm.base_url = target.base_url || ''
  targetForm.hostname = target.hostname || ''
  mobileFile.value = null
  useManualIdentifier.value = !!target.base_url && !target.base_url.startsWith('file://')
  formError.value = null
  isModalOpen.value = true
}

function closeModal() {
  isModalOpen.value = false
  editingTargetId.value = null
  mobileFile.value = null
  useManualIdentifier.value = false
  formError.value = null
}

async function saveTarget() {
  if (!targetForm.project_id) {
    formError.value = 'Proyek induk wajib dipilih.'
    return
  }

  if (targetForm.type === 'mobile') {
    if (!mobileFile.value && !targetForm.base_url.trim() && !targetForm.hostname.trim()) {
      formError.value = 'Silakan unggah berkas binary aplikasi (.apk, .ipa, .aab, .zip) atau masukkan identifier/tautan aplikasi.'
      return
    }
  } else {
    if (!targetForm.name.trim()) {
      formError.value = 'Nama target wajib diisi.'
      return
    }
    if (!targetForm.base_url.trim() && !targetForm.hostname.trim()) {
      formError.value = targetForm.type === 'container'
        ? 'Container Image URI wajib diisi.'
        : 'Base URL atau Hostname wajib diisi.'
      return
    }
  }

  isSaving.value = true
  formError.value = null

  try {
    if (targetForm.type === 'mobile' && mobileFile.value) {
      const formData = new FormData()
      formData.append('project_id', String(targetForm.project_id))
      formData.append('type', targetForm.type)
      formData.append('name', targetForm.name.trim() || mobileFile.value.name)
      formData.append('file', mobileFile.value)
      if (targetForm.hostname.trim()) formData.append('hostname', targetForm.hostname.trim())
      if (targetForm.base_url.trim()) formData.append('base_url', targetForm.base_url.trim())

      if (editingTargetId.value) {
        await apiFetch(`/api/targets/${editingTargetId.value}`, {
          method: 'POST',
          body: formData
        })
        showFeedback('success', `Target "${targetForm.name || mobileFile.value.name}" berhasil diperbarui.`)
      } else {
        await apiFetch('/api/targets', {
          method: 'POST',
          body: formData
        })
        showFeedback('success', `Target aplikasi "${targetForm.name || mobileFile.value.name}" berhasil didaftarkan.`)
      }
    } else {
      const payload = {
        project_id: Number(targetForm.project_id),
        type: targetForm.type,
        name: targetForm.name.trim(),
        base_url: targetForm.base_url.trim() || null,
        hostname: targetForm.hostname.trim() || null
      }

      if (editingTargetId.value) {
        await apiFetch(`/api/targets/${editingTargetId.value}`, {
          method: 'PUT',
          body: JSON.stringify(payload)
        })
        showFeedback('success', `Target "${payload.name}" berhasil diperbarui.`)
      } else {
        await apiFetch('/api/targets', {
          method: 'POST',
          body: JSON.stringify(payload)
        })
        showFeedback('success', `Target baru "${payload.name}" berhasil didaftarkan.`)
      }
    }

    await loadData()
    closeModal()
  } catch (err: any) {
    console.error('Gagal menyimpan target:', err)
    formError.value = err?.data?.message || err?.message || 'Gagal menyimpan target. Pastikan data valid.'
  } finally {
    isSaving.value = false
  }
}

// Verify domain ownership
async function verifyTargetOwnership(target: Target) {
  verifyingTargetId.value = target.id
  try {
    await apiFetch(`/api/targets/${target.id}/verify`, {
      method: 'POST',
      body: JSON.stringify({
        verification_status: 'verified'
      })
    })
    showFeedback('success', `Kepemilikan domain target "${target.name}" berhasil diverifikasi!`)
    await loadData()
  } catch (err: any) {
    console.error('Gagal memverifikasi target:', err)
    showFeedback('danger', err?.data?.message || err?.message || 'Gagal memverifikasi kepemilikan target.')
  } finally {
    verifyingTargetId.value = null
  }
}

// Modal Scan DAST (seperti Scan Mandiri)
function openScanModal(target: Target) {
  scanModalTarget.value = target
  scanSuccessJob.value = null
  scanError.value = null
}

function closeScanModal() {
  scanModalTarget.value = null
  scanSuccessJob.value = null
  scanError.value = null
}

async function executeScan() {
  if (!scanModalTarget.value || isScanning.value) return
  isScanning.value = true
  scanError.value = null
  try {
    const target = scanModalTarget.value
    const res = await apiFetch(`/api/targets/${target.id}/scan`, {
      method: 'POST'
    })
    const job = res?.scan_job
    scanSuccessJob.value = job || { code: 'BARU' }
    const scanLabel = target.type === 'mobile' ? 'Keamanan Aplikasi (MobSF)' : target.type === 'container' ? 'Container' : 'DAST'
    showFeedback(
      'success',
      `Pemindaian ${scanLabel} untuk target "${target.name}" berhasil dimulai (#${job?.code || 'Baru'})!`,
      job?.code
    )
  } catch (err: any) {
    console.error('Gagal memulai pemindaian target:', err)
    scanError.value = err?.data?.message || err?.message || 'Gagal memulai pemindaian target.'
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
          class="toast-container position-fixed end-0 p-3"
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
              <div class="fw-bold fs-5 mb-1">{{ alertMessage.type === 'success' ? 'Berhasil!' : 'Terjadi Kesalahan' }}</div>
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
              Aset Dinamis & Pengujian DAST
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /><path d="M11.5 3a17 17 0 0 0 0 18" /><path d="M12.5 3a17 17 0 0 1 0 18" /></svg>
              <span>Target Web, API Endpoint, & Aplikasi</span>
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
              <span>Daftarkan Target Baru</span>
            </button>
          </div>
        </div>
      </div>

      <!-- KPI Metric Cards (Grid 2x2 pada Mobile, 4 Kolom pada Tablet/Desktop) -->
      <div class="row row-cards mb-3">
        <div class="col-6 col-md-3">
          <div class="card card-sm">
            <div class="card-body p-2 p-sm-3">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-primary-lt text-primary avatar avatar-sm avatar-sm-md">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /><path d="M11.5 3a17 17 0 0 0 0 18" /><path d="M12.5 3a17 17 0 0 1 0 18" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.total }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Total Target URL</div>
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
                    <!-- Flutter / Web App icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-brand-flutter"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M7 14l-3 -3l8 -8h6l-11 11" /><path d="M14 21l-5 -5l5 -5h5l-5 5l5 5l-5 0" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.webCount }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Web Applications</div>
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
                    <!-- Code/API icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 8l-4 4l4 4" /><path d="M17 8l4 4l-4 4" /><path d="M14 4l-4 16" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.apiCount }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">REST / GraphQL APIs</div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M9 12l2 2l4 -4" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-3 fs-sm-2">{{ stats.verified }}</div>
                  <div class="text-secondary small text-truncate" style="font-size: 0.72rem;">Terverifikasi Milik</div>
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
            <span>Daftar Target Web, API, & Aplikasi</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{ filteredTargets.length }}</span>
          </h3>

          <!-- Restyled Modern Filter & Search Controls -->
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

            <!-- Filter Tipe -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-azure">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /></svg>
              </span>
              <select
                v-model="filterType"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterType !== 'all' }"
                title="Filter berdasarkan tipe target"
              >
                <option value="all">Semua Tipe</option>
                <option value="web">Web Application</option>
                <option value="api">REST / API</option>
                <option value="container">Container Image</option>
                <option value="mobile">Aplikasi Mobile</option>
              </select>
            </div>

            <!-- Filter Verifikasi -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-success">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M9 12l2 2l4 -4" /></svg>
              </span>
              <select
                v-model="filterVerification"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterVerification !== 'all' }"
                title="Filter berdasarkan status verifikasi"
              >
                <option value="all">Semua Verifikasi</option>
                <option value="verified">Terverifikasi</option>
                <option value="pending">Menunggu Verifikasi</option>
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
                  placeholder="Cari target / url / host..."
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
              v-if="filterProject !== 'all' || filterType !== 'all' || filterVerification !== 'all'"
              type="button"
              class="btn btn-sm btn-ghost-danger d-flex align-items-center gap-1 filter-reset-btn"
              @click="resetFilters"
              title="Reset semua filter ke kondisi awal"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
              <span>Reset Filter</span>
            </button>
          </div>
        </div>

        <!-- Table (Desktop & Tablet >= 768px) -->
        <div class="table-responsive d-none d-md-block">
          <table class="table table-vcenter card-table table-hover">
            <thead>
              <tr>
                <th class="col-index text-white" style="width: 40px;">#</th>
                <th>Target & Tipe</th>
                <th>Proyek Induk</th>
                <th>Endpoint / Identifier / Aset</th>
                <th>Hostname / Package ID</th>
                <th style="width: 180px;">Verifikasi Kepemilikan</th>
                <th class="text-center" style="width: 230px;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <!-- Loading -->
              <tr v-if="isLoading">
                <td colspan="7" class="text-center py-4 text-secondary">
                  <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                  Memuat data target...
                </td>
              </tr>

              <!-- Empty state -->
              <tr v-else-if="filteredTargets.length === 0">
                <td colspan="7" class="text-center py-5">
                  <div class="empty">
                    <div class="empty-icon text-muted">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /><path d="M11.5 3a17 17 0 0 0 0 18" /><path d="M12.5 3a17 17 0 0 1 0 18" /></svg>
                    </div>
                    <p class="empty-title">Tidak ada target yang ditemukan</p>
                    <p class="empty-subtitle text-secondary">
                      {{ searchQuery || filterProject !== 'all' ? 'Tidak ada target yang cocok dengan filter pencarian.' : 'Belum ada target web atau API yang didaftarkan ke TAMENG.' }}
                    </p>
                    <div v-if="canManage && !searchQuery" class="empty-action">
                      <button type="button" class="btn btn-primary" @click="openCreateModal">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                        Daftarkan Target Pertama
                      </button>
                    </div>
                  </div>
                </td>
              </tr>

              <!-- Rows -->
              <tr v-else v-for="(target, idx) in filteredTargets" :key="target.id">
                <td class="col-index text-white small fw-bold">{{ idx + 1 }}</td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <span
                      class="avatar avatar-sm rounded"
                      :class="target.type === 'api' ? 'bg-purple-lt text-purple' : target.type === 'container' ? 'bg-teal-lt text-teal' : (target.type === 'mobile' || target.type === 'app') ? 'bg-green-lt text-green' : 'bg-azure-lt text-azure'"
                    >
                      <svg v-if="target.type === 'api'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 8l-4 4l4 4" /><path d="M17 8l4 4l-4 4" /><path d="M14 4l-4 16" /></svg>
                      <svg v-else-if="target.type === 'container'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" /><path d="M12 12l8 -4.5" /><path d="M12 12l0 9" /><path d="M12 12l-8 -4.5" /></svg>
                      <svg v-else-if="target.type === 'mobile' || target.type === 'app'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" /><path d="M11 4h2" /><path d="M12 17v.01" /></svg>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M7 14l-3 -3l8 -8h6l-11 11" /><path d="M14 21l-5 -5l5 -5h5l-5 5l5 5l-5 0" /></svg>
                    </span>
                    <div>
                      <div class="fw-bold text-reset">{{ target.name }}</div>
                      <div class="badge px-1 py-0 font-monospace" :class="getTargetTypeBadge(target.type).class" style="font-size: 0.7rem;">
                        {{ getTargetTypeBadge(target.type).label }}
                      </div>
                    </div>
                  </div>
                </td>
                <td>
                  <span v-if="target.project" class="badge bg-blue-lt text-blue font-monospace">
                    {{ target.project.name }}
                  </span>
                  <span v-else class="text-muted small">-</span>
                </td>
                <td>
                  <div v-if="target.base_url" class="d-flex align-items-center gap-1">
                    <!-- Dynamic Asset Type Badge -->
                    <span
                      v-if="target.type === 'mobile' || target.type === 'app'"
                      class="badge px-1 py-0 font-monospace bg-green-lt text-green"
                      style="font-size: 0.68rem;"
                    >
                      APP
                    </span>
                    <span
                      v-else-if="target.type === 'container'"
                      class="badge px-1 py-0 font-monospace bg-teal-lt text-teal"
                      style="font-size: 0.68rem;"
                    >
                      IMAGE
                    </span>
                    <span
                      v-else
                      class="badge px-1 py-0 font-monospace"
                      :class="target.base_url.startsWith('https://') ? 'bg-success-lt text-success' : 'bg-warning-lt text-warning'"
                      style="font-size: 0.68rem;"
                    >
                      {{ target.base_url.startsWith('https://') ? 'HTTPS' : target.base_url.startsWith('http://') ? 'HTTP' : 'URL' }}
                    </span>

                    <a
                      v-if="target.base_url.startsWith('http://') || target.base_url.startsWith('https://')"
                      :href="target.base_url"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="text-secondary small font-monospace text-truncate text-decoration-none"
                      style="max-width: 250px;"
                      :title="target.base_url"
                    >
                      {{ target.base_url }}
                    </a>
                    <span
                      v-else
                      class="text-secondary small font-monospace text-truncate"
                      style="max-width: 250px;"
                      :title="target.base_url"
                    >
                      {{ target.base_url }}
                    </span>
                  </div>
                  <span v-else class="text-muted small">-</span>
                </td>
                <td>
                  <div class="text-secondary small font-monospace">
                    {{ target.hostname || '-' }}
                  </div>
                </td>
                <td>
                  <div v-if="target.verification_status === 'verified'">
                    <span class="badge bg-success-lt d-inline-flex align-items-center gap-1">
                      <span class="status-dot bg-success"></span>
                      <span>Terverifikasi</span>
                    </span>
                    <div v-if="target.verifier" class="text-muted small" style="font-size: 0.7rem;">
                      Oleh {{ target.verifier.name }}
                    </div>
                  </div>
                  <div v-else>
                    <span class="badge bg-warning-lt d-inline-flex align-items-center gap-1 mb-1">
                      <span class="status-dot bg-warning"></span>
                      <span>Menunggu Verifikasi</span>
                    </span>
                    <button
                      v-if="canManage"
                      type="button"
                      class="btn btn-sm btn-link p-0 text-decoration-none d-block small"
                      style="font-size: 0.72rem;"
                      :disabled="verifyingTargetId === target.id"
                      @click="verifyTargetOwnership(target)"
                    >
                      <span v-if="verifyingTargetId === target.id" class="spinner-border spinner-border-sm me-1" role="status"></span>
                      <span>Verifikasi Sekarang</span>
                    </button>
                  </div>
                </td>
                <td class="text-center">
                  <div class="d-flex align-items-center justify-content-center gap-1">
                    <!-- Trigger Scan -->
                    <button
                      type="button"
                      class="btn btn-sm btn-primary d-flex align-items-center gap-1"
                      @click="openScanModal(target)"
                      :title="target.type === 'mobile' ? 'Pindai Keamanan Aplikasi (MobSF & SAST)' : target.type === 'container' ? 'Pindai Keamanan Container (Trivy & Grype)' : 'Pindai DAST (OWASP ZAP & Nuclei)'"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                      <span>Pindai</span>
                    </button>

                    <!-- Edit button -->
                    <button
                      v-if="canManage"
                      type="button"
                      class="btn btn-sm btn-secondary d-flex align-items-center gap-1"
                      @click="openEditModal(target)"
                      title="Ubah Target"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" /><path d="M16 5l3 3" /></svg>
                      <span>Edit</span>
                    </button>

                    <!-- Delete button -->
                    <button
                      v-if="canManage"
                      type="button"
                      class="btn btn-sm btn-danger d-flex align-items-center gap-1"
                      @click="openDeleteModal(target)"
                      title="Hapus Target"
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
            Memuat data target...
          </div>

          <!-- Empty state -->
          <div v-else-if="filteredTargets.length === 0" class="empty py-4">
            <div class="empty-icon text-muted">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg" width="48" height="48" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /><path d="M11.5 3a17 17 0 0 0 0 18" /><path d="M12.5 3a17 17 0 0 1 0 18" /></svg>
            </div>
            <p class="empty-title">Tidak ada target yang ditemukan</p>
            <p class="empty-subtitle text-secondary">
              {{ searchQuery || filterProject !== 'all' ? 'Tidak ada target yang cocok dengan filter pencarian.' : 'Belum ada target web atau API yang didaftarkan ke TAMENG.' }}
            </p>
            <div v-if="canManage && !searchQuery" class="empty-action">
              <button type="button" class="btn btn-primary btn-sm" @click="openCreateModal">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                Daftarkan Target Pertama
              </button>
            </div>
          </div>

          <!-- Cards List -->
          <div v-else class="d-flex flex-column gap-3">
            <div
              v-for="target in filteredTargets"
              :key="target.id"
              class="card shadow-none border mb-0"
              style="border-radius: 12px; overflow: hidden;"
            >
              <div class="card-body p-3">
                <!-- Top Header: Name, Type, Project Badge -->
                <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                  <div class="d-flex align-items-center gap-2 min-width-0">
                    <span
                      class="avatar avatar-xs rounded flex-shrink-0"
                      :class="target.type === 'api' ? 'bg-purple-lt text-purple' : target.type === 'container' ? 'bg-teal-lt text-teal' : (target.type === 'mobile' || target.type === 'app') ? 'bg-green-lt text-green' : 'bg-azure-lt text-azure'"
                    >
                      <svg v-if="target.type === 'api'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 8l-4 4l4 4" /><path d="M17 8l4 4l-4 4" /><path d="M14 4l-4 16" /></svg>
                      <svg v-else-if="target.type === 'container'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" /><path d="M12 12l8 -4.5" /><path d="M12 12l0 9" /><path d="M12 12l-8 -4.5" /></svg>
                      <svg v-else-if="target.type === 'mobile' || target.type === 'app'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" /><path d="M11 4h2" /><path d="M12 17v.01" /></svg>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M7 14l-3 -3l8 -8h6l-11 11" /><path d="M14 21l-5 -5l5 -5h5l-5 5l5 5l-5 0" /></svg>
                    </span>
                    <div class="min-width-0">
                      <div class="fw-bold text-reset fs-4 lh-1 text-truncate">{{ target.name }}</div>
                      <div class="d-flex align-items-center gap-1 mt-1">
                        <span class="badge px-1 py-0 font-monospace" :class="getTargetTypeBadge(target.type).class" style="font-size: 0.68rem;">
                          {{ getTargetTypeBadge(target.type).label }}
                        </span>
                      </div>
                    </div>
                  </div>
                  <span v-if="target.project" class="badge bg-blue-lt text-blue font-monospace flex-shrink-0">
                    {{ target.project.name }}
                  </span>
                </div>

                <!-- Endpoint / Identifier Box with Copy button -->
                <div v-if="target.base_url || target.hostname" class="bg-body-tertiary rounded p-2 mb-2 border" style="font-size: 0.78rem;">
                  <div class="d-flex align-items-center justify-content-between gap-1 mb-1">
                    <span class="text-secondary small fw-medium">Endpoint / Aset:</span>
                    <div class="d-flex align-items-center gap-1">
                      <span
                        v-if="target.type === 'mobile' || target.type === 'app'"
                        class="badge px-1 py-0 font-monospace bg-green-lt text-green"
                        style="font-size: 0.68rem;"
                      >
                        APP
                      </span>
                      <span
                        v-else-if="target.type === 'container'"
                        class="badge px-1 py-0 font-monospace bg-teal-lt text-teal"
                        style="font-size: 0.68rem;"
                      >
                        IMAGE
                      </span>
                      <span
                        v-else-if="target.base_url"
                        class="badge px-1 py-0 font-monospace"
                        :class="target.base_url.startsWith('https://') ? 'bg-success-lt text-success' : 'bg-warning-lt text-warning'"
                        style="font-size: 0.68rem;"
                      >
                        {{ target.base_url.startsWith('https://') ? 'HTTPS' : target.base_url.startsWith('http://') ? 'HTTP' : 'URL' }}
                      </span>
                      <button
                        v-if="target.base_url || target.hostname"
                        type="button"
                        class="btn btn-sm btn-ghost-secondary p-0 px-1"
                        style="height: 20px; font-size: 0.7rem;"
                        @click="copyToClipboard(target.base_url || target.hostname)"
                        title="Salin Alamat"
                      >
                        <span v-if="copiedUrl === (target.base_url || target.hostname)" class="text-success fw-bold">Tersalin!</span>
                        <span v-else>Salin</span>
                      </button>
                    </div>
                  </div>
                  <div v-if="target.base_url" class="text-break font-monospace text-secondary mb-1" style="font-size: 0.73rem;">
                    {{ target.base_url }}
                  </div>
                  <div v-if="target.hostname && target.hostname !== target.base_url" class="text-secondary small font-monospace" style="font-size: 0.7rem;">
                    Host: {{ target.hostname }}
                  </div>
                </div>

                <!-- Verification Status -->
                <div class="d-flex align-items-center justify-content-between mb-3">
                  <span class="text-secondary small">Status Verifikasi:</span>
                  <div v-if="target.verification_status === 'verified'">
                    <span class="badge bg-success-lt d-inline-flex align-items-center gap-1">
                      <span class="status-dot status-dot-animated bg-success"></span>
                      <span>Terverifikasi</span>
                    </span>
                  </div>
                  <div v-else class="d-flex align-items-center gap-2">
                    <span class="badge bg-warning-lt d-inline-flex align-items-center gap-1">
                      <span class="status-dot bg-warning"></span>
                      <span>Menunggu</span>
                    </span>
                    <button
                      v-if="canManage"
                      type="button"
                      class="btn btn-sm btn-link p-0 text-decoration-none small"
                      style="font-size: 0.72rem;"
                      :disabled="verifyingTargetId === target.id"
                      @click="verifyTargetOwnership(target)"
                    >
                      <span v-if="verifyingTargetId === target.id" class="spinner-border spinner-border-sm me-1" role="status"></span>
                      <span>Verifikasi</span>
                    </button>
                  </div>
                </div>

                <!-- Action Buttons Row -->
                <div class="row g-2">
                  <div class="col-4">
                    <button
                      type="button"
                      class="btn btn-primary btn-sm w-100 d-flex align-items-center justify-content-center gap-1 py-2"
                      @click="openScanModal(target)"
                      :title="target.type === 'mobile' ? 'Pindai Keamanan Aplikasi' : target.type === 'container' ? 'Pindai Keamanan Container' : 'Pindai DAST'"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                      <span>Pindai</span>
                    </button>
                  </div>
                  <div :class="canManage ? 'col-4' : 'col-8'">
                    <button
                      type="button"
                      class="btn btn-secondary btn-sm w-100 d-flex align-items-center justify-content-center gap-1 py-2"
                      @click="openDetailModal(target)"
                      title="Detail Target"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 8l.01 0" /><path d="M11 12l1 0l0 4l1 0" /></svg>
                      <span>Detail</span>
                    </button>
                  </div>
                  <div v-if="canManage" class="col-4 d-flex gap-1">
                    <button
                      type="button"
                      class="btn btn-secondary btn-sm flex-fill d-flex align-items-center justify-content-center p-0 py-2"
                      @click="openEditModal(target)"
                      title="Ubah Target"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" /><path d="M16 5l3 3" /></svg>
                    </button>
                    <button
                      type="button"
                      class="btn btn-danger btn-sm flex-fill d-flex align-items-center justify-content-center p-0 py-2"
                      @click="openDeleteModal(target)"
                      title="Hapus Target"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Form Tambah / Edit Target -->
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
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-white" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /><path d="M11.5 3a17 17 0 0 0 0 18" /><path d="M12.5 3a17 17 0 0 1 0 18" /></svg>
              <span>{{ editingTargetId ? 'Ubah Target Web & API' : 'Daftarkan Target Baru' }}</span>
            </h5>
            <button type="button" class="btn-close btn-close-white" aria-label="Close" @click="closeModal"></button>
          </div>

          <form @submit.prevent="saveTarget">
            <div class="modal-body p-3">
              <!-- Error alert -->
              <div v-if="formError" class="alert alert-danger py-2 px-3 small mb-3">
                {{ formError }}
              </div>

              <!-- Project Selection -->
              <div class="mb-3">
                <label class="form-label required small fw-bold">Proyek Induk</label>
                <select v-model="targetForm.project_id" class="form-select" required>
                  <option value="" disabled>-- Pilih Proyek Terkait --</option>
                  <option v-for="proj in projects" :key="proj.id" :value="proj.id">
                    {{ proj.name }} ({{ proj.code }})
                  </option>
                </select>
              </div>

              <!-- Target Type & Name -->
              <div class="row g-2 mb-3">
                <div class="col-sm-5">
                  <label class="form-label required small fw-bold">Tipe Target</label>
                  <select v-model="targetForm.type" class="form-select">
                    <option value="web">Web Application</option>
                    <option value="api">REST / GraphQL API</option>
                    <option value="container">Container Image</option>
                    <option value="mobile">Aplikasi Mobile (Android / iOS / Flutter)</option>
                  </select>
                </div>
                <div class="col-sm-7">
                  <label class="form-label required small fw-bold">Nama Label Target</label>
                  <input
                    v-model="targetForm.name"
                    type="text"
                    class="form-control"
                    :placeholder="targetForm.type === 'container' ? 'Contoh: Nginx Ingress Image' : targetForm.type === 'mobile' ? 'Contoh: Mobile Banking Android / iOS' : 'Contoh: Portal Web Nasabah'"
                    required
                  />
                </div>
              </div>

              <!-- Info Box khusus Target Aplikasi Non-Web -->
              <div v-if="targetForm.type === 'mobile'" class="alert alert-info py-2 px-3 small mb-3 d-flex align-items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon text-info mt-1 flex-shrink-0" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" /><path d="M11 4h2" /><path d="M12 17v.01" /></svg>
                <div>
                  <strong>Target Keamanan Aplikasi (Bukan Web)</strong><br />
                  Target ini akan dipindai langsung pada level aplikasi (APK / IPA / Binary / Source) menggunakan engine <strong>MobSF</strong>, <strong>Semgrep SAST</strong>, dan <strong>Gitleaks</strong> (bukan pemindaian web DAST).
                </div>
              </div>

              <!-- Dropzone khusus Target Aplikasi Mobile -->
              <div v-if="targetForm.type === 'mobile'" class="mb-3">
                <label class="form-label required small fw-bold">Berkas Binary Aplikasi Mobile</label>
                <div
                  class="dropzone-box text-center p-3 rounded border-2 border-dashed transition-all"
                  :class="{
                    'border-primary bg-primary-subtle': isDragging,
                    'border-secondary-subtle bg-body-tertiary': !isDragging && !mobileFile,
                    'border-success bg-success-subtle': mobileFile
                  }"
                  @dragover.prevent="isDragging = true"
                  @dragleave.prevent="isDragging = false"
                  @drop.prevent="handleFileDrop"
                >
                  <input
                    ref="fileInputRef"
                    type="file"
                    class="d-none"
                    accept=".apk,.ipa,.aab,.zip"
                    @change="handleFileInput"
                  />

                  <div v-if="!mobileFile">
                    <div class="avatar avatar-md bg-primary-lt rounded-circle mx-auto mb-2">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 9l5 -5l5 5" /><path d="M12 4l0 12" /></svg>
                    </div>
                    <div class="fw-bold mb-1 small">Unggah Berkas Binary Aplikasi</div>
                    <div class="text-secondary small mb-2" style="font-size: 0.78rem;">
                      Format .apk (Android), .ipa (iOS), .aab, atau .zip (Maks. 200MB)
                    </div>
                    <button
                      type="button"
                      class="btn btn-primary btn-sm"
                      @click="fileInputRef?.click()"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
                      Pilih Berkas Binary
                    </button>
                  </div>

                  <div v-else class="d-flex align-items-center justify-content-between p-1">
                    <div class="d-flex align-items-center gap-2 text-start">
                      <div class="avatar bg-success text-white rounded">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" /><path d="M11 4h2" /><path d="M12 17v.01" /></svg>
                      </div>
                      <div>
                        <div class="fw-bold font-monospace small text-truncate" style="max-width: 250px;">{{ mobileFile.name }}</div>
                        <div class="text-secondary small" style="font-size: 0.75rem;">
                          Ukuran: {{ (mobileFile.size / (1024 * 1024)).toFixed(2) }} MB
                        </div>
                      </div>
                    </div>
                    <button
                      type="button"
                      class="btn btn-sm btn-danger"
                      @click="removeMobileFile"
                    >
                      Ganti Berkas
                    </button>
                  </div>
                </div>

                <div class="mt-2 text-end">
                  <button
                    type="button"
                    class="btn btn-link btn-sm p-0 text-decoration-none text-muted"
                    @click="useManualIdentifier = !useManualIdentifier"
                  >
                    <span v-if="!useManualIdentifier">+ Opsi Package ID / Store URL manual</span>
                    <span v-else>- Sembunyikan opsi ID / URL manual</span>
                  </button>
                </div>
              </div>

              <!-- Base URL / Container URI / Mobile Package (Jika bukan mobile atau jika manual identifier aktif atau tidak ada file) -->
              <div v-if="targetForm.type !== 'mobile' || useManualIdentifier || !mobileFile" class="mb-3">
                <label class="form-label small fw-bold" :class="{ required: targetForm.type !== 'mobile' && !mobileFile }">
                  {{ targetForm.type === 'container' ? 'Container Image URI / Tag' : targetForm.type === 'mobile' ? 'Package Name / App URL (Opsional jika ada file)' : 'Base URL (Protokol Lengkap)' }}
                </label>
                <input
                  v-model="targetForm.base_url"
                  :type="targetForm.type === 'container' || targetForm.type === 'mobile' ? 'text' : 'url'"
                  class="form-control font-monospace"
                  :placeholder="targetForm.type === 'container' ? 'docker.io/library/nginx:alpine atau registry.gitlab.com/app:v1' : targetForm.type === 'mobile' ? 'com.perusahaan.banking atau https://play.google.com/...' : 'https://app.perusahaan.com'"
                  :required="targetForm.type !== 'mobile' && !mobileFile"
                  @input="onBaseUrlInput"
                />
                <div class="form-text small">
                  {{ targetForm.type === 'container' ? 'Format Docker / OCI Image (contoh: nginx:alpine atau redis:7-alpine)' : targetForm.type === 'mobile' ? 'Package name Android (com.domain.app), Bundle ID iOS, atau tautan binary aplikasi' : 'Harus diawali dengan https:// atau http://' }}
                </div>
              </div>

              <!-- Hostname / Registry Host / Package ID -->
              <div v-if="targetForm.type !== 'mobile' || useManualIdentifier || !mobileFile" class="mb-2">
                <label class="form-label small fw-bold">
                  {{ targetForm.type === 'container' ? 'Registry Host / Image Name' : targetForm.type === 'mobile' ? 'App Identifier / Package Name' : 'Hostname / Domain' }}
                </label>
                <input
                  v-model="targetForm.hostname"
                  type="text"
                  class="form-control font-monospace"
                  :placeholder="targetForm.type === 'container' ? 'docker.io atau nama repositori' : targetForm.type === 'mobile' ? 'com.perusahaan.banking' : 'app.perusahaan.com'"
                />
              </div>
            </div>

            <div class="modal-footer bg-body-tertiary py-2 d-flex flex-column-reverse flex-sm-row align-items-stretch align-items-sm-center justify-content-sm-between gap-2">
              <button type="button" class="btn btn-secondary w-100 w-sm-auto justify-content-center" @click="closeModal" :disabled="isSaving">
                Batal
              </button>
              <button type="submit" class="btn btn-primary d-flex align-items-center justify-content-center gap-1 w-100 w-sm-auto py-2" :disabled="isSaving">
                <span v-if="isSaving" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <span>{{ editingTargetId ? 'Simpan Perubahan' : 'Daftarkan Target' }}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL KONFIRMASI PEMINDAIAN DAST (SEPERTI SCAN MANDIRI)   -->
    <!-- ========================================================= -->
    <div
      v-if="scanModalTarget"
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
            <div class="avatar avatar-lg rounded-circle mx-auto mb-3 shadow-sm" :class="scanModalTarget.type === 'mobile' ? 'bg-green-lt text-green' : scanModalTarget.type === 'container' ? 'bg-teal-lt text-teal' : 'bg-primary-lt text-primary'">
              <svg v-if="scanModalTarget.type === 'mobile'" xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-green" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" /><path d="M11 4h2" /><path d="M12 17v.01" /></svg>
              <svg v-else-if="scanModalTarget.type === 'container'" xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-teal" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" /><path d="M12 12l8 -4.5" /><path d="M12 12l0 9" /><path d="M12 12l-8 -4.5" /></svg>
              <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-primary" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
            </div>
            <h3 class="modal-title mb-1">{{ scanModalTarget.type === 'container' ? 'Mulai Pemindaian Container?' : (scanModalTarget.type === 'mobile' || scanModalTarget.type === 'app') ? 'Mulai Pemindaian Keamanan Aplikasi?' : 'Mulai Pemindaian DAST?' }}</h3>
            <div class="text-secondary small mb-3">
              {{ scanModalTarget.type === 'container' ? 'Pekerjaan akan didaftarkan ke antrean sandbox dengan profil pemindaian image container.' : (scanModalTarget.type === 'mobile' || scanModalTarget.type === 'app') ? 'Pekerjaan akan memindai langsung binary aplikasi (APK / IPA / source) dengan MobSF & SAST (bukan pengujian web dinamis).' : 'Pekerjaan akan didaftarkan ke antrean sandbox dengan profil DAST aktif.' }}
            </div>

            <div class="card card-sm bg-body text-start border mb-0">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Nama Target:</span>
                  <span class="fw-bold small text-reset text-truncate" style="max-width: 160px;" :title="scanModalTarget.name">{{ scanModalTarget.name }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Tipe:</span>
                  <span class="badge px-1 py-0 font-monospace" :class="getTargetTypeBadge(scanModalTarget.type).class" style="font-size: 0.7rem;">{{ getTargetTypeBadge(scanModalTarget.type).label }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Proyek:</span>
                  <span class="fw-medium small text-truncate" style="max-width: 160px;">{{ scanModalTarget.project?.name || '-' }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">{{ scanModalTarget.type === 'container' ? 'Image Tag:' : (scanModalTarget.type === 'mobile' || scanModalTarget.type === 'app') ? 'Package / App:' : 'Target URL:' }}</span>
                  <span class="font-monospace small text-truncate text-secondary" style="max-width: 160px;" :title="scanModalTarget.base_url">{{ scanModalTarget.base_url || scanModalTarget.hostname || '-' }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-secondary small">{{ scanModalTarget.type === 'container' ? 'Engine Container:' : (scanModalTarget.type === 'mobile' || scanModalTarget.type === 'app') ? 'Engine Mobile:' : 'Engine DAST:' }}</span>
                  <span class="small text-primary fw-medium">{{ scanModalTarget.type === 'container' ? 'Trivy, Grype' : (scanModalTarget.type === 'mobile' || scanModalTarget.type === 'app') ? 'MobSF, Semgrep, Gitleaks' : scanModalTarget.type === 'api' ? 'ZAP, Nuclei' : 'ZAP, Nuclei, TestSSL' }}</span>
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
              Permintaan scan telah diterima dan sedang diproses oleh engine di latar belakang.
            </div>

            <div class="card card-sm bg-body text-start border mb-0">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Kode Scan:</span>
                  <span class="font-monospace fw-bold small text-primary">#{{ scanSuccessJob.code }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Target:</span>
                  <span class="fw-medium small text-truncate" style="max-width: 160px;">{{ scanModalTarget.name }}</span>
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

    <!-- Modal Detail Target & Riwayat -->
    <div
      v-if="isDetailModalOpen"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.65);"
      @click.self="closeDetailModal"
    >
      <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header bg-dark text-white py-3">
            <div class="d-flex align-items-center gap-2">
              <span
                class="avatar avatar-sm rounded"
                :class="targetDetail?.target?.type === 'api' ? 'bg-purple-lt text-purple' : targetDetail?.target?.type === 'container' ? 'bg-teal-lt text-teal' : 'bg-azure-lt text-azure'"
              >
                <svg v-if="targetDetail?.target?.type === 'api'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 8l-4 4l4 4" /><path d="M17 8l4 4l-4 4" /><path d="M14 4l-4 16" /></svg>
                <svg v-else-if="targetDetail?.target?.type === 'container'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" /><path d="M12 12l8 -4.5" /><path d="M12 12l0 9" /><path d="M12 12l-8 -4.5" /></svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M7 14l-3 -3l8 -8h6l-11 11" /><path d="M14 21l-5 -5l5 -5h5l-5 5l5 5l-5 0" /></svg>
              </span>
              <div>
                <h5 class="modal-title text-white mb-0 d-flex align-items-center gap-2">
                  <span>{{ targetDetail?.target?.name || 'Detail Target' }}</span>
                  <span class="badge px-1 py-0 font-monospace" :class="getTargetTypeBadge(targetDetail?.target?.type).class" style="font-size: 0.7rem;">
                    {{ getTargetTypeBadge(targetDetail?.target?.type).label }}
                  </span>
                </h5>
                <span class="text-secondary small font-monospace">{{ targetDetail?.target?.base_url || targetDetail?.target?.hostname || '-' }}</span>
              </div>
            </div>
            <button type="button" class="btn-close btn-close-white" aria-label="Close" @click="closeDetailModal"></button>
          </div>

          <div class="modal-body p-3">
            <!-- Loading -->
            <div v-if="isLoadingDetail" class="text-center py-5 text-secondary">
              <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
              Memuat data detail target...
            </div>

            <div v-else-if="targetDetail">
              <!-- Info Cards Grid -->
              <div class="row g-2 mb-3">
                <div class="col-sm-6 col-md-3">
                  <div class="card card-sm bg-body-tertiary border">
                    <div class="card-body p-2">
                      <div class="text-secondary small">Proyek Induk</div>
                      <div class="fw-bold text-truncate">{{ targetDetail.target?.project?.name || '-' }}</div>
                      <div class="text-muted small font-monospace">{{ targetDetail.target?.project?.code || '' }}</div>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-md-3">
                  <div class="card card-sm bg-body-tertiary border">
                    <div class="card-body p-2">
                      <div class="text-secondary small">Status Verifikasi</div>
                      <div>
                        <span v-if="targetDetail.target?.verification_status === 'verified'" class="badge bg-success-lt">
                          Terverifikasi
                        </span>
                        <span v-else class="badge bg-warning-lt">
                          Menunggu
                        </span>
                      </div>
                      <div v-if="targetDetail.target?.verifier" class="text-muted small">
                        Oleh {{ targetDetail.target?.verifier?.name }}
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-md-3">
                  <div class="card card-sm bg-body-tertiary border">
                    <div class="card-body p-2">
                      <div class="text-secondary small">Riwayat Scan</div>
                      <div class="h3 mb-0 text-primary fw-bold">{{ targetDetail.scan_jobs?.length || 0 }}</div>
                      <div class="text-muted small">Total pekerjaan scan</div>
                    </div>
                  </div>
                </div>
                <div class="col-sm-6 col-md-3">
                  <div class="card card-sm bg-body-tertiary border">
                    <div class="card-body p-2">
                      <div class="text-secondary small">Total Temuan</div>
                      <div class="h3 mb-0 text-danger fw-bold">{{ targetDetail.findings_summary?.total || 0 }}</div>
                      <div class="text-muted small">Kerentanan terdeteksi</div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Vulnerability Severity Strip -->
              <div class="card card-sm bg-body border mb-3">
                <div class="card-body p-2 d-flex flex-wrap align-items-center justify-content-around gap-2 text-center">
                  <div>
                    <span class="badge bg-danger text-white me-1">Kritis</span>
                    <span class="fw-bold text-danger">{{ targetDetail.findings_summary?.critical || 0 }}</span>
                  </div>
                  <div class="vr"></div>
                  <div>
                    <span class="badge bg-orange text-white me-1">Tinggi</span>
                    <span class="fw-bold text-orange">{{ targetDetail.findings_summary?.high || 0 }}</span>
                  </div>
                  <div class="vr"></div>
                  <div>
                    <span class="badge bg-warning text-dark me-1">Sedang</span>
                    <span class="fw-bold text-warning">{{ targetDetail.findings_summary?.medium || 0 }}</span>
                  </div>
                  <div class="vr"></div>
                  <div>
                    <span class="badge bg-info text-white me-1">Rendah</span>
                    <span class="fw-bold text-info">{{ targetDetail.findings_summary?.low || 0 }}</span>
                  </div>
                </div>
              </div>

              <!-- Tabs Navigation -->
              <ul class="nav nav-tabs nav-fill mb-3">
                <li class="nav-item">
                  <button
                    type="button"
                    class="nav-link py-2"
                    :class="{ active: activeDetailTab === 'scans' }"
                    @click="activeDetailTab = 'scans'"
                  >
                    Riwayat Scan ({{ targetDetail.scan_jobs?.length || 0 }})
                  </button>
                </li>
                <li class="nav-item">
                  <button
                    type="button"
                    class="nav-link py-2"
                    :class="{ active: activeDetailTab === 'findings' }"
                    @click="activeDetailTab = 'findings'"
                  >
                    Temuan Kerentanan ({{ targetDetail.findings_summary?.recent?.length || 0 }})
                  </button>
                </li>
                <li class="nav-item">
                  <button
                    type="button"
                    class="nav-link py-2"
                    :class="{ active: activeDetailTab === 'scopes' }"
                    @click="activeDetailTab = 'scopes'"
                  >
                    Scope Aktif ({{ targetDetail.scopes?.length || 0 }})
                  </button>
                </li>
              </ul>

              <!-- Tab: Riwayat Scan -->
              <div v-if="activeDetailTab === 'scans'">
                <div v-if="!targetDetail.scan_jobs || targetDetail.scan_jobs.length === 0" class="text-center py-4 text-muted">
                  Belum ada riwayat pemindaian untuk target ini.
                </div>
                <div v-else class="table-responsive">
                  <table class="table table-sm table-hover card-table">
                    <thead>
                      <tr>
                        <th>Kode Scan</th>
                        <th>Profil</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Temuan</th>
                        <th>Waktu</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="job in targetDetail.scan_jobs" :key="job.id">
                        <td>
                          <span class="font-monospace fw-bold text-primary small">#{{ job.code }}</span>
                        </td>
                        <td>
                          <span class="badge bg-secondary-lt small">{{ job.scan_profile?.name || 'Scan Profile' }}</span>
                        </td>
                        <td>
                          <span
                            class="badge px-1 py-0 font-monospace"
                            :class="job.status === 'completed' ? 'bg-success-lt text-success' : job.status === 'failed' ? 'bg-danger-lt text-danger' : 'bg-primary-lt text-primary'"
                            style="font-size: 0.72rem;"
                          >
                            {{ job.status }}
                          </span>
                        </td>
                        <td style="width: 120px;">
                          <div class="progress progress-xs">
                            <div class="progress-bar bg-primary" :style="{ width: (job.progress || 0) + '%' }"></div>
                          </div>
                          <span class="text-muted" style="font-size: 0.68rem;">{{ job.progress || 0 }}%</span>
                        </td>
                        <td>
                          <span class="badge bg-red-lt font-monospace">{{ job.findings_count || 0 }}</span>
                        </td>
                        <td class="text-muted small">
                          {{ job.created_at ? new Date(job.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '-' }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Tab: Temuan Kerentanan -->
              <div v-if="activeDetailTab === 'findings'">
                <div v-if="!targetDetail.findings_summary?.recent || targetDetail.findings_summary.recent.length === 0" class="text-center py-4 text-muted">
                  Tidak ada temuan kerentanan yang tercatat untuk target ini.
                </div>
                <div v-else class="table-responsive">
                  <table class="table table-sm table-hover card-table">
                    <thead>
                      <tr>
                        <th>Tingkat</th>
                        <th>Judul Kerentanan</th>
                        <th>Aset / Endpoint</th>
                        <th>Waktu Ditemukan</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="finding in targetDetail.findings_summary.recent" :key="finding.id">
                        <td>
                          <span
                            class="badge px-1 py-0"
                            :class="finding.severity === 'critical' ? 'bg-danger text-white' : finding.severity === 'high' ? 'bg-orange text-white' : finding.severity === 'medium' ? 'bg-warning text-dark' : 'bg-info text-white'"
                            style="font-size: 0.72rem;"
                          >
                            {{ (finding.severity || 'info').toUpperCase() }}
                          </span>
                        </td>
                        <td>
                          <div class="fw-medium text-truncate small" style="max-width: 280px;" :title="finding.title">{{ finding.title }}</div>
                          <div class="text-muted font-monospace" style="font-size: 0.68rem;">#{{ finding.code }}</div>
                        </td>
                        <td>
                          <span class="font-monospace small text-muted text-truncate d-inline-block" style="max-width: 180px;">{{ finding.asset_identifier || '-' }}</span>
                        </td>
                        <td class="text-muted small">
                          {{ finding.created_at ? new Date(finding.created_at).toLocaleDateString('id-ID', { day: '2-digit', month: 'short' }) : '-' }}
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Tab: Scope Aktif -->
              <div v-if="activeDetailTab === 'scopes'">
                <div v-if="!targetDetail.scopes || targetDetail.scopes.length === 0" class="text-center py-4 text-muted">
                  Belum ada scope khusus yang terhubung ke target ini. Scope dibuat secara otomatis saat pemindaian pertama.
                </div>
                <div v-else class="table-responsive">
                  <table class="table table-sm table-hover card-table">
                    <thead>
                      <tr>
                        <th>Pola / Pattern</th>
                        <th>Tipe Scope</th>
                        <th>Efek</th>
                        <th>Status</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="scope in targetDetail.scopes" :key="scope.id">
                        <td>
                          <code class="text-primary">{{ scope.pattern }}</code>
                        </td>
                        <td>
                          <span class="badge bg-secondary-lt small">{{ scope.type }}</span>
                        </td>
                        <td>
                          <span :class="scope.effect === 'allow' ? 'badge bg-success-lt' : 'badge bg-danger-lt'">
                            {{ scope.effect }}
                          </span>
                        </td>
                        <td>
                          <span class="badge bg-info-lt">{{ scope.status }}</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer bg-body-tertiary py-2 d-flex flex-column-reverse flex-sm-row align-items-stretch align-items-sm-center justify-content-sm-between gap-2">
            <button type="button" class="btn btn-secondary w-100 w-sm-auto justify-content-center" @click="closeDetailModal">
              Tutup
            </button>
            <div class="d-flex w-100 w-sm-auto">
              <button
                v-if="targetDetail?.target"
                type="button"
                class="btn btn-primary d-flex align-items-center justify-content-center gap-1 w-100 w-sm-auto py-2"
                @click="closeDetailModal(); openScanModal(targetDetail.target)"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                <span>Pindai Target Ini</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Konfirmasi Hapus Target -->
    <div
      v-if="isDeleteModalOpen"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.65);"
      @click.self="closeDeleteModal"
    >
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-body text-center py-4">
            <div class="avatar avatar-lg bg-danger-lt rounded-circle mx-auto mb-3">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-danger" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></svg>
            </div>
            <h3 class="modal-title mb-1 text-danger">Hapus Target?</h3>
            <div class="text-secondary small mb-3">
              Apakah Anda yakin ingin menghapus target <strong>{{ deletingTarget?.name }}</strong>? Seluruh konfigurasi scope terkait juga akan dibersihkan.
            </div>
          </div>
          <div class="modal-footer bg-body-tertiary py-2">
            <div class="w-100 row g-2">
              <div class="col-6">
                <button type="button" class="btn btn-secondary w-100" @click="closeDeleteModal" :disabled="isDeleting">
                  Batal
                </button>
              </div>
              <div class="col-6">
                <button type="button" class="btn btn-danger w-100 d-flex align-items-center justify-content-center gap-1" @click="confirmDelete" :disabled="isDeleting">
                  <span v-if="isDeleting" class="spinner-border spinner-border-sm me-1" role="status"></span>
                  <span>{{ isDeleting ? 'Menghapus...' : 'Ya, Hapus' }}</span>
                </button>
              </div>
            </div>
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
