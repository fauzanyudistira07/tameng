<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '../services/api'

interface AuditUser {
  id: number
  name: string
  email: string
}

interface AuditProject {
  id: number
  name: string
  code: string
}

interface AuditAuth {
  id: number
  code: string
}

interface AuditScanJob {
  id: number
  code: string
  status: string
}

interface AuditLogItem {
  id: number
  user_id?: number
  project_id?: number
  authorization_id?: number
  scan_job_id?: number
  action: string
  result: string
  actor_ip?: string
  target_type?: string
  target_id?: number
  metadata?: any
  created_at: string
  user?: AuditUser
  project?: AuditProject
  authorization?: AuditAuth
  scanJob?: AuditScanJob
  scan_job?: AuditScanJob
}

const auditLogs = ref<AuditLogItem[]>([])
const isLoading = ref(true)

// Filter states
const searchQuery = ref('')
const filterResult = ref<string>('all')
const filterActionCategory = ref<string>('all')

function resetFilters() {
  searchQuery.value = ''
  filterResult.value = 'all'
  filterActionCategory.value = 'all'
}

// Modal Detail state
const isDetailModalOpen = ref(false)
const selectedLog = ref<AuditLogItem | null>(null)
const copySuccess = ref(false)

async function loadData() {
  isLoading.value = true
  try {
    const res = await apiFetch('/api/audit-logs')
    auditLogs.value = Array.isArray(res?.audit_logs) ? res.audit_logs : (Array.isArray(res) ? res : [])
  } catch (err: any) {
    console.error('Gagal memuat log audit forensik:', err)
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  const list = auditLogs.value
  const total = list.length
  const uniqueUsers = new Set(list.map(l => l.user?.id || l.user_id).filter(Boolean)).size
  const successCount = list.filter(l => l.result?.toLowerCase() === 'success').length
  const successRate = total > 0 ? Math.round((successCount / total) * 100) : 100

  // Count events today
  const todayStr = new Date().toISOString().slice(0, 10)
  const todayEvents = list.filter(l => l.created_at?.slice(0, 10) === todayStr).length

  return { total, uniqueUsers, successRate, todayEvents }
})

const filteredLogs = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  return auditLogs.value.filter(l => {
    if (filterResult.value !== 'all' && l.result?.toLowerCase() !== filterResult.value.toLowerCase()) return false
    if (filterActionCategory.value !== 'all' && !l.action?.toLowerCase().includes(filterActionCategory.value.toLowerCase())) return false

    if (!query) return true
    const act = l.action?.toLowerCase() || ''
    const uName = (l.user?.name || '').toLowerCase()
    const uEmail = (l.user?.email || '').toLowerCase()
    const ip = (l.actor_ip || '').toLowerCase()
    const pName = (l.project?.name || '').toLowerCase()
    const jobCode = (l.scanJob?.code || l.scan_job?.code || '').toLowerCase()
    return act.includes(query) || uName.includes(query) || uEmail.includes(query) || ip.includes(query) || pName.includes(query) || jobCode.includes(query)
  })
})

function openDetailModal(log: AuditLogItem) {
  selectedLog.value = log
  copySuccess.value = false
  isDetailModalOpen.value = true
}

function closeDetailModal() {
  isDetailModalOpen.value = false
  selectedLog.value = null
  copySuccess.value = false
}

function copyMetadata() {
  if (!selectedLog.value?.metadata) return
  const text = JSON.stringify(selectedLog.value.metadata, null, 2)
  navigator.clipboard.writeText(text).then(() => {
    copySuccess.value = true
    setTimeout(() => {
      copySuccess.value = false
    }, 2000)
  })
}

function getActionBadgeClass(action: string) {
  const act = action.toLowerCase()
  if (act.includes('create') || act.includes('store')) return 'bg-blue-lt text-blue'
  if (act.includes('delete') || act.includes('destroy')) return 'bg-danger-lt text-danger'
  if (act.includes('update') || act.includes('triage')) return 'bg-warning-lt text-warning'
  if (act.includes('toggle')) return 'bg-purple-lt text-purple'
  if (act.includes('scan') || act.includes('rerun')) return 'bg-teal-lt text-teal'
  if (act.includes('report')) return 'bg-indigo-lt text-indigo'
  return 'bg-secondary-lt text-secondary'
}

