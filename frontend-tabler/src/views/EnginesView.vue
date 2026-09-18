<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '../services/api'
import { useAuth } from '../composables/useAuth'

const { currentUser } = useAuth()

interface SecurityEngine {
  id: number
  code: string
  name: string
  domain: string
  resource_class: string
  enabled: boolean
  status: 'AVAILABLE' | 'DEGRADED' | 'DISABLED'
  container_image?: string
  binary_command?: string
  timeout_seconds?: number
  last_health_check?: string
}

interface ProfileEngineMapping {
  id: number
  code: string
  name: string
  domain: string
  resource_class: string
  is_required: boolean
}

interface ScanProfileItem {
  code: string
  name: string
  engine_count: number
  engines: ProfileEngineMapping[]
}

const activeTab = ref<'engines' | 'profiles'>('engines')
const engines = ref<SecurityEngine[]>([])
const scanProfiles = ref<ScanProfileItem[]>([])
const isLoading = ref(true)
const actionLoadingId = ref<number | null>(null)
const actionMessage = ref<{ type: 'success' | 'danger'; text: string } | null>(null)

// Filters for engines
const searchQuery = ref('')
const selectedDomain = ref<string>('all')
const selectedStatus = ref<string>('all')
const selectedResource = ref<string>('all')

function resetFilters() {
  searchQuery.value = ''
  selectedDomain.value = 'all'
  selectedStatus.value = 'all'
  selectedResource.value = 'all'
}

const canManage = computed(() => {
  const role = currentUser.value?.role?.name || ''
  return ['super_admin', 'security_admin'].includes(role)
})

async function loadData() {
  isLoading.value = true
  try {
    const [engRes, profRes] = await Promise.all([
      apiFetch('/api/security/engines'),
      apiFetch('/api/security/scan-profiles').catch(() => ({ data: [] }))
    ])

    engines.value = Array.isArray(engRes?.data) ? engRes.data : (Array.isArray(engRes) ? engRes : [])
    scanProfiles.value = Array.isArray(profRes?.data) ? profRes.data : []
  } catch (err: any) {
    console.error('Gagal memuat daftar security engines:', err)
  } finally {
    isLoading.value = false
  }
}

const stats = computed(() => {
  const list = engines.value
  const total = list.length
  const active = list.filter(e => e.enabled).length
  const available = list.filter(e => e.status === 'AVAILABLE').length
  const domains = new Set(list.map(e => e.domain)).size
  return { total, active, available, domains }
})

const uniqueDomains = computed(() => {
  const doms = new Set(engines.value.map(e => e.domain))
  return Array.from(doms).sort()
})

const filteredEngines = computed(() => {
  const query = searchQuery.value.trim().toLowerCase()
  return engines.value.filter(e => {
    if (selectedDomain.value !== 'all' && e.domain !== selectedDomain.value) return false
    if (selectedStatus.value !== 'all') {
      if (selectedStatus.value === 'enabled' && !e.enabled) return false
      if (selectedStatus.value === 'disabled' && e.enabled) return false
      if (['AVAILABLE', 'DEGRADED', 'DISABLED'].includes(selectedStatus.value) && e.status !== selectedStatus.value) return false
    }
    if (selectedResource.value !== 'all' && e.resource_class !== selectedResource.value) return false

    if (!query) return true
    const name = e.name?.toLowerCase() || ''
    const code = e.code?.toLowerCase() || ''
    const image = e.container_image?.toLowerCase() || ''
    const domain = e.domain?.toLowerCase() || ''
    return name.includes(query) || code.includes(query) || image.includes(query) || domain.includes(query)
  })
})

async function toggleEngine(engine: SecurityEngine) {
  if (!canManage.value) return
  actionLoadingId.value = engine.id
  actionMessage.value = null

  try {
    const res = await apiFetch(`/api/security/engines/${engine.id}/toggle`, {
      method: 'POST'
    })
    engine.enabled = res.data.enabled
    engine.status = res.data.status
    actionMessage.value = {
      type: 'success',
      text: res.message || `Status mesin ${engine.name} berhasil diperbarui.`
    }
    setTimeout(() => {
      if (actionMessage.value?.text.includes(engine.name)) {
        actionMessage.value = null
      }
    }, 4000)
  } catch (err: any) {
    console.error('Gagal mengubah status mesin:', err)
    actionMessage.value = {
      type: 'danger',
      text: err?.data?.message || err?.message || 'Gagal mengubah status mesin.'
    }
  } finally {
    actionLoadingId.value = null
  }
}

