<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { apiFetch } from '../services/api'

interface Project {
  id: number
  name: string
  code: string
}

interface Repository {
  id: number
  name: string
  url?: string
  metadata?: any
}

interface Target {
  id: number
  name: string
  type: string
  base_url?: string
  hostname?: string
}

interface ScanProfile {
  id: number
  key: string
  name: string
  description?: string
  engine_keys?: string[]
}

interface Authorization {
  id: number
  code: string
  project_id: number
  repository_id?: number | null
  target_id?: number | null
  scan_profile_id?: number | null
  allowed_engines?: string[]
  project?: Project
  repository?: Repository
  target?: Target
  scan_profile?: ScanProfile
}

interface ScanRun {
  id: number
  scan_job_id: number
  engine_key: string
  status: string
  exit_code?: number | null
  started_at?: string | null
  finished_at?: string | null
  failure_reason?: string | null
  command_spec?: any
  runtime_metrics?: {
    duration_ms?: number
    finding_count?: number
    stderr_present?: boolean
  }
}

interface ScanJob {
  id: number
  code: string
  project_id: number
  repository_id?: number | null
  target_id?: number | null
  scan_profile_id?: number | null
  authorization_id?: number | null
  status: 'queued' | 'running' | 'completed' | 'failed' | 'denied' | 'skipped'
  progress: number
  engine_plan?: Array<{ engine_key: string }>
  queued_at?: string | null
  started_at?: string | null
  finished_at?: string | null
  failure_reason?: string | null
  project?: Project
  repository?: Repository
  target?: Target
  scan_profile?: ScanProfile
  authorization?: Authorization
  scan_runs?: ScanRun[]
}

// State
const scanJobs = ref<ScanJob[]>([])
const authorizations = ref<Authorization[]>([])
const isLoading = ref(true)
const isSubmitting = ref(false)
const isRerunningId = ref<number | null>(null)
const expandedJobId = ref<number | null>(null)

// Filtering & Search
const searchQuery = ref('')
const filterStatus = ref<'all' | 'running' | 'queued' | 'completed' | 'failed'>('all')
const currentPage = ref(1)
const pageSize = ref(10)

// Polling & Auto Refresh
const autoRefreshEnabled = ref(true)
let pollTimer: any = null
const lastSyncedAt = ref<string>('')

// Alert / Notification
const alertMessage = ref<{ type: 'success' | 'danger' | 'info'; text: string } | null>(null)

// Modal State
const isCreateModalOpen = ref(false)
const selectedAuthId = ref<number | string>('')

// Load Data
async function loadScanJobs(showLoading = false) {
  if (showLoading) isLoading.value = true
  try {
    const res = await apiFetch('/api/scan-jobs')
    if (res && Array.isArray(res.scan_jobs)) {
      scanJobs.value = res.scan_jobs
    }
    const now = new Date()
    lastSyncedAt.value = now.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  } catch (err: any) {
    console.warn('[ScanJobs] Gagal memuat scan jobs:', err)
  } finally {
    if (showLoading) isLoading.value = false
  }
}

async function loadAuthorizations() {
  try {
    const res = await apiFetch('/api/authorizations')
    if (res && Array.isArray(res.data)) {
      authorizations.value = res.data.filter((a: any) => a.status === 'active')
    } else if (Array.isArray(res)) {
      authorizations.value = res.filter((a: any) => a.status === 'active')
    }
  } catch (err) {
    console.warn('[ScanJobs] Gagal memuat authorisasi:', err)
  }
}

// Polling management
function startPolling() {
  stopPolling()
  if (!autoRefreshEnabled.value) return
  pollTimer = setInterval(() => {
    // Poll jika ada pekerjaan yang belum terminal (running / queued), atau secara berkala
    loadScanJobs(false)
  }, 6000)
}

function stopPolling() {
  if (pollTimer) {
    clearInterval(pollTimer)
    pollTimer = null
  }
}

