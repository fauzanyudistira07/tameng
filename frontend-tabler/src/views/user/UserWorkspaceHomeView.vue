<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { apiFetch } from '../../services/api'
import { useAuth } from '../../composables/useAuth'

const router = useRouter()
const { currentUser, roleDisplayName, assignedProjects } = useAuth()

interface Finding {
  id: number
  code: string
  title: string
  severity: string
  status: string
  asset_type?: string
  asset_identifier?: string
  normalization_metadata?: {
    location?: {
      file?: string
      line?: number
      component?: string
    }
    triage_notes?: string
    triaged_by?: string
  }
  project?: {
    id: number
    name: string
    code: string
  }
  created_at: string
}

interface Repository {
  id: number
  name: string
  url: string
  default_branch: string
  project?: {
    id: number
    name: string
  }
  created_at: string
}

interface ScanRequest {
  id: number
  code: string
  status: string
  scan_profile?: {
    name: string
  }
  project?: {
    name: string
  }
  created_at: string
}

const findings = ref<Finding[]>([])
const repositories = ref<Repository[]>([])
const recentScans = ref<ScanRequest[]>([])
const isLoading = ref(true)
const fetchError = ref<string | null>(null)

// Quick Fix Modal State
const targetFinding = ref<Finding | null>(null)
const fixStatusChoice = ref<'fixed' | 'in_progress' | 'open'>('fixed')
const fixResolutionNotes = ref('')
const isSavingFix = ref(false)
const fixModalError = ref<string | null>(null)
const isFixModalOpen = ref(false)
const updatingTicketId = ref<number | null>(null)

async function loadDashboardData() {
  isLoading.value = true
  fetchError.value = null
  try {
    const [findingsRes, reposRes, scansRes] = await Promise.all([
      apiFetch('/api/findings').catch(() => ({ findings: [] })),
      apiFetch('/api/repositories').catch(() => ({ repositories: [] })),
      apiFetch('/api/my/scan-requests').catch(() => ({ scan_requests: [] }))
    ])

    findings.value = findingsRes?.findings || []
    repositories.value = reposRes?.repositories || []
    recentScans.value = (scansRes?.scan_requests || []).slice(0, 5)
  } catch (err: any) {
    fetchError.value = err?.message || 'Gagal memuat data ruang kerja.'
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  loadDashboardData()
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && isFixModalOpen.value) {
    closeQuickFix()
  }
}

// Metrics
const metrics = computed(() => {
  const openCount = findings.value.filter(f => f.status === 'open' || f.status === 'reviewing').length
  const inProgressCount = findings.value.filter(f => f.status === 'in_progress').length
  const fixedCount = findings.value.filter(f => f.status === 'fixed' || f.status === 'resolved').length
  const totalRepos = repositories.value.length

  return { openCount, inProgressCount, fixedCount, totalRepos }
})

// Priority tickets (Critical & High that are not fixed yet)
const priorityTickets = computed(() => {
  return findings.value
    .filter(f => f.status !== 'fixed' && f.status !== 'resolved')
    .sort((a, b) => {
      const order: Record<string, number> = { critical: 4, high: 3, medium: 2, low: 1, informational: 0 }
      return (order[b.severity?.toLowerCase()] || 0) - (order[a.severity?.toLowerCase()] || 0)
    })
    .slice(0, 6)
})

