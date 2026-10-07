<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '../services/api'
import { useAuth } from '../composables/useAuth'
import { getFailureExplanation } from '../services/failureExplainer'

const { currentUser } = useAuth()

interface ReportProject {
  id: number
  name: string
  code: string
}

interface ReportScanJob {
  id: number
  code: string
  status: string
  project?: ReportProject
}

interface ReportGenerator {
  id: number
  name: string
}

interface ReportItem {
  id: number
  scan_job_id: number
  generated_by?: number
  format: string
  metadata?: any
  summary?: any
  created_at?: string
  scan_job?: ReportScanJob
  scanJob?: ReportScanJob
  generator?: ReportGenerator
}

interface CompletedScanJobOption {
  id: number
  code: string
  status: string
  target_type?: string
  project?: { name: string; code: string }
}

const reports = ref<ReportItem[]>([])
const completedScanJobs = ref<CompletedScanJobOption[]>([])
const isLoading = ref(true)
const isGenerating = ref(false)

// Filter states
const searchQuery = ref('')
const filterFormat = ref<string>('all')

function resetFilters() {
  searchQuery.value = ''
  filterFormat.value = 'all'
}

// Modal Generate state
const isGenerateModalOpen = ref(false)
const selectedScanJobId = ref<string | number>('')
const formError = ref<string | null>(null)
const formSuccess = ref<string | null>(null)

// Modal Preview state
const isPreviewModalOpen = ref(false)
const previewingReport = ref<ReportItem | null>(null)
const previewActiveTab = ref<'summary' | 'findings' | 'raw'>('summary')
const previewFindingFilter = ref<string>('all')
const previewSearch = ref<string>('')
const copiedJson = ref(false)

const previewContent = computed(() => {
  const meta = previewingReport.value?.metadata || previewingReport.value?.summary || {}
  return meta.content || meta
})

const previewRiskSummary = computed(() => {
  const content = previewContent.value
  return content?.risk_summary || previewingReport.value?.metadata?.risk_summary || {
    total: 0,
    critical: 0,
    high: 0,
    medium: 0,
    low: 0,
    informational: 0
  }
})

const previewFindings = computed(() => {
  const findings = previewContent.value?.findings
  if (!Array.isArray(findings)) return []
  const query = previewSearch.value.trim().toLowerCase()
  const filter = previewFindingFilter.value
  return findings.filter((f: any) => {
    if (filter !== 'all' && f.severity?.toLowerCase() !== filter.toLowerCase()) return false
    if (!query) return true
    const title = (f.title || '').toLowerCase()
    const code = (f.code || '').toLowerCase()
    const engine = (f.engine_key || '').toLowerCase()
    const loc = (f.location?.file_path || f.location?.endpoint || '').toLowerCase()
    return title.includes(query) || code.includes(query) || engine.includes(query) || loc.includes(query)
  })
})

const previewScanRuns = computed(() => {
  const runs = previewContent.value?.scan?.runs
  return Array.isArray(runs) ? runs : []
})

const previewFailedEngines = computed(() => {
  const list = previewContent.value?.execution_summary?.failed_engines || []
  if (Array.isArray(list) && list.length > 0) {
    return list
  }
  const failedRuns = previewScanRuns.value.filter((r: any) => r.status === 'failed')
  return failedRuns.map((r: any) => ({
    engine_key: r.engine_key,
    failure_reason: r.failure_reason || 'ENGINE_PROCESS_FAILED',
    exit_code: r.exit_code
  }))
})

function getSeverityBadge(sev?: string) {
  switch (sev?.toLowerCase()) {
    case 'critical':
      return 'bg-danger text-danger-fg'
    case 'high':
      return 'bg-orange text-orange-fg'
    case 'medium':
      return 'bg-warning text-warning-fg'
    case 'low':
      return 'bg-azure text-azure-fg'
    case 'informational':
    case 'info':
      return 'bg-secondary text-secondary-fg'
    default:
      return 'bg-secondary text-secondary-fg'
  }
}

function copyJsonMetadata() {
  if (!previewingReport.value) return
  const data = JSON.stringify(previewingReport.value.metadata || previewingReport.value.summary, null, 2)
  navigator.clipboard.writeText(data)
  copiedJson.value = true
  setTimeout(() => {
    copiedJson.value = false
  }, 2000)
}

const canGenerate = computed(() => {
  const role = currentUser.value?.role?.name || ''
  return ['super_admin', 'security_admin', 'security_analyst'].includes(role)
})