function toggleAutoRefresh() {
  autoRefreshEnabled.value = !autoRefreshEnabled.value
  if (autoRefreshEnabled.value) {
    startPolling()
    showAlert('info', 'Auto-refresh aktif (sinkronisasi setiap 6 detik).')
  } else {
    stopPolling()
    showAlert('info', 'Auto-refresh dinonaktifkan.')
  }
}

// KPI Counters
const totalCount = computed(() => scanJobs.value.length)
const runningCount = computed(() => scanJobs.value.filter(j => j.status === 'running').length)
const queuedCount = computed(() => scanJobs.value.filter(j => j.status === 'queued').length)
const activeCount = computed(() => runningCount.value + queuedCount.value)
const completedCount = computed(() => scanJobs.value.filter(j => j.status === 'completed').length)
const failedCount = computed(() => scanJobs.value.filter(j => ['failed', 'denied'].includes(j.status)).length)

// Filtered & Paginated Jobs
const filteredJobs = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  return scanJobs.value.filter(job => {
    // Status Filter
    if (filterStatus.value === 'running' && job.status !== 'running') return false
    if (filterStatus.value === 'queued' && job.status !== 'queued') return false
    if (filterStatus.value === 'completed' && job.status !== 'completed') return false
    if (filterStatus.value === 'failed' && !['failed', 'denied'].includes(job.status)) return false

    // Search Query
    if (!q) return true
    const code = (job.code || '').toLowerCase()
    const projName = (job.project?.name || '').toLowerCase()
    const projCode = (job.project?.code || '').toLowerCase()
    const repoName = (job.repository?.name || '').toLowerCase()
    const targetName = (job.target?.name || job.target?.base_url || '').toLowerCase()
    const profileName = (job.scan_profile?.name || '').toLowerCase()

    return code.includes(q) || projName.includes(q) || projCode.includes(q) || repoName.includes(q) || targetName.includes(q) || profileName.includes(q)
  })
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredJobs.value.length / pageSize.value)))

const paginatedJobs = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredJobs.value.slice(start, start + pageSize.value)
})

watch([searchQuery, filterStatus], () => {
  currentPage.value = 1
})

// Helper Functions
function formatTime(isoStr: string | null | undefined): string {
  if (!isoStr) return '-'
  const d = new Date(isoStr)
  return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) +
    ' (' + d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) + ')'
}

