<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { exportFindingsToCsv } from '../services/exportCsvService'
import { getFindingAiRemediation } from '../services/findingService'
import { createMyScanRequest, getMyScanRequests, rerunMyScanRequest } from '../services/myScanRequestService'
import { downloadReportPdf } from '../services/reportService'

const scanRequests = ref<any[]>([])
const selectedRequestId = ref<any>(null)
const isLoading = ref(true)
const isSaving = ref(false)
const isRerunning = ref(false)
const isDownloadingPdf = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const activeTab = ref<'detail' | 'findings' | 'raw_json'>('detail')
const findingsFilter = ref<'all' | 'critical' | 'high' | 'medium' | 'low'>('all')
const copiedJson = ref(false)
let refreshTimer: number | null = null

const currentPage = ref(1)
const pageSize = ref(5)
const findingsPage = ref(1)
const findingsPageSize = ref(10)

const mobileFile = ref<File | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const mobileMode = ref<'upload' | 'url'>('upload')

const mobileFileName = computed(() => mobileFile.value?.name ?? '')
const mobileFileSize = computed(() => {
  if (!mobileFile.value) return ''
  const mb = mobileFile.value.size / (1024 * 1024)
  return mb >= 1 ? `${mb.toFixed(2)} MB` : `${(mobileFile.value.size / 1024).toFixed(1)} KB`
})

function onFileSelected(event: Event) {
  const target = event.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    handleMobileFile(target.files[0])
  }
}

function onDropFile(event: DragEvent) {
  isDragging.value = false
  if (event.dataTransfer?.files && event.dataTransfer.files.length > 0) {
    handleMobileFile(event.dataTransfer.files[0])
  }
}

function handleMobileFile(file: File) {
  const ext = file.name.split('.').pop()?.toLowerCase() || ''
  if (!['apk', 'ipa', 'aab', 'zip'].includes(ext)) {
    errorMessage.value = 'Format file tidak didukung. Mohon unggah file aplikasi mobile (.apk, .ipa, .aab, .zip).'
    return
  }
  if (file.size > 200 * 1024 * 1024) {
    errorMessage.value = 'Ukuran file melebihi batas maksimum 200MB.'
    return
  }
  errorMessage.value = ''
  mobileFile.value = file
  if (!form.project_name) {
    form.project_name = file.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ')
  }
}

function removeMobileFile() {
  mobileFile.value = null
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
}

const form = reactive({
  scan_type: 'repository',
  project_name: '',
  asset_url: '',
  default_branch: 'main',
  is_private: false,
  access_token: '',
  auth_type: 'none',
  login_url_path: '/login',
  auth_header_name: 'Authorization',
  auth_header_value: '',
  auth_username: '',
  auth_password: '',
  notes: '',
})

const selectedRequest = computed(() =>
  scanRequests.value.find((item) => String(item.id) === String(selectedRequestId.value)) ?? scanRequests.value[0] ?? null,
)

const selectedReport = computed(() => selectedRequest.value?.reports?.[0] ?? null)
const selectedReportContent = computed(() => selectedReport.value?.metadata?.content ?? null)
const selectedFindings = computed(() => selectedReportContent.value?.findings ?? [])
const selectedRiskSummary = computed(() => selectedReportContent.value?.risk_summary ?? {})

const filteredFindings = computed(() => {
  if (findingsFilter.value === 'all') return selectedFindings.value
  return selectedFindings.value.filter((f: any) => f.severity === findingsFilter.value)
})

const paginatedRequests = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return scanRequests.value.slice(start, start + pageSize.value)
})

const paginatedFindings = computed(() => {
  const start = (findingsPage.value - 1) * findingsPageSize.value
  return filteredFindings.value.slice(start, start + findingsPageSize.value)
})

watch(findingsFilter, () => {
  findingsPage.value = 1
})

const reportJson = computed(() =>
  selectedReport.value ? JSON.stringify(selectedReport.value.metadata?.content ?? selectedReport.value, null, 2) : '',
)

const statusCounts = computed(() => ({
  total: scanRequests.value.length,
  berjalan: scanRequests.value.filter((item) => ['queued', 'running'].includes(item.status)).length,
  selesai: scanRequests.value.filter((item) => item.status === 'completed').length,
  gagal: scanRequests.value.filter((item) => ['failed', 'denied'].includes(item.status)).length,
}))