async function loadData() {
  isLoading.value = true
  try {
    const [repRes, jobRes] = await Promise.all([
      apiFetch('/api/reports'),
      apiFetch('/api/scan-jobs').catch(() => ({ scan_jobs: [] }))
    ])

    reports.value = Array.isArray(repRes?.reports) ? repRes.reports : (Array.isArray(repRes) ? repRes : [])

    const jobs = Array.isArray(jobRes?.scan_jobs) ? jobRes.scan_jobs : (Array.isArray(jobRes) ? jobRes : [])
    // Filter only completed or failed scan jobs
    completedScanJobs.value = jobs.filter((j: any) => ['completed', 'failed'].includes(j.status))
  } catch (err: any) {
    console.error('Gagal memuat daftar laporan:', err)
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  const list = reports.value
  const total = list.length
  const totalFindings = list.reduce((acc, r) => acc + (r.metadata?.finding_count || 0), 0)
  const uniqueProjects = new Set(list.map(r => r.scanJob?.project?.name || r.scan_job?.project?.name).filter(Boolean)).size
  const pdfReady = list.filter(r => r.format?.toLowerCase().includes('pdf') || true).length
  return { total, totalFindings, uniqueProjects, pdfReady }
})

const filteredReports = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  return reports.value.filter(r => {
    if (filterFormat.value !== 'all' && !r.format?.toLowerCase().includes(filterFormat.value.toLowerCase())) return false

    if (!query) return true
    const idStr = String(r.id)
    const jobCode = (r.scanJob?.code || r.scan_job?.code || '').toLowerCase()
    const projName = (r.scanJob?.project?.name || r.scan_job?.project?.name || '').toLowerCase()
    const genName = (r.generator?.name || '').toLowerCase()
    return idStr.includes(query) || jobCode.includes(query) || projName.includes(query) || genName.includes(query)
  })
})

function openGenerateModal() {
  formError.value = null
  formSuccess.value = null
  selectedScanJobId.value = completedScanJobs.value[0]?.id || ''
  isGenerateModalOpen.value = true
}

function closeGenerateModal() {
  isGenerateModalOpen.value = false
  formError.value = null
  formSuccess.value = null
}

function openPreviewModal(report: ReportItem) {
  previewingReport.value = report
  previewActiveTab.value = 'summary'
  previewFindingFilter.value = 'all'
  previewSearch.value = ''
  copiedJson.value = false
  isPreviewModalOpen.value = true
}

function closePreviewModal() {
  isPreviewModalOpen.value = false
  previewingReport.value = null
  copiedJson.value = false
}

async function generateReport() {
  if (!selectedScanJobId.value) {
    formError.value = 'Pilih pekerjaan scan yang telah selesai.'
    return
  }

  isGenerating.value = true
  formError.value = null
  formSuccess.value = null

  try {
    const payload = {
      scan_job_id: Number(selectedScanJobId.value)
    }

    const res = await apiFetch('/api/reports', {
      method: 'POST',
      body: JSON.stringify(payload)
    })

    formSuccess.value = `Laporan #${res.report?.id || ''} berhasil dibuat.`
    await loadData()
    setTimeout(() => {
      closeGenerateModal()
    }, 600)
  } catch (err: any) {
    console.error('Gagal membuat laporan:', err)
    formError.value = err?.data?.message || err?.message || 'Gagal membuat laporan untuk pekerjaan scan ini.'
  } finally {
    isGenerating.value = false
  }
}

function downloadPdf(report: ReportItem) {
  // Use anchor to download PDF directly with session
  window.open(`/api/reports/${report.id}/download-pdf`, '_blank')
}

const isSyncing = ref(false)
const syncSuccess = ref<string | null>(null)
const syncError = ref<string | null>(null)

