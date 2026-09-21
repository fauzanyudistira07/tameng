<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { apiFetch } from '../../services/api'
import { useAuth } from '../../composables/useAuth'

const route = useRoute()
const { assignedProjects } = useAuth()

interface Finding {
  id: number
  code: string
  title: string
  description?: string
  severity: string
  status: string
  asset_type?: string
  asset_identifier?: string
  cve?: string
  cwe?: string
  project_id: number
  project?: {
    id: number
    name: string
    code: string
  }
  normalization_metadata?: {
    location?: {
      file?: string
      line?: number
      component?: string
    }
    triage_notes?: string
    triaged_by?: string
    triaged_at?: string
  }
  created_at: string
}

const findings = ref<Finding[]>([])
const isLoading = ref(true)
const searchQuery = ref('')
const selectedTab = ref<'open_pending' | 'in_progress' | 'completed' | 'all'>('open_pending')
const selectedSeverity = ref<string>('all')
const selectedProject = ref<string>('all')
const copiedId = ref<number | null>(null)

// Quick Fix Modal
const targetFinding = ref<Finding | null>(null)
const fixStatusChoice = ref<'fixed' | 'in_progress'>('fixed')
const fixResolutionNotes = ref('')
const isSavingFix = ref(false)
const fixModalError = ref<string | null>(null)
const isFixModalOpen = ref(false)

// AI Guidance Modal
const activeGuidanceFinding = ref<Finding | null>(null)
export interface AiRemediationData {
  finding_id?: number
  finding_code?: string
  rule_id?: string
  title?: string
  severity?: string
  category?: string
  summary?: string
  cause?: string
  attack_vector?: string
  business_impact?: string
  vulnerable_code?: string
  secure_code?: string
  code_diff?: string
  mitigation_checklist?: string[]
  verification_command?: string
  compliance?: {
    cwe?: string
    owasp?: string
    cvss_score?: string | number
  }
  owasp_guidance?: {
    top_10_category?: string
    defense_in_depth?: string
    verification_method?: string
  }
}

const aiRemediationData = ref<AiRemediationData | null>(null)
const aiActiveTab = ref<'solution' | 'diff' | 'analysis' | 'checklist'>('solution')
const copiedCode = ref(false)
const isLoadingAi = ref(false)
const isAiModalOpen = ref(false)

async function loadTickets() {
  isLoading.value = true
  try {
    const res = await apiFetch('/api/findings')
    findings.value = res?.findings || []
  } catch (err: any) {
    console.error('Gagal memuat tiket perbaikan:', err)
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  // Check query parameter from search
  if (route.query.q && typeof route.query.q === 'string') {
    searchQuery.value = route.query.q
  }
  loadTickets()
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    if (isAiModalOpen.value) closeAiGuidance()
    if (isFixModalOpen.value) closeQuickFix()
  }
}

const availableProjects = computed(() => {
  const map = new Map<number, string>()
  findings.value.forEach(f => {
    if (f.project) {
      map.set(f.project.id, f.project.name)
    }
  })
  return Array.from(map.entries()).map(([id, name]) => ({ id, name }))
})

// Counts per pipeline tab
const tabCounts = computed(() => {
  const openCount = findings.value.filter(f => f.status === 'open' || f.status === 'reviewing').length
  const inProgressCount = findings.value.filter(f => f.status === 'in_progress').length
  const completedCount = findings.value.filter(f => f.status === 'fixed' || f.status === 'resolved').length
  return {
    open_pending: openCount,
    in_progress: inProgressCount,
    completed: completedCount,
    all: findings.value.length
  }
})

const filteredTickets = computed(() => {
  return findings.value.filter(f => {
    // 1. Pipeline Tab Filter
    if (selectedTab.value === 'open_pending') {
      if (f.status !== 'open' && f.status !== 'reviewing') return false
    } else if (selectedTab.value === 'in_progress') {
      if (f.status !== 'in_progress') return false
    } else if (selectedTab.value === 'completed') {
      if (f.status !== 'fixed' && f.status !== 'resolved') return false
    }

    // 2. Severity Filter
    if (selectedSeverity.value !== 'all' && f.severity?.toLowerCase() !== selectedSeverity.value) {
      return false
    }

    // 3. Project Filter
    if (selectedProject.value !== 'all' && String(f.project_id) !== selectedProject.value) {
      return false
    }

    // 4. Search Query
    if (!searchQuery.value.trim()) return true
    const q = searchQuery.value.toLowerCase().trim()
    const loc = getLocationDisplay(f)?.toLowerCase() || ''
    return f.title.toLowerCase().includes(q) ||
      f.code.toLowerCase().includes(q) ||
      (f.cve && f.cve.toLowerCase().includes(q)) ||
      (f.cwe && f.cwe.toLowerCase().includes(q)) ||
      loc.includes(q) ||
      (f.project?.name && f.project.name.toLowerCase().includes(q))
  })
})

