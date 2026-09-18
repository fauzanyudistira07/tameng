<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '../services/api'
import { useAuth } from '../composables/useAuth'

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
  isPreviewModalOpen.value = true
}

function closePreviewModal() {
  isPreviewModalOpen.value = false
  previewingReport.value = null
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
                <th class="w-1 text-end">Aksi</th>
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
                  <div class="btn-group">
                    <button
                      class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1"
                      @click="downloadPdf(r)"
                      title="Unduh Dokumen PDF Resmi"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
                      <span>PDF</span>
                    </button>
                    <button
                      class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                      @click="openPreviewModal(r)"
                      title="Lihat Pratinjau Ringkasan JSON"
                    >
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
              <div class="btn-group">
                <button
                  type="button"
                  class="btn btn-sm btn-primary py-1 px-2 d-inline-flex align-items-center gap-1"
                  @click.stop="downloadPdf(r)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
                  <span>PDF</span>
                </button>
                <button
                  type="button"
                  class="btn btn-sm btn-outline-secondary py-1 px-2"
                  @click.stop="openPreviewModal(r)"
                >
                  Pratinjau
                </button>
              </div>
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

            <div class="modal-footer">
              <button
                type="button"
                class="btn btn-link link-secondary"
                :disabled="isGenerating"
                @click="closeGenerateModal"
              >
                Batal
              </button>
              <button
                type="submit"
                class="btn btn-primary ms-auto"
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
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title d-flex align-items-center gap-2">
              <span class="badge bg-primary text-primary-fg font-monospace">#REP-{{ String(previewingReport.id).padStart(4, '0') }}</span>
              <span>Ringkasan Eksekutif Dokumen Audit</span>
            </h5>
            <button type="button" class="btn-close" @click="closePreviewModal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-2 mb-3 small">
              <div class="col-sm-6">
                <div><strong>Scan Job:</strong> {{ previewingReport.scanJob?.code || previewingReport.scan_job?.code || '-' }}</div>
                <div><strong>Proyek:</strong> {{ previewingReport.scanJob?.project?.name || previewingReport.scan_job?.project?.name || '-' }}</div>
              </div>
              <div class="col-sm-6">
                <div><strong>Tanggal Terbit:</strong> {{ formatDate(previewingReport.created_at) }}</div>
                <div><strong>Pembuat:</strong> {{ previewingReport.generator?.name || 'Sistem TAMENG' }}</div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold text-secondary">Rincian Metadata & Ringkasan Laporan</label>
              <pre class="bg-dark text-light p-3 rounded font-monospace small" style="max-height: 260px; overflow-y: auto;">{{ JSON.stringify(previewingReport.metadata || previewingReport.summary, null, 2) }}</pre>
            </div>
          </div>
          <div class="modal-footer">
            <button
              type="button"
              class="btn btn-primary"
              @click="downloadPdf(previewingReport)"
            >
              Unduh Versi PDF
            </button>
            <button
              type="button"
              class="btn btn-secondary ms-auto"
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
