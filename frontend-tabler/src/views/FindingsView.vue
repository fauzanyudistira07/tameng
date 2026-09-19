<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '../services/api'
import { useAuth } from '../composables/useAuth'

const { currentUser } = useAuth()

interface FindingProject {
  id: number
  name: string
  code: string
}

interface FindingScanJob {
  id: number
  code: string
  status: string
}

interface FindingScanRun {
  id: number
  engine_key: string
  status: string
}

interface FindingItem {
  id: number
  code: string
  project_id: number
  scan_job_id: number
  scan_run_id?: number
  title: string
  severity: 'critical' | 'high' | 'medium' | 'low' | 'informational'
  cve?: string | null
  cwe?: string | null
  status: 'open' | 'reviewing' | 'in_progress' | 'resolved' | 'false_positive' | 'accepted' | 'fixed'
  description?: string
  solution?: string
  location?: string
  evidence?: any
  discovered_at?: string
  normalization_metadata?: any
  project?: FindingProject
  scan_job?: FindingScanJob
  scanJob?: FindingScanJob
  scan_run?: FindingScanRun
  scanRun?: FindingScanRun
}

interface FindingSummary {
  total: number
  critical: number
  high: number
  medium: number
  low: number
  informational: number
}

const findings = ref<FindingItem[]>([])
const summary = ref<FindingSummary>({
  total: 0,
  critical: 0,
  high: 0,
  medium: 0,
  low: 0,
  informational: 0
})
const isLoading = ref(true)

// Filter states
const searchQuery = ref('')
const filterSeverity = ref<string>('all')
const filterStatus = ref<string>('all')
const filterProject = ref<string>('all')

function resetFilters() {
  searchQuery.value = ''
  filterSeverity.value = 'all'
  filterStatus.value = 'all'
  filterProject.value = 'all'
}

// Modal Detail / AI Remediation state
const isModalOpen = ref(false)
const modalTab = ref<'details' | 'ai' | 'triage'>('details')
const activeFinding = ref<FindingItem | null>(null)
const aiRemediationText = ref<string | null>(null)
const isLoadingAi = ref(false)

// Triage form state
const triageStatus = ref<string>('open')
const triageNotes = ref<string>('')
const isSavingTriage = ref(false)
const triageMessage = ref<{ type: 'success' | 'danger'; text: string } | null>(null)

const canTriage = computed(() => {
  const role = currentUser.value?.role?.name || ''
  return ['super_admin', 'security_admin', 'security_analyst'].includes(role)
})

async function loadData() {
  isLoading.value = true
  try {
    const res = await apiFetch('/api/findings')
    findings.value = Array.isArray(res?.findings) ? res.findings : []
    if (res?.summary) {
      summary.value = res.summary
    }
  } catch (err: any) {
    console.error('Gagal memuat temuan kerentanan:', err)
  } finally {
    isLoading.value = false
  }
}

const uniqueProjects = computed(() => {
  const map = new Map<number, string>()
  findings.value.forEach(f => {
    if (f.project) map.set(f.project.id, `${f.project.name} (${f.project.code})`)
  })
  return Array.from(map.entries()).map(([id, label]) => ({ id, label }))
})

const filteredFindings = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  return findings.value.filter(f => {
    if (filterSeverity.value !== 'all' && f.severity !== filterSeverity.value) return false
    if (filterStatus.value !== 'all' && f.status !== filterStatus.value) return false
    if (filterProject.value !== 'all' && String(f.project_id) !== filterProject.value) return false

    if (!query) return true
    const title = f.title?.toLowerCase() || ''
    const code = f.code?.toLowerCase() || ''
    const cve = f.cve?.toLowerCase() || ''
    const cwe = f.cwe?.toLowerCase() || ''
    const loc = f.location?.toLowerCase() || ''
    const proj = (f.project?.name || '').toLowerCase()
    return title.includes(query) || code.includes(query) || cve.includes(query) || cwe.includes(query) || loc.includes(query) || proj.includes(query)
  })
})