function formatDate(dt?: string) {
  if (!dt) return '-'
  try {
    return new Date(dt).toLocaleString('id-ID', {
      day: '2-digit',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit'
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
              Administrasi, Integritas & Kepatuhan Forensik
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M12 11m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 12l0 2.5" /></svg>
              <span>Log Audit Forensik (Tamper-Evident Audit Trail)</span>
            </h2>
          </div>
          <div class="col-auto ms-auto d-print-none d-flex align-items-center gap-2">
            <button
              type="button"
              class="btn btn-outline-secondary d-flex align-items-center gap-1"
              :disabled="isLoading"
              @click="loadData"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
              <span>Refresh Log</span>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M7 8h10" /><path d="M7 12h10" /><path d="M7 16h10" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4">{{ stats.total }} Log</div>
                  <div class="text-secondary small">Total Jejak Audit Terekam</div>
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
                  <span class="bg-indigo-lt text-indigo avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-indigo">{{ stats.uniqueUsers }} Aktor</div>
                  <div class="text-secondary small">Pengguna Teridentifikasi</div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 5m0 2a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2z" /><path d="M16 3l0 4" /><path d="M8 3l0 4" /><path d="M4 11l16 0" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-teal">{{ stats.todayEvents }} Event</div>
                  <div class="text-secondary small">Aktivitas Hari Ini</div>
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
                  <span class="bg-success-lt text-success avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-success">{{ stats.successRate }}%</div>
                  <div class="text-secondary small">Tingkat Aksi Berhasil</div>
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
            <span>Daftar Jejak Audit</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{ filteredLogs.length }}</span>
          </h3>

          <!-- Filter Toolbar -->
          <div class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2">
            <!-- Filter Action Category -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
              </span>
              <select
                v-model="filterActionCategory"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterActionCategory !== 'all' }"
                title="Filter berdasarkan Kategori Aksi"
              >
                <option value="all">Semua Kategori Aksi</option>
                <option value="authorization">Otorisasi (Authorization)</option>
                <option value="finding">Temuan (Finding/Triage)</option>
                <option value="report">Laporan (Report)</option>
                <option value="scan">Pekerjaan Scan (Scan Job)</option>
                <option value="engine">Security Engine</option>
                <option value="user">Manajemen Pengguna</option>
              </select>
            </div>

            <!-- Filter Result -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-success">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
              </span>
              <select
                v-model="filterResult"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': filterResult !== 'all' }"
                title="Filter Hasil"
              >
                <option value="all">Semua Hasil</option>
                <option value="success">Success</option>
                <option value="failed">Failed</option>
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
                  placeholder="Cari aksi, aktor, IP, proyek..."
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
              v-if="searchQuery || filterResult !== 'all' || filterActionCategory !== 'all'"
              type="button"
              class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1 filter-reset-btn"
              @click="resetFilters"
              title="Reset filter"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
              <span>↺ Reset Filter</span>
            </button>
          </div>
        </div>

        <!-- Table Loading State -->
        <div v-if="isLoading" class="card-body text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
          <div class="text-secondary mt-2">Memuat jejak audit sistem...</div>
        </div>

        <!-- Empty State -->
        <div v-else-if="filteredLogs.length === 0" class="card-body text-center py-5">
          <div class="empty">
            <div class="empty-icon">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-secondary" width="32" height="32" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /></svg>
            </div>
            <p class="empty-title">Tidak ada aktivitas audit ditemukan</p>
            <p class="empty-subtitle text-secondary">
              Setiap aktivitas sensitif di sistem TAMENG dicatat secara otomatis ke log audit forensik.
            </p>
          </div>
        </div>

        <!-- Data Table -->
        <div v-else class="table-responsive">
          <table class="table table-vcenter card-table table-hover">
            <thead>
              <tr>
                <th style="width: 170px;">Waktu Kejadian</th>
                <th>Aktor / Pelaku</th>
                <th>Kode Aksi</th>
                <th style="width: 90px;">Hasil</th>
                <th style="width: 130px;">IP Address</th>
                <th>Konteks Aset / Target</th>
                <th class="w-1 text-end">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="log in filteredLogs" :key="log.id">
                <td>
                  <div class="small font-monospace text-secondary">
                    {{ formatDate(log.created_at) }}
                  </div>
                </td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <span class="avatar avatar-xs bg-primary-lt text-primary font-monospace fw-bold">
                      {{ (log.user?.name || 'S').slice(0, 1).toUpperCase() }}
                    </span>
                    <div>
                      <div class="fw-bold small">{{ log.user?.name || 'Sistem Otomatis' }}</div>
                      <div class="text-secondary" style="font-size: 0.725rem;">{{ log.user?.email || '-' }}</div>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="badge font-monospace" :class="getActionBadgeClass(log.action)">
                    {{ log.action }}
                  </span>
                </td>
                <td>
                  <span
                    class="badge"
                    :class="log.result?.toLowerCase() === 'success' ? 'bg-success text-success-fg' : 'bg-danger text-danger-fg'"
                  >
                    {{ log.result?.toUpperCase() || 'SUCCESS' }}
                  </span>
                </td>
                <td>
                  <code class="text-muted small font-monospace">
                    {{ log.actor_ip || '127.0.0.1' }}
                  </code>
                </td>
                <td>
                  <div v-if="log.project" class="small">
                    <span class="badge bg-blue-lt font-monospace me-1">{{ log.project.code }}</span>
                    <span>{{ log.project.name }}</span>
                  </div>
                  <div v-else-if="log.scanJob || log.scan_job" class="small font-monospace text-primary">
                    {{ log.scanJob?.code || log.scan_job?.code }}
                  </div>
                  <div v-else-if="log.authorization" class="small font-monospace text-indigo">
                    {{ log.authorization.code }}
                  </div>
                  <div v-else class="small text-muted fst-italic">
                    {{ log.target_type ? `${log.target_type} #${log.target_id || ''}` : 'Sistem Core' }}
                  </div>
                </td>
                <td class="text-end">
                  <button
                    class="btn btn-sm btn-ghost-primary d-inline-flex align-items-center gap-1"
                    @click="openDetailModal(log)"
                    title="Lihat Metadata Snapshot JSON"
                  >
                    <span>Payload</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal Metadata Snapshot -->
    <div
      v-if="isDetailModalOpen && selectedLog"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.5);"
    >
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title d-flex align-items-center gap-2">
              <span class="badge font-monospace" :class="getActionBadgeClass(selectedLog.action)">{{ selectedLog.action }}</span>
              <span>Metadata & Forensik Audit #{{ selectedLog.id }}</span>
            </h5>
            <button type="button" class="btn-close" @click="closeDetailModal"></button>
          </div>
          <div class="modal-body">
            <div class="row g-2 mb-3 small">
              <div class="col-sm-6">
                <div><strong>Aktor:</strong> {{ selectedLog.user?.name || 'Sistem' }} ({{ selectedLog.user?.email || '-' }})</div>
                <div><strong>IP Address:</strong> {{ selectedLog.actor_ip || '-' }}</div>
              </div>
              <div class="col-sm-6">
                <div><strong>Waktu:</strong> {{ formatDate(selectedLog.created_at) }}</div>
                <div><strong>Hasil:</strong> <span class="badge bg-success-lt">{{ selectedLog.result }}</span></div>
              </div>
            </div>

            <div class="mb-3 position-relative">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <label class="form-label fw-bold text-secondary m-0">JSON Payload & Snapshot</label>
                <button
                  type="button"
                  class="btn btn-xs btn-outline-secondary d-flex align-items-center gap-1"
                  @click="copyMetadata"
                >
                  <span v-if="copySuccess" class="text-success">✓ Tersalin</span>
                  <span v-else>Salin JSON</span>
                </button>
              </div>
              <pre class="bg-dark text-light p-3 rounded font-monospace small" style="max-height: 320px; overflow-y: auto;">{{ JSON.stringify(selectedLog.metadata, null, 2) }}</pre>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary ms-auto" @click="closeDetailModal">
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
