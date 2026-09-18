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
  status?: string
  valid_from?: string
  valid_until?: string
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
const nowTimestamp = ref<number>(Date.now())
let secondTickerTimer: any = null

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
    const list = res?.authorizations || res?.data || (Array.isArray(res) ? res : [])
    const now = Date.now()
    authorizations.value = list.filter((a: any) => {
      if (a.status !== 'active') return false
      if (a.valid_until && new Date(a.valid_until).getTime() < now) return false
      return true
    })
    if (authorizations.value.length > 0 && !selectedAuthId.value) {
      selectedAuthId.value = authorizations.value[0].id
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

function startTicker() {
  stopTicker()
  secondTickerTimer = setInterval(() => {
    nowTimestamp.value = Date.now()
  }, 1000)
}

function stopTicker() {
  if (secondTickerTimer) {
    clearInterval(secondTickerTimer)
    secondTickerTimer = null
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
  const list = scanJobs.value.filter(job => {
    // Status Filter
    if (filterStatus.value === 'running' && job.status !== 'running') return false
    if (filterStatus.value === 'queued' && job.status !== 'queued') return false
    if (filterStatus.value === 'completed' && job.status !== 'completed') return false
    if (filterStatus.value === 'failed' && !['failed', 'denied'].includes(job.status)) return false

    // Search Query: MURNI HANYA NAMA PROYEK
    if (!q) return true
    const projName = (job.project?.name || '').toLowerCase()
    return projName.includes(q)
  })

  return list.sort((a, b) => {
    const timeA = a.queued_at || a.created_at ? new Date(a.queued_at || a.created_at).getTime() : a.id
    const timeB = b.queued_at || b.created_at ? new Date(b.queued_at || b.created_at).getTime() : b.id
    return timeB - timeA
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

function formatShortDate(isoStr: string | null | undefined): string {
  if (!isoStr) return '-'
  const d = new Date(isoStr)
  return d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
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
      const diffSec = Math.max(0, Math.round((nowTimestamp.value - new Date(job.started_at).getTime()) / 1000))
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

function isJobPartialSuccess(job: ScanJob): boolean {
  if (job.status !== 'completed') return false
  if (job.failure_reason) return true
  if (job.scan_runs && job.scan_runs.some(r => ['failed', 'denied'].includes(r.status))) {
    return true
  }
  return false
}

function getJobStatusBadgeClass(job: ScanJob): string {
  if (isJobPartialSuccess(job)) {
    return 'bg-warning text-warning-fg'
  }
  switch (job.status) {
    case 'completed': return 'bg-success text-success-fg'
    case 'running': return 'bg-primary text-primary-fg'
    case 'queued': return 'bg-azure text-azure-fg'
    case 'failed': return 'bg-danger text-danger-fg'
    case 'denied': return 'bg-danger text-danger-fg'
    default: return 'bg-secondary text-secondary-fg'
  }
}

function getJobStatusLabel(job: ScanJob): string {
  if (isJobPartialSuccess(job)) {
    return 'Selesai Parsial'
  }
  switch (job.status) {
    case 'completed': return 'Selesai'
    case 'running': return 'Berjalan'
    case 'queued': return 'Antrean'
    case 'failed': return 'Gagal'
    case 'denied': return 'Ditolak'
    default: return job.status
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
    default: return 'bg-secondary-lt text-secondary'
  }
}

function getEngineBadgeClass(job: ScanJob, engineKey: string): string {
  if (job.scan_runs && job.scan_runs.length > 0) {
    const run = job.scan_runs.find(r => r.engine_key === engineKey)
    if (run) {
      return getEngineRunStatusClass(run.status)
    }
  }
  if (job.status === 'completed') {
    return 'bg-success-lt text-success'
  }
  if (job.status === 'running') {
    return 'bg-primary-lt text-primary'
  }
  if (['failed', 'denied'].includes(job.status)) {
    return 'bg-danger-lt text-danger'
  }
  return 'bg-secondary-lt text-secondary'
}

function getJobEngines(job: ScanJob): Array<{ engine_key: string, statusClass: string }> {
  const engineKeys: string[] = []
  if (job.engine_plan && Array.isArray(job.engine_plan) && job.engine_plan.length > 0) {
    job.engine_plan.forEach(e => {
      const key = e.engine_key || e
      if (typeof key === 'string' && !engineKeys.includes(key)) engineKeys.push(key)
    })
  }
  if (job.scan_runs && Array.isArray(job.scan_runs) && job.scan_runs.length > 0) {
    job.scan_runs.forEach(r => {
      if (r.engine_key && !engineKeys.includes(r.engine_key)) engineKeys.push(r.engine_key)
    })
  }
  return engineKeys.map(key => ({
    engine_key: key,
    statusClass: getEngineBadgeClass(job, key)
  }))
}

function toggleExpandRow(jobId: number) {
  if (expandedJobId.value === jobId) {
    expandedJobId.value = null
  } else {
    expandedJobId.value = jobId
  }
}

function handleRowClick(event: MouseEvent, jobId: number) {
  const target = event.target as HTMLElement
  if (target.closest('button') || target.closest('a') || target.closest('.btn')) {
    return
  }
  const selection = window.getSelection()
  if (selection && selection.toString().trim().length > 0) {
    return
  }
  toggleExpandRow(jobId)
}

function showAlert(type: 'success' | 'danger' | 'info', text: string) {
  alertMessage.value = { type, text }
  setTimeout(() => {
    if (alertMessage.value?.text === text) {
      alertMessage.value = null
    }
  }, 5000)
}

// Modal Rerun Confirmation
const rerunConfirmJob = ref<ScanJob | null>(null)

function promptRerun(job: ScanJob) {
  rerunConfirmJob.value = job
}

function closeRerunModal() {
  rerunConfirmJob.value = null
}

async function confirmAndExecuteRerun() {
  if (!rerunConfirmJob.value || isRerunningId.value !== null) return
  const job = rerunConfirmJob.value
  isRerunningId.value = job.id
  try {
    const res = await apiFetch(`/api/scan-jobs/${job.id}/rerun`, {
      method: 'POST'
    })
    const newJob = res?.scan_job
    showAlert('success', `Pekerjaan #${job.code} berhasil di-rerun menjadi #${newJob?.code || 'baru'}.`)
    closeRerunModal()
    filterStatus.value = 'all'
    await loadScanJobs(false)
  } catch (err: any) {
    showAlert('danger', err?.message || 'Gagal menjalankan ulang pemindaian.')
  } finally {
    isRerunningId.value = null
  }
}

// Modal Create Scan Job (User-Friendly Guided Selector)
const formProjectId = ref<number | ''>('')
const formTargetKey = ref<string>('')
const formProfileId = ref<number | ''>('')

// Unique Projects from active authorizations
const availableProjects = computed(() => {
  const map = new Map<number, { id: number; name: string; code: string }>()
  for (const auth of authorizations.value) {
    if (auth.project && !map.has(auth.project.id)) {
      map.set(auth.project.id, {
        id: auth.project.id,
        name: auth.project.name,
        code: auth.project.code
      })
    }
  }
  return Array.from(map.values())
})

// Targets available under the selected project
const availableTargets = computed(() => {
  if (!formProjectId.value) return []
  const map = new Map<string, { key: string; name: string; type: string; badgeClass: string; icon: string }>()

  for (const auth of authorizations.value) {
    if (auth.project_id === formProjectId.value) {
      if (auth.repository) {
        const isMobile = auth.repository.metadata?.scan_type === 'mobile' || auth.repository.metadata?.is_direct_file
        const key = `repo:${auth.repository.id}`
        if (!map.has(key)) {
          map.set(key, {
            key,
            name: auth.repository.name,
            type: isMobile ? 'Mobile App' : 'Git Repository',
            badgeClass: isMobile ? 'bg-purple-lt' : 'bg-azure-lt',
            icon: isMobile ? 'device-mobile' : 'git-branch'
          })
        }
      } else if (auth.target) {
        const isContainer = auth.target.type === 'container'
        const key = `target:${auth.target.id}`
        if (!map.has(key)) {
          map.set(key, {
            key,
            name: auth.target.name || auth.target.base_url || 'Target Host',
            type: isContainer ? 'Container Image' : 'Web / DAST Target',
            badgeClass: isContainer ? 'bg-teal-lt' : 'bg-indigo-lt',
            icon: isContainer ? 'box' : 'world'
          })
        }
      }
    }
  }
  return Array.from(map.values())
})

// Profiles available for selected project & target
const availableProfiles = computed(() => {
  if (!formProjectId.value || !formTargetKey.value) return []
  const isRepo = formTargetKey.value.startsWith('repo:')
  const targetId = Number(formTargetKey.value.split(':')[1])

  const map = new Map<number, { id: number; name: string; description?: string }>()
  for (const auth of authorizations.value) {
    if (auth.project_id === formProjectId.value) {
      const matchTarget = isRepo ? auth.repository_id === targetId : auth.target_id === targetId
      if (matchTarget) {
        const profile = auth.scan_profile || (auth as any).scanProfile
        if (profile && !map.has(profile.id)) {
          map.set(profile.id, {
            id: profile.id,
            name: profile.name,
            description: profile.description
          })
        }
      }
    }
  }
  return Array.from(map.values())
})

// Automatically resolved active authorization permit
const resolvedAuthorization = computed(() => {
  if (!formProjectId.value || !formTargetKey.value) return null
  const isRepo = formTargetKey.value.startsWith('repo:')
  const targetId = Number(formTargetKey.value.split(':')[1])

  return authorizations.value.find(auth => {
    if (auth.project_id !== formProjectId.value) return false
    const matchTarget = isRepo ? auth.repository_id === targetId : auth.target_id === targetId
    if (!matchTarget) return false
    if (formProfileId.value && auth.scan_profile_id !== formProfileId.value) return false
    return true
  }) || null
})

// Watchers for guided cascading selection
watch(formProjectId, (newVal) => {
  formTargetKey.value = ''
  formProfileId.value = ''
  if (newVal) {
    const targets = availableTargets.value
    if (targets.length > 0) {
      formTargetKey.value = targets[0].key
    }
  }
})

watch(formTargetKey, (newVal) => {
  formProfileId.value = ''
  if (newVal) {
    const profiles = availableProfiles.value
    if (profiles.length > 0) {
      formProfileId.value = profiles[0].id
    }
  }
})

function openCreateModal() {
  if (authorizations.value.length === 0) {
    loadAuthorizations()
  }
  if (availableProjects.value.length > 0 && !formProjectId.value) {
    formProjectId.value = availableProjects.value[0].id
  }
  isCreateModalOpen.value = true
}

function closeCreateModal() {
  isCreateModalOpen.value = false
}

async function handleCreateScan() {
  const auth = resolvedAuthorization.value
  if (!auth) {
    showAlert('danger', 'Silakan pilih target dan profil pemindaian yang valid.')
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
    const rawMsg = err?.message || 'Gagal memulai pekerjaan pemindaian.'
    let userMsg = rawMsg
    if (rawMsg.includes('AUTHORIZATION_NOT_IN_VALID_WINDOW')) {
      userMsg = 'Izin otorisasi telah kedaluwarsa atau belum masuk masa aktif (Authorization out of valid window).'
    } else if (rawMsg.includes('AUTHORIZATION_NOT_ACTIVE')) {
      userMsg = 'Izin otorisasi untuk target/profil ini berstatus tidak aktif.'
    } else if (rawMsg.includes('REPOSITORY_NOT_VERIFIED') || rawMsg.includes('TARGET_NOT_VERIFIED')) {
      userMsg = 'Aset target belum berstatus terverifikasi (verified).'
    }
    showAlert('danger', userMsg)
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
  startTicker()
})

onUnmounted(() => {
  stopPolling()
  stopTicker()
})
</script>

<template>
  <div class="page-body mt-0">
    <div class="container-fluid">
    <!-- Flash Toast Notification (Floating Modern Tabler) -->
    <transition name="toast-slide">
      <div
        v-if="alertMessage"
        class="toast-container position-fixed end-0 p-3"
        style="top: 72px; z-index: 1070; max-width: 480px;"
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
              <path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0" />
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
              <path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" />
              <path d="M12 9h.01" />
              <path d="M11 12h1v4h1" />
            </svg>
          </div>
          <div class="flex-fill pe-2">
            <div class="fw-medium small">{{ alertMessage.text }}</div>
          </div>
          <a class="btn-close ms-auto" @click="alertMessage = null" aria-label="close"></a>
        </div>
      </div>
    </transition>

      <!-- Page Header -->
      <div class="page-header d-print-none mb-3">
        <div class="row g-2 align-items-center">
          <div class="col">
            <div class="page-pretitle text-secondary text-uppercase fw-bold fs-6">
              Operasional &amp; Orkestrasi &middot; Mesin Pemindai Sandbox
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M3 12h4l3 8l4 -16l3 8h4" />
              </svg>
              <span>Pekerjaan Scan</span>
            </h2>
          </div>
          <!-- Top Action Buttons -->
          <div class="col-auto ms-auto d-flex align-items-center gap-2">

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
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-clock"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium">Berjalan / Antrean</div>
                  <div class="text-secondary fs-3 fw-bold d-flex align-items-center gap-2">
                    <span>{{ activeCount }}</span>
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
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-alert-triangle"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0" /><path d="M12 16h.01" /></svg>
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

          <!-- Restyled Modern Search Input -->
          <div class="card-actions my-1">
            <div class="search-box-wrapper position-relative">
              <div class="input-icon">
                <span class="input-icon-addon text-primary">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                </span>
                <input
                  type="text"
                  v-model="searchQuery"
                  class="form-control form-control-sm modern-search-input"
                  :class="{ 'has-query': searchQuery }"
                  placeholder="Cari nama proyek..."
                  @keydown.esc="searchQuery = ''"
                />
                <!-- Tombol Hapus Cepat (X) -->
                <button
                  v-if="searchQuery"
                  type="button"
                  class="btn btn-sm btn-link p-0 text-muted search-clear-btn"
                  @click="searchQuery = ''"
                  title="Hapus pencarian (Esc)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                </button>
              </div>
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
                <tr
                  class="scan-job-row"
                  :class="{ 'table-active': expandedJobId === job.id }"
                  @click="handleRowClick($event, job.id)"
                >
                  <!-- Expand Trigger -->
                  <td>
                    <button
                      class="btn btn-icon btn-ghost-secondary btn-sm text-white"
                      @click.stop="toggleExpandRow(job.id)"
                      :title="expandedJobId === job.id ? 'Tutup rincian' : 'Buka log rincian mesin'"
                      style="color: #ffffff !important;"
                    >
                      <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="icon icon-tabler text-white"
                        :style="{ transform: expandedJobId === job.id ? 'rotate(90deg)' : 'rotate(0deg)', transition: 'transform 0.2s', stroke: '#ffffff', color: '#ffffff' }"
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
                      <!-- Engine Badges List with dynamic execution status colors -->
                      <div class="d-flex flex-wrap gap-1 mt-1">
                        <template v-if="getJobEngines(job).length > 0">
                          <span
                            v-for="eng in getJobEngines(job)"
                            :key="eng.engine_key"
                            class="badge font-monospace px-1 py-0 fs-6"
                            :class="eng.statusClass"
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
                            'bg-warning': isJobPartialSuccess(job),
                            'bg-success': job.status === 'completed' && !isJobPartialSuccess(job),
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
                    <span class="badge d-inline-flex align-items-center gap-1" :class="getJobStatusBadgeClass(job)">
                      <svg
                        v-if="isJobPartialSuccess(job)"
                        xmlns="http://www.w3.org/2000/svg"
                        class="icon icon-xs"
                        width="14"
                        height="14"
                        viewBox="0 0 24 24"
                        stroke-width="2"
                        stroke="currentColor"
                        fill="none"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                      >
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M12 9v4" />
                        <path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0" />
                        <path d="M12 16h.01" />
                      </svg>
                      {{ getJobStatusLabel(job) }}
                    </span>
                  </td>

                  <!-- 6. Aksi -->
                  <td class="text-end">
                    <div class="btn-list flex-nowrap justify-content-end">
                      <!-- Rerun Button -->
                      <button
                        class="btn btn-sm btn-outline-secondary"
                        :disabled="isRerunningId === job.id || job.status === 'running'"
                        @click.stop="promptRerun(job)"
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
                        class="btn btn-sm"
                        :class="expandedJobId === job.id ? 'btn-primary' : 'btn-ghost-primary'"
                        @click.stop="toggleExpandRow(job.id)"
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

            <!-- Step 1: Pilih Proyek -->
            <div class="mb-3">
              <label class="form-label required">1. Pilih Proyek</label>
              <div v-if="availableProjects.length === 0" class="alert alert-warning py-2 small mb-0">
                <span class="status-dot status-warning me-1"></span>
                Belum ada proyek dengan izin pemindaian aktif di sistem.
              </div>
              <select v-else v-model="formProjectId" class="form-select">
                <option value="" disabled>-- Pilih Proyek Terdaftar --</option>
                <option v-for="proj in availableProjects" :key="proj.id" :value="proj.id">
                  {{ proj.name }} ({{ proj.code }})
                </option>
              </select>
            </div>

            <!-- Step 2: Pilih Target Aset -->
            <div v-if="formProjectId" class="mb-3">
              <label class="form-label required">2. Pilih Target / Repositori</label>
              <div v-if="availableTargets.length === 0" class="alert alert-warning py-2 small mb-0">
                Tidak ada target aktif yang terdaftar untuk proyek ini.
              </div>
              <select v-else v-model="formTargetKey" class="form-select">
                <option value="" disabled>-- Pilih Target yang Akan Dipindai --</option>
                <option v-for="tgt in availableTargets" :key="tgt.key" :value="tgt.key">
                  [{{ tgt.type }}] {{ tgt.name }}
                </option>
              </select>
            </div>

            <!-- Step 3: Pilih Profil Pemindaian -->
            <div v-if="formTargetKey && availableProfiles.length > 0" class="mb-3">
              <label class="form-label required">3. Mode / Profil Pemindaian</label>
              <select v-model="formProfileId" class="form-select">
                <option v-for="prof in availableProfiles" :key="prof.id" :value="prof.id">
                  {{ prof.name }}
                </option>
              </select>
            </div>

            <!-- Kartu Pratinjau Siap Luncur -->
            <div v-if="resolvedAuthorization" class="card bg-light-subtle border mb-3">
              <div class="card-status-top bg-primary"></div>
              <div class="card-body py-3">
                <div class="row g-3">
                  <div class="col-sm-6">
                    <div class="text-secondary small">Proyek Terpilih</div>
                    <div class="fw-bold">{{ resolvedAuthorization.project?.name || '-' }}</div>
                    <div class="text-muted small font-monospace">{{ resolvedAuthorization.project?.code }}</div>
                  </div>
                  <div class="col-sm-6">
                    <div class="text-secondary small">Mode Pemindaian</div>
                    <div class="fw-bold text-primary">{{ resolvedAuthorization.scan_profile?.name || (resolvedAuthorization as any).scanProfile?.name || 'Profil Default' }}</div>
                    <div class="text-muted small">{{ resolvedAuthorization.scan_profile?.description || (resolvedAuthorization as any).scanProfile?.description || '-' }}</div>
                  </div>
                  <div class="col-12">
                    <div class="text-secondary small">Target Aset yang Diperiksa</div>
                    <div class="fw-medium font-monospace mt-1">
                      <span v-if="resolvedAuthorization.repository" class="badge bg-azure-lt me-1">Git Repository</span>
                      <span v-else-if="resolvedAuthorization.target" class="badge bg-teal-lt me-1">Target Web/Host</span>
                      {{ resolvedAuthorization.repository?.name || resolvedAuthorization.target?.name || resolvedAuthorization.target?.base_url || '-' }}
                    </div>
                  </div>
                  <div class="col-12">
                    <div class="text-secondary small mb-1">Mesin Pemindai Siap Eksekusi (Sandbox)</div>
                    <div class="d-flex flex-wrap gap-1">
                      <span
                        v-for="eng in resolvedAuthorization.allowed_engines || resolvedAuthorization.scan_profile?.engine_keys || (resolvedAuthorization as any).scanProfile?.engine_keys || []"
                        :key="eng"
                        class="badge bg-success-lt font-monospace"
                      >
                        {{ eng }}
                      </span>
                    </div>
                  </div>
                  <div class="col-12">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top">
                      <div class="text-muted small d-flex align-items-center gap-1 font-monospace">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-sm text-success" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                        Izin Otorisasi Sah: #{{ resolvedAuthorization.code }}
                      </div>
                      <div v-if="resolvedAuthorization.valid_until" class="small text-secondary">
                        Berlaku s.d. <span class="fw-bold text-azure">{{ formatShortDate(resolvedAuthorization.valid_until) }}</span>
                      </div>
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
              :disabled="isSubmitting || !resolvedAuthorization"
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

    <!-- Modal Konfirmasi Rerun Modern Tabler -->
    <div
      v-if="rerunConfirmJob"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.65); z-index: 1060;"
      @click.self="closeRerunModal"
    >
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0">
          <button
            type="button"
            class="btn-close"
            aria-label="Close"
            @click="closeRerunModal"
          ></button>
          <div class="modal-status bg-primary"></div>
          <div class="modal-body text-center py-4">
            <!-- Icon Rerun / Refresh -->
            <div class="avatar avatar-lg bg-primary-lt rounded-circle mx-auto mb-3 shadow-sm">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                class="icon icon-lg text-primary"
                width="28"
                height="28"
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
            </div>
            <h3 class="modal-title mb-1">Jalankan Ulang Pemindaian?</h3>
            <div class="text-secondary small mb-3">
              Pekerjaan pemindaian akan didaftarkan kembali ke antrean sandbox dengan profil dan target aset yang sama.
            </div>

            <!-- Detail ringkas -->
            <div class="card card-sm bg-body text-start border mb-0">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Kode Scan:</span>
                  <span class="font-monospace fw-bold small text-primary">#{{ rerunConfirmJob.code }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Proyek:</span>
                  <span class="fw-medium small text-truncate" style="max-width: 170px;">{{ rerunConfirmJob.project?.name || '-' }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Target Aset:</span>
                  <span class="fw-medium small font-monospace text-truncate" style="max-width: 170px;">{{ rerunConfirmJob.repository?.name || rerunConfirmJob.target?.name || '-' }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-secondary small">Mode Profil:</span>
                  <span class="badge bg-blue-lt small">{{ rerunConfirmJob.scan_profile?.name || '-' }}</span>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer bg-transparent border-top-0 pt-0">
            <div class="w-100">
              <div class="row g-2">
                <div class="col-6">
                  <button
                    type="button"
                    class="btn btn-secondary w-100"
                    :disabled="isRerunningId !== null"
                    @click="closeRerunModal"
                  >
                    Batal
                  </button>
                </div>
                <div class="col-6">
                  <button
                    type="button"
                    class="btn btn-primary w-100"
                    :disabled="isRerunningId !== null"
                    @click="confirmAndExecuteRerun"
                  >
                    <svg
                      v-if="isRerunningId !== null"
                      xmlns="http://www.w3.org/2000/svg"
                      class="icon animate-spin me-1"
                      width="18"
                      height="18"
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
                    <span>{{ isRerunningId !== null ? 'Memproses...' : 'Ya, Rerun' }}</span>
                  </button>
                </div>
              </div>
            </div>
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

/* Toast Slide Transition */
.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
}

.toast-slide-enter-from {
  opacity: 0;
  transform: translateY(-16px) scale(0.96);
}

.toast-slide-leave-to {
  opacity: 0;
  transform: translateY(-12px) scale(0.96);
}

/* Restyled Search Bar */
.search-box-wrapper {
  display: flex;
  align-items: center;
}

.modern-search-input {
  width: 270px;
  border-radius: 8px;
  padding-right: 34px;
  background-color: rgba(var(--tblr-body-bg-rgb), 0.7);
  font-size: 0.825rem;
  border: 1px solid var(--tblr-border-color);
}

.modern-search-input:focus {
  border-color: var(--tblr-primary);
  background-color: var(--tblr-bg-surface);
  box-shadow: 0 0 0 3px rgba(var(--tblr-primary-rgb), 0.18);
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
  background: rgba(var(--tblr-body-color-rgb), 0.1);
  z-index: 5;
  transition: all 0.15s ease;
}

.search-clear-btn:hover {
  background: rgba(214, 57, 57, 0.2);
  color: var(--tblr-danger) !important;
}

/* Scan Job Clickable Row */
.scan-job-row {
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.scan-job-row:hover > td {
  background-color: rgba(var(--tblr-primary-rgb), 0.05);
}

.scan-job-row.table-active > td {
  background-color: rgba(var(--tblr-primary-rgb), 0.08);
}

.scan-job-row td:first-child .btn,
.scan-job-row td:first-child .btn svg {
  color: #ffffff !important;
  stroke: #ffffff !important;
}
</style>