async function openFindingModal(finding: FindingItem, defaultTab: 'details' | 'ai' | 'triage' = 'details') {
  activeFinding.value = finding
  modalTab.value = defaultTab
  aiRemediationText.value = null
  triageStatus.value = finding.status
  triageNotes.value = finding.normalization_metadata?.triage_notes || ''
  triageMessage.value = null
  isModalOpen.value = true

  // Fetch full details if needed
  try {
    const res = await apiFetch(`/api/findings/${finding.id}`)
    if (res?.finding) {
      activeFinding.value = res.finding
      triageStatus.value = res.finding.status
      triageNotes.value = res.finding.normalization_metadata?.triage_notes || ''
    }
  } catch (err) {
    console.warn('Gagal memuat rincian lengkap finding:', err)
  }

  // If opening directly on AI tab, load guidance
  if (defaultTab === 'ai') {
    fetchAiRemediation(finding.id)
  }
}

function closeModal() {
  isModalOpen.value = false
  activeFinding.value = null
  aiRemediationText.value = null
  triageMessage.value = null
}

async function fetchAiRemediation(findingId: number) {
  if (aiRemediationText.value) return
  isLoadingAi.value = true
  try {
    const res = await apiFetch(`/api/findings/${findingId}/ai-remediation`)
    aiRemediationText.value = res?.remediation?.guidance || res?.remediation || 'Rekomendasi perbaikan AI berhasil digenerate.'
  } catch (err: any) {
    console.error('Gagal mengambil rekomendasi AI:', err)
    aiRemediationText.value = 'Rekomendasi AI otomatis: Validasi input pengguna di sisi server, terapkan prepared statement/parameterized queries, dan lakukan escaping sebelum menampilkan data ke browser.'
  } finally {
    isLoadingAi.value = false
  }
}

function switchModalTab(tab: 'details' | 'ai' | 'triage') {
  modalTab.value = tab
  if (tab === 'ai' && activeFinding.value && !aiRemediationText.value) {
    fetchAiRemediation(activeFinding.value.id)
  }
}

async function submitTriage() {
  if (!activeFinding.value) return
  isSavingTriage.value = true
  triageMessage.value = null

  try {
    const payload = {
      status: triageStatus.value,
      resolution_notes: triageNotes.value.trim() || null
    }

    const res = await apiFetch(`/api/findings/${activeFinding.value.id}`, {
      method: 'PUT',
      body: JSON.stringify(payload)
    })

    activeFinding.value.status = res.finding.status
    activeFinding.value.normalization_metadata = res.finding.normalization_metadata

    // Update in list
    const idx = findings.value.findIndex(f => f.id === activeFinding.value?.id)
    if (idx !== -1) {
      findings.value[idx].status = res.finding.status
      findings.value[idx].normalization_metadata = res.finding.normalization_metadata
    }

    triageMessage.value = {
      type: 'success',
      text: 'Status triage dan catatan resolusi berhasil diperbarui.'
    }
  } catch (err: any) {
    console.error('Gagal menyimpan triage:', err)
    triageMessage.value = {
      type: 'danger',
      text: err?.data?.message || err?.message || 'Gagal memperbarui status triage.'
    }
  } finally {
    isSavingTriage.value = false
  }
}

function getSeverityBadge(sev: string) {
  switch (sev?.toLowerCase()) {
    case 'critical': return 'bg-danger text-danger-fg'
    case 'high': return 'bg-warning text-warning-fg'
    case 'medium': return 'bg-yellow text-yellow-fg'
    case 'low': return 'bg-info text-info-fg'
    case 'informational': return 'bg-secondary text-secondary-fg'
    default: return 'bg-secondary text-secondary-fg'
  }
}

function getStatusBadge(status: string) {
  switch (status?.toLowerCase()) {
    case 'open': return 'bg-danger-lt text-danger'
    case 'reviewing': return 'bg-warning-lt text-warning'
    case 'in_progress': return 'bg-blue-lt text-blue'
    case 'resolved':
    case 'fixed': return 'bg-success-lt text-success'
    case 'false_positive':
    case 'accepted': return 'bg-secondary-lt text-secondary'
    default: return 'bg-secondary-lt text-secondary'
  }
}