async function syncReport(report: ReportItem) {
  if (!report?.id || isSyncing.value) return
  isSyncing.value = true
  syncSuccess.value = null
  syncError.value = null

  try {
    const res = await apiFetch(`/api/reports/${report.id}/sync`, {
      method: 'POST'
    })
    if (res.report) {
      if (previewingReport.value?.id === report.id) {
        previewingReport.value = res.report
      }
      const idx = reports.value.findIndex(r => r.id === report.id)
      if (idx !== -1) {
        reports.value[idx] = res.report
      }
      syncSuccess.value = res.message || 'Laporan berhasil disinkronisasi dengan temuan scan terbaru.'
      setTimeout(() => {
        syncSuccess.value = null
      }, 5000)
    }
  } catch (err: any) {
    console.error('Gagal sinkronisasi laporan:', err)
    syncError.value = err?.data?.message || err?.message || 'Gagal sinkronisasi laporan.'
    setTimeout(() => {
      syncError.value = null
    }, 5000)
  } finally {
    isSyncing.value = false
  }
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
              Analisis & Pelaporan Eksekutif
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 17l0 -5" /><path d="M12 17l0 -1" /><path d="M15 17l0 -3" /></svg>
              <span>Laporan Keamanan (Security Audit Reports)</span>
            </h2>
          </div>
          <div class="col-auto ms-auto d-print-none d-flex align-items-center gap-2">
            <button
              v-if="canGenerate"
              type="button"
              class="btn btn-primary d-flex align-items-center gap-1"
              @click="openGenerateModal"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
              <span>Generate Laporan Baru</span>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4">{{ stats.total }} Laporan</div>
                  <div class="text-secondary small">Total Dokumen Laporan</div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-danger">{{ stats.pdfReady }} PDF</div>
                  <div class="text-secondary small">Siap Diunduh Resmi</div>
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
                  <span class="bg-warning-lt text-warning avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-warning">{{ stats.totalFindings }}</div>
                  <div class="text-secondary small">Total Celah Terdokumentasi</div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v8h-6z" /><path d="M4 16h6v4h-6z" /><path d="M14 12h6v8h-6z" /><path d="M14 4h6v4h-6z" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-teal">{{ stats.uniqueProjects }} Proyek</div>
                  <div class="text-secondary small">Cakupan Proyek Teruji</div>
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
            <span>Daftar Laporan Keamanan</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{ filteredReports.length }}</span>
          </h3>

          <!-- Filter Toolbar -->
          <div class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2">
            <!-- Filter Format -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
              </span>
              <select
                v-model="filterFormat"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterFormat !== 'all' }"
                title="Filter Format Laporan"
              >
                <option value="all">Semua Format</option>
                <option value="pdf">PDF</option>
                <option value="json">JSON Eksekutif</option>
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
                  placeholder="Cari ID laporan, pekerjaan scan, proyek..."
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
              v-if="searchQuery || filterFormat !== 'all'"
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
          <div class="text-secondary mt-2">Memuat daftar laporan keamanan...</div>
        </div>

        <!-- Empty State -->
        <div v-else-if="filteredReports.length === 0" class="card-body text-center py-5">
          <div class="empty">
            <div class="empty-icon">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-secondary" width="32" height="32" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
            </div>
            <p class="empty-title">Belum ada laporan keamanan</p>
            <p class="empty-subtitle text-secondary">
              Laporan dapat digenerate secara instan dari pekerjaan scan yang telah selesai dijalankan.
            </p>
            <div v-if="canGenerate" class="empty-action">
              <button class="btn btn-primary" @click="openGenerateModal">
                Generate Laporan Baru
              </button>
            </div>
          </div>
        </div>

        <!-- Data Table (Desktop & Tablet >= 768px) -->
        <div v-else class="table-responsive d-none d-md-block">
          <table class="table table-vcenter card-table table-hover">
            <thead>
              <tr>
                <th style="width: 110px;">ID Laporan</th>
                <th>Pekerjaan Scan & Proyek Terkait</th>
                <th>Temuan Tercatat</th>
                <th style="width: 100px;">Format</th>
                <th>Dibuat Oleh</th>
                <th style="width: 140px;">Waktu Terbit</th>
                <th class="text-end" style="min-width: 175px;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in filteredReports" :key="r.id">
                <td>
                  <span class="badge bg-primary-lt font-monospace fs-5 fw-bold">
                    #REP-{{ String(r.id).padStart(4, '0') }}
                  </span>
                </td>
                <td>
                  <div class="fw-bold font-monospace text-primary">
                    {{ r.scanJob?.code || r.scan_job?.code || '-' }}
                  </div>
                  <div class="text-secondary small d-flex align-items-center gap-1 mt-1">
                    <span class="badge bg-blue-lt font-monospace">{{ r.scanJob?.project?.code || r.scan_job?.project?.code || 'PRJ' }}</span>
                    <span>{{ r.scanJob?.project?.name || r.scan_job?.project?.name || '-' }}</span>
                  </div>
                </td>
                <td>
                  <div class="d-flex align-items-center gap-1">
                    <span class="badge bg-warning-lt text-warning font-monospace">
                      {{ r.metadata?.finding_count || 0 }} Temuan
                    </span>
                  </div>
                </td>
                <td>
                  <span class="badge bg-danger-lt text-danger font-monospace text-uppercase">
                    PDF & JSON
                  </span>
                </td>
                <td>
                  <div class="small fw-medium">{{ r.generator?.name || 'Sistem TAMENG' }}</div>
                </td>
                <td>
                  <div class="small text-secondary">{{ formatDate(r.created_at) }}</div>
                </td>
                <td class="text-end">
                  <div class="d-inline-flex align-items-center justify-content-end gap-1">
                    <button
                      class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                      :disabled="isSyncing"
                      @click="syncReport(r)"
                      title="Sinkronkan dengan data scan terbaru"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -5v5h5" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 5v-5h-5" /></svg>
                      <span class="d-none d-lg-inline">Sinkronkan</span>
                    </button>
                    <button
                      class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1"
                      @click="downloadPdf(r)"
                      title="Unduh Dokumen PDF Resmi"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
                      <span>PDF</span>
                    </button>
                    <button
                      class="btn btn-sm btn-secondary d-inline-flex align-items-center gap-1"
                      @click="openPreviewModal(r)"
                      title="Lihat Pratinjau Ringkasan JSON"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" /><path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" /></svg>
                      <span>Pratinjau</span>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Card List (< 768px) -->
        <div class="d-md-none list-group list-group-flush">
          <div
            v-for="r in filteredReports"
            :key="r.id"
            class="list-group-item px-3 py-2 cursor-pointer"
            style="cursor: pointer;"
            @click="openPreviewModal(r)"
          >
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="badge bg-primary-lt font-monospace fw-bold">
                #REP-{{ String(r.id).padStart(4, '0') }}
              </span>
              <span class="badge bg-warning-lt text-warning font-monospace">
                {{ r.metadata?.finding_count || 0 }} Temuan
              </span>
            </div>
            <div class="fw-bold font-monospace text-primary mb-1">
              {{ r.scanJob?.code || r.scan_job?.code || '-' }}
            </div>
            <div class="d-flex align-items-center gap-1 text-secondary small mb-1">
              <span class="badge bg-blue-lt font-monospace">{{ r.scanJob?.project?.code || r.scan_job?.project?.code || 'PRJ' }}</span>
              <span class="text-truncate">{{ r.scanJob?.project?.name || r.scan_job?.project?.name || '-' }}</span>
            </div>
            <div class="d-flex align-items-center justify-content-between text-secondary small mt-2 pt-1 border-top">
              <span>{{ formatDate(r.created_at) }}</span>
            </div>
            <div class="d-flex align-items-center gap-2 pt-2">
              <button
                type="button"
                class="btn btn-sm btn-primary flex-fill d-inline-flex align-items-center justify-content-center gap-1"
                @click.stop="downloadPdf(r)"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
                <span>Unduh PDF</span>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-secondary flex-fill"
                @click.stop="openPreviewModal(r)"
              >
                Pratinjau
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Generate Laporan Baru -->
    <div
      v-if="isGenerateModalOpen"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">Generate Laporan Audit Keamanan Baru</h5>
            <button
              type="button"
              class="btn-close"
              :disabled="isGenerating"
              @click="closeGenerateModal"
            ></button>
          </div>
          <form @submit.prevent="generateReport">
            <div class="modal-body">
              <div v-if="formError" class="alert alert-danger alert-dismissible mb-3">
                {{ formError }}
              </div>
              <div v-if="formSuccess" class="alert alert-success mb-3">
                {{ formSuccess }}
              </div>

              <div class="mb-3">
                <label class="form-label required">Pilih Pekerjaan Scan Selesai</label>
                <select
                  v-model="selectedScanJobId"
                  class="form-select"
                  required
                >
                  <option value="" disabled>Pilih Pekerjaan Scan...</option>
                  <option
                    v-for="job in completedScanJobs"
                    :key="job.id"
                    :value="job.id"
                  >
                    {{ job.code }} ({{ job.status }}) - {{ job.project?.name || 'Proyek' }}
                  </option>
                </select>
                <small v-if="completedScanJobs.length === 0" class="text-danger small mt-1 d-flex align-items-center gap-1">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
                  <span>Belum ada pekerjaan scan yang selesai dijalankan. Lakukan scan terlebih dahulu di menu Pekerjaan Scan.</span>
                </small>
                <small v-else class="form-hint">
                  Laporan akan mengompilasi seluruh temuan dari engine yang telah selesai berjalan pada scan ini.
                </small>
              </div>

              <div class="mb-3">
                <label class="form-label">Format Dokumen Hasil</label>
                <input
                  type="text"
                  class="form-control"
                  value="PDF Resmi & JSON Eksekutif (Otomatis)"
                  disabled
                />
              </div>
            </div>

            <div class="modal-footer d-flex flex-column-reverse flex-sm-row align-items-stretch align-items-sm-center justify-content-sm-between gap-2">
              <button
                type="button"
                class="btn btn-secondary w-100 w-sm-auto justify-content-center"
                :disabled="isGenerating"
                @click="closeGenerateModal"
              >
                Batal
              </button>
              <button
                type="submit"
                class="btn btn-primary d-flex align-items-center justify-content-center gap-1 w-100 w-sm-auto py-2"
                :disabled="isGenerating || completedScanJobs.length === 0"
              >
                <span v-if="isGenerating" class="spinner-border spinner-border-sm me-1" role="status"></span>
                <span>{{ isGenerating ? 'Memproses...' : 'Buat Laporan Sekarang' }}</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal Pratinjau Ringkasan Laporan -->
    <div
      v-if="isPreviewModalOpen && previewingReport"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header d-flex align-items-center justify-content-between py-3 px-4">
            <div>
              <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                <span class="badge bg-primary-lt font-monospace fw-bold" style="font-size: 0.8rem;">
                  #REP-{{ String(previewingReport.id).padStart(4, '0') }}
                </span>
                <span class="badge bg-secondary-lt font-monospace">
                  {{ previewingReport.scanJob?.code || previewingReport.scan_job?.code || 'SCAN-JOB' }}
                </span>
                <span
                  class="badge"
                  :class="previewContent.scan?.status === 'completed' ? 'bg-success-lt text-success' : 'bg-warning-lt text-warning'"
                >
                  {{ previewContent.scan?.status_label || previewingReport.scanJob?.status || 'Selesai' }}
                </span>
                <span v-if="previewingReport.metadata?.scanner_execution" class="badge bg-teal-lt text-teal">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                  Scanner Real
                </span>
                <span v-else class="badge bg-secondary-lt">
                  Simulasi
                </span>
              </div>
              <h4 class="modal-title m-0 fw-bold">
                Ringkasan Eksekutif Hasil Audit Keamanan
              </h4>
            </div>
            <div class="d-flex align-items-center gap-2">
              <button
                type="button"
                class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1"
                :disabled="isSyncing"
                title="Perbarui laporan dengan data scan terbaru"
                @click="syncReport(previewingReport)"
              >
                <span v-if="isSyncing" class="spinner-border spinner-border-sm" role="status"></span>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -5v5h5" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 5v-5h-5" /></svg>
                <span>{{ isSyncing ? 'Menyinkronkan...' : 'Sinkronkan Scan' }}</span>
              </button>
              <button type="button" class="btn-close" @click="closePreviewModal"></button>
            </div>
          </div>

          <!-- Alert Sync Status -->
          <div v-if="syncSuccess" class="alert alert-success alert-dismissible mx-4 mt-3 mb-0" role="alert">
            <div class="d-flex align-items-center">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon me-2" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
              <div>{{ syncSuccess }}</div>
            </div>
            <button type="button" class="btn-close" @click="syncSuccess = null"></button>
          </div>
          <div v-if="syncError" class="alert alert-danger alert-dismissible mx-4 mt-3 mb-0" role="alert">
            <div class="d-flex align-items-center">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon me-2" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 16v.01" /><path d="M12 3a9 9 0 1 0 9 9a9 9 0 0 0 -9 -9" /></svg>
              <div>{{ syncError }}</div>
            </div>
            <button type="button" class="btn-close" @click="syncError = null"></button>
          </div>

          <!-- Nav Tabs in Modal Header -->
          <div class="card-header p-0 border-bottom bg-surface">
            <ul class="nav nav-tabs card-header-tabs m-0 px-4">
              <li class="nav-item">
                <button
                  type="button"
                  class="nav-link py-2 d-flex align-items-center gap-1"
                  :class="{ active: previewActiveTab === 'summary' }"
                  @click="previewActiveTab = 'summary'"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" /></svg>
                  <span>Ringkasan & Profil Audit</span>
                </button>
              </li>
              <li class="nav-item">
                <button
                  type="button"
                  class="nav-link py-2 d-flex align-items-center gap-1"
                  :class="{ active: previewActiveTab === 'findings' }"
                  @click="previewActiveTab = 'findings'"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 8l0 4" /><path d="M12 16l.01 0" /></svg>
                  <span>Temuan Kerentanan</span>
                  <span class="badge bg-danger-lt rounded-pill ms-1">{{ previewRiskSummary.total }}</span>
                </button>
              </li>
              <li class="nav-item">
                <button
                  type="button"
                  class="nav-link py-2 d-flex align-items-center gap-1"
                  :class="{ active: previewActiveTab === 'raw' }"
                  @click="previewActiveTab = 'raw'"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs font-monospace" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 8l-4 4l4 4" /><path d="M17 8l4 4l-4 4" /><path d="M14 4l-4 16" /></svg>
                  <span>Metadata JSON</span>
                </button>
              </li>
            </ul>
          </div>

          <div class="modal-body p-4">
            <!-- TAB 1: RINGKASAN & PROFIL AUDIT -->
            <div v-if="previewActiveTab === 'summary'" class="d-flex flex-column gap-3">
              <!-- Risk Summary Counters -->
              <div class="row g-2">
                <div class="col-4 col-sm-2">
                  <div class="card card-sm border-0 bg-danger-lt text-center py-2 px-1">
                    <div class="text-uppercase fw-bold font-monospace" style="font-size: 0.7rem;">Critical</div>
                    <div class="h2 mb-0 fw-bold text-danger">{{ previewRiskSummary.critical || 0 }}</div>
                  </div>
                </div>
                <div class="col-4 col-sm-2">
                  <div class="card card-sm border-0 bg-orange-lt text-center py-2 px-1">
                    <div class="text-uppercase fw-bold font-monospace" style="font-size: 0.7rem;">High</div>
                    <div class="h2 mb-0 fw-bold text-orange">{{ previewRiskSummary.high || 0 }}</div>
                  </div>
                </div>
                <div class="col-4 col-sm-2">
                  <div class="card card-sm border-0 bg-warning-lt text-center py-2 px-1">
                    <div class="text-uppercase fw-bold font-monospace" style="font-size: 0.7rem;">Medium</div>
                    <div class="h2 mb-0 fw-bold text-warning">{{ previewRiskSummary.medium || 0 }}</div>
                  </div>
                </div>
                <div class="col-4 col-sm-2">
                  <div class="card card-sm border-0 bg-azure-lt text-center py-2 px-1">
                    <div class="text-uppercase fw-bold font-monospace" style="font-size: 0.7rem;">Low</div>
                    <div class="h2 mb-0 fw-bold text-azure">{{ previewRiskSummary.low || 0 }}</div>
                  </div>
                </div>
                <div class="col-4 col-sm-2">
                  <div class="card card-sm border-0 bg-secondary-lt text-center py-2 px-1">
                    <div class="text-uppercase fw-bold font-monospace" style="font-size: 0.7rem;">Info</div>
                    <div class="h2 mb-0 fw-bold text-secondary">{{ previewRiskSummary.informational || 0 }}</div>
                  </div>
                </div>
                <div class="col-4 col-sm-2">
                  <div class="card card-sm border-0 bg-primary text-primary-fg text-center py-2 px-1">
                    <div class="text-uppercase fw-bold font-monospace" style="font-size: 0.7rem;">Total</div>
                    <div class="h2 mb-0 fw-bold">{{ previewRiskSummary.total || 0 }}</div>
                  </div>
                </div>
              </div>

              <!-- Executive Description / Overview -->
              <div class="card border-0 shadow-sm bg-body-tertiary">
                <div class="card-body p-3">
                  <div class="d-flex align-items-center gap-2 mb-2 text-primary fw-bold">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 12h6" /><path d="M9 16h6" /><path d="M12 3c7.2 0 9 1.8 9 9s-1.8 9 -9 9s-9 -1.8 -9 -9s1.8 -9 9 -9z" /></svg>
                    <span>Deskripsi & Ruang Lingkup Audit</span>
                  </div>
                  <p class="mb-0 text-secondary" style="line-height: 1.6; font-size: 0.925rem;">
                    {{ previewContent.description || 'Laporan audit keamanan standar TAMENG dihasilkan dari rangkaian pemindaian otomatis DevSecOps.' }}
                  </p>
                </div>
              </div>

              <!-- Context Details: Target, Project, Scan Job, Generator -->
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header py-2 px-3 bg-surface border-bottom">
                      <span class="fw-bold small text-uppercase font-monospace text-secondary">Target & Proyek Pemindaian</span>
                    </div>
                    <div class="card-body p-3">
                      <table class="table table-sm table-borderless m-0 small">
                        <tbody>
                          <tr>
                            <td class="text-secondary ps-0" style="width: 140px;">Proyek:</td>
                            <td class="fw-bold text-reset">
                              {{ previewingReport.scanJob?.project?.name || previewingReport.scan_job?.project?.name || previewContent.project?.name || '-' }}
                              <span v-if="previewContent.project?.code" class="text-muted font-monospace">({{ previewContent.project.code }})</span>
                            </td>
                          </tr>
                          <tr>
                            <td class="text-secondary ps-0">Target / URL:</td>
                            <td class="font-monospace text-primary">
                              {{ previewContent.target?.base_url || previewContent.target?.hostname || previewContent.repository?.url || previewingReport.scanJob?.project?.name || '-' }}
                            </td>
                          </tr>
                          <tr v-if="previewContent.repository?.default_branch">
                            <td class="text-secondary ps-0">Cabang Git:</td>
                            <td class="font-monospace text-reset">{{ previewContent.repository.default_branch }}</td>
                          </tr>
                          <tr>
                            <td class="text-secondary ps-0">Scan Profile:</td>
                            <td class="text-reset">{{ previewContent.scan?.profile?.name || 'Standard Full Security Suite' }}</td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>

                <div class="col-md-6">
                  <div class="card h-100 border-0 shadow-sm">
                    <div class="card-header py-2 px-3 bg-surface border-bottom">
                      <span class="fw-bold small text-uppercase font-monospace text-secondary">Informasi Eksekusi & Audit</span>
                    </div>
                    <div class="card-body p-3">
                      <table class="table table-sm table-borderless m-0 small">
                        <tbody>
                          <tr>
                            <td class="text-secondary ps-0" style="width: 140px;">Scan Job ID:</td>
                            <td class="font-monospace fw-bold text-reset">
                              {{ previewingReport.scanJob?.code || previewingReport.scan_job?.code || '-' }}
                            </td>
                          </tr>
                          <tr>
                            <td class="text-secondary ps-0">Tanggal Terbit:</td>
                            <td class="text-reset">{{ formatDate(previewingReport.created_at) }}</td>
                          </tr>
                          <tr>
                            <td class="text-secondary ps-0">Auditor / Pembuat:</td>
                            <td class="text-reset">{{ previewingReport.generator?.name || previewContent.generated_by?.name || 'Admin TAMENG' }}</td>
                          </tr>
                          <tr>
                            <td class="text-secondary ps-0">Waktu Scan:</td>
                            <td class="font-monospace small text-reset">
                              {{ previewContent.scan?.started_at ? formatDate(previewContent.scan.started_at) : '-' }}
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Scanner Engine Execution Status -->
              <div class="card border-0 shadow-sm">
                <div class="card-header py-2 px-3 bg-surface border-bottom d-flex align-items-center justify-content-between">
                  <span class="fw-bold small text-uppercase font-monospace text-secondary">Status Scanner Engines</span>
                  <span class="badge bg-surface-secondary text-secondary">
                    {{ previewScanRuns.length }} Engine Terkonfigurasi
                  </span>
                </div>
                <div class="card-body p-3">
                  <div class="d-flex flex-wrap gap-2 mb-2">
                    <span
                      v-for="(run, rIdx) in previewScanRuns"
                      :key="rIdx"
                      class="badge py-2 px-2 d-inline-flex align-items-center gap-1 font-monospace"
                      :class="run.status === 'completed' ? 'bg-success-lt text-success' : (run.status === 'failed' ? 'bg-danger-lt text-danger' : 'bg-secondary-lt text-secondary')"
                    >
                      <svg v-if="run.status === 'completed'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="12" height="12" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                      <strong>{{ run.engine_key?.toUpperCase() }}</strong>
                      <span class="opacity-75">({{ run.status }})</span>
                    </span>
                  </div>
                  <!-- Detail Analisis & Penyebab Engine Gagal -->
                  <div v-if="previewFailedEngines.length" class="d-flex flex-column gap-2 mt-3">
                    <div class="fw-bold small text-danger d-flex align-items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.871l-8.106 -13.534a1.914 1.914 0 0 0 -3.274 0z" /><path d="M12 16h.01" /></svg>
                      <span>Analisis & Rincian Penyebab Kegagalan Engine ({{ previewFailedEngines.length }} Engine):</span>
                    </div>

                    <div
                      v-for="(fe, fIdx) in previewFailedEngines"
                      :key="fIdx"
                      class="card border border-danger-subtle shadow-none bg-danger-lt p-3"
                    >
                      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                        <div class="d-flex align-items-center gap-2">
                          <span class="badge bg-danger text-danger-fg text-uppercase font-monospace fw-bold px-2 py-1">
                            {{ fe.engine_key }}
                          </span>
                          <strong class="text-danger-emphasis" style="font-size: 0.95rem;">
                            {{ getFailureExplanation(fe.failure_reason, fe.engine_key).title }}
                          </strong>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                          <span class="text-muted small font-monospace">Error Code:</span>
                          <code class="text-danger small font-monospace bg-surface px-2 py-0 rounded border border-danger-subtle">
                            {{ fe.failure_reason || 'ENGINE_PROCESS_FAILED' }}
                          </code>
                        </div>
                      </div>

                      <div class="text-secondary small mb-2" style="line-height: 1.6;">
                        <strong class="text-danger-emphasis">Penyebab Masalah:</strong>
                        {{ getFailureExplanation(fe.failure_reason, fe.engine_key).cause }}
                      </div>

                      <div class="bg-surface p-2 rounded small text-secondary border border-danger-subtle d-flex align-items-start gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-primary mt-1 flex-shrink-0" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 11l3 3l8 -8" /><path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" /></svg>
                        <div>
                          <strong class="text-primary-emphasis">Rekomendasi Tindak Lanjut:</strong>
                          <span> {{ getFailureExplanation(fe.failure_reason, fe.engine_key).remediation }}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Audit Notes & Guidance -->
              <div v-if="previewContent.notes?.length" class="card border-0 shadow-sm bg-body-tertiary">
                <div class="card-body p-3">
                  <div class="d-flex align-items-center gap-2 mb-2 text-teal fw-bold">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 11l3 3l8 -8" /><path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" /></svg>
                    <span>Catatan Kepatuhan & Rekomendasi Audit</span>
                  </div>
                  <ul class="list-unstyled mb-0 small text-secondary d-flex flex-column gap-2">
                    <li v-for="(note, nIdx) in previewContent.notes" :key="nIdx" class="d-flex align-items-start gap-2">
                      <span class="badge bg-teal-lt text-teal rounded-circle px-2 py-1 mt-0 flex-shrink-0">{{ nIdx + 1 }}</span>
                      <span style="line-height: 1.5;">{{ note }}</span>
                    </li>
                  </ul>
                </div>
              </div>
            </div>

            <!-- TAB 2: TEMUAN KERENTANAN -->
            <div v-else-if="previewActiveTab === 'findings'" class="d-flex flex-column gap-3">
              <!-- Filter & Search Toolbar -->
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                <div class="d-flex align-items-center gap-1 flex-wrap">
                  <button
                    type="button"
                    class="btn btn-xs"
                    :class="previewFindingFilter === 'all' ? 'btn-primary' : 'btn-outline-secondary'"
                    @click="previewFindingFilter = 'all'"
                  >
                    Semua ({{ previewContent.findings?.length || 0 }})
                  </button>
                  <button
                    type="button"
                    class="btn btn-xs"
                    :class="previewFindingFilter === 'critical' ? 'btn-danger' : 'btn-outline-danger'"
                    @click="previewFindingFilter = 'critical'"
                  >
                    Critical ({{ previewRiskSummary.critical || 0 }})
                  </button>
                  <button
                    type="button"
                    class="btn btn-xs"
                    :class="previewFindingFilter === 'high' ? 'btn-warning text-dark' : 'btn-outline-warning'"
                    @click="previewFindingFilter = 'high'"
                  >
                    High ({{ previewRiskSummary.high || 0 }})
                  </button>
                  <button
                    type="button"
                    class="btn btn-xs"
                    :class="previewFindingFilter === 'medium' ? 'btn-yellow text-dark' : 'btn-outline-secondary'"
                    @click="previewFindingFilter = 'medium'"
                  >
                    Medium ({{ previewRiskSummary.medium || 0 }})
                  </button>
                  <button
                    type="button"
                    class="btn btn-xs"
                    :class="previewFindingFilter === 'low' ? 'btn-info text-white' : 'btn-outline-secondary'"
                    @click="previewFindingFilter = 'low'"
                  >
                    Low ({{ previewRiskSummary.low || 0 }})
                  </button>
                </div>
                <div style="min-width: 200px;">
                  <input
                    v-model="previewSearch"
                    type="text"
                    class="form-control form-control-sm"
                    placeholder="Cari temuan..."
                  />
                </div>
              </div>

              <!-- Findings Table -->
              <div class="table-responsive border rounded" style="max-height: 480px; overflow-y: auto;">
                <table class="table table-vcenter table-hover table-striped mb-0">
                  <thead class="sticky-top bg-surface border-bottom small text-uppercase">
                    <tr>
                      <th style="width: 100px;">Tingkat</th>
                      <th>Temuan & Kode</th>
                      <th style="width: 120px;">Engine</th>
                      <th>Lokasi / Endpoint</th>
                      <th style="width: 140px;">Kepatuhan</th>
                    </tr>
                  </thead>
                  <tbody class="small">
                    <tr v-for="f in previewFindings" :key="f.id">
                      <td>
                        <span class="badge" :class="getSeverityBadge(f.severity)">
                          {{ f.severity?.toUpperCase() }}
                        </span>
                      </td>
                      <td>
                        <div class="fw-bold text-reset mb-1">{{ f.title }}</div>
                        <div class="d-flex align-items-center gap-1 font-monospace text-muted" style="font-size: 0.75rem;">
                          <span>{{ f.code }}</span>
                          <span v-if="f.rule_id" class="badge bg-secondary-lt py-0 px-1">{{ f.rule_id }}</span>
                        </div>
                      </td>
                      <td>
                        <span class="badge bg-secondary-lt font-monospace text-uppercase">{{ f.engine_key }}</span>
                      </td>
                      <td>
                        <div class="text-truncate font-monospace small" style="max-width: 260px;" :title="f.location?.file_path || f.location?.endpoint || '-'">
                          {{ f.location?.file_path || f.location?.endpoint || '-' }}
                          <span v-if="f.location?.line_start" class="text-muted">:{{ f.location.line_start }}</span>
                        </div>
                      </td>
                      <td>
                        <div class="d-flex flex-column gap-1" style="font-size: 0.72rem;">
                          <span v-if="f.classification?.cwe" class="badge bg-secondary-lt font-monospace text-truncate" style="max-width: 130px;">
                            {{ f.classification.cwe }}
                          </span>
                          <span v-if="f.classification?.cvss" class="badge bg-danger-lt font-monospace">
                            CVSS {{ f.classification.cvss }}
                          </span>
                          <span v-if="!f.classification?.cwe && !f.classification?.cvss" class="text-muted">-</span>
                        </div>
                      </td>
                    </tr>
                    <tr v-if="previewFindings.length === 0">
                      <td colspan="5" class="text-center py-4 text-secondary">
                        Tidak ada temuan yang sesuai dengan filter atau kata kunci.
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- TAB 3: METADATA MENTAH (JSON) -->
            <div v-else-if="previewActiveTab === 'raw'" class="d-flex flex-column gap-2">
              <div class="d-flex align-items-center justify-content-between">
                <span class="small text-secondary font-monospace">Raw Payload Metadata (Format JSON):</span>
                <button
                  type="button"
                  class="btn btn-xs btn-outline-secondary d-inline-flex align-items-center gap-1"
                  @click="copyJsonMetadata"
                >
                  <svg v-if="!copiedJson" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 8m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /><path d="M16 8v-2a2 2 0 0 0 -2 -2h-8a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2" /></svg>
                  <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-success" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                  <span>{{ copiedJson ? 'Tersalin!' : 'Salin JSON' }}</span>
                </button>
              </div>
              <pre class="bg-dark text-light p-3 rounded font-monospace small mb-0 border" style="max-height: 480px; overflow-y: auto; line-height: 1.45;"><code>{{ JSON.stringify(previewingReport.metadata || previewingReport.summary, null, 2) }}</code></pre>
            </div>
          </div>

          <div class="modal-footer py-2 px-4 d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
              <button
                type="button"
                class="btn btn-primary d-inline-flex align-items-center gap-1"
                @click="downloadPdf(previewingReport)"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
                <span>Unduh Versi PDF</span>
              </button>
              <button
                type="button"
                class="btn btn-outline-secondary d-inline-flex align-items-center gap-1"
                :disabled="isSyncing"
                @click="syncReport(previewingReport)"
              >
                <span v-if="isSyncing" class="spinner-border spinner-border-sm" role="status"></span>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -5v5h5" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 5v-5h-5" /></svg>
                <span>{{ isSyncing ? 'Menyinkronkan...' : 'Sinkronkan Scan' }}</span>
              </button>
            </div>
            <button
              type="button"
              class="btn btn-secondary"
              @click="closePreviewModal"
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