async function runHealthCheck(engine: SecurityEngine) {
  actionLoadingId.value = engine.id
  actionMessage.value = null

  try {
    const res = await apiFetch(`/api/security/engines/${engine.id}/health-check`, {
      method: 'POST'
    })
    engine.status = res.data.status
    engine.last_health_check = res.data.last_health_check
    actionMessage.value = {
      type: res.data.image_present ? 'success' : 'danger',
      text: res.message
    }
    setTimeout(() => {
      actionMessage.value = null
    }, 5000)
  } catch (err: any) {
    console.error('Gagal menjalankan health check:', err)
    actionMessage.value = {
      type: 'danger',
      text: err?.data?.message || err?.message || 'Gagal memeriksa kesehatan container image.'
    }
  } finally {
    actionLoadingId.value = null
  }
}

function getDomainBadgeClass(domain: string) {
  switch (domain.toUpperCase()) {
    case 'SAST': return 'bg-blue-lt text-blue'
    case 'DAST': return 'bg-red-lt text-red'
    case 'SCA': return 'bg-warning-lt text-warning'
    case 'SECRETS': return 'bg-purple-lt text-purple'
    case 'SBOM': return 'bg-teal-lt text-teal'
    case 'CONTAINER': return 'bg-cyan-lt text-cyan'
    case 'MOBILE': return 'bg-green-lt text-green'
    case 'SSL': return 'bg-yellow-lt text-yellow'
    default: return 'bg-secondary-lt text-secondary'
  }
}

function getResourceBadgeClass(rc: string) {
  switch (rc.toUpperCase()) {
    case 'LIGHT': return 'bg-success text-success-fg'
    case 'MEDIUM': return 'bg-warning text-warning-fg'
    case 'HEAVY': return 'bg-danger text-danger-fg'
    default: return 'bg-secondary text-secondary-fg'
  }
}