function getLocationDisplay(f: Finding): string | null {
  const loc = f.normalization_metadata?.location
  if (loc?.file) {
    return loc.line ? `${loc.file}:${loc.line}` : loc.file
  }
  if (f.asset_identifier && !f.asset_identifier.startsWith('http')) {
    return f.asset_identifier
  }
  return null
}

async function copyLocation(f: Finding) {
  const loc = getLocationDisplay(f)
  if (!loc) return
  try {
    await navigator.clipboard.writeText(loc)
    copiedId.value = f.id
    setTimeout(() => {
      if (copiedId.value === f.id) copiedId.value = null
    }, 2000)
  } catch {
    // ignore
  }
}

function getSeverityBadge(sev: string) {
  switch (sev?.toLowerCase()) {
    case 'critical': return 'bg-danger text-danger-fg'
    case 'high': return 'bg-warning text-warning-fg'
    case 'medium': return 'bg-yellow text-yellow-fg'
    case 'low': return 'bg-info text-info-fg'
    default: return 'bg-secondary text-secondary-fg'
  }
}

function getStatusBadge(st: string) {
  switch (st) {
    case 'fixed':
    case 'resolved': return 'badge bg-success-lt text-success'
    case 'in_progress': return 'badge bg-blue-lt text-blue'
    default: return 'badge bg-danger-lt text-danger'
  }
}

function getStatusLabel(st: string) {
  switch (st) {
    case 'fixed': return 'Sudah Diperbaiki'
    case 'resolved': return 'Terselesaikan'
    case 'in_progress': return 'Sedang Dikerjakan'
    default: return 'Perlu Diperbaiki'
  }
}

// Open Quick Fix Modal
function openQuickFix(f: Finding) {
  targetFinding.value = f
  fixStatusChoice.value = f.status === 'in_progress' ? 'fixed' : 'in_progress'
  fixResolutionNotes.value = f.normalization_metadata?.triage_notes || ''
  fixModalError.value = null
  isFixModalOpen.value = true
}

function closeQuickFix() {
  isFixModalOpen.value = false
  targetFinding.value = null
}

async function submitQuickFix() {
  if (!targetFinding.value) return
  isSavingFix.value = true
  fixModalError.value = null

  try {
    const payload = {
      status: fixStatusChoice.value,
      resolution_notes: fixResolutionNotes.value.trim() || 'Diperbarui oleh pengembang'
    }

    const res = await apiFetch(`/api/findings/${targetFinding.value.id}`, {
      method: 'PUT',
      body: JSON.stringify(payload)
    })

    if (res?.finding) {
      const idx = findings.value.findIndex(f => f.id === targetFinding.value?.id)
      if (idx !== -1) {
        findings.value[idx].status = res.finding.status
        findings.value[idx].normalization_metadata = res.finding.normalization_metadata
      }
      closeQuickFix()
    } else {
      fixModalError.value = res?.message || 'Gagal menyimpan status perbaikan.'
    }
  } catch (err: any) {
    fixModalError.value = err?.message || 'Terjadi kesalahan saat menyimpan.'
  } finally {
    isSavingFix.value = false
  }
}

function parseAiRemediation(data: any): AiRemediationData {
  if (!data) {
    return { summary: 'Panduan perbaikan AI berhasil digenerate.' }
  }

  // Jika data berupa string JSON, coba parse
  if (typeof data === 'string') {
    const trimmed = data.trim()
    if (trimmed.startsWith('{') && trimmed.endsWith('}')) {
      try {
        return JSON.parse(trimmed)
      } catch {}
    }
    return { summary: data }
  }

  // Jika data berupa object
  if (typeof data === 'object') {
    return data
  }

  return { summary: String(data) }
}