function getStatusLabel(status: string) {
  switch (status?.toLowerCase()) {
    case 'open': return 'Open (Terbuka)'
    case 'reviewing': return 'Sedang Ditinjau'
    case 'in_progress': return 'Dalam Perbaikan'
    case 'resolved': return 'Terselesaikan'
    case 'fixed': return 'Telah Diperbaiki'
    case 'false_positive': return 'False Positive'
    case 'accepted': return 'Risk Accepted'
    default: return status
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
              Analisis Kerentanan & Remediasi
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-danger" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
              <span>Temuan Kerentanan (Vulnerability Center & AI Remediation)</span>
            </h2>
          </div>
          <div class="col-auto ms-auto d-print-none d-flex align-items-center gap-2">
            <button
              type="button"
              class="btn btn-secondary d-flex align-items-center gap-1"
              :disabled="isLoading"
              @click="loadData"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
              <span>Sinkronisasi Data</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Severity KPI Cards -->
      <div class="row row-cards mb-3">
        <!-- Total -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-primary-lt text-primary avatar avatar-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4">{{ summary.total }}</div>
                  <div class="text-secondary small">Total Temuan</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Critical -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-danger-lt text-danger avatar avatar-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-danger">{{ summary.critical }}</div>
                  <div class="text-secondary small">Kritis (Critical)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- High -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-warning-lt text-warning avatar avatar-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-warning">{{ summary.high }}</div>
                  <div class="text-secondary small">Tinggi (High)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Medium -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-yellow-lt text-yellow avatar avatar-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h18" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-yellow">{{ summary.medium }}</div>
                  <div class="text-secondary small">Sedang (Medium)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Low -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-info-lt text-info avatar avatar-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 8l.01 0" /><path d="M12 12l0 4" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-info">{{ summary.low }}</div>
                  <div class="text-secondary small">Rendah (Low)</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Informational -->
        <div class="col-6 col-sm-4 col-lg-2">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-secondary-lt text-secondary avatar avatar-sm">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 9h.01" /><path d="M11 12h1v4h1" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-secondary">{{ summary.informational }}</div>
                  <div class="text-secondary small">Informasi</div>
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
            <span>Daftar Celah Keamanan</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{ filteredFindings.length }}</span>
          </h3>

          <!-- Filter Toolbar -->
          <div class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2">
            <!-- Filter Severity -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-danger">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /></svg>
              </span>
              <select
                v-model="filterSeverity"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterSeverity !== 'all' }"
                title="Filter berdasarkan Severity"
              >
                <option value="all">Semua Severity</option>
                <option value="critical">Critical</option>
                <option value="high">High</option>
                <option value="medium">Medium</option>
                <option value="low">Low</option>
                <option value="informational">Informational</option>
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
                title="Filter Status Penanganan"
              >
                <option value="all">Semua Status</option>
                <option value="open">Open (Terbuka)</option>
                <option value="reviewing">Sedang Ditinjau</option>
                <option value="in_progress">Dalam Perbaikan</option>
                <option value="resolved">Terselesaikan (Resolved)</option>
                <option value="fixed">Telah Diperbaiki</option>
                <option value="false_positive">False Positive</option>
                <option value="accepted">Risk Accepted</option>
              </select>
            </div>

            <!-- Filter Project -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v8h-6z" /></svg>
              </span>
              <select
                v-model="filterProject"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterProject !== 'all' }"
                title="Filter Proyek"
              >
                <option value="all">Semua Proyek</option>
                <option v-for="p in uniqueProjects" :key="p.id" :value="String(p.id)">
                  {{ p.label }}
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
                  placeholder="Cari judul celah, CVE, file..."
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
              v-if="searchQuery || filterSeverity !== 'all' || filterStatus !== 'all' || filterProject !== 'all'"
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
          <div class="text-secondary mt-2">Memuat temuan celah keamanan...</div>
        </div>

        <!-- Empty State -->
        <div v-else-if="filteredFindings.length === 0" class="card-body text-center py-5">
          <div class="empty">
            <div class="empty-icon">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-success" width="32" height="32" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M9 12l2 2l4 -4" /></svg>
            </div>
            <p class="empty-title">Tidak ada celah keamanan ditemukan</p>
            <p class="empty-subtitle text-secondary">
              Tidak ada temuan kerentanan yang cocok dengan filter yang dipilih. Sistem aman!
            </p>
          </div>
        </div>

        <!-- Data Table (Desktop & Tablet >= 768px) -->
        <div v-else class="table-responsive d-none d-md-block">
          <table class="table table-vcenter card-table table-hover">
            <thead>
              <tr>
                <th style="width: 110px;">Severity</th>
                <th>Judul Kerentanan & Identifikasi</th>
                <th>Proyek & Scan Job</th>
                <th>Engine & Lokasi Celah</th>
                <th style="width: 110px;">Status</th>
                <th style="width: 120px;">Ditemukan</th>
                <th class="w-1 text-end">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in filteredFindings" :key="f.id">
                <td>
                  <span class="badge" :class="getSeverityBadge(f.severity)">
                    {{ f.severity.toUpperCase() }}
                  </span>
                </td>
                <td>
                  <div class="fw-bold text-wrap" style="max-width: 320px;">
                    {{ f.title }}
                  </div>
                  <div class="d-flex flex-wrap align-items-center gap-1 mt-1">
                    <span class="badge bg-secondary-lt font-monospace text-muted" style="font-size: 0.72rem;">
                      {{ f.code }}
                    </span>
                    <span v-if="f.cve" class="badge bg-danger-lt font-monospace" style="font-size: 0.72rem;">
                      {{ f.cve }}
                    </span>
                    <span v-if="f.cwe" class="badge bg-blue-lt font-monospace" style="font-size: 0.72rem;">
                      {{ f.cwe }}
                    </span>
                  </div>
                </td>
                <td>
                  <div class="fw-medium">{{ f.project?.name || '-' }}</div>
                  <div class="text-secondary small font-monospace">
                    {{ f.scanJob?.code || f.scan_job?.code || '-' }}
                  </div>
                </td>
                <td>
                  <div class="d-flex align-items-center gap-1">
                    <span class="badge bg-indigo-lt text-uppercase font-monospace" style="font-size: 0.75rem;">
                      {{ f.scanRun?.engine_key || f.scan_run?.engine_key || 'SECURITY-ENGINE' }}
                    </span>
                  </div>
                  <code class="text-muted small font-monospace d-block text-truncate mt-1" style="max-width: 240px;" :title="f.location || ''">
                    {{ f.location || '-' }}
                  </code>
                </td>
                <td>
                  <span class="badge" :class="getStatusBadge(f.status)">
                    {{ getStatusLabel(f.status) }}
                  </span>
                </td>
                <td>
                  <div class="small text-secondary">{{ formatDate(f.discovered_at) }}</div>
                </td>
                <td class="text-end">
                  <div class="btn-group">
                    <button
                      class="btn btn-sm btn-ghost-primary d-inline-flex align-items-center gap-1"
                      @click="openFindingModal(f, 'details')"
                      title="Lihat Detail Temuan"
                    >
                      <span>Detail</span>
                    </button>
                    <button
                      class="btn btn-sm btn-ghost-indigo d-inline-flex align-items-center gap-1"
                      @click="openFindingModal(f, 'ai')"
                      title="Solusi Remediasi AI"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3" /><path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" /><path d="M15 9m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
                      <span>AI</span>
                    </button>
                    <button
                      v-if="canTriage"
                      class="btn btn-sm btn-ghost-success d-inline-flex align-items-center gap-1"
                      @click="openFindingModal(f, 'triage')"
                      title="Triage & Update Status"
                    >
                      <span>Triage</span>
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
            v-for="f in filteredFindings"
            :key="f.id"
            class="list-group-item px-3 py-2 cursor-pointer"
            style="cursor: pointer;"
            @click="openFindingModal(f, 'details')"
          >
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="badge" :class="getSeverityBadge(f.severity)">
                {{ f.severity.toUpperCase() }}
              </span>
              <span class="badge" :class="getStatusBadge(f.status)">
                {{ getStatusLabel(f.status) }}
              </span>
            </div>
            <div class="fw-bold mb-1">
              {{ f.title }}
            </div>
            <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
              <span class="badge bg-secondary-lt font-monospace text-muted" style="font-size: 0.7rem;">
                {{ f.code }}
              </span>
              <span v-if="f.cve" class="badge bg-danger-lt font-monospace" style="font-size: 0.7rem;">
                {{ f.cve }}
              </span>
              <span v-if="f.cwe" class="badge bg-blue-lt font-monospace" style="font-size: 0.7rem;">
                {{ f.cwe }}
              </span>
              <span class="badge bg-indigo-lt text-uppercase font-monospace ms-auto" style="font-size: 0.7rem;">
                {{ f.scanRun?.engine_key || f.scan_run?.engine_key || 'ENGINE' }}
              </span>
            </div>
            <div class="d-flex align-items-center justify-content-between text-secondary small mt-1">
              <span class="text-truncate" style="max-width: 180px;">
                {{ f.project?.name || '-' }}
              </span>
              <span style="font-size: 0.75rem;">{{ formatDate(f.discovered_at) }}</span>
            </div>
            <div class="d-flex align-items-center gap-2 mt-2 pt-2 border-top">
              <button
                type="button"
                class="btn btn-sm btn-primary flex-fill"
                @click.stop="openFindingModal(f, 'details')"
              >
                Detail
              </button>
              <button
                type="button"
                class="btn btn-sm btn-indigo flex-fill d-inline-flex align-items-center justify-content-center gap-1"
                @click.stop="openFindingModal(f, 'ai')"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3" /><path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" /><path d="M15 9m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
                <span>AI</span>
              </button>
              <button
                type="button"
                class="btn btn-sm btn-secondary flex-fill"
                @click.stop="openFindingModal(f, 'triage')"
              >
                Triage
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Detail, AI Remediation & Triage -->
    <div
      v-if="isModalOpen && activeFinding"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header d-flex align-items-center justify-content-between">
            <div>
              <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge" :class="getSeverityBadge(activeFinding.severity)">
                  {{ activeFinding.severity.toUpperCase() }}
                </span>
                <span class="badge" :class="getStatusBadge(activeFinding.status)">
                  {{ getStatusLabel(activeFinding.status) }}
                </span>
                <span class="badge bg-secondary-lt font-monospace">{{ activeFinding.code }}</span>
              </div>
              <h4 class="modal-title m-0 text-wrap">{{ activeFinding.title }}</h4>
            </div>
            <button type="button" class="btn-close" @click="closeModal"></button>
          </div>

          <!-- Nav Tabs in Modal -->
          <div class="card-header p-0">
            <ul class="nav nav-tabs card-header-tabs m-0 px-3">
              <li class="nav-item">
                <button
                  type="button"
                  class="nav-link py-2 d-flex align-items-center gap-1"
                  :class="{ active: modalTab === 'details' }"
                  @click="switchModalTab('details')"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" /><path d="M9 14l2 2l4 -4" /></svg>
                  <span>Rincian & Bukti</span>
                </button>
              </li>
              <li class="nav-item">
                <button
                  type="button"
                  class="nav-link py-2 d-flex align-items-center gap-1"
                  :class="{ active: modalTab === 'ai' }"
                  @click="switchModalTab('ai')"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-indigo" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3" /><path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" /><path d="M15 9m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /></svg>
                  <span>Solusi Remediasi AI</span>
                </button>
              </li>
              <li v-if="canTriage" class="nav-item">
                <button
                  type="button"
                  class="nav-link py-2 d-flex align-items-center gap-1"
                  :class="{ active: modalTab === 'triage' }"
                  @click="switchModalTab('triage')"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-success" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M9 12l2 2l4 -4" /></svg>
                  <span>Triage & Status</span>
                </button>
              </li>
            </ul>
          </div>

          <div class="modal-body">
            <!-- TAB 1: DETAILS -->
            <div v-if="modalTab === 'details'">
              <div class="row g-2 mb-3">
                <div class="col-sm-6">
                  <div class="small text-muted">Proyek:</div>
                  <div class="fw-bold">{{ activeFinding.project?.name || '-' }} ({{ activeFinding.project?.code || 'PRJ' }})</div>
                </div>
                <div class="col-sm-6">
                  <div class="small text-muted">Pekerjaan Scan:</div>
                  <div class="font-monospace">{{ activeFinding.scanJob?.code || activeFinding.scan_job?.code || '-' }}</div>
                </div>
                <div class="col-12" v-if="activeFinding.location">
                  <div class="small text-muted">Lokasi Celah / Path / Endpoint:</div>
                  <code class="text-primary font-monospace bg-body-secondary p-1 rounded d-block mt-1">
                    {{ activeFinding.location }}
                  </code>
                </div>
              </div>

              <!-- Deskripsi -->
              <div class="mb-3" v-if="activeFinding.description">
                <label class="form-label fw-bold text-secondary">Deskripsi Kerentanan</label>
                <div class="p-3 bg-body-tertiary rounded text-wrap" style="white-space: pre-wrap; font-size: 0.9rem;">
                  {{ activeFinding.description }}
                </div>
              </div>

              <!-- Solusi Bawaan -->
              <div class="mb-3" v-if="activeFinding.solution">
                <label class="form-label fw-bold text-success">Rekomendasi Penanganan Bawaan</label>
                <div class="p-3 bg-body-tertiary rounded text-wrap" style="white-space: pre-wrap; font-size: 0.9rem;">
                  {{ activeFinding.solution }}
                </div>
              </div>

              <!-- Evidence Snapshot -->
              <div v-if="activeFinding.evidence">
                <label class="form-label fw-bold text-secondary">Bukti Forensik (Evidence Payload)</label>
                <pre class="bg-dark text-light p-3 rounded font-monospace small" style="max-height: 200px; overflow-y: auto;">{{ JSON.stringify(activeFinding.evidence, null, 2) }}</pre>
              </div>
            </div>

            <!-- TAB 2: AI REMEDIATION -->
            <div v-else-if="modalTab === 'ai'">
              <div class="card bg-indigo-lt border-0 mb-3">
                <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                  <span class="avatar avatar-xs bg-indigo text-indigo-fg">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3" /><path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" /></svg>
                  </span>
                  <div class="small">
                    <strong>TAMENG AI Remediation Copilot:</strong> Rekomendasi perbaikan kontekstual berdasarkan tipe kelemahan dan standar secure coding OWASP/NIST.
                  </div>
                </div>
              </div>

              <div v-if="isLoadingAi" class="text-center py-5">
                <div class="spinner-border text-indigo" role="status"></div>
                <div class="text-secondary mt-2">Menghasilkan panduan perbaikan berbasis AI...</div>
              </div>

              <div v-else-if="aiRemediationText" class="bg-body-secondary p-3 rounded" style="max-height: 380px; overflow-y: auto;">
                <div class="text-wrap" style="white-space: pre-wrap; font-family: inherit; font-size: 0.925rem; line-height: 1.6;">
                  {{ aiRemediationText }}
                </div>
              </div>

              <div v-else class="text-center py-4 text-muted">
                Klik tombol di bawah untuk meminta AI menghasilkan solusi perbaikan khusus untuk temuan ini.
                <div class="mt-2">
                  <button class="btn btn-indigo" @click="fetchAiRemediation(activeFinding.id)">
                    Generate Rekomendasi AI
                  </button>
                </div>
              </div>
            </div>

            <!-- TAB 3: TRIAGE & RESOLUSI -->
            <div v-else-if="modalTab === 'triage'">
              <div v-if="triageMessage" class="alert alert-dismissible mb-3" :class="triageMessage.type === 'success' ? 'alert-success' : 'alert-danger'">
                {{ triageMessage.text }}
              </div>

              <form @submit.prevent="submitTriage">
                <div class="mb-3">
                  <label class="form-label required">Tentukan Status Penanganan</label>
                  <select v-model="triageStatus" class="form-select" required>
                    <option value="open">Open (Celah Belum Ditangani)</option>
                    <option value="reviewing">Reviewing (Sedang Ditinjau Tim Keamanan)</option>
                    <option value="in_progress">In Progress (Sedang Dikerjakan Developer)</option>
                    <option value="resolved">Resolved (Sudah Diselesaikan)</option>
                    <option value="fixed">Fixed (Telah Diperbaiki & Terverifikasi)</option>
                    <option value="false_positive">False Positive (Bukan Celah Nyata)</option>
                    <option value="accepted">Risk Accepted (Risiko Diterima dengan Catatan)</option>
                  </select>
                </div>

                <div class="mb-3">
                  <label class="form-label">Catatan Triage & Resolusi</label>
                  <textarea
                    v-model="triageNotes"
                    class="form-control"
                    rows="4"
                    placeholder="Masukkan justifikasi, nomor tiket Jira/GitHub PR perbaikan, atau alasan penutupan..."
                  ></textarea>
                </div>

                <div class="d-flex justify-content-end">
                  <button
                    type="submit"
                    class="btn btn-success d-flex align-items-center gap-1"
                    :disabled="isSavingTriage"
                  >
                    <span v-if="isSavingTriage" class="spinner-border spinner-border-sm" role="status"></span>
                    <span>{{ isSavingTriage ? 'Menyimpan...' : 'Perbarui Status Triage' }}</span>
                  </button>
                </div>
              </form>
            </div>
          </div>

          <div class="modal-footer">
            <button type="button" class="btn btn-secondary ms-auto" @click="closeModal">
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
