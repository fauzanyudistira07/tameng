<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { getAuthorizations } from '../services/authorizationService'
import { createScanJob, getScanJobs, rerunScanJob } from '../services/scanJobService'
import { authState } from '../stores/authStore'

const scanJobs = ref<any[]>([])
const authorizations = ref<any[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const isRerunningId = ref<any>(null)
const errorMessage = ref('')
const successMessage = ref('')
const filterStatus = ref<string>('all')
const searchQuery = ref<string>('')
const form = reactive({ authorization_id: '' })
let refreshTimer: number | null = null

const currentPage = ref(1)
const pageSize = ref(5)

const canCreateScan = computed(() => ['super_admin', 'security_admin'].includes(authState.user?.role?.name))
const activeAuthorizations = computed(() => authorizations.value.filter((item) => item.status === 'active'))
const selectedAuthorization = computed(() =>
  authorizations.value.find((item) => String(item.id) === String(form.authorization_id)),
)

const filteredScanJobs = computed(() => {
  const query = (searchQuery.value || '').trim().toLowerCase()
  return (scanJobs.value || []).filter((job) => {
    const matchesFilter = filterStatus.value === 'all' || job.status === filterStatus.value
    if (!matchesFilter) return false
    if (!query) return true

    const code = (job.code ?? '').toLowerCase()
    const projName = (job.project?.name ?? '').toLowerCase()
    const projCode = (job.project?.code ?? '').toLowerCase()
    const repoName = (job.repository?.name ?? '').toLowerCase()
    const targetName = (job.target?.name ?? '').toLowerCase()

    return code.includes(query) || projName.includes(query) || projCode.includes(query) || repoName.includes(query) || targetName.includes(query)
  })
})

const paginatedScanJobs = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredScanJobs.value.slice(start, start + pageSize.value)
})

watch([searchQuery, filterStatus], () => {
  currentPage.value = 1
})

const statusSummary = computed(() => ({
  total: scanJobs.value.length,
  berjalan: scanJobs.value.filter((job) => ['queued', 'running'].includes(job.status)).length,
  selesai: scanJobs.value.filter((job) => job.status === 'completed').length,
  gagal: scanJobs.value.filter((job) => ['failed', 'denied'].includes(job.status)).length,
}))

function resetForm() {
  form.authorization_id = activeAuthorizations.value[0]?.id ?? ''
}

function statusLabel(status: string) {
  return (
    {
      queued: 'Dalam antrean',
      running: 'Sedang berjalan',
      completed: 'Selesai',
      failed: 'Gagal',
      denied: 'Ditolak',
      skipped: 'Dilewati',
      planned: 'Direncanakan',
    }[status] ?? status
  )
}

function statusClass(status: string) {
  return (
    {
      queued: 'is-info',
      running: 'is-info',
      completed: 'is-success',
      failed: 'is-danger',
      denied: 'is-danger',
      skipped: 'is-muted',
    }[status] ?? 'is-muted'
  )
}

function assetLabel(job: any) {
  return job.repository?.name ?? job.target?.name ?? job.target?.base_url ?? job.target?.hostname ?? '-'
}