function statusLabel(status: string) {
  return (
    {
      queued: 'Dalam antrean',
      running: 'Sedang berjalan',
      completed: 'Selesai',
      failed: 'Gagal',
      denied: 'Ditolak',
      skipped: 'Dilewati',
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

function scanTypeLabel(type: string) {
  return (
    {
      repository: 'Repository GitHub',
      container: 'Container Image',
      web: 'Website URL',
      api: 'API Endpoint',
      mobile: 'Aplikasi Mobile',
    }[type] ?? type
  )
}

function assetName(item: any) {
  return item.repository?.url ?? item.target?.base_url ?? item.target?.hostname ?? '-'
}

function estimateScanDuration(item: any): string {
  if (!item) return '-'

  if (['completed', 'failed', 'denied'].includes(item.status)) {
    if (item.started_at && item.finished_at) {
      const diffMs = new Date(item.finished_at).getTime() - new Date(item.started_at).getTime()
      if (diffMs > 0) {
        const totalSecs = Math.round(diffMs / 1000)
        const mins = Math.floor(totalSecs / 60)
        const secs = totalSecs % 60
        return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`
      }
    }
    return item.status === 'completed' ? 'Selesai' : 'Dihentikan'
  }

  if (item.status === 'queued') {
    return '~3 - 5 menit (Menunggu antrean worker)'
  }

  if (item.status === 'running') {
    if (item.scan_type === 'container') {
      return '~30 - 90 detik lagi (Eksekusi Trivy, Grype & Syft SBOM)'
    }
    if (item.scan_type === 'mobile') {
      return '~1 - 2 menit lagi (Eksekusi MobSF Mobile Security Framework)'
    }
    if (item.scan_type === 'web' || item.scan_type === 'api') {
      return '~1 - 2 menit lagi (Eksekusi DAST Nuclei & Web Exposure)'
    }
    const progress = Number(item.progress ?? 0)
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

function scanDurationBadge(item: any): string {
  if (!item) return ''

  if (['completed', 'failed', 'denied'].includes(item.status)) {
    if (item.started_at && item.finished_at) {
      const diffMs = new Date(item.finished_at).getTime() - new Date(item.started_at).getTime()
      if (diffMs > 0) {
        const totalSecs = Math.round(diffMs / 1000)
        const mins = Math.floor(totalSecs / 60)
        const secs = totalSecs % 60
        return `Durasi: ${mins > 0 ? `${mins}m ${secs}s` : `${secs}s`}`
      }
    }
    return ''
  }

  if (item.status === 'queued') return 'Estimasi: ~3-5 mnt'
  if (item.status === 'running') {
    const p = Number(item.progress ?? 0)
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
    summary = 'Akses ditolak atau repositori GitHub bersifat privat. Diperlukan repositori publik atau token akses.'
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

function formatEngineReason(raw: string, status?: string): string {
  if (!raw) return ''

  if (/HADOLINT_REQUIRES_DOCKERFILE/i.test(raw)) {
    return 'Hadolint menganalisis Dockerfile (Dilewati untuk target direct container image)'
  }
  if (/NO_DOCKERFILE_FOUND/i.test(raw)) {
    return 'Tidak ada file Dockerfile pada repositori (Analisis Dockerfile dilewati)'
  }
  if (/NO_LOCKFILE_FOUND|NO_SBOM_FOUND/i.test(raw)) {
    return 'Tidak ada file manifest dependensi (Analisis SCA dilewati)'
  }
  if (/ENGINE_PROCESS_TIMEOUT/i.test(raw)) {
    return 'Batas waktu pemindaian engine habis (Timeout)'
  }
  if (/GRYPE_PROCESS_FAILED/i.test(raw)) {
    return 'Pemindaian kerentanan OS & dependensi Grype mengalami kendala'
  }
  if (/TRIVY_PROCESS_FAILED/i.test(raw)) {
    return 'Pemindaian kerentanan CVE Trivy mengalami kendala'
  }
  if (/SYFT_PROCESS_FAILED/i.test(raw)) {
    return 'Penyusunan katalog SBOM Syft mengalami kendala'
  }
  if (/MOBSF_PROCESS_FAILED/i.test(raw)) {
    return 'Pemindaian keamanan mobile MobSF mengalami kendala'
  }
  if (/SEMGREP_PROCESS_FAILED/i.test(raw)) {
    return 'Parsing struktur kode sumber mengalami kendala'
  }
  if (/GITLEAKS_PROCESS_FAILED/i.test(raw)) {
    return 'Pemindaian riwayat secret git mengalami kendala'
  }
  if (/TESTSSL_PROCESS_FAILED|TESTSSL_EXECUTION_ERROR/i.test(raw)) {
    return 'Pemindaian konfigurasi TLS/SSL testssl mengalami kendala'
  }
  if (/NUCLEI_PROCESS_FAILED/i.test(raw)) {
    return 'Target web/API tidak merespons pengujian DAST'
  }
  if (/DENIED/i.test(raw)) {
    return 'Ditolak oleh kebijakan otorisasi pemindaian'
  }

  return raw
    .replace(/_/g, ' ')
    .toLowerCase()
    .replace(/^\w/, (c) => c.toUpperCase())
}

function resetForm() {
  form.scan_type = 'repository'
  form.project_name = ''
  form.asset_url = ''
  form.default_branch = 'main'
  form.is_private = false
  form.access_token = ''
  form.auth_type = 'none'
  form.login_url_path = '/login'
  form.auth_header_name = 'Authorization'
  form.auth_header_value = ''
  form.auth_username = ''
  form.auth_password = ''
  form.notes = ''
  mobileFile.value = null
  mobileMode.value = 'upload'
  if (fileInputRef.value) {
    fileInputRef.value.value = ''
  }
}

async function loadData(silent = false) {
  if (!silent && !scanRequests.value.length) {
    isLoading.value = true
  }

  try {
    const data = await getMyScanRequests(silent ? { skipCache: true } : {})
    scanRequests.value = data
    if (!selectedRequestId.value && data.length) {
      selectedRequestId.value = data[0].id
    }
  } catch (error) {
    if (!silent) {
      console.error('Gagal memuat scan saya.', error)
      errorMessage.value = 'Gagal memuat data scan saya. Pastikan koneksi backend aktif.'
    }
  } finally {
    isLoading.value = false
  }
}

async function submitScanRequest() {
  isSaving.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    if (form.scan_type === 'mobile' && mobileMode.value === 'upload') {
      if (!mobileFile.value) {
        errorMessage.value = 'Silakan pilih atau seret file binary aplikasi mobile (.apk / .ipa / .aab) terlebih dahulu.'
        isSaving.value = false
        return
      }

      if (!form.project_name.trim()) {
        form.project_name = mobileFile.value.name.replace(/\.[^/.]+$/, '').replace(/[-_]/g, ' ')
      }

      const formData = new FormData()
      formData.append('scan_type', 'mobile')
      formData.append('project_name', form.project_name.trim())
      formData.append('file', mobileFile.value)
      if (form.notes) {
        formData.append('notes', form.notes)
      }

      const request = await createMyScanRequest(formData)
      selectedRequestId.value = request.id
      successMessage.value = `File APK '${mobileFile.value.name}' berhasil diunggah! Engine MobSF sedang memindai kerentanan binary.`
      resetForm()
      await loadData(true)
      return
    }

    const isRepo = form.scan_type === 'repository' || (form.scan_type === 'mobile' && form.asset_url.includes('github.com'))
    const isWeb = ['web', 'api'].includes(form.scan_type)
    const isFormLogin = isWeb && form.auth_type === 'form_login'
    const isBasic = isWeb && form.auth_type === 'basic'

    const request = await createMyScanRequest({
      scan_type: form.scan_type,
      project_name: form.project_name,
      asset_url: form.asset_url,
      default_branch: isRepo ? form.default_branch : null,
      is_private: isRepo ? form.is_private : false,
      access_token: isRepo && form.is_private ? form.access_token : null,
      auth_type: isWeb ? form.auth_type : 'none',
      login_url_path: isFormLogin ? form.login_url_path : null,
      auth_header_name: isWeb && form.auth_type === 'header' ? form.auth_header_name : null,
      auth_header_value: isWeb && ['header', 'cookie'].includes(form.auth_type) ? form.auth_header_value : null,
      auth_username: isBasic || isFormLogin ? form.auth_username : null,
      auth_password: isBasic || isFormLogin ? form.auth_password : null,
      notes: form.notes,
    })
    selectedRequestId.value = request.id
    successMessage.value = 'Permintaan scan berhasil dibuat! Queue worker sedang memproses di latar belakang.'
    resetForm()
    await loadData(true)
  } catch (error: any) {
    console.error('Gagal membuat scan.', error)
    const backendMessage = error?.response?.data?.message || error?.response?.data?.errors?.file?.[0] || error?.response?.data?.errors?.asset_url?.[0] || error?.response?.data?.errors?.access_token?.[0]
    errorMessage.value = backendMessage || 'Gagal membuat permintaan scan. Periksa URL repositori/target dan izin scope.'
  } finally {
    isSaving.value = false
  }
}

async function triggerRerun(item: any) {
  isRerunning.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const newRequest = await rerunMyScanRequest(item.id)
    selectedRequestId.value = newRequest.id
    successMessage.value = `Scan ${item.code} berhasil dimasukkan kembali ke antrean (${newRequest.code})!`
    await loadData(true)
  } catch (error) {
    console.error('Gagal menjalankan ulang scan.', error)
    errorMessage.value = 'Gagal menjalankan ulang scan.'
  } finally {
    isRerunning.value = false
  }
}

function exportCurrentFindings() {
  exportFindingsToCsv(filteredFindings.value, `tameng-scan-${selectedRequest.value?.code ?? 'report'}`)
}

function copyJson() {
  navigator.clipboard.writeText(reportJson.value)
  copiedJson.value = true
  setTimeout(() => (copiedJson.value = false), 2000)
}

async function downloadPdfReport() {
  if (!selectedReport.value) return

  isDownloadingPdf.value = true
  errorMessage.value = ''

  try {
    const blob = await downloadReportPdf(selectedReport.value.id)
    const url = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }))
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `${selectedRequest.value?.code ?? 'secsys-report'}.pdf`
    anchor.click()
    URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Gagal mengunduh PDF laporan.', error)
    errorMessage.value = 'Gagal mengunduh PDF laporan.'
  } finally {
    isDownloadingPdf.value = false
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
    <template #eyebrow>Developer Self-Service Portal</template>
    <template #title>Scan Keamanan Mandiri TAMENG</template>

    <!-- Top Metrics -->
    <section class="metrics">
      <div class="stat-card" style="border-top: 3px solid var(--accent-cyan)">
        <span>Total Permintaan</span>
        <strong>{{ statusCounts.total }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--tameng-sapphire)">
        <span>Sedang Berjalan</span>
        <div style="display: flex; align-items: baseline; gap: 8px;">
          <strong>{{ statusCounts.berjalan }}</strong>
          <span v-if="statusCounts.berjalan > 0" class="pulse-dot" style="background: var(--tameng-sapphire)"></span>
        </div>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--status-success)">
        <span>Selesai & Terbit</span>
        <strong style="color: var(--status-success)">{{ statusCounts.selesai }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--severity-critical)">
        <span>Gagal / Ditolak</span>
        <strong style="color: var(--severity-critical)">{{ statusCounts.gagal }}</strong>
      </div>
    </section>

    <!-- Notifications -->
    <div v-if="successMessage" class="status-panel success fade-in">
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <span>{{ errorMessage }}</span>
    </div>

    <div class="two-column">
      <!-- Left: Create Scan Form -->
      <div class="panel">
        <div class="panel-heading">
          <h2>Buat Permintaan Scan Baru</h2>
          <span class="badge is-info">Auto Scope & Guarded</span>
        </div>

        <form class="entity-form" @submit.prevent="submitScanRequest">
          <label>
            <span>Tipe Pemindaian</span>
            <select v-model="form.scan_type" required>
              <option value="repository">Repositori Git (Source Code, Secrets, Dependencies, SBOM, Dockerfile, IaC)</option>
              <option value="mobile">Aplikasi Mobile (Android / iOS / Flutter / React Native — MobSF)</option>
              <option value="container">Container Image (Docker Hub / Registry Tag — Trivy, Grype & Syft)</option>
              <option value="web">Aplikasi Web / Website (DAST - Nuclei Web Exposure & Vulnerabilities)</option>
              <option value="api">REST API Endpoint (DAST - Nuclei API Security Assessment)</option>
            </select>
          </label>

          <div v-if="form.scan_type === 'mobile'" class="inline-alert info" style="margin-top: 4px; margin-bottom: 8px; font-size: 11.5px; padding: 10px 14px; background: rgba(59, 130, 246, 0.08); border-left: 3px solid var(--tameng-sapphire); border-radius: 4px;">
            <strong style="color: var(--tameng-sapphire); display: block; margin-bottom: 2px;">Pemindaian Keamanan Mobile Terintegrasi (MobSF)</strong>
            <span style="color: var(--text-muted);">
              Engine MobSF (Mobile Security Framework) akan memindai konfigurasi AndroidManifest / Info.plist, izin aplikasi (*permissions*), celah kriptografi, penyimpanan lokal tidak aman, dan standar kepatuhan OWASP Mobile Top 10 (MASVS).
            </span>
          </div>

          <div v-if="form.scan_type === 'container'" class="inline-alert info" style="margin-top: 4px; margin-bottom: 8px; font-size: 11.5px; padding: 10px 14px; background: rgba(59, 130, 246, 0.08); border-left: 3px solid var(--tameng-sapphire); border-radius: 4px;">
            <strong style="color: var(--tameng-sapphire); display: block; margin-bottom: 2px;">Pemindaian Container Image Terisolasi</strong>
            <span style="color: var(--text-muted);">
              Suite scanner (Aqua Trivy, Anchore Grype, dan Anchore Syft) akan memindai kerentanan OS packages, CVE pustaka dependensi, serta membuat katalog Software Bill of Materials (SBOM) lengkap.
            </span>
          </div>

          <div v-if="['web', 'api'].includes(form.scan_type)" class="inline-alert info" style="margin-top: 4px; margin-bottom: 8px; font-size: 11.5px; padding: 10px 14px; background: rgba(59, 130, 246, 0.08); border-left: 3px solid var(--tameng-sapphire); border-radius: 4px;">
            <strong style="color: var(--tameng-sapphire); display: block; margin-bottom: 2px;">Pemindaian Dinamis DAST Terisolasi</strong>
            <span style="color: var(--text-muted);">
              Engine <strong>Nuclei</strong> dan <strong>TestSSL (testssl.sh)</strong> akan menguji kerentanan perimeter web, sertifikat TLS/SSL, cipher suites usang (SWEET32, BEAST, POODLE, ROBOT, RC4), header keamanan, dan eksposur endpoint secara otomatis.
            </span>
          </div>

          <label>
            <span>Nama Proyek / Layanan</span>
            <input v-model="form.project_name" placeholder="Contoh: Mobile Banking Android / iOS App" required />
          </label>

          <!-- Mobile App Target (Binary Upload vs Download URL/GitHub) -->
          <div v-if="form.scan_type === 'mobile'" style="margin-top: 4px; margin-bottom: 12px;">
            <span style="display: block; font-size: 12.5px; font-weight: 700; color: var(--text-main); margin-bottom: 8px;">
              Metode Input Aplikasi Mobile
            </span>

            <div class="segmented-group" style="margin-bottom: 12px;">
              <button
                type="button"
                :class="['segmented-btn', { active: mobileMode === 'upload' }]"
                @click="mobileMode = 'upload'"
              >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <span>Unggah File Binary (.apk / .ipa / .aab)</span>
              </button>
              <button
                type="button"
                :class="['segmented-btn', { active: mobileMode === 'url' }]"
                @click="mobileMode = 'url'"
              >
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <span>Link Unduhan URL / Repositori GitHub</span>
              </button>
            </div>

            <!-- Upload Dropzone -->
            <div
              v-if="mobileMode === 'upload'"
              class="mobile-upload-zone"
              :class="{ 'is-dragging': isDragging, 'has-file': !!mobileFile }"
              @dragover.prevent="isDragging = true"
              @dragleave.prevent="isDragging = false"
              @drop.prevent="onDropFile"
            >
              <input
                ref="fileInputRef"
                type="file"
                accept=".apk,.ipa,.aab,.zip"
                style="display: none;"
                @change="onFileSelected"
              />

              <div v-if="!mobileFile" style="text-align: center; padding: 26px 18px;">
                <div class="upload-icon-circle">
                  <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                </div>
                <h4 style="margin: 12px 0 4px 0; font-size: 14px; font-weight: 700; color: var(--text-main);">
                  Tarik & Lepas File APK / IPA Anda di sini
                </h4>
                <p style="font-size: 12px; color: var(--text-muted); margin: 0 0 14px 0;">
                  Mendukung binary <code>.apk</code>, <code>.ipa</code>, <code>.aab</code>, atau <code>.zip</code> (Maksimal 200MB)
                </p>
                <button type="button" class="btn secondary-button" style="display: inline-flex; align-items: center; gap: 8px;" @click="fileInputRef?.click()">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                  <span>Pilih File dari Komputer</span>
                </button>
              </div>

              <div v-else class="file-preview-card">
                <div style="display: flex; align-items: center; gap: 14px;">
                  <div class="apk-badge-box">
                    <span style="font-weight: 800; font-size: 11px; letter-spacing: 0.5px; color: #fff;">APK</span>
                  </div>
                  <div>
                    <strong style="display: block; font-size: 13.5px; color: var(--text-main); word-break: break-all;">
                      {{ mobileFileName }}
                    </strong>
                    <span style="font-size: 11.5px; color: var(--text-muted); display: block; margin-top: 2px;">
                      Ukuran: {{ mobileFileSize }} &bull; <strong style="color: var(--status-success);">Siap Dipindai MobSF</strong>
                    </span>
                  </div>
                </div>
                <div style="display: flex; gap: 8px;">
                  <button type="button" class="small-button secondary-button" @click="fileInputRef?.click()">Ganti</button>
                  <button type="button" class="small-button danger-button" @click="removeMobileFile">Hapus</button>
                </div>
              </div>
            </div>

            <!-- Mobile Download URL / GitHub Input -->
            <div v-if="mobileMode === 'url'">
              <input
                v-model="form.asset_url"
                placeholder="https://example.com/app-release.apk atau https://github.com/org/mobile-app"
                type="url"
                required
              />
              <small style="color: var(--text-dim); font-size: 11px; margin-top: 4px; display: block;">
                Mendukung link unduhan direct binary APK atau repositori source code mobile GitHub.
              </small>
            </div>
          </div>

          <!-- URL / Image Input for Repository, Container, Web, and API -->
          <label v-if="form.scan_type !== 'mobile'">
            <span>
              {{
                form.scan_type === 'repository'
                  ? 'URL Repositori GitHub'
                  : (form.scan_type === 'container'
                      ? 'Nama Docker Image / Tag Registry'
                      : (form.scan_type === 'api' ? 'URL Base API Endpoint' : 'URL Aplikasi Web'))
              }}
            </span>
            <input
              v-model="form.asset_url"
              :placeholder="
                form.scan_type === 'repository'
                  ? 'https://github.com/org/source-code'
                  : (form.scan_type === 'container'
                      ? 'alpine:latest, nginx:alpine, node:20-alpine, atau ghcr.io/org/app:latest'
                      : 'https://app.example.com')
              "
              :type="['web', 'api', 'repository'].includes(form.scan_type) ? 'url' : 'text'"
              required
            />
            <small v-if="form.scan_type === 'container'" style="color: var(--text-dim); font-size: 11px; margin-top: 4px; display: block;">
              Mendukung image Docker Hub publik (contoh: <code>nginx:alpine</code>, <code>redis:7</code>, <code>node:20-slim</code>) dan custom registry (contoh: <code>ghcr.io/org/app:v1.0</code>).
            </small>
          </label>

          <!-- Optional DAST Authentication for Deep / Grey-box Scan -->
          <div v-if="['web', 'api'].includes(form.scan_type)" class="entity-card" style="margin-top: 6px; margin-bottom: 12px; padding: 14px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
              <span style="font-weight: 700; font-size: 12.5px; color: var(--text-main);">Kredensial Autentikasi (Opsional / Grey-Box)</span>
              <span class="badge is-info" style="font-size: 10.5px;">Terenkripsi Aman</span>
            </div>
            <p style="font-size: 11.5px; color: var(--text-muted); margin: 0 0 10px 0; line-height: 1.4;">
              Opsional: Masukkan session cookie, header token, atau kredensial akun uji coba jika ingin memindai endpoint internal di balik halaman login.
            </p>

            <label style="margin-bottom: 8px;">
              <span style="font-size: 11.5px;">Metode Autentikasi</span>
              <select v-model="form.auth_type" style="font-size: 12px; padding: 6px 10px;">
                <option value="none">Tanpa Autentikasi (Black-Box / Perimeter Luar)</option>
                <option value="form_login">Form Login Otomatis (Email/Username & Password)</option>
                <option value="cookie">Session Cookie (Laravel Session / Django / JWT Cookie)</option>
                <option value="header">Custom HTTP Header / Bearer Token</option>
                <option value="basic">HTTP Basic Authentication</option>
              </select>
            </label>

            <!-- Mode Form Login Otomatis -->
            <div v-if="form.auth_type === 'form_login'" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 6px;">
              <label>
                <span style="font-size: 11px;">Email / Username Akun Uji Coba</span>
                <input v-model="form.auth_username" placeholder="admin@domain.com atau tester" style="font-size: 12px; padding: 6px 10px;" required />
              </label>
              <label>
                <span style="font-size: 11px;">Password Akun Uji Coba</span>
                <input v-model="form.auth_password" placeholder="••••••••" type="password" style="font-size: 12px; padding: 6px 10px;" required />
              </label>
              <label style="grid-column: span 2;">
                <span style="font-size: 11px;">Path Halaman Login (Default: /login)</span>
                <input v-model="form.login_url_path" placeholder="/login atau /auth/signin" style="font-size: 12px; padding: 6px 10px;" />
              </label>
            </div>

            <!-- Mode Cookie (Session Cookie) -->
            <div v-if="form.auth_type === 'cookie'" style="margin-top: 6px;">
              <label>
                <span style="font-size: 11px;">Nilai Cookie Sesi</span>
                <input v-model="form.auth_header_value" placeholder="laravel_session=eyJpdiI6...; XSRF-TOKEN=..." type="password" style="font-size: 12px; padding: 6px 10px;" />
              </label>
            </div>

            <!-- Mode Header (Bearer Token, API Key) -->
            <div v-if="form.auth_type === 'header'" style="display: grid; grid-template-columns: 1fr 2fr; gap: 10px; margin-top: 6px;">
              <label>
                <span style="font-size: 11px;">Header Name</span>
                <input v-model="form.auth_header_name" placeholder="Authorization" style="font-size: 12px; padding: 6px 10px;" />
              </label>
              <label>
                <span style="font-size: 11px;">Header Value / Token</span>
                <input v-model="form.auth_header_value" placeholder="Bearer eyJhbGciOi..." type="password" style="font-size: 12px; padding: 6px 10px;" />
              </label>
            </div>

            <!-- Mode Basic Auth -->
            <div v-if="form.auth_type === 'basic'" style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 6px;">
              <label>
                <span style="font-size: 11px;">Username</span>
                <input v-model="form.auth_username" placeholder="test_user" style="font-size: 12px; padding: 6px 10px;" />
              </label>
              <label>
                <span style="font-size: 11px;">Password</span>
                <input v-model="form.auth_password" placeholder="••••••••" type="password" style="font-size: 12px; padding: 6px 10px;" />
              </label>
            </div>
          </div>

          <!-- Git Branch & PAT for Repository or GitHub Mobile Scans -->
          <div v-if="form.scan_type === 'repository' || (form.scan_type === 'mobile' && mobileMode === 'url' && form.asset_url.includes('github.com'))" style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <label>
              <span>Default Git Branch</span>
              <input v-model="form.default_branch" placeholder="main" required />
            </label>

            <label>
              <span>Visibilitas Repositori</span>
              <select v-model="form.is_private">
                <option :value="false">Publik (Public)</option>
                <option :value="true">Privat (Private - Butuh Token PAT)</option>
              </select>
            </label>
          </div>

          <label v-if="(form.scan_type === 'repository' || (form.scan_type === 'mobile' && mobileMode === 'url' && form.asset_url.includes('github.com'))) && form.is_private">
            <span style="display: flex; justify-content: space-between; align-items: center;">
              <span>GitHub Personal Access Token (PAT)</span>
              <strong style="color: var(--severity-critical); font-size: 11px;">*Terenkripsi Aman</strong>
            </span>
            <input
              v-model="form.access_token"
              type="password"
              placeholder="ghp_xxxxxxxxxxxx atau github_pat_xxxx"
              :required="form.is_private"
            />
            <small style="color: var(--text-dim); font-size: 11px; margin-top: 4px; display: block;">
              Token disimpan menggunakan enkripsi Crypt Laravel untuk mengunduh source code secara aman.
            </small>
          </label>

          <label>
            <span>Catatan / Keterangan (Opsional)</span>
            <textarea v-model="form.notes" placeholder="Tulis catatan asesmen atau tujuan scan..."></textarea>
          </label>

          <div class="form-actions">
            <button class="btn btn-primary-glow" type="submit" :disabled="isSaving">
              <svg v-if="isSaving" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="pulse-dot"></svg>
              <span>{{ isSaving ? 'Memproses...' : 'Mulai Scan Keamanan' }}</span>
            </button>
            <button class="secondary-button" type="button" @click="resetForm">Reset Form</button>
          </div>
        </form>
      </div>

      <!-- Right: Scan Requests List -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
            Riwayat Pemindaian Saya
          </h2>
          <span class="badge">{{ scanRequests.length }} Riwayat</span>
        </div>

        <div v-if="!scanRequests.length && !isLoading" class="status-panel">
          Belum ada riwayat scan. Buat permintaan pertama Anda pada form di sebelah kiri.
        </div>

        <div class="profile-list" style="max-height: 480px; overflow-y: auto;">
          <article
            v-for="item in paginatedRequests"
            :key="item.id"
            :style="{
              borderLeft: String(item.id) === String(selectedRequestId) ? '3px solid var(--tameng-sapphire)' : undefined,
              background: String(item.id) === String(selectedRequestId) ? '#f0f9ff' : undefined,
              cursor: 'pointer'
            }"
            @click="selectedRequestId = item.id"
          >
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
              <div>
                <span class="code-text" style="font-weight: 700; color: var(--text-main);">{{ item.code }}</span>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">{{ item.project?.name }}</div>
              </div>
              <span class="badge" :class="statusClass(item.status)">
                <span v-if="['queued', 'running'].includes(item.status)" class="pulse-dot"></span>
                {{ statusLabel(item.status) }}
              </span>
            </div>

            <p style="margin-top: 4px; font-size: 12.5px; word-break: break-all;">
              {{ assetName(item) }}
            </p>

            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; color: var(--text-dim); margin-top: 6px;">
              <span>{{ scanTypeLabel(item.scan_type ?? 'repository') }}</span>
              <span v-if="scanDurationBadge(item)" style="font-size: 11px; color: var(--tameng-sapphire); font-weight: 600;">
                {{ scanDurationBadge(item) }}
              </span>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; color: var(--text-dim); margin-top: 4px;">
              <span>Progres: <strong style="color: var(--text-main)">{{ item.progress }}%</strong></span>
              <button
                class="small-button secondary-button"
                type="button"
                :disabled="isRerunning"
                style="padding: 2px 6px; font-size: 11px;"
                title="Jalankan ulang pemindaian ini"
                @click.stop="triggerRerun(item)"
              >
                Pindai Ulang
              </button>
            </div>
          </article>
        </div>

        <!-- Requests Pagination -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="scanRequests.length"
          :page-size-options="[5, 10, 20]"
        />
      </div>
    </div>

    <!-- Selected Scan Request Detail & Findings Studio -->
    <section v-if="selectedRequest" class="panel">
      <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <div>
          <h2 style="display: flex; align-items: center; gap: 8px;">
            <span class="code-text" style="color: var(--text-main);">{{ selectedRequest.code }}</span>
            <span class="badge" :class="statusClass(selectedRequest.status)">{{ statusLabel(selectedRequest.status) }}</span>
          </h2>
          <span style="font-size: 12px; color: var(--text-muted); margin-top: 2px; display: block;">
            Proyek: {{ selectedRequest.project?.name }} | Target: {{ assetName(selectedRequest) }}
          </span>
        </div>

        <div style="display: flex; gap: 8px; align-items: center;">
          <button
            v-if="selectedReport"
            class="small-button secondary-button"
            style="background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe; font-weight: 600;"
            type="button"
            :disabled="isDownloadingPdf"
            @click="downloadPdfReport"
          >
            {{ isDownloadingPdf ? 'Mengunduh PDF...' : 'Unduh Laporan PDF' }}
          </button>
          <button
            class="small-button secondary-button"
            type="button"
            :disabled="isRerunning"
            @click="triggerRerun(selectedRequest)"
          >
            Pindai Ulang
          </button>
        </div>

        <!-- Detail Tabs Navigation -->
        <div class="filter-group" style="width: 100%; margin-top: 8px;">
          <button
            class="filter-pill"
            :class="{ active: activeTab === 'detail' }"
            type="button"
            @click="activeTab = 'detail'"
          >
            Pipeline & Status
          </button>
          <button
            class="filter-pill"
            :class="{ active: activeTab === 'findings' }"
            type="button"
            @click="activeTab = 'findings'"
          >
            Temuan Kerentanan ({{ selectedFindings.length }})
          </button>
          <button
            class="filter-pill"
            :class="{ active: activeTab === 'raw_json' }"
            type="button"
            @click="activeTab = 'raw_json'"
          >
            Raw JSON Report
          </button>
        </div>
      </div>

      <!-- Tab 1: Pipeline & Status Detail -->
      <div v-if="activeTab === 'detail'">
        <!-- Progress Bar Track with Estimasi Waktu -->
        <div class="progress-block" style="margin-bottom: 24px;">
          <div class="progress-header">
            <span>Kemajuan Pekerjaan Scan</span>
            <strong style="color: var(--tameng-sapphire)">{{ selectedRequest.progress }}% Selesai</strong>
          </div>
          <div class="progress-track">
            <span :style="{ width: `${selectedRequest.progress}%` }"></span>
          </div>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 8px; font-size: 12px; flex-wrap: wrap; gap: 8px;">
            <span style="color: var(--text-muted);">
              <span v-if="['queued', 'running'].includes(selectedRequest.status)">
                Estimasi Selesai: <strong style="color: var(--tameng-sapphire)">{{ estimateScanDuration(selectedRequest) }}</strong>
              </span>
              <span v-else>
                Durasi Eksekusi: <strong style="color: var(--text-main)">{{ estimateScanDuration(selectedRequest) }}</strong>
              </span>
            </span>
            <span v-if="selectedRequest.started_at" style="color: var(--text-dim); font-size: 11.5px;">
              Dimulai: {{ new Date(selectedRequest.started_at).toLocaleTimeString('id-ID') }}
              <span v-if="selectedRequest.finished_at"> • Selesai: {{ new Date(selectedRequest.finished_at).toLocaleTimeString('id-ID') }}</span>
            </span>
          </div>
        </div>

        <!-- Failure Reason Alert -->
        <div v-if="selectedRequest.failure_reason" class="inline-alert error" style="margin-bottom: 20px;">
          <div class="alert-header">
            <span>{{ formatFailureReason(selectedRequest.failure_reason).code }}</span>
          </div>
          <p style="margin: 0; font-size: 12.5px; font-weight: 600; color: #991b1b;">
            {{ formatFailureReason(selectedRequest.failure_reason).summary }}
          </p>
          <details v-if="formatFailureReason(selectedRequest.failure_reason).hasDetail" style="margin-top: 6px;">
            <summary style="font-size: 11px; cursor: pointer; color: #b91c1c; font-weight: 600;">Lihat Log Teknis</summary>
            <pre>{{ formatFailureReason(selectedRequest.failure_reason).rawDetail }}</pre>
          </details>
        </div>

        <!-- Engine Runs Execution Matrix -->
        <h3 style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 12px;">Rincian Engine Scanner</h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; margin-bottom: 24px;">
          <div
            v-for="run in selectedRequest.scan_runs ?? []"
            :key="run.id"
            class="entity-card"
            style="padding: 14px 16px;"
          >
            <div style="display: flex; justify-content: space-between; align-items: center;">
              <strong style="text-transform: uppercase; font-size: 13px; color: var(--text-main);">{{ run.engine_key }}</strong>
              <span class="badge" :class="statusClass(run.status)">{{ statusLabel(run.status) }}</span>
            </div>
            <div style="font-size: 12px; color: var(--text-muted); margin-top: 6px;">
              Temuan: <strong>{{ run.runtime_metrics?.finding_count ?? 0 }}</strong> |
              Durasi: {{ run.runtime_metrics?.duration_ms ? `${(run.runtime_metrics.duration_ms / 1000).toFixed(1)}s` : '-' }}
            </div>
            <div
              v-if="run.failure_reason"
              style="font-size: 11px; margin-top: 8px; padding: 4px 8px; border-radius: 4px; line-height: 1.4;"
              :style="{
                background: run.status === 'skipped' ? 'rgba(100, 116, 139, 0.08)' : 'rgba(239, 68, 68, 0.08)',
                color: run.status === 'skipped' ? 'var(--text-muted)' : 'var(--severity-critical)',
                borderLeft: run.status === 'skipped' ? '2px solid var(--text-muted)' : '2px solid var(--severity-critical)'
              }"
            >
              <span v-if="run.status === 'skipped'">ℹ️ {{ formatEngineReason(run.failure_reason, run.status) }}</span>
              <span v-else>⚠️ {{ formatEngineReason(run.failure_reason, run.status) }}</span>
            </div>
          </div>

          <div v-if="!selectedRequest.scan_runs?.length" class="status-panel" style="grid-column: 1 / -1;">
            Menunggu queue worker memulai engine scanner terisolasi...
          </div>
        </div>
      </div>

      <!-- Tab 2: Findings Studio -->
      <div v-if="activeTab === 'findings'">
        <!-- Risk Summary Chips & CSV Export -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 12px;">
          <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <div class="stat-card" style="padding: 10px 16px; border-top: 2px solid var(--severity-critical); min-width: 100px;">
              <span style="font-size: 10px;">Kritis</span>
              <strong style="font-size: 18px; color: var(--severity-critical)">{{ selectedRiskSummary.critical ?? 0 }}</strong>
            </div>
            <div class="stat-card" style="padding: 10px 16px; border-top: 2px solid var(--severity-high); min-width: 100px;">
              <span style="font-size: 10px;">Tinggi</span>
              <strong style="font-size: 18px; color: var(--severity-high)">{{ selectedRiskSummary.high ?? 0 }}</strong>
            </div>
            <div class="stat-card" style="padding: 10px 16px; border-top: 2px solid var(--severity-medium); min-width: 100px;">
              <span style="font-size: 10px;">Sedang</span>
              <strong style="font-size: 18px; color: var(--severity-medium)">{{ selectedRiskSummary.medium ?? 0 }}</strong>
            </div>
            <div class="stat-card" style="padding: 10px 16px; border-top: 2px solid var(--severity-low); min-width: 100px;">
              <span style="font-size: 10px;">Rendah</span>
              <strong style="font-size: 18px; color: var(--severity-low)">{{ selectedRiskSummary.low ?? 0 }}</strong>
            </div>
          </div>

          <button
            v-if="selectedFindings.length > 0"
            class="small-button secondary-button"
            type="button"
            @click="exportCurrentFindings"
          >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span>Ekspor CSV</span>
          </button>
        </div>

        <div v-if="!selectedFindings.length" class="status-panel success">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <span>Tidak ada temuan kerentanan pada scan ini. Repositori dalam status aman.</span>
        </div>

        <div v-else class="profile-list">
          <article v-for="finding in paginatedFindings" :key="finding.code" style="padding: 16px 20px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 16px;">
              <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                  <span class="badge" :class="finding.severity">{{ finding.severity }}</span>
                  <strong style="font-size: 14.5px; color: var(--text-main);">{{ finding.title }}</strong>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 4px; font-family: var(--font-mono);">
                  Lokasi: {{ finding.location?.file_path ?? finding.location?.endpoint ?? '-' }}
                  <span v-if="finding.location?.line_start">:{{ finding.location?.line_start }}</span>
                </div>
              </div>
              <div style="display: flex; gap: 8px; align-items: center;">
                <span class="badge">{{ finding.rule_id ?? 'Vulnerability' }}</span>
              </div>
            </div>

            <div v-if="finding.evidence" style="margin-top: 8px; font-size: 12.5px; color: var(--text-muted); background: var(--bg-surface-elevated); padding: 8px 12px; border-radius: 6px; font-family: var(--font-mono); border: 1px solid var(--border-subtle);">
              {{ finding.evidence }}
            </div>
          </article>

          <!-- Findings Pagination -->
          <TamengPagination
            v-model:currentPage="findingsPage"
            v-model:pageSize="findingsPageSize"
            :total-items="filteredFindings.length"
            :page-size-options="[5, 10, 20, 50]"
          />
        </div>
      </div>

      <!-- Tab 3: Raw JSON Report -->
      <div v-if="activeTab === 'raw_json'">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
          <span style="font-size: 12.5px; color: var(--text-muted);">Format Laporan Standar JSON</span>
          <div class="table-actions">
            <button class="small-button secondary-button" type="button" :disabled="!selectedReport" @click="copyJson">
              {{ copiedJson ? 'Tersalin ke Clipboard!' : 'Salin JSON' }}
            </button>
            <button
              class="small-button secondary-button"
              type="button"
              :disabled="!selectedReport || isDownloadingPdf"
              @click="downloadPdfReport"
            >
              {{ isDownloadingPdf ? 'Mengunduh...' : 'Unduh Laporan PDF' }}
            </button>
          </div>
        </div>
        <pre class="json-preview">{{ reportJson || 'Belum ada laporan terbit untuk scan ini.' }}</pre>
      </div>
    </section>
  </AppShell>
</template>