function formatDate(dt?: string) {
  if (!dt) return 'Belum diperiksa'
  try {
    return new Date(dt).toLocaleString('id-ID', {
      day: '2-digit',
      month: 'short',
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
              Tata Kelola & Mesin Pemindai
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" /><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" /></svg>
              <span>Registri Mesin Keamanan (Security Engine Registry)</span>
            </h2>
          </div>
          <div class="col-auto ms-auto d-print-none d-flex align-items-center gap-2">
            <!-- Tab switch pills -->
            <div class="btn-group" role="group">
              <button
                type="button"
                class="btn d-inline-flex align-items-center gap-1"
                :class="activeTab === 'engines' ? 'btn-primary' : 'btn-outline-secondary'"
                @click="activeTab = 'engines'"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" /><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" /></svg>
                <span>20 Mesin Keamanan</span>
              </button>
              <button
                type="button"
                class="btn d-inline-flex align-items-center gap-1"
                :class="activeTab === 'profiles' ? 'btn-primary' : 'btn-outline-secondary'"
                @click="activeTab = 'profiles'"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
                <span>Pemetaan Profil Scan</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Action Toast / Alert -->
      <div
        v-if="actionMessage"
        class="alert alert-dismissible mb-3 d-flex align-items-center gap-2"
        :class="actionMessage.type === 'success' ? 'alert-success' : 'alert-danger'"
      >
        <span>{{ actionMessage.text }}</span>
        <button
          type="button"
          class="btn-close ms-auto"
          @click="actionMessage = null"
        ></button>
      </div>

      <!-- KPI Metric Cards -->
      <div class="row row-cards mb-3">
        <div class="col-sm-6 col-lg-3">
          <div class="card card-sm">
            <div class="card-body">
              <div class="row align-items-center">
                <div class="col-auto">
                  <span class="bg-primary-lt text-primary avatar">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4">{{ stats.total }} Mesin</div>
                  <div class="text-secondary small">Total Security Engines</div>
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
                  <div class="font-weight-medium fs-4 text-success">{{ stats.active }} Aktif</div>
                  <div class="text-secondary small">Mesin Siap Operasi</div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 12l2 2l4 -4" /><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-teal">{{ stats.available }} Ready</div>
                  <div class="text-secondary small">Status Healthy (Image Ready)</div>
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
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v6h-6z" /><path d="M14 4h6v6h-6z" /><path d="M4 14h6v6h-6z" /><path d="M17 17m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /></svg>
                  </span>
                </div>
                <div class="col">
                  <div class="font-weight-medium fs-4 text-indigo">{{ stats.domains }} Domain</div>
                  <div class="text-secondary small">Cakupan Domain Keamanan</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 1: 20 Mesin Keamanan -->
      <div v-if="activeTab === 'engines'" class="card">
        <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
          <h3 class="card-title m-0 d-flex align-items-center gap-2">
            <span>Daftar Mesin Keamanan</span>
            <span class="badge bg-secondary-lt text-secondary font-monospace">{{ filteredEngines.length }}</span>
          </h3>

          <!-- Filter Toolbar -->
          <div class="filter-toolbar-group d-flex flex-wrap align-items-center gap-2">
            <!-- Filter Domain -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-primary">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
              </span>
              <select
                v-model="selectedDomain"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': selectedDomain !== 'all' }"
                title="Filter berdasarkan Domain Keamanan"
              >
                <option value="all">Semua Domain</option>
                <option v-for="dom in uniqueDomains" :key="dom" :value="dom">
                  {{ dom }}
                </option>
              </select>
            </div>

            <!-- Filter Status -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-success">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
              </span>
              <select
                v-model="selectedStatus"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': selectedStatus !== 'all' }"
                title="Filter Status Mesin"
              >
                <option value="all">Semua Status</option>
                <option value="enabled">Aktif (Enabled)</option>
                <option value="disabled">Nonaktif (Disabled)</option>
                <option value="AVAILABLE">Tersedia (AVAILABLE)</option>
                <option value="DEGRADED">Degraded / Image Miss</option>
              </select>
            </div>

            <!-- Filter Resource -->
            <div class="filter-select-wrapper position-relative">
              <span class="select-prefix-icon text-warning">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h4l3 8l4 -16l3 8h4" /></svg>
              </span>
              <select
                v-model="selectedResource"
                class="form-select form-select-sm custom-filter-select"
                :class="{ 'filter-active': selectedResource !== 'all' }"
                title="Filter Kelas Beban Resource"
              >
                <option value="all">Semua Beban</option>
                <option value="LIGHT">Light</option>
                <option value="MEDIUM">Medium</option>
                <option value="HEAVY">Heavy</option>
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
                  placeholder="Cari nama mesin, image..."
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
              v-if="searchQuery || selectedDomain !== 'all' || selectedStatus !== 'all' || selectedResource !== 'all'"
              type="button"
              class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1 filter-reset-btn"
              @click="resetFilters"
              title="Reset filter"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
              <span>Reset Filter</span>
            </button>
          </div>
        </div>

        <!-- Table Loading State -->
        <div v-if="isLoading" class="card-body text-center py-5">
          <div class="spinner-border text-primary" role="status"></div>
          <div class="text-secondary mt-2">Memuat daftar 20 security engines...</div>
        </div>

        <!-- Data Table (Desktop & Tablet >= 768px) -->
        <div v-else class="table-responsive d-none d-md-block">
          <table class="table table-vcenter card-table table-hover">
            <thead>
              <tr>
                <th>Mesin Keamanan</th>
                <th style="width: 100px;">Domain</th>
                <th style="width: 90px;">Resource</th>
                <th>Docker Image / Binary</th>
                <th style="width: 120px;">Kondisi Image</th>
                <th style="width: 120px;">Health Check</th>
                <th v-if="canManage" style="width: 100px;">Aktifkan</th>
                <th v-if="canManage" class="w-1 text-end">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="engine in filteredEngines" :key="engine.id">
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <span class="avatar avatar-sm bg-primary-lt text-primary font-monospace fw-bold">
                      {{ engine.name.slice(0, 2).toUpperCase() }}
                    </span>
                    <div>
                      <div class="fw-bold">{{ engine.name }}</div>
                      <div class="text-secondary small font-monospace">{{ engine.code }}</div>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="badge" :class="getDomainBadgeClass(engine.domain)">
                    {{ engine.domain }}
                  </span>
                </td>
                <td>
                  <span class="badge" :class="getResourceBadgeClass(engine.resource_class)">
                    {{ engine.resource_class }}
                  </span>
                </td>
                <td>
                  <code class="text-muted font-monospace small d-block text-truncate" style="max-width: 260px;" :title="engine.container_image || engine.binary_command">
                    {{ engine.container_image || engine.binary_command || '-' }}
                  </code>
                </td>
                <td>
                  <span
                    class="badge"
                    :class="{
                      'bg-success-lt text-success': engine.status === 'AVAILABLE',
                      'bg-warning-lt text-warning': engine.status === 'DEGRADED',
                      'bg-secondary-lt text-secondary': engine.status === 'DISABLED'
                    }"
                  >
                    {{ engine.status }}
                  </span>
                </td>
                <td>
                  <div class="small text-secondary">{{ formatDate(engine.last_health_check) }}</div>
                </td>
                <td v-if="canManage">
                  <!-- Toggle Switch -->
                  <label class="form-check form-switch m-0">
                    <input
                      class="form-check-input"
                      type="checkbox"
                      :checked="engine.enabled"
                      :disabled="actionLoadingId === engine.id"
                      @change="toggleEngine(engine)"
                    />
                  </label>
                </td>
                <td v-if="canManage" class="text-end">
                  <button
                    class="btn btn-sm btn-ghost-secondary d-inline-flex align-items-center gap-1"
                    :disabled="actionLoadingId === engine.id"
                    @click="runHealthCheck(engine)"
                    title="Jalankan Preflight Docker Health Check"
                  >
                    <span v-if="actionLoadingId === engine.id" class="spinner-border spinner-border-sm" role="status"></span>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h4l3 8l4 -16l3 8h4" /></svg>
                    <span>Cek Image</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile Card List (< 768px) -->
        <div class="d-md-none list-group list-group-flush">
          <div
            v-for="engine in filteredEngines"
            :key="engine.id"
            class="list-group-item px-3 py-2"
          >
            <div class="d-flex align-items-center justify-content-between mb-1">
              <div class="d-flex align-items-center gap-2">
                <span class="avatar avatar-xs bg-primary-lt text-primary font-monospace fw-bold">
                  {{ engine.name.slice(0, 2).toUpperCase() }}
                </span>
                <span class="fw-bold">{{ engine.name }}</span>
              </div>
              <div class="d-flex align-items-center gap-2">
                <span
                  class="badge"
                  :class="{
                    'bg-success-lt text-success': engine.status === 'AVAILABLE',
                    'bg-warning-lt text-warning': engine.status === 'DEGRADED',
                    'bg-secondary-lt text-secondary': engine.status === 'DISABLED'
                  }"
                >
                  {{ engine.status }}
                </span>
                <label v-if="canManage" class="form-check form-switch m-0">
                  <input
                    class="form-check-input"
                    type="checkbox"
                    :checked="engine.enabled"
                    :disabled="actionLoadingId === engine.id"
                    @change="toggleEngine(engine)"
                  />
                </label>
              </div>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
              <span class="badge" :class="getDomainBadgeClass(engine.domain)">
                {{ engine.domain }}
              </span>
              <span class="badge" :class="getResourceBadgeClass(engine.resource_class)">
                {{ engine.resource_class }}
              </span>
              <span class="text-secondary small font-monospace ms-auto" style="font-size: 0.72rem;">
                {{ engine.code }}
              </span>
            </div>
            <code class="text-muted font-monospace small d-block text-truncate mt-1" :title="engine.container_image || engine.binary_command">
              {{ engine.container_image || engine.binary_command || '-' }}
            </code>
            <div class="d-flex align-items-center justify-content-between text-secondary small mt-2 pt-1 border-top">
              <span style="font-size: 0.75rem;">Cek: {{ formatDate(engine.last_health_check) }}</span>
              <button
                v-if="canManage"
                type="button"
                class="btn btn-sm btn-outline-secondary py-1 px-2 d-inline-flex align-items-center gap-1"
                :disabled="actionLoadingId === engine.id"
                @click="runHealthCheck(engine)"
              >
                <span v-if="actionLoadingId === engine.id" class="spinner-border spinner-border-sm" role="status"></span>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h4l3 8l4 -16l3 8h4" /></svg>
                <span>Cek Image</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- TAB 2: Pemetaan Scan Profiles -->
      <div v-else class="row row-cards">
        <div v-for="profile in scanProfiles" :key="profile.code" class="col-md-6 col-lg-4">
          <div class="card h-100">
            <div class="card-header py-2 d-flex align-items-center justify-content-between">
              <h3 class="card-title text-capitalize fs-4 m-0 d-flex align-items-center gap-2">
                <span class="avatar avatar-xs bg-indigo-lt text-indigo">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
                </span>
                <span>{{ profile.name }}</span>
              </h3>
              <span class="badge bg-indigo-lt font-monospace">{{ profile.engine_count }} Engines</span>
            </div>
            <div class="card-body p-3">
              <div class="text-secondary small mb-2 font-monospace">Kode: {{ profile.code }}</div>
              <div class="list-group list-group-flush border-top border-bottom" style="max-height: 280px; overflow-y: auto;">
                <div
                  v-for="eng in profile.engines"
                  :key="eng.id"
                  class="list-group-item px-1 py-2 d-flex align-items-center justify-content-between"
                >
                  <div class="d-flex align-items-center gap-2">
                    <span class="badge" :class="getDomainBadgeClass(eng.domain)" style="font-size: 0.7rem;">
                      {{ eng.domain }}
                    </span>
                    <span class="fw-bold small">{{ eng.name }}</span>
                  </div>
                  <div>
                    <span v-if="eng.is_required" class="badge bg-danger-lt" style="font-size: 0.7rem;">
                      Wajib
                    </span>
                    <span v-else class="badge bg-secondary-lt text-secondary" style="font-size: 0.7rem;">
                      Opsional
                    </span>
                  </div>
                </div>
              </div>
            </div>
            <div class="card-footer py-2 bg-body-tertiary small text-secondary">
              Dijalankan secara otomatis saat profil ini dipilih pada pekerjaan scan.
            </div>
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