function calculateDuration(job: ScanJob): string {
  if (job.started_at && job.finished_at) {
    const diffMs = new Date(job.finished_at).getTime() - new Date(job.started_at).getTime()
    if (diffMs > 0) {
      const totalSec = Math.round(diffMs / 1000)
      const mins = Math.floor(totalSec / 60)
      const secs = totalSec % 60
      return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`
    }
  }

  if (job.status === 'running') {
    if (job.started_at) {
      const diffSec = Math.max(0, Math.round((Date.now() - new Date(job.started_at).getTime()) / 1000))
      const m = Math.floor(diffSec / 60)
      const s = diffSec % 60
      return `Berjalan: ${m > 0 ? `${m}m ${s}s` : `${s}s`}`
    }
    return 'Sedang berjalan...'
  }

  if (job.status === 'queued') {
    return 'Menunggu worker...'
  }

  return '-'
}

function assetBadgeInfo(job: ScanJob) {
  if (job.repository) {
    const isMobile = job.repository.metadata?.scan_type === 'mobile' || job.repository.metadata?.is_direct_file
    return {
      type: isMobile ? 'Mobile App' : 'Repository',
      label: job.repository.name,
      badgeClass: isMobile ? 'bg-purple-lt' : 'bg-azure-lt',
      icon: isMobile ? 'device-mobile' : 'git-branch'
    }
  }

  if (job.target) {
    const isContainer = job.target.type === 'container'
    return {
      type: isContainer ? 'Container Image' : 'Web Target',
      label: job.target.name || job.target.base_url || job.target.hostname || 'Target',
      badgeClass: isContainer ? 'bg-teal-lt' : 'bg-indigo-lt',
      icon: isContainer ? 'box' : 'world'
    }
  }

  return {
    type: 'Asset',
    label: '-',
    badgeClass: 'bg-secondary-lt',
    icon: 'cube'
  }
}

function getStatusBadgeClass(status: string): string {
  switch (status) {
    case 'completed': return 'bg-success text-success-fg'
    case 'running': return 'bg-primary text-primary-fg'
    case 'queued': return 'bg-azure text-azure-fg'
    case 'failed': return 'bg-danger text-danger-fg'
    case 'denied': return 'bg-danger text-danger-fg'
    default: return 'bg-secondary text-secondary-fg'
  }
}

function getStatusLabel(status: string): string {
  switch (status) {
    case 'completed': return 'Selesai'
    case 'running': return 'Berjalan'
    case 'queued': return 'Antrean'
    case 'failed': return 'Gagal'
    case 'denied': return 'Ditolak'
    default: return status
  }
}

function getEngineRunStatusClass(status: string): string {
  switch (status) {
    case 'completed': return 'bg-success-lt text-success'
    case 'running': return 'bg-primary-lt text-primary'
    case 'failed': return 'bg-danger-lt text-danger'
    case 'denied': return 'bg-danger-lt text-danger'
    case 'skipped': return 'bg-secondary-lt text-secondary'
    default: return 'bg-secondary-lt'
  }
}

function toggleExpandRow(jobId: number) {
  if (expandedJobId.value === jobId) {
    expandedJobId.value = null
  } else {
    expandedJobId.value = jobId
  }
}

function showAlert(type: 'success' | 'danger' | 'info', text: string) {
  alertMessage.value = { type, text }
  setTimeout(() => {
    if (alertMessage.value?.text === text) {
      alertMessage.value = null
    }
  }, 5000)
}

// Rerun Scan Job
async function handleRerun(job: ScanJob) {
  if (isRerunningId.value !== null) return
  if (!confirm(`Konfirmasi untuk menjalankan ulang pekerjaan scan #${job.code}?`)) {
    return
  }

  isRerunningId.value = job.id
  try {
    const res = await apiFetch(`/api/scan-jobs/${job.id}/rerun`, {
      method: 'POST'
    })
    showAlert('success', res?.message || `Pekerjaan scan #${job.code} berhasil dijalankan ulang.`)
    await loadScanJobs(false)
  } catch (err: any) {
    showAlert('danger', err?.message || 'Gagal menjalankan ulang pemindaian.')
  } finally {
    isRerunningId.value = null
  }
}

// Modal Create Scan Job
const currentSelectedAuth = computed(() => {
  return authorizations.value.find(a => String(a.id) === String(selectedAuthId.value))
})

function openCreateModal() {
  if (authorizations.value.length > 0) {
    selectedAuthId.value = authorizations.value[0].id
  }
  isCreateModalOpen.value = true
}

function closeCreateModal() {
  isCreateModalOpen.value = false
}

async function handleCreateScan() {
  const auth = currentSelectedAuth.value
  if (!auth) {
    showAlert('danger', 'Silakan pilih otorisasi pemindaian yang valid.')
    return
  }

  isSubmitting.value = true
  try {
    const payload: any = {
      project_id: auth.project_id,
      authorization_id: auth.id,
      scan_profile_id: auth.scan_profile_id
    }

    if (auth.repository_id) {
      payload.repository_id = auth.repository_id
    }
    if (auth.target_id) {
      payload.target_id = auth.target_id
    }

    const res = await apiFetch('/api/scan-jobs', {
      method: 'POST',
      body: JSON.stringify(payload)
    })

    showAlert('success', res?.message || 'Pekerjaan pemindaian baru berhasil diinisialisasi.')
    closeCreateModal()
    await loadScanJobs(false)
  } catch (err: any) {
    showAlert('danger', err?.message || 'Gagal memulai pekerjaan pemindaian.')
  } finally {
    isSubmitting.value = false
  }
}

onMounted(async () => {
  await Promise.allSettled([
    loadScanJobs(true),
    loadAuthorizations()
  ])
  startPolling()
})

onUnmounted(() => {
  stopPolling()
})
</script>

<template>
  <div class="page-body">
    <div class="container-xl">
      <!-- Flash Alert Message -->
      <div
        v-if="alertMessage"
        class="alert alert-dismissible mb-3"
        :class="`alert-${alertMessage.type}`"
        role="alert"
      >
        <div class="d-flex align-items-center">
          <div>{{ alertMessage.text }}</div>
        </div>
        <a class="btn-close" @click="alertMessage = null" aria-label="close"></a>
      </div>

      <!-- Page Header -->
      <div class="page-header d-print-none mb-3">
        <div class="row g-2 align-items-center">
          <div class="col">
            <div class="page-pretitle text-secondary">
              OPERASIONAL & ORKESTRASI SANDBOX
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <span>Pekerjaan Pemindaian Keamanan</span>
              <span v-if="runningCount > 0" class="badge bg-primary-lt">
                <span class="status-dot status-dot-animated status-blue me-1"></span>
                {{ runningCount }} Aktif
              </span>
            </h2>
          </div>
          <!-- Top Action Buttons -->
          <div class="col-auto ms-auto d-flex align-items-center gap-2">
            <!-- Sync Indicator -->
            <span class="text-secondary small d-none d-md-inline-block font-monospace">
              <span class="status-dot status-green me-1"></span>
              Update: {{ lastSyncedAt || '-' }}
            </span>

            <!-- Toggle Auto Refresh -->
            <button
              class="btn btn-outline-secondary"
              :class="{ active: autoRefreshEnabled }"
              @click="toggleAutoRefresh"
              :title="autoRefreshEnabled ? 'Matikan auto-refresh' : 'Aktifkan auto-refresh'"
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
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
              </svg>
              <span class="d-none d-sm-inline ms-1">Auto-Sync</span>
            </button>

            <!-- Refresh Manual -->
            <button
              class="btn btn-icon btn-outline-secondary"
              @click="loadScanJobs(true)"
              :disabled="isLoading"
              title="Refresh data sekarang"
            >
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="icon"
                :class="{ 'animate-spin': isLoading }"
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
                <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
              </svg>
            </button>

            <!-- Button New Scan -->
            <button class="btn btn-primary" @click="openCreateModal">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="icon"
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
                <path d="M12 5l0 14" />
                <path d="M5 12l14 0" />
              </svg>
              <span>Mulai Scan Baru</span>
            </button>
          </div>
        </div>
      </div>

      <!-- ROW 1: Telemetry KPI Cards -->
      <div class="row row-deck row-cards mb-3">
        <!-- 1. Total Scan -->
        <div class="col-sm-6 col-lg-3">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-primary text-white avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h4l3 8l4 -16l3 8h4" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium">Total Pemindaian</div>
                  <div class="text-secondary fs-3 fw-bold">{{ totalCount }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 2. Sedang Berjalan / Antrean -->
        <div class="col-sm-6 col-lg-3">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-azure text-white avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a9 9 0 1 0 9 9" /><path d="M12 7v5l3 3" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium">Berjalan / Antrean</div>
                  <div class="text-secondary fs-3 fw-bold d-flex align-items-center gap-2">
                    <span>{{ activeCount }}</span>
                    <span v-if="runningCount > 0" class="badge bg-primary text-primary-fg small fs-6">
                      {{ runningCount }} Berjalan
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 3. Selesai Sukses -->
        <div class="col-sm-6 col-lg-3">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-success text-white avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium">Selesai Sukses</div>
                  <div class="text-secondary fs-3 fw-bold">{{ completedCount }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 4. Gagal / Ditolak -->
        <div class="col-sm-6 col-lg-3">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-danger text-white avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium">Gagal / Ditolak</div>
                  <div class="text-secondary fs-3 fw-bold">{{ failedCount }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ROW 2: Master Card Pekerjaan Scan -->
      <div class="card">
        <div class="card-header border-bottom py-2">
          <!-- Status Filter Tabs -->
          <ul class="nav nav-pills card-header-pills me-auto">
            <li class="nav-item">
              <button
                class="nav-link"
                :class="{ active: filterStatus === 'all' }"
                @click="filterStatus = 'all'"
              >
                Semua ({{ totalCount }})
              </button>
            </li>
            <li class="nav-item">
              <button
                class="nav-link"
                :class="{ active: filterStatus === 'running' }"
                @click="filterStatus = 'running'"
              >
                <span class="status-dot status-blue me-1"></span>
                Berjalan ({{ runningCount }})
              </button>
            </li>
            <li class="nav-item">
              <button
                class="nav-link"
                :class="{ active: filterStatus === 'queued' }"
                @click="filterStatus = 'queued'"
              >
                <span class="status-dot status-azure me-1"></span>
                Antrean ({{ queuedCount }})
              </button>
            </li>
            <li class="nav-item">
              <button
                class="nav-link"
                :class="{ active: filterStatus === 'completed' }"
                @click="filterStatus = 'completed'"
              >
                <span class="status-dot status-green me-1"></span>
                Selesai ({{ completedCount }})
              </button>
            </li>
            <li class="nav-item">
              <button
                class="nav-link"
                :class="{ active: filterStatus === 'failed' }"
                @click="filterStatus = 'failed'"
              >
                <span class="status-dot status-red me-1"></span>
                Gagal ({{ failedCount }})
              </button>
            </li>
          </ul>

          <!-- Search Input -->
          <div class="card-actions my-1">
            <div class="input-icon">
              <span class="input-icon-addon">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
              </span>
              <input
                type="text"
                v-model="searchQuery"
                class="form-control form-control-sm"
                placeholder="Cari kode, proyek, atau target..."
                style="min-width: 240px;"
              />
            </div>
          </div>
        </div>

        <!-- Master Table -->
        <div class="table-responsive">
          <table class="table table-vcenter table-hover card-table">
            <thead>
              <tr>
                <th style="width: 40px;"></th>
                <th>Kode & Waktu Scan</th>
                <th>Proyek & Target Aset</th>
                <th>Profil & Mesin</th>
                <th style="min-width: 140px;">Progress</th>
                <th>Status</th>
                <th class="text-end">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <!-- Empty State -->
              <tr v-if="filteredJobs.length === 0">
                <td colspan="7" class="text-center py-5">
                  <div class="empty">
                    <div class="empty-icon">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 8l.01 0" /><path d="M12 12l0 4" /></svg>
                    </div>
                    <p class="empty-title">Tidak ada pekerjaan scan ditemukan</p>
                    <p class="empty-subtitle text-secondary">
                      Tidak ada hasil yang cocok dengan filter atau kata kunci pencarian saat ini.
                    </p>
                    <div class="empty-action">
                      <button class="btn btn-primary" @click="openCreateModal">
                        Mulai Pemindaian Pertama
                      </button>
                    </div>
                  </div>
                </td>
              </tr>

              <!-- Rows -->
              <template v-for="job in paginatedJobs" :key="job.id">
                <tr :class="{ 'table-active': expandedJobId === job.id }">
                  <!-- Expand Trigger -->
                  <td>
                    <button
                      class="btn btn-icon btn-ghost-secondary btn-sm"
                      @click="toggleExpandRow(job.id)"
                      :title="expandedJobId === job.id ? 'Tutup rincian' : 'Buka log rincian mesin'"
                    >
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="icon icon-tabler"
                        :style="{ transform: expandedJobId === job.id ? 'rotate(90deg)' : 'rotate(0deg)', transition: 'transform 0.2s' }"
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
                        <path d="M9 6l6 6l-6 6" />
                      </svg>
                    </button>
                  </td>

                  <!-- 1. Kode & Waktu -->
                  <td>
                    <div class="d-flex flex-column">
                      <span class="fw-bold font-monospace text-primary">
                        #{{ job.code }}
                      </span>
                      <div class="text-secondary small font-monospace mt-1">
                        {{ formatTime(job.started_at || job.queued_at) }}
                      </div>
                      <div class="small text-muted mt-1">
                        <span class="badge badge-outline text-secondary font-monospace">
                          {{ calculateDuration(job) }}
                        </span>
                      </div>
                    </div>
                  </td>

                  <!-- 2. Proyek & Target Aset -->
                  <td>
                    <div class="d-flex flex-column">
                      <div class="fw-medium text-truncate" style="max-width: 220px;" :title="job.project?.name">
                        {{ job.project?.name || 'Proyek Mandiri' }}
                      </div>
                      <div class="mt-1 d-flex align-items-center gap-1">
                        <span class="badge" :class="assetBadgeInfo(job).badgeClass">
                          {{ assetBadgeInfo(job).type }}
                        </span>
                        <span
                          class="text-secondary small font-monospace text-truncate"
                          style="max-width: 180px;"
                          :title="assetBadgeInfo(job).label"
                        >
                          {{ assetBadgeInfo(job).label }}
                        </span>
                      </div>
                    </div>
                  </td>

                  <!-- 3. Profil & Mesin Scanner -->
                  <td>
                    <div class="d-flex flex-column gap-1">
                      <span class="badge bg-blue-lt text-truncate" style="max-width: 220px;" :title="job.scan_profile?.name">
                        {{ job.scan_profile?.name || 'Deterministic Plan' }}
                      </span>
                      <!-- Engine Badges List -->
                      <div class="d-flex flex-wrap gap-1 mt-1">
                        <template v-if="job.engine_plan && job.engine_plan.length > 0">
                          <span
                            v-for="eng in job.engine_plan"
                            :key="eng.engine_key"
                            class="badge bg-secondary-lt font-monospace small px-1 py-0"
                          >
                            {{ eng.engine_key }}
                          </span>
                        </template>
                        <span v-else class="text-secondary small font-monospace">-</span>
                      </div>
                    </div>
                  </td>

                  <!-- 4. Progress -->
                  <td>
                    <div class="d-flex flex-column gap-1">
                      <div class="d-flex justify-content-between text-secondary small font-monospace">
                        <span>{{ job.progress }}%</span>
                        <span v-if="job.status === 'running'" class="text-primary fw-medium">Scanning</span>
                      </div>
                      <div class="progress progress-sm">
                        <div
                          class="progress-bar"
                          :class="{
                            'bg-success': job.status === 'completed',
                            'bg-primary progress-bar-striped progress-bar-animated': job.status === 'running',
                            'bg-azure': job.status === 'queued',
                            'bg-danger': ['failed', 'denied'].includes(job.status)
                          }"
                          :style="{ width: `${job.progress}%` }"
                        ></div>
                      </div>
                    </div>
                  </td>

                  <!-- 5. Status -->
                  <td>
                    <span class="badge" :class="getStatusBadgeClass(job.status)">
                      {{ getStatusLabel(job.status) }}
                    </span>
                  </td>

                  <!-- 6. Aksi -->
                  <td class="text-end">
                    <div class="btn-list flex-nowrap justify-content-end">
                      <!-- Rerun Button -->
                      <button
                        class="btn btn-sm btn-outline-secondary"
                        :disabled="isRerunningId === job.id || job.status === 'running'"
                        @click="handleRerun(job)"
                        title="Jalankan ulang pemindaian ini"
                      >
                        <svg
                          xmlns="http://www.w3.org/2000/svg"
                          class="icon"
                          :class="{ 'animate-spin': isRerunningId === job.id }"
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
                          <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                          <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                        </svg>
                        <span class="d-none d-lg-inline ms-1">Rerun</span>
                      </button>

                      <!-- Detail Inspector Toggle -->
                      <button
                        class="btn btn-sm btn-ghost-primary"
                        @click="toggleExpandRow(job.id)"
                      >
                        Log Mesin
                      </button>
                    </div>
                  </td>
                </tr>

                <!-- EXPANDED ACCORDION: Mesin Execution Inspector -->
                <tr v-if="expandedJobId === job.id" class="bg-light-subtle">
                  <td colspan="7" class="p-3">
                    <div class="card card-sm">
                      <div class="card-status-top bg-azure"></div>
                      <div class="card-header bg-transparent py-2">
                        <h4 class="card-title text-azure d-flex align-items-center gap-2">
                          <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M7 8h10" /><path d="M7 12h10" /><path d="M7 16h10" /></svg>
                          Rincian Eksekusi Mesin Pemindai Sandbox (#{{ job.code }})
                        </h4>
                        <div class="card-actions">
                          <span class="badge bg-secondary text-secondary-fg">
                            {{ job.scan_runs?.length || 0 }} Mesin Terdaftar
                          </span>
                        </div>
                      </div>

                      <div class="card-body p-0">
                        <!-- If No Run Yet -->
                        <div v-if="!job.scan_runs || job.scan_runs.length === 0" class="p-4 text-center text-secondary">
                          Belum ada log eksekusi kontainer (sedang dalam antrean penjadwalan worker).
                        </div>

                        <!-- Scan Runs Table -->
                        <div v-else class="table-responsive">
                          <table class="table table-vcenter table-striped table-sm mb-0">
                            <thead>
                              <tr>
                                <th>Mesin Pemindai</th>
                                <th>Status Eksekusi</th>
                                <th>Exit Code</th>
                                <th>Durasi Eksekusi</th>
                                <th>Temuan (Findings)</th>
                                <th>Image Sandbox</th>
                              </tr>
                            </thead>
                            <tbody>
                              <tr v-for="run in job.scan_runs" :key="run.id">
                                <td>
                                  <span class="fw-bold font-monospace">{{ run.engine_key }}</span>
                                </td>
                                <td>
                                  <span class="badge" :class="getEngineRunStatusClass(run.status)">
                                    {{ run.status.toUpperCase() }}
                                  </span>
                                  <span v-if="run.failure_reason" class="text-danger small ms-2">
                                    ({{ run.failure_reason }})
                                  </span>
                                </td>
                                <td>
                                  <span class="badge badge-outline font-monospace">
                                    {{ run.exit_code !== null ? run.exit_code : '-' }}
                                  </span>
                                </td>
                                <td>
                                  <span class="text-secondary font-monospace small">
                                    {{ run.runtime_metrics?.duration_ms ? `${(run.runtime_metrics.duration_ms / 1000).toFixed(2)} detik` : '-' }}
                                  </span>
                                </td>
                                <td>
                                  <span
                                    class="badge"
                                    :class="(run.runtime_metrics?.finding_count ?? 0) > 0 ? 'bg-danger text-danger-fg' : 'bg-success-lt text-success'"
                                  >
                                    {{ run.runtime_metrics?.finding_count ?? 0 }} Temuan
                                  </span>
                                </td>
                                <td>
                                  <span class="text-muted font-monospace small">
                                    {{ run.command_spec?.container_image || 'docker' }}
                                  </span>
                                </td>
                              </tr>
                            </tbody>
                          </table>
                        </div>
                      </div>

                      <!-- Footer Log Failure Warning if any -->
                      <div v-if="job.failure_reason" class="card-footer bg-danger-lt py-2 text-danger small">
                        <strong>Catatan Kegagalan:</strong> {{ job.failure_reason }}
                      </div>
                    </div>
                  </td>
                </tr>
              </template>
            </tbody>
          </table>
        </div>

        <!-- Card Footer: Pagination -->
        <div class="card-footer d-flex align-items-center justify-content-between py-2">
          <div class="text-secondary small">
            Menampilkan
            <span class="fw-bold">{{ (currentPage - 1) * pageSize + 1 }}</span>
            -
            <span class="fw-bold">{{ Math.min(currentPage * pageSize, filteredJobs.length) }}</span>
            dari <span class="fw-bold">{{ filteredJobs.length }}</span> pekerjaan scan
          </div>

          <ul class="pagination pagination-sm m-0">
            <li class="page-item" :class="{ disabled: currentPage === 1 }">
              <button class="page-link" @click="currentPage--" :disabled="currentPage === 1">
                Sebelumnya
              </button>
            </li>
            <li
              v-for="p in totalPages"
              :key="p"
              class="page-item"
              :class="{ active: p === currentPage }"
            >
              <button class="page-link" @click="currentPage = p">{{ p }}</button>
            </li>
            <li class="page-item" :class="{ disabled: currentPage === totalPages }">
              <button class="page-link" @click="currentPage++" :disabled="currentPage === totalPages">
                Berikutnya
              </button>
            </li>
          </ul>
        </div>
      </div>
    </div>

    <!-- MODAL: Mulai Scan Baru -->
    <div
      v-if="isCreateModalOpen"
      class="modal modal-blur fade show d-block"
      style="background: rgba(0, 0, 0, 0.5);"
      tabindex="-1"
      role="dialog"
    >
      <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
              Inisialisasi Pemindaian Keamanan Baru
            </h5>
            <button type="button" class="btn-close" @click="closeCreateModal" aria-label="Close"></button>
          </div>

          <div class="modal-body">
            <p class="text-secondary mb-3">
              Pilih otorisasi keamanan aktif untuk menentukan target aset, izin scope, dan konfigurasi profil mesin pemindai secara otomatis.
            </p>

            <!-- Dropdown Otorisasi -->
            <div class="mb-3">
              <label class="form-label required">Otorisasi Keamanan Aktif</label>
              <select v-model="selectedAuthId" class="form-select">
                <option v-for="auth in authorizations" :key="auth.id" :value="auth.id">
                  #{{ auth.code }} — {{ auth.project?.name || 'Proyek' }} ({{ auth.scan_profile?.name || 'Profil Scan' }})
                </option>
              </select>
            </div>

            <!-- Preview Data Otorisasi Terpilih -->
            <div v-if="currentSelectedAuth" class="card bg-light-subtle border mb-3">
              <div class="card-body py-3">
                <div class="row g-3">
                  <div class="col-sm-6">
                    <div class="text-secondary small">Nama Proyek</div>
                    <div class="fw-bold">{{ currentSelectedAuth.project?.name || '-' }}</div>
                    <div class="text-muted small font-monospace">{{ currentSelectedAuth.project?.code }}</div>
                  </div>
                  <div class="col-sm-6">
                    <div class="text-secondary small">Profil Pemindaian</div>
                    <div class="fw-bold text-primary">{{ currentSelectedAuth.scan_profile?.name || 'Profil Default' }}</div>
                    <div class="text-muted small">{{ currentSelectedAuth.scan_profile?.description || '-' }}</div>
                  </div>
                  <div class="col-12">
                    <div class="text-secondary small">Target Aset yang Diperiksa</div>
                    <div class="fw-medium font-monospace mt-1">
                      <span v-if="currentSelectedAuth.repository" class="badge bg-azure-lt me-1">Git Repository</span>
                      <span v-else-if="currentSelectedAuth.target" class="badge bg-teal-lt me-1">Target Web/Host</span>
                      {{ currentSelectedAuth.repository?.name || currentSelectedAuth.target?.name || currentSelectedAuth.target?.base_url || '-' }}
                    </div>
                  </div>
                  <div class="col-12">
                    <div class="text-secondary small mb-1">Mesin Pemindai Diizinkan (Sandbox)</div>
                    <div class="d-flex flex-wrap gap-1">
                      <span
                        v-for="eng in currentSelectedAuth.allowed_engines || currentSelectedAuth.scan_profile?.engine_keys || []"
                        :key="eng"
                        class="badge bg-success-lt font-monospace"
                      >
                        {{ eng }}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-link link-secondary" @click="closeCreateModal">
              Batal
            </button>
            <button
              type="button"
              class="btn btn-primary"
              :disabled="isSubmitting || !currentSelectedAuth"
              @click="handleCreateScan"
            >
              <svg
                v-if="isSubmitting"
                xmlns="http://www.w3.org/2000/svg"
                class="icon animate-spin"
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
                <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
              </svg>
              <span>{{ isSubmitting ? 'Memulai Scan...' : 'Luncurkan Pemindaian' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>