// AI Remediation Modal
async function openAiGuidance(f: Finding) {
  activeGuidanceFinding.value = f
  aiRemediationData.value = null
  aiActiveTab.value = 'solution'
  copiedCode.value = false
  isLoadingAi.value = true
  isAiModalOpen.value = true

  try {
    const res = await apiFetch(`/api/findings/${f.id}/ai-remediation`)
    aiRemediationData.value = parseAiRemediation(res?.remediation)
  } catch (err: any) {
    aiRemediationData.value = {
      summary: 'Panduan perbaikan: Validasi input pengguna di sisi server, terapkan prepared statement / parameterized queries, dan lakukan sanitasi output.',
      cause: 'Input tidak divalidasi atau token kredensial terekspos langsung pada berkas kode sumber.',
      mitigation_checklist: [
        'Pindahkan kredensial rahasia ke environment variables (.env).',
        'Terapkan sanitasi input dan prepared statements.',
        'Lakukan audit dependensi kode secara berkala.'
      ]
    }
  } finally {
    isLoadingAi.value = false
  }
}

function closeAiGuidance() {
  isAiModalOpen.value = false
  activeGuidanceFinding.value = null
  aiRemediationData.value = null
}

function copyToClipboard(text?: string) {
  if (!text) return
  navigator.clipboard.writeText(text)
  copiedCode.value = true
  setTimeout(() => {
    copiedCode.value = false
  }, 2000)
}

function formatDate(d?: string) {
  if (!d) return '-'
  try {
    return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
  } catch {
    return d
  }
}
</script>

