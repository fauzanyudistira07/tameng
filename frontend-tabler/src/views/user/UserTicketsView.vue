<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
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
const aiGuidanceText = ref<string | null>(null)
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
})

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

// AI Remediation Modal
async function openAiGuidance(f: Finding) {
  activeGuidanceFinding.value = f
  aiGuidanceText.value = null
  isLoadingAi.value = true
  isAiModalOpen.value = true

  try {
    const res = await apiFetch(`/api/findings/${f.id}/ai-remediation`)
    aiGuidanceText.value = res?.remediation?.guidance || res?.remediation || 'Panduan perbaikan AI berhasil digenerate.'
  } catch (err: any) {
    aiGuidanceText.value = 'Panduan perbaikan: Validasi input pengguna di sisi server, terapkan prepared statement/parameterized queries, dan lakukan sanitasi output.'
  } finally {
    isLoadingAi.value = false
  }
}

function closeAiGuidance() {
  isAiModalOpen.value = false
  activeGuidanceFinding.value = null
  aiGuidanceText.value = null
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

    <!-- AI Remediation Modal -->
    <div
      v-if="isAiModalOpen && activeGuidanceFinding"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      role="dialog"
      style="background: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header">
            <div class="d-flex align-items-center gap-2">
              <span class="avatar avatar-xs bg-primary-lt text-primary rounded">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3c1.92 0 3.708 .72 5.074 1.916a9 9 0 1 1 -10.148 0a9 9 0 0 1 5.074 -1.916" /><path d="M12 11l0 6" /><path d="M12 8l.01 0" /></svg>
              </span>
              <h5 class="modal-title fw-bold">Panduan Remediasi AI (AI Remediation Guidance)</h5>
            </div>
            <button type="button" class="btn-close" aria-label="Close" @click="closeAiGuidance"></button>
          </div>
          <div class="modal-body">
            <div class="card card-sm mb-3 bg-light-subtle border-0">
              <div class="card-body">
                <div class="fw-bold">{{ activeGuidanceFinding.code }} &bull; {{ activeGuidanceFinding.title }}</div>
                <div v-if="getLocationDisplay(activeGuidanceFinding)" class="small text-muted font-monospace mt-1">
                  📄 {{ getLocationDisplay(activeGuidanceFinding) }}
                </div>
              </div>
            </div>

            <div v-if="isLoadingAi" class="p-5 text-center text-muted">
              <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
              <div>Menganalisis kode dan menyusun langkah perbaikan dari mesin AI...</div>
            </div>

            <div v-else class="p-3 border rounded bg-body" style="white-space: pre-wrap; font-family: inherit; line-height: 1.6;">
              {{ aiGuidanceText }}
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="closeAiGuidance">
              Tutup
            </button>
            <button
              type="button"
              class="btn btn-success"
              @click="closeAiGuidance(); openQuickFix(activeGuidanceFinding);"
            >
              Tandai Sudah Selesai Diperbaiki
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