function estimateScanDuration(job: any): string {
  if (!job) return '-'

  if (['completed', 'failed', 'denied'].includes(job.status)) {
    if (job.started_at && job.finished_at) {
      const diffMs = new Date(job.finished_at).getTime() - new Date(job.started_at).getTime()
      if (diffMs > 0) {
        const totalSecs = Math.round(diffMs / 1000)
        const mins = Math.floor(totalSecs / 60)
        const secs = totalSecs % 60
        return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`
      }
    }
    return job.status === 'completed' ? 'Selesai' : 'Dihentikan'
  }

  if (job.status === 'queued') {
    return '~3 - 5 menit (Menunggu antrean)'
  }

  if (job.status === 'running') {
    const progress = Number(job.progress ?? 0)
    if (progress < 25) {
      return '~3 - 4 menit lagi (Inisialisasi & Scan Semgrep SAST)'
    } else if (progress < 50) {
      return '~2 - 3 menit lagi (Pemeriksaan Secrets & Dependensi)'
    } else if (progress < 75) {
      return '~1 - 2 menit lagi (Analisis CVE & Katalog SBOM)'
    } else {
      return '~30 - 60 detik lagi (Kompilasi & Deduplikasi Laporan)'
    }
  }

  return '-'
}

function scanDurationBadge(job: any): string {
  if (!job) return ''

  if (['completed', 'failed', 'denied'].includes(job.status)) {
    if (job.started_at && job.finished_at) {
      const diffMs = new Date(job.finished_at).getTime() - new Date(job.started_at).getTime()
      if (diffMs > 0) {
        const totalSecs = Math.round(diffMs / 1000)
        const mins = Math.floor(totalSecs / 60)
        const secs = totalSecs % 60
        return `Durasi: ${mins > 0 ? `${mins}m ${secs}s` : `${secs}s`}`
      }
    }
    return ''
  }

  if (job.status === 'queued') return 'Estimasi: ~3-5 mnt'
  if (job.status === 'running') {
    const p = Number(job.progress ?? 0)
    if (p < 35) return 'Estimasi: ~3-4 mnt'
    if (p < 70) return 'Estimasi: ~1-2 mnt'
    return 'Estimasi: ~30-60 dtk'
  }

  return ''
}

function formatFailureReason(raw: string) {
  if (!raw) return { code: 'FAILED', summary: 'Proses pemindaian gagal.', hasDetail: false, rawDetail: '' }

  let code = 'FAILED'
  let message = raw

  const colonIndex = raw.indexOf(': ')
  if (colonIndex !== -1 && colonIndex < 35) {
    code = raw.substring(0, colonIndex).trim()
    message = raw.substring(colonIndex + 2).trim()
  }

  let summary = ''

  if (/could not read Username|Authentication failed|Repository not found|Invalid username/i.test(message)) {
    summary = 'Akses ditolak atau repositori GitHub bersifat privat. Diperlukan token PAT yang valid.'
  } else if (/Filename too long|unable to create file/i.test(message)) {
    summary = 'Checkout repositori gagal: Terdapat struktur nama file/path yang melebihi batas panjang karakter Windows (MAX_PATH).'
  } else if (/Remote branch .* not found|did not match any file/i.test(message)) {
    summary = 'Branch target tidak ditemukan pada repositori GitHub ini.'
  } else if (/timed out|Connection refused|Could not resolve host/i.test(message)) {
    summary = 'Koneksi jaringan ke GitHub terputus atau mengalami timeout saat mengunduh source code.'
  } else if (/SEMGREP_PROCESS_FAILED/i.test(code)) {
    summary = 'Engine Semgrep SAST mengalami kendala saat membedah struktur source code.'
  } else if (/GITLEAKS_PROCESS_FAILED/i.test(code)) {
    summary = 'Engine Gitleaks mengalami kendala saat memindai riwayat commit git.'
  } else if (/TIMEOUT|time limit/i.test(code) || /timeout/i.test(message)) {
    summary = 'Eksekusi pemindaian dihentikan karena melebihi batas waktu toleransi maksimum (Timeout).'
  } else {
    const cleaned = message
      .replace(/Cloning into '[^']+'\.+/gi, '')
      .replace(/Updating files:\s*\d+%\s*\(\d+\/\d+\)/gi, '')
      .replace(/Receiving objects:\s*\d+%\s*\(\d+\/\d+\)/gi, '')
      .replace(/Resolving deltas:\s*\d+%\s*\(\d+\/\d+\)/gi, '')
      .replace(/\s+/g, ' ')
      .trim()

    summary = cleaned.length > 160 ? cleaned.substring(0, 160) + '...' : cleaned
  }

  return {
    code,
    summary,
    hasDetail: message.length > 80 || message !== summary,
    rawDetail: message,
  }
}

function scanRunMode(run: any) {
  return run.command_spec?.scanner_execution ? `Docker (${run.command_spec?.runtime ?? 'sandbox'})` : 'Simulasi Guarded'
}

function scanRunFindingCount(run: any) {
  return run.runtime_metrics?.finding_count ?? 0
}

async function loadData(silent = false) {
  if (!silent && !scanJobs.value.length) {
    isLoading.value = true
  }

  try {
    const [authorizationList, scanJobList] = await Promise.all([
      getAuthorizations(silent ? { skipCache: true } : {}),
      getScanJobs(silent ? { skipCache: true } : {}),
    ])
    authorizations.value = authorizationList
    scanJobs.value = scanJobList
    if (!form.authorization_id) resetForm()
  } catch (error) {
    if (!silent) {
      console.error('Gagal memuat pekerjaan scan.', error)
      errorMessage.value = 'Gagal memuat pekerjaan scan.'
    }
  } finally {
    isLoading.value = false
  }
}

async function submitScanJob() {
  if (!selectedAuthorization.value) {
    errorMessage.value = 'Pilih otorisasi aktif terlebih dahulu.'
    return
  }

  isSaving.value = true
  errorMessage.value = ''
  successMessage.value = ''

  const authorization = selectedAuthorization.value
  const payload = {
    authorization_id: authorization.id,
    project_id: authorization.project_id,
    repository_id: authorization.repository_id,
    target_id: authorization.target_id,
    scan_profile_id: authorization.scan_profile_id,
  }

  try {
    await createScanJob(payload)
    successMessage.value = 'Pekerjaan scan berhasil dibuat dan dimasukkan ke dalam antrean!'
    await loadData(true)
  } catch (error) {
    console.error('Gagal membuat pekerjaan scan.', error)
    errorMessage.value = 'Gagal membuat pekerjaan scan. Authorization Gateway menolak permintaan.'
  } finally {
    isSaving.value = false
  }
}

async function triggerRerun(job: any) {
  isRerunningId.value = job.id
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const newJob = await rerunScanJob(job.id)
    successMessage.value = `Pekerjaan scan ${job.code} berhasil dijalankan ulang (${newJob.code})!`
    await loadData(true)
  } catch (error) {
    console.error('Gagal menjalankan ulang scan job.', error)
    errorMessage.value = 'Gagal menjalankan ulang pekerjaan scan.'
  } finally {
    isRerunningId.value = null
  }
}

onMounted(() => {
  loadData(false)
  refreshTimer = window.setInterval(() => loadData(true), 5000)
})

onUnmounted(() => {
  if (refreshTimer) window.clearInterval(refreshTimer)
})
</script>

<template>
  <AppShell>
    <template #eyebrow>Orkestrasi Eksekusi Scanner</template>
    <template #title>Pekerjaan Scan (Scan Jobs)</template>

    <!-- Top Summary Metrics -->
    <section class="metrics">
      <div class="stat-card" style="border-top: 3px solid var(--accent-cyan)">
        <span>Total Pekerjaan</span>
        <strong>{{ statusSummary.total }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--tameng-sapphire)">
        <span>Sedang Berjalan</span>
        <div style="display: flex; align-items: baseline; gap: 8px;">
          <strong>{{ statusSummary.berjalan }}</strong>
          <span v-if="statusSummary.berjalan > 0" class="pulse-dot" style="background: var(--tameng-sapphire)"></span>
        </div>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--status-success)">
        <span>Selesai</span>
        <strong style="color: var(--status-success)">{{ statusSummary.selesai }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--severity-critical)">
        <span>Gagal / Ditolak</span>
        <strong style="color: var(--severity-critical)">{{ statusSummary.gagal }}</strong>
      </div>
    </section>

    <!-- Feedback Alerts -->
    <div v-if="successMessage" class="status-panel success fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <!-- Main Content Layout -->
    <section class="two-column">
      <!-- Left: Scan Jobs List -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
            Daftar Pekerjaan Scan
          </h2>
          <span class="badge">{{ filteredScanJobs.length }} Pekerjaan</span>
        </div>

        <!-- Filter & Search Toolbar -->
        <div class="table-toolbar">
          <div class="search-input-wrapper">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input v-model="searchQuery" placeholder="Cari kode scan, proyek, aset..." />
          </div>

          <div class="filter-group">
            <button class="filter-pill" :class="{ active: filterStatus === 'all' }" type="button" @click="filterStatus = 'all'">Semua</button>
            <button class="filter-pill" :class="{ active: filterStatus === 'running' }" type="button" @click="filterStatus = 'running'">Berjalan</button>
            <button class="filter-pill" :class="{ active: filterStatus === 'completed' }" type="button" @click="filterStatus = 'completed'">Selesai</button>
            <button class="filter-pill" :class="{ active: filterStatus === 'failed' }" type="button" @click="filterStatus = 'failed'">Gagal</button>
          </div>
        </div>

        <div class="profile-list" style="max-height: 520px; overflow-y: auto;">
          <article v-for="job in paginatedScanJobs" :key="job.id" class="entity-card" style="padding: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 8px;">
              <div>
                <h3 class="code-text" style="font-size: 14px; font-weight: 700; color: var(--text-main);">{{ job.code }}</h3>
                <div style="font-size: 12.5px; color: var(--text-muted); margin-top: 2px;">
                  Proyek: <strong>{{ job.project?.name ?? '-' }}</strong> ({{ job.project?.code ?? '-' }})
                </div>
              </div>
              <div style="display: flex; gap: 8px; align-items: center;">
                <span v-if="scanDurationBadge(job)" style="font-size: 11px; color: var(--tameng-sapphire); font-weight: 600;">
                  {{ scanDurationBadge(job) }}
                </span>
                <span class="badge" :class="statusClass(job.status)">
                  <span v-if="['queued', 'running'].includes(job.status)" class="pulse-dot"></span>
                  {{ statusLabel(job.status) }}
                </span>
                <button
                  class="small-button secondary-button"
                  type="button"
                  :disabled="isRerunningId === job.id"
                  style="padding: 3px 8px; font-size: 11px;"
                  title="Jalankan ulang pekerjaan scan ini"
                  @click="triggerRerun(job)"
                >
                  {{ isRerunningId === job.id ? 'Memproses...' : 'Pindai Ulang' }}
                </button>
              </div>
            </div>

            <!-- Failure Reason Box -->
            <div v-if="job.failure_reason" class="inline-alert error" style="margin-bottom: 12px;">
              <div class="alert-header">
                <span>{{ formatFailureReason(job.failure_reason).code }}</span>
              </div>
              <p style="margin: 0; font-size: 12px; font-weight: 600; color: #991b1b;">
                {{ formatFailureReason(job.failure_reason).summary }}
              </p>
              <details v-if="formatFailureReason(job.failure_reason).hasDetail" style="margin-top: 4px;">
                <summary style="font-size: 11px; cursor: pointer; color: #b91c1c;">Detail Error</summary>
                <pre>{{ formatFailureReason(job.failure_reason).rawDetail }}</pre>
              </details>
            </div>

            <!-- Target Asset & Metadata -->
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-muted); margin-bottom: 8px;">
              <div>Aset Target: <code class="code-text" style="color: var(--tameng-sapphire)">{{ assetLabel(job) }}</code></div>
              <span v-if="['queued', 'running'].includes(job.status)" style="font-size: 11.5px; color: var(--text-dim)">
                {{ estimateScanDuration(job) }}
              </span>
            </div>

            <!-- Progress & Engine Plan -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
              <div style="flex: 1; min-width: 140px;">
                <div class="progress-track" style="height: 6px;">
                  <span :style="{ width: `${job.progress}%` }"></span>
                </div>
              </div>

              <div class="engine-plan" style="display: flex; gap: 4px; flex-wrap: wrap;">
                <span v-for="engine in job.engine_plan ?? []" :key="`${job.id}-${engine.engine_key}`" class="run-pill">
                  {{ engine.engine_key }}: {{ statusLabel(engine.status ?? 'queued') }}
                </span>
              </div>

              <div style="font-size: 11.5px; color: var(--text-dim);">
                {{ job.scan_runs?.length ?? 0 }} run tercatat
              </div>
            </div>
          </article>

          <div v-if="!filteredScanJobs.length && !isLoading" class="status-panel">
            Tidak ada pekerjaan scan yang sesuai dengan filter.
          </div>
        </div>

        <!-- Pagination Controls -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="filteredScanJobs.length"
          :page-size-options="[5, 10, 20, 50]"
        />
      </div>

      <!-- Right: Create Scan Job Form -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Buat Pekerjaan Scan
          </h2>
          <span class="badge is-info">Security Admin+</span>
        </div>

        <form class="entity-form" @submit.prevent="submitScanJob">
          <label>
            <span>Otorisasi Aktif</span>
            <select v-model="form.authorization_id" :disabled="!canCreateScan" required>
              <option v-for="authorization in activeAuthorizations" :key="authorization.id" :value="authorization.id">
                {{ authorization.code }} - {{ authorization.project?.code }} ({{ authorization.scan_profile?.name }})
              </option>
            </select>
          </label>

          <div v-if="selectedAuthorization" class="status-panel info" style="margin-top: 12px;">
            <div style="font-size: 12.5px;">
              <div><strong>Proyek:</strong> {{ selectedAuthorization.project?.name }}</div>
              <div><strong>Aset:</strong> {{ selectedAuthorization.repository?.name ?? selectedAuthorization.target?.name ?? '-' }}</div>
              <div><strong>Engine:</strong> {{ selectedAuthorization.allowed_engines?.join(', ') }}</div>
            </div>
          </div>

          <div class="form-actions">
            <button class="btn btn-primary-glow" type="submit" :disabled="!canCreateScan || isSaving || !activeAuthorizations.length">
              {{ isSaving ? 'Membuat antrean...' : 'Buat dan Antrekan Scan' }}
            </button>
            <button class="secondary-button" type="button" @click="resetForm">Reset</button>
          </div>
        </form>
      </div>
    </section>

    <!-- Recent Engine Runs Activity -->
    <section class="panel">
      <div class="panel-heading">
        <h2>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/></svg>
          Telemetri Run Engine Terbaru
        </h2>
        <span>Docker Container & Sandbox Metrics</span>
      </div>

      <div class="profile-list">
        <article v-for="job in scanJobs.filter((item) => item.scan_runs?.length)" :key="`run-${job.id}`">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <h3 class="code-text" style="font-size: 14px; color: var(--text-main);">{{ job.code }}</h3>
            <span class="badge" :class="statusClass(job.status)">{{ statusLabel(job.status) }}</span>
          </div>

          <div style="display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px;">
            <span v-for="run in job.scan_runs" :key="run.id" class="run-pill">
              <strong>{{ run.engine_key }}</strong>: {{ statusLabel(run.status) }} | {{ scanRunMode(run) }} | {{ scanRunFindingCount(run) }} temuan
            </span>
          </div>
        </article>
      </div>
    </section>
  </AppShell>
</template>