function getLocation(f: Finding): string | null {
  const loc = f.normalization_metadata?.location
  if (loc?.file) {
    return loc.line ? `${loc.file}:${loc.line}` : loc.file
  }
  if (f.asset_identifier && !f.asset_identifier.startsWith('http')) {
    return f.asset_identifier
  }
  return null
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

function openQuickFix(f: Finding, defaultStatus?: 'fixed' | 'in_progress' | 'open') {
  targetFinding.value = f
  fixStatusChoice.value = defaultStatus || (f.status === 'in_progress' ? 'fixed' : 'in_progress')
  fixResolutionNotes.value = f.normalization_metadata?.triage_notes || ''
  fixModalError.value = null
  isFixModalOpen.value = true
}

async function startWorking(ticket: Finding) {
  updatingTicketId.value = ticket.id
  try {
    const res = await apiFetch(`/api/findings/${ticket.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        status: 'in_progress',
        resolution_notes: `Mulai dikerjakan oleh ${currentUser.value?.name || 'developer'}`
      })
    })

    if (res?.finding) {
      const idx = findings.value.findIndex(f => f.id === ticket.id)
      if (idx !== -1) {
        findings.value[idx].status = res.finding.status
        findings.value[idx].normalization_metadata = res.finding.normalization_metadata
      }
    }
  } catch (err: any) {
    console.error('Gagal memperbarui status pengerjaan:', err)
  } finally {
    updatingTicketId.value = null
  }
}

async function markAsFixed(ticket: Finding) {
  updatingTicketId.value = ticket.id
  try {
    const res = await apiFetch(`/api/findings/${ticket.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        status: 'fixed',
        resolution_notes: `Diselesaikan oleh ${currentUser.value?.name || 'developer'}`
      })
    })

    if (res?.finding) {
      const idx = findings.value.findIndex(f => f.id === ticket.id)
      if (idx !== -1) {
        findings.value[idx].status = res.finding.status
        findings.value[idx].normalization_metadata = res.finding.normalization_metadata
      }
    }
  } catch (err: any) {
    console.error('Gagal menyelesaikan tiket:', err)
  } finally {
    updatingTicketId.value = null
  }
}