<template>
  <div class="user-tickets-view">
    <!-- Header -->
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
      <div>
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item">
              <router-link to="/workspace" class="text-decoration-none">Ruang Kerja</router-link>
            </li>
            <li class="breadcrumb-item active" aria-current="page">Tiket Celah Saya</li>
          </ol>
        </nav>
        <h1 class="h2 mb-0 fw-bold">Tiket Perbaikan Celah</h1>
        <p class="text-secondary small mb-0 mt-1">
          Daftar temuan kerentanan kode pada proyek Anda yang memerlukan tindakan perbaikan atau patch.
        </p>
      </div>

      <div class="d-flex align-items-center gap-2">
        <router-link to="/workspace/scans" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1">
          <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
          <span>Scan Ulang Proyek</span>
        </router-link>
      </div>
    </div>

    <!-- Status Pipeline Tabs (Perlu Diperbaiki, Sedang Dikerjakan, Selesai, Semua) -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header border-bottom p-2">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button
              class="nav-link"
              :class="{ active: selectedTab === 'open_pending' }"
              type="button"
              @click="selectedTab = 'open_pending'"
            >
              <span class="d-flex align-items-center gap-2">
                <span>Perlu Diperbaiki</span>
                <span class="badge bg-danger-lt text-danger">{{ tabCounts.open_pending }}</span>
              </span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button
              class="nav-link"
              :class="{ active: selectedTab === 'in_progress' }"
              type="button"
              @click="selectedTab = 'in_progress'"
            >
              <span class="d-flex align-items-center gap-2">
                <span>Sedang Dikerjakan</span>
                <span class="badge bg-blue-lt text-blue">{{ tabCounts.in_progress }}</span>
              </span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button
              class="nav-link"
              :class="{ active: selectedTab === 'completed' }"
              type="button"
              @click="selectedTab = 'completed'"
            >
              <span class="d-flex align-items-center gap-2">
                <span>Sudah Diperbaiki</span>
                <span class="badge bg-success-lt text-success">{{ tabCounts.completed }}</span>
              </span>
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button
              class="nav-link"
              :class="{ active: selectedTab === 'all' }"
              type="button"
              @click="selectedTab = 'all'"
            >
              <span class="d-flex align-items-center gap-2">
                <span>Semua Tiket</span>
                <span class="badge bg-secondary-lt text-secondary">{{ tabCounts.all }}</span>
              </span>
            </button>
          </li>
        </ul>
      </div>

      <!-- Filters & Search Toolbar -->
      <div class="card-body p-3">
        <div class="row g-2 align-items-center justify-content-between">
          <!-- Search Box -->
          <div class="col-12 col-md-5">
            <div class="input-icon">
              <span class="input-icon-addon">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
              </span>
              <input
                v-model="searchQuery"
                type="text"
                class="form-control"
                placeholder="Cari kode celah, file, fungsi, CVE, atau judul..."
              />
            </div>
          </div>

          <!-- Dropdowns: Severity & Project -->
          <div class="col-12 col-md-auto d-flex align-items-center gap-2 flex-wrap">
            <!-- Severity Filter -->
            <select v-model="selectedSeverity" class="form-select form-select-sm" style="width: auto;">
              <option value="all">Semua Severity</option>
              <option value="critical">Critical</option>
              <option value="high">High</option>
              <option value="medium">Medium</option>
              <option value="low">Low</option>
            </select>

            <!-- Project Filter -->
            <select v-model="selectedProject" class="form-select form-select-sm" style="width: auto;">
              <option value="all">Semua Proyek Ditugaskan</option>
              <option v-for="p in availableProjects" :key="p.id" :value="String(p.id)">
                {{ p.name }}
              </option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="isLoading" class="card border-0 shadow-sm p-5 text-center text-muted">
      <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
      <div>Memuat tiket celah keamanan proyek Anda...</div>
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredTickets.length === 0" class="card border-0 shadow-sm p-5 text-center text-muted">
      <div class="avatar avatar-xl bg-success-lt text-success rounded-circle mx-auto mb-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg" width="36" height="36" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>
      </div>
      <h3 class="fw-bold mb-1">Tidak Ada Tiket Celah Ditemukan</h3>
      <p class="text-secondary small mb-3">
        {{ searchQuery ? 'Tidak ada tiket yang cocok dengan kata kunci pencarian Anda.' : 'Seluruh tiket pada kategori ini sudah bersih atau belum ada temuan baru.' }}
      </p>
      <div v-if="searchQuery">
        <button type="button" class="btn btn-outline-secondary btn-sm" @click="searchQuery = ''">
          Hapus Pencarian
        </button>
      </div>
    </div>

    <!-- Ticket Cards List -->
    <div v-else class="d-flex flex-column gap-3">
      <div
        v-for="ticket in filteredTickets"
        :key="ticket.id"
        class="card border-0 shadow-sm ticket-item-card p-3 p-md-4"
      >
        <div class="d-flex flex-column flex-md-row align-items-md-start justify-content-between gap-3">
          <!-- Left: Details -->
          <div class="d-flex flex-column gap-2 flex-grow-1">
            <!-- Top Badges -->
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="badge" :class="getSeverityBadge(ticket.severity)">
                {{ ticket.severity?.toUpperCase() }}
              </span>
              <span :class="getStatusBadge(ticket.status)">
                {{ getStatusLabel(ticket.status) }}
              </span>
              <span class="badge bg-secondary-lt text-secondary font-monospace" style="font-size: 0.72rem;">
                {{ ticket.code }}
              </span>
              <span v-if="ticket.cve" class="badge bg-orange-lt text-orange font-monospace" style="font-size: 0.72rem;">
                {{ ticket.cve }}
              </span>
              <span class="badge bg-light text-secondary border">
                Proyek: <strong>{{ ticket.project?.name || 'Proyek' }}</strong>
              </span>
            </div>

            <!-- Title -->
            <h3 class="h3 mb-0 fw-bold text-reset">
              {{ ticket.title }}
            </h3>

            <!-- Description snippet if present -->
            <p v-if="ticket.description" class="text-secondary small mb-0 text-truncate" style="max-width: 800px;">
              {{ ticket.description }}
            </p>

            <!-- Code Location with Copy Button -->
            <div v-if="getLocationDisplay(ticket)" class="d-flex align-items-center gap-2 mt-1">
              <code class="px-2 py-1 bg-body-tertiary border rounded font-monospace text-truncate" style="max-width: 600px; font-size: 0.85rem;">
                📄 {{ getLocationDisplay(ticket) }}
              </code>
              <button
                type="button"
                class="btn btn-xs btn-ghost-secondary px-2 py-1"
                :title="copiedId === ticket.id ? 'Tersalin!' : 'Salin path file'"
                @click="copyLocation(ticket)"
              >
                <span v-if="copiedId === ticket.id" class="text-success small fw-semibold">Tersalin ✓</span>
                <span v-else class="small text-muted">Salin</span>
              </button>
            </div>

            <!-- Resolution / Triage Notes -->
            <div v-if="ticket.normalization_metadata?.triage_notes" class="card card-sm bg-light-subtle border-0 p-2 mt-1">
              <div class="small">
                <strong>Catatan Pengerjaan:</strong> {{ ticket.normalization_metadata.triage_notes }}
                <span v-if="ticket.normalization_metadata.triaged_by" class="text-muted">
                  &bull; Oleh {{ ticket.normalization_metadata.triaged_by }} ({{ formatDate(ticket.normalization_metadata.triaged_at) }})
                </span>
              </div>
            </div>
          </div>

          <!-- Right: Actions Buttons -->
          <div class="d-flex flex-row flex-md-column align-items-end gap-2 flex-shrink-0">
            <!-- AI Guidance Button -->
            <button
              type="button"
              class="btn btn-outline-primary btn-sm d-inline-flex align-items-center gap-1 w-100"
              @click="openAiGuidance(ticket)"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3c1.92 0 3.708 .72 5.074 1.916a9 9 0 1 1 -10.148 0a9 9 0 0 1 5.074 -1.916" /><path d="M12 11l0 6" /><path d="M12 8l.01 0" /></svg>
              <span>Panduan Fix AI</span>
            </button>

            <!-- Mark Fixed / In Progress Button -->
            <button
              v-if="ticket.status !== 'fixed' && ticket.status !== 'resolved'"
              type="button"
              class="btn btn-success btn-sm d-inline-flex align-items-center gap-1 w-100 shadow-sm"
              @click="openQuickFix(ticket)"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
              <span>Tandai Selesai</span>
            </button>

            <button
              v-else
              type="button"
              class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1 w-100"
              @click="openQuickFix(ticket)"
            >
              <span>Ubah Status</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Quick Mark Fixed Modal -->
    <div
      v-if="isFixModalOpen && targetFinding"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      role="dialog"
      style="background: rgba(0, 0, 0, 0.5);"
      @click.self="closeQuickFix"
    >
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header">
            <h5 class="modal-title fw-bold">Update Status Pengerjaan Celah</h5>
            <button type="button" class="btn-close" aria-label="Close" @click="closeQuickFix"></button>
          </div>
          <div class="modal-body">
            <div class="card card-sm mb-3 bg-light-subtle border-0">
              <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-1">
                  <span class="badge" :class="getSeverityBadge(targetFinding.severity)">
                    {{ targetFinding.severity?.toUpperCase() }}
                  </span>
                  <span class="fw-bold">{{ targetFinding.code }}</span>
                </div>
                <div class="fw-semibold text-reset">{{ targetFinding.title }}</div>
                <div v-if="getLocationDisplay(targetFinding)" class="small text-muted mt-1 font-monospace">
                  📄 {{ getLocationDisplay(targetFinding) }}
                </div>
              </div>
            </div>

            <div v-if="fixModalError" class="alert alert-danger py-2 small mb-3">
              {{ fixModalError }}
            </div>

            <div class="mb-3">
              <label class="form-label required">Status Perbaikan</label>
              <select v-model="fixStatusChoice" class="form-select">
                <option value="fixed">Sudah Diperbaiki (Fixed in commit / PR)</option>
                <option value="in_progress">Sedang Dikerjakan (In Progress)</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Catatan Resolusi / Commit / PR Hash</label>
              <textarea
                v-model="fixResolutionNotes"
                class="form-control"
                rows="3"
                placeholder="Contoh: Sudah diperbaiki di commit a7c21f9 di branch feature/security-patch. Query SQL telah diganti menggunakan prepared statement."
              ></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="closeQuickFix">
              Batal
            </button>
            <button
              type="button"
              class="btn btn-success d-inline-flex align-items-center gap-2"
              :disabled="isSavingFix"
              @click="submitQuickFix"
            >
              <span v-if="isSavingFix" class="spinner-border spinner-border-sm" role="status"></span>
              <span>Simpan Status</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- AI Remediation Modal (DevSecOps Interactive Patch Workbench) -->
    <div
      v-if="isAiModalOpen && activeGuidanceFinding"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      role="dialog"
      style="background: rgba(0, 0, 0, 0.65); backdrop-filter: blur(2px);"
      @click.self="closeAiGuidance"
    >
      <div class="modal-dialog modal-dialog-centered modal-xl" role="document" style="max-width: 960px;">
        <div class="modal-content border-0 shadow-xl overflow-hidden">
          <!-- Modal Header -->
          <div class="modal-header py-3 px-4 border-bottom bg-surface">
            <div class="d-flex align-items-center gap-3">
              <span class="avatar avatar-md bg-teal-lt text-teal rounded-circle shadow-sm">
                <!-- Sparkles / AI Robot Icon -->
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                  <path d="M16 18a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm0 -12a2 2 0 0 1 2 2a2 2 0 0 1 2 -2a2 2 0 0 1 -2 -2a2 2 0 0 1 -2 2zm-7 12a6 6 0 0 1 6 -6a6 6 0 0 1 -6 -6a6 6 0 0 1 -6 6a6 6 0 0 1 6 6z" />
                </svg>
              </span>
              <div>
                <div class="d-flex align-items-center gap-2">
                  <h4 class="modal-title fw-bold mb-0">Panduan Remediasi AI (DevSecOps Patch)</h4>
                  <span class="badge bg-teal-lt text-teal">AI Powered</span>
                </div>
                <div class="small text-secondary mt-0">
                  Analisis kerentanan cerdas dan rekomendasi kode perbaikan siap implementasi.
                </div>
              </div>
            </div>
            <button type="button" class="btn-close" aria-label="Close" @click="closeAiGuidance"></button>
          </div>

          <!-- Finding Context Strip -->
          <div class="px-4 py-2 bg-body border-bottom d-flex flex-wrap align-items-center justify-content-between gap-2">
            <div class="d-flex align-items-center gap-2">
              <span class="badge" :class="getSeverityBadge(activeGuidanceFinding.severity)">
                {{ activeGuidanceFinding.severity?.toUpperCase() }}
              </span>
              <span class="fw-bold text-reset font-monospace">{{ activeGuidanceFinding.code }}</span>
              <span class="text-secondary small text-truncate" style="max-width: 460px;" :title="activeGuidanceFinding.title">
                &bull; {{ activeGuidanceFinding.title }}
              </span>
            </div>
            <div v-if="getLocationDisplay(activeGuidanceFinding)" class="d-inline-flex align-items-center gap-1 small text-muted font-monospace bg-surface px-2 py-1 rounded border">
              📄 {{ getLocationDisplay(activeGuidanceFinding) }}
            </div>
          </div>

          <!-- Modal Body -->
          <div class="modal-body p-4" style="max-height: 70vh; overflow-y: auto;">
            <!-- Loading State -->
            <div v-if="isLoadingAi" class="py-5 text-center text-muted">
              <div class="spinner-border text-teal mb-3" style="width: 2.5rem; height: 2.5rem;" role="status"></div>
              <h5 class="fw-bold mb-1">Menganalisis Pola Kerentanan...</h5>
              <div class="small text-secondary">Mesin AI TAMENG sedang menyusun rekomendasi mitigasi dan kode patch perbaikan.</div>
            </div>

            <!-- Loaded Content -->
            <div v-else-if="aiRemediationData">
              <!-- Category & Summary Banner -->
              <div class="card mb-4 border-start border-4 border-start-primary bg-surface shadow-sm">
                <div class="card-body p-3">
                  <div class="d-flex align-items-center justify-content-between mb-1">
                    <span class="badge bg-primary-lt text-primary text-uppercase font-monospace" style="font-size: 0.72rem;">
                      {{ aiRemediationData.category || 'Kerentanan Keamanan Aplikasi' }}
                    </span>
                    <span v-if="aiRemediationData.compliance?.cwe" class="small text-muted font-monospace">
                      {{ aiRemediationData.compliance.cwe }}
                    </span>
                  </div>
                  <p class="mb-0 text-reset" style="line-height: 1.6;">
                    {{ aiRemediationData.summary }}
                  </p>
                </div>
              </div>

              <!-- Remediation Tabs: Solusi Kode, Analisis Risiko, Langkah Mitigasi -->
              <div class="mb-3">
                <ul class="nav nav-pills gap-1" role="tablist">
                  <li class="nav-item">
                    <button
                      class="nav-link btn-sm"
                      :class="{ active: aiActiveTab === 'solution' }"
                      type="button"
                      @click="aiActiveTab = 'solution'"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 8l-4 4l4 4" /><path d="M17 8l4 4l-4 4" /><path d="M14 4l-4 16" /></svg>
                      Solusi Perbaikan Kode
                    </button>
                  </li>
                  <li class="nav-item">
                    <button
                      class="nav-link btn-sm"
                      :class="{ active: aiActiveTab === 'diff' }"
                      type="button"
                      @click="aiActiveTab = 'diff'"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M16 3l0 4" /><path d="M8 3l0 4" /><path d="M4 11l16 0" /><path d="M11 15l1 0" /><path d="M12 15l0 3" /></svg>
                      Perbandingan Diff
                    </button>
                  </li>
                  <li class="nav-item">
                    <button
                      class="nav-link btn-sm"
                      :class="{ active: aiActiveTab === 'analysis' }"
                      type="button"
                      @click="aiActiveTab = 'analysis'"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
                      Akar Masalah & Vektor Serangan
                    </button>
                  </li>
                  <li class="nav-item">
                    <button
                      class="nav-link btn-sm"
                      :class="{ active: aiActiveTab === 'checklist' }"
                      type="button"
                      @click="aiActiveTab = 'checklist'"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3.5 5.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 11.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 17.5l1.5 1.5l2.5 -2.5" /><path d="M11 6l9 0" /><path d="M11 12l9 0" /><path d="M11 18l9 0" /></svg>
                      Langkah Mitigasi
                    </button>
                  </li>
                </ul>
              </div>

              <!-- TAB 1: Solusi Perbaikan Kode (Kondisi Rentan vs Solusi Aman) -->
              <div v-if="aiActiveTab === 'solution'" class="d-flex flex-column gap-3">
                <!-- Kode Rentan (Sebelum) -->
                <div v-if="aiRemediationData.vulnerable_code" class="card border border-danger-subtle shadow-none">
                  <div class="card-header py-2 px-3 bg-danger-lt d-flex align-items-center justify-content-between">
                    <span class="small fw-bold text-danger d-flex align-items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                      KONDISI RENTAN (KODE SEBELUM PERBAIKAN)
                    </span>
                  </div>
                  <div class="card-body p-0">
                    <pre class="m-0 p-3 bg-dark text-danger-lt font-monospace small" style="white-space: pre-wrap; line-height: 1.5;"><code>{{ aiRemediationData.vulnerable_code }}</code></pre>
                  </div>
                </div>

                <!-- Kode Solusi Aman (Sesudah) -->
                <div class="card border border-success-subtle shadow-none">
                  <div class="card-header py-2 px-3 bg-success-lt d-flex align-items-center justify-content-between">
                    <span class="small fw-bold text-success d-flex align-items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                      REKOMENDASI SOLUSI AMAN (SIAP DITERAPKAN)
                    </span>
                    <button
                      type="button"
                      class="btn btn-xs btn-outline-success d-inline-flex align-items-center gap-1"
                      @click="copyToClipboard(aiRemediationData.secure_code || aiRemediationData.code_diff)"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 8m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /><path d="M16 8v-2a2 2 0 0 0 -2 -2h-8a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2" /></svg>
                      <span>{{ copiedCode ? 'Tersalin!' : 'Salin Solusi Kode' }}</span>
                    </button>
                  </div>
                  <div class="card-body p-0">
                    <pre class="m-0 p-3 bg-dark text-success-lt font-monospace small" style="white-space: pre-wrap; line-height: 1.5;"><code>{{ aiRemediationData.secure_code || aiRemediationData.code_diff || 'Terapkan sanitasi input dan pisahkan kredensial ke .env' }}</code></pre>
                  </div>
                </div>
              </div>

              <!-- TAB 2: Perbandingan Diff -->
              <div v-else-if="aiActiveTab === 'diff'">
                <div class="card border-0 shadow-none">
                  <div class="card-header py-2 px-3 bg-surface border-bottom d-flex align-items-center justify-content-between">
                    <span class="small fw-bold font-monospace">Unified Code Patch (Diff)</span>
                    <button
                      type="button"
                      class="btn btn-xs btn-outline-secondary d-inline-flex align-items-center gap-1"
                      @click="copyToClipboard(aiRemediationData.code_diff)"
                    >
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 8m0 2a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2z" /><path d="M16 8v-2a2 2 0 0 0 -2 -2h-8a2 2 0 0 0 -2 2v8a2 2 0 0 0 2 2h2" /></svg>
                      <span>{{ copiedCode ? 'Tersalin!' : 'Salin Patch' }}</span>
                    </button>
                  </div>
                  <div class="card-body p-0">
                    <pre class="m-0 p-3 bg-dark text-light font-monospace small" style="white-space: pre-wrap; line-height: 1.5;"><code>{{ aiRemediationData.code_diff || 'Tidak ada unified diff khusus untuk temuan ini.' }}</code></pre>
                  </div>
                </div>
              </div>

              <!-- TAB 3: Akar Masalah & Vektor Serangan -->
              <div v-else-if="aiActiveTab === 'analysis'" class="row g-3">
                <div class="col-md-6">
                  <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-3">
                      <div class="d-flex align-items-center gap-2 mb-2 text-warning">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
                        <h5 class="fw-bold mb-0">Akar Masalah (Cause)</h5>
                      </div>
                      <p class="small text-secondary mb-0" style="line-height: 1.6;">
                        {{ aiRemediationData.cause || 'Kurangnya kontrol sanitasi dan pemisahan kredensial pada kode sumber.' }}
                      </p>
                    </div>
                  </div>
                </div>

                <div class="col-md-6">
                  <div class="card h-100 border-0 shadow-sm">
                    <div class="card-body p-3">
                      <div class="d-flex align-items-center gap-2 mb-2 text-danger">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
                        <h5 class="fw-bold mb-0">Dampak Risiko (Impact)</h5>
                      </div>
                      <p class="small text-secondary mb-0" style="line-height: 1.6;">
                        {{ aiRemediationData.business_impact || 'Potensi kebocoran data sensitif dan kompromi sistem produksi.' }}
                      </p>
                    </div>
                  </div>
                </div>

                <div v-if="aiRemediationData.attack_vector" class="col-12">
                  <div class="card border-0 shadow-sm">
                    <div class="card-body p-3">
                      <div class="d-flex align-items-center gap-2 mb-2 text-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7" /></svg>
                        <h5 class="fw-bold mb-0">Skenario Serangan (Attack Vector)</h5>
                      </div>
                      <p class="small text-secondary mb-0" style="white-space: pre-wrap; line-height: 1.6;">
                        {{ aiRemediationData.attack_vector }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>

              <!-- TAB 4: Langkah Mitigasi & Verifikasi -->
              <div v-else-if="aiActiveTab === 'checklist'" class="d-flex flex-column gap-3">
                <div class="card border-0 shadow-sm">
                  <div class="card-body p-3">
                    <h5 class="fw-bold mb-3 d-flex align-items-center gap-2 text-teal">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 11l3 3l8 -8" /><path d="M20 12v6a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2h9" /></svg>
                      Daftar Tindakan Mitigasi
                    </h5>
                    <div v-if="aiRemediationData.mitigation_checklist?.length" class="list-group list-group-flush">
                      <div
                        v-for="(step, sIdx) in aiRemediationData.mitigation_checklist"
                        :key="sIdx"
                        class="list-group-item px-0 py-2 d-flex align-items-start gap-2 border-0"
                      >
                        <span class="badge bg-teal-lt text-teal rounded-circle px-2 py-1 mt-1">{{ sIdx + 1 }}</span>
                        <span class="text-reset" style="line-height: 1.5;">{{ step }}</span>
                      </div>
                    </div>
                    <div v-else class="text-secondary small">
                      Terapkan patch kode yang direkomendasikan pada tab Solusi Perbaikan Kode.
                    </div>
                  </div>
                </div>

                <div v-if="aiRemediationData.verification_command" class="card border-0 shadow-sm">
                  <div class="card-body p-3">
                    <h5 class="fw-bold mb-2 small text-uppercase text-secondary font-monospace">Metode / Perintah Pengujian Verifikasi</h5>
                    <pre class="m-0 p-2 bg-dark text-light rounded font-monospace small"><code>{{ aiRemediationData.verification_command }}</code></pre>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Modal Footer -->
          <div class="modal-footer py-3 px-4 bg-surface border-top d-flex align-items-center justify-content-between">
            <button type="button" class="btn btn-secondary" @click="closeAiGuidance">
              Tutup
            </button>
            <button
              type="button"
              class="btn btn-success d-inline-flex align-items-center gap-2 shadow-sm"
              @click="closeAiGuidance(); openQuickFix(activeGuidanceFinding);"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
              <span>Terapkan Solusi & Tandai Selesai</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ticket-item-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.ticket-item-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
}
</style>