function startScanForRepo(repo: any) {
  const targetUrl = repo.url && (repo.url.startsWith('http://') || repo.url.startsWith('https://'))
    ? repo.url
    : (repo.name.includes('/') ? `https://github.com/${repo.name}.git` : repo.name)

  router.push({
    path: '/workspace/scans',
    query: {
      action: 'new',
      scan_type: 'repository',
      project_name: repo.project?.name || repo.name,
      asset_url: targetUrl,
      branch: repo.default_branch || 'main'
    }
  })
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
      resolution_notes: fixResolutionNotes.value.trim() || 'Diperbaiki oleh pengembang di commit/PR'
    }

    const res = await apiFetch(`/api/findings/${targetFinding.value.id}`, {
      method: 'PUT',
      body: JSON.stringify(payload)
    })

    if (res?.finding) {
      const idx = findings.value.findIndex(f => f.id === targetFinding.value?.id)
      if (idx !== -1) {
        findings.value[idx].status = res.finding.status
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

function formatDate(d?: string) {
  if (!d) return '-'
  try {
    const dt = new Date(d)
    return dt.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
  } catch {
    return d
  }
}
</script>

<template>
  <div class="user-workspace-home">
    <!-- Welcome Greeting & Quick Action Banner -->
    <div class="card mb-4 border-0 shadow-sm">
      <div class="card-body p-3 p-md-4">
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
          <div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <h1 class="h2 mb-0 fw-bold">
                Selamat Datang, {{ currentUser?.name || 'Pengembang' }} 👋
              </h1>
              <span class="badge bg-teal-lt text-teal fs-6 fw-medium d-none d-sm-inline-block">
                {{ roleDisplayName }}
              </span>
            </div>
            <p class="text-secondary mb-0">
              Berikut ruang kerja personal Anda untuk memantau keamanan kode, memperbaiki tiket celah, dan menjalankan scan mandiri.
            </p>
          </div>

          <!-- Action Buttons -->
          <div class="d-flex flex-wrap align-items-center gap-2">
            <router-link to="/workspace/scans" class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
              <span>Scan Mandiri</span>
            </router-link>

            <router-link to="/workspace/files" class="btn btn-outline-secondary d-inline-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
              <span>Berkas & Repositori</span>
            </router-link>
          </div>
        </div>
      </div>
    </div>

    <!-- Personal Workspace Metric Cards (Clean, 4 items) -->
    <div class="row row-cards mb-4">
      <!-- 1. Tiket Perlu Diperbaiki -->
      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm border-0 shadow-sm h-100 hover-shadow">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="avatar bg-danger-lt text-danger rounded me-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 9v4" /><path d="M12 17h.01" /><path d="M5 19h14a2 2 0 0 0 1.84 -2.75l-7.1 -12.25a2 2 0 0 0 -3.5 0l-7.1 12.25a2 2 0 0 0 1.75 2.75" /></svg>
              </div>
              <div>
                <div class="h2 mb-0 fw-bold text-danger">{{ isLoading ? '...' : metrics.openCount }}</div>
                <div class="text-secondary small fw-medium">Perlu Diperbaiki</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 2. Sedang Dikerjakan -->
      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm border-0 shadow-sm h-100 hover-shadow">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="avatar bg-blue-lt text-blue rounded me-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
              </div>
              <div>
                <div class="h2 mb-0 fw-bold text-blue">{{ isLoading ? '...' : metrics.inProgressCount }}</div>
                <div class="text-secondary small fw-medium">Sedang Dikerjakan</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 3. Sudah Diperbaiki -->
      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm border-0 shadow-sm h-100 hover-shadow">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="avatar bg-success-lt text-success rounded me-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
              </div>
              <div>
                <div class="h2 mb-0 fw-bold text-success">{{ isLoading ? '...' : metrics.fixedCount }}</div>
                <div class="text-secondary small fw-medium">Sudah Diperbaiki</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- 4. Total Repositori & Aset -->
      <div class="col-sm-6 col-lg-3">
        <div class="card card-sm border-0 shadow-sm h-100 hover-shadow">
          <div class="card-body">
            <div class="d-flex align-items-center">
              <div class="avatar bg-purple-lt text-purple rounded me-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
              </div>
              <div>
                <div class="h2 mb-0 fw-bold text-purple">{{ isLoading ? '...' : metrics.totalRepos }}</div>
                <div class="text-secondary small fw-medium">Repositori Ditugaskan</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Main Workspace Section: Priority Fixes & Assigned Assets -->
    <div class="row row-cards">
      <!-- Left Column: Priority Fix Tickets (7 cols) -->
      <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
          <div class="card-header py-3 d-flex align-items-center justify-content-between border-bottom">
            <div class="d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-teal" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3.5 5.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 11.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 17.5l1.5 1.5l2.5 -2.5" /><path d="M11 6l9 0" /><path d="M11 12l9 0" /><path d="M11 18l9 0" /></svg>
              <h3 class="card-title mb-0 fw-bold">Tiket Perbaikan Prioritas</h3>
            </div>
            <router-link to="/workspace/tickets" class="btn btn-sm btn-ghost-primary">
              Lihat Semua Tiket ({{ findings.length }}) &rarr;
            </router-link>
          </div>

          <!-- Loading State -->
          <div v-if="isLoading" class="p-5 text-center text-muted">
            <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
            <div>Memuat tiket perbaikan Anda...</div>
          </div>

          <!-- Empty State -->
          <div v-else-if="priorityTickets.length === 0" class="p-5 text-center text-muted">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xl text-success mb-2" width="48" height="48" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M9 12l2 2l4 -4" /></svg>
            <h4 class="fw-bold mb-1">Hebat! Semua Tiket Teratasi</h4>
            <p class="small text-secondary mb-3">Tidak ada tiket celah prioritas yang menunggu perbaikan pada proyek Anda.</p>
            <router-link to="/workspace/scans" class="btn btn-sm btn-outline-primary">
              Jalankan Scan Baru
            </router-link>
          </div>

          <!-- Ticket List -->
          <div v-else class="list-group list-group-flush">
            <div
              v-for="ticket in priorityTickets"
              :key="ticket.id"
              class="list-group-item p-3 list-group-item-action d-flex flex-column gap-2"
            >
              <div class="d-flex align-items-start justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <span class="badge" :class="getSeverityBadge(ticket.severity)">
                    {{ ticket.severity?.toUpperCase() }}
                  </span>
                  <span class="fw-bold text-reset" style="font-size: 0.95rem;">
                    {{ ticket.title }}
                  </span>
                </div>
                <span :class="getStatusBadge(ticket.status)">
                  {{ getStatusLabel(ticket.status) }}
                </span>
              </div>

              <!-- Location Monospace Badge -->
              <div v-if="getLocation(ticket)" class="d-flex align-items-center gap-2">
                <code class="px-2 py-1 bg-body-tertiary border rounded text-truncate" style="max-width: 480px; font-size: 0.8rem;">
                  📄 {{ getLocation(ticket) }}
                </code>
              </div>

              <!-- Meta Footer & Action Button -->
              <div class="d-flex align-items-center justify-content-between pt-1 text-muted small">
                <div class="d-flex align-items-center gap-3">
                  <span>Proyek: <strong>{{ ticket.project?.name || 'Proyek' }}</strong></span>
                  <span>{{ formatDate(ticket.created_at) }}</span>
                </div>

                <div class="d-flex align-items-center gap-2">
                  <!-- Mulai Kerjakan if open -->
                  <button
                    v-if="ticket.status === 'open' || ticket.status === 'reviewing'"
                    type="button"
                    class="btn btn-sm btn-primary d-inline-flex align-items-center gap-1 shadow-sm"
                    :disabled="updatingTicketId === ticket.id"
                    @click="startWorking(ticket)"
                    title="Mulai kerjakan perbaikan celah ini sekarang"
                  >
                    <span v-if="updatingTicketId === ticket.id" class="spinner-border spinner-border-sm" role="status"></span>
                    <template v-else>
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 4v16l13 -8z" /></svg>
                      <span>Mulai Kerjakan</span>
                    </template>
                  </button>

                  <!-- Tandai Selesai (Direct 1-Click Action) -->
                  <button
                    type="button"
                    class="btn btn-sm d-inline-flex align-items-center gap-1"
                    :class="ticket.status === 'in_progress' ? 'btn-success shadow-sm' : 'btn-outline-success'"
                    :disabled="updatingTicketId === ticket.id"
                    @click="markAsFixed(ticket)"
                    title="Langsung tandai celah ini selesai diperbaiki"
                  >
                    <span v-if="updatingTicketId === ticket.id" class="spinner-border spinner-border-sm" role="status"></span>
                    <template v-else>
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                      <span>Tandai Selesai</span>
                    </template>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right Column: Assigned Repositories & Recent Scans (5 cols) -->
      <div class="col-lg-5">
        <div class="d-flex flex-column gap-4">
          <!-- 1. Repositori Kode Saya -->
          <div class="card border-0 shadow-sm">
            <div class="card-header py-3 d-flex align-items-center justify-content-between border-bottom">
              <div class="d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
                <h3 class="card-title mb-0 fw-bold">Repositori Ditugaskan</h3>
              </div>
              <router-link to="/workspace/files" class="btn btn-sm btn-ghost-primary">
                Semua Berkas &rarr;
              </router-link>
            </div>

            <div v-if="isLoading" class="p-4 text-center text-muted">
              <div class="spinner-border spinner-border-sm text-primary mb-1" role="status"></div>
            </div>

            <div v-else-if="repositories.length === 0" class="p-4 text-center text-muted">
              <div class="small">Belum ada repositori yang ditugaskan ke akun Anda.</div>
            </div>

            <div v-else class="list-group list-group-flush">
              <div
                v-for="repo in repositories.slice(0, 4)"
                :key="repo.id"
                class="list-group-item p-3 d-flex align-items-center justify-content-between"
              >
                <div class="d-flex align-items-center gap-3">
                  <span class="avatar avatar-sm bg-azure-lt text-azure rounded">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 19c-4.3 1.4 -4.3 -2.5 -6 -3m12 5v-3.5c0 -1 .1 -1.4 -.5 -2c2.8 -.3 5.5 -1.4 5.5 -6a4.6 4.6 0 0 0 -1.3 -3.2a4.2 4.2 0 0 0 -.1 -3.2s-1.1 -.3 -3.5 1.3a12.3 12.3 0 0 0 -6.2 0c-2.4 -1.6 -3.5 -1.3 -3.5 -1.3a4.2 4.2 0 0 0 -.1 3.2a4.6 4.6 0 0 0 -1.3 3.2c0 4.6 2.7 5.7 5.5 6c-.6 .6 -.6 1.2 -.5 2v3.5" /></svg>
                  </span>
                  <div>
                    <div class="fw-bold text-reset">{{ repo.name }}</div>
                    <div class="small text-muted">
                      Proyek: <strong>{{ repo.project?.name || '-' }}</strong> &bull; Branch: {{ repo.default_branch || 'main' }}
                    </div>
                  </div>
                </div>

                <button
                  type="button"
                  class="btn btn-sm btn-ghost-primary d-inline-flex align-items-center gap-1"
                  title="Ajukan scan untuk repositori ini"
                  @click="startScanForRepo(repo)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3" /><path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" /></svg>
                  <span>Scan</span>
                </button>
              </div>
            </div>
          </div>

          <!-- 2. Riwayat Scan Mandiri Terbaru -->
          <div class="card border-0 shadow-sm">
            <div class="card-header py-3 d-flex align-items-center justify-content-between border-bottom">
              <div class="d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon text-yellow" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
                <h3 class="card-title mb-0 fw-bold">Aktivitas Scan Saya</h3>
              </div>
              <router-link to="/workspace/scans" class="btn btn-sm btn-ghost-primary">
                Semua Riwayat &rarr;
              </router-link>
            </div>

            <div v-if="isLoading" class="p-4 text-center text-muted">
              <div class="spinner-border spinner-border-sm text-primary mb-1" role="status"></div>
            </div>

            <div v-else-if="recentScans.length === 0" class="p-4 text-center text-muted">
              <div class="small">Belum ada riwayat pengajuan scan yang Anda buat.</div>
              <router-link to="/workspace/scans" class="btn btn-sm btn-primary mt-2">
                Ajukan Scan Baru
              </router-link>
            </div>

            <div v-else class="list-group list-group-flush">
              <div
                v-for="scan in recentScans"
                :key="scan.id"
                class="list-group-item p-3 d-flex align-items-center justify-content-between"
              >
                <div>
                  <div class="fw-semibold">{{ scan.code }}</div>
                  <div class="small text-muted">
                    {{ scan.scan_profile?.name || 'Full Scan' }} &bull; {{ formatDate(scan.created_at) }}
                  </div>
                </div>

                <span
                  class="badge"
                  :class="{
                    'bg-warning text-dark fw-bold': scan.status === 'pending_approval',
                    'bg-success-lt text-success': scan.status === 'completed',
                    'bg-danger-lt text-danger': scan.status === 'failed' || scan.status === 'cancelled',
                    'bg-azure-lt text-azure': scan.status === 'queued',
                    'bg-blue-lt text-blue': scan.status === 'processing' || scan.status === 'running'
                  }"
                >
                  {{ scan.status === 'pending_approval' ? 'Menunggu Approval' : (scan.status === 'cancelled' ? 'Ditolak' : scan.status) }}
                </span>
              </div>
            </div>
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
            <h5 class="modal-title fw-bold">
              Tandai Perbaikan Celah
            </h5>
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
                <div v-if="getLocation(targetFinding)" class="small text-muted mt-1 font-monospace">
                  📄 {{ getLocation(targetFinding) }}
                </div>
              </div>
            </div>

            <div v-if="fixModalError" class="alert alert-danger py-2 small mb-3">
              {{ fixModalError }}
            </div>

            <div class="mb-3">
              <label class="form-label required">Status Perbaikan</label>
              <select v-model="fixStatusChoice" class="form-select">
                <option value="in_progress">Sedang Dikerjakan (In Progress - Mulai Kerjakan Sekarang)</option>
                <option value="fixed">Sudah Diperbaiki (Fixed in commit / PR)</option>
                <option value="open">Perlu Diperbaiki (Open - Kembalikan ke Antrean Backlog)</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label">Catatan Perbaikan (Commit hash, branch, atau nomor PR)</label>
              <textarea
                v-model="fixResolutionNotes"
                class="form-control"
                rows="3"
                placeholder="Contoh: Sudah diperbaiki pada commit 7f3a2b1 di branch fix/sql-injection, parameter telah disanitasi menggunakan query builder."
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
  </div>
</template>

<style scoped>
.hover-shadow {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.hover-shadow:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
}
</style>
