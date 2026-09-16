<script setup lang="ts">
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { apiFetch } from '../services/api'

// Tab Navigation State
const activeTab = ref<'new_scan' | 'my_scans'>('new_scan')

// Data Types
interface ScanRun {
  id: number
  engine_key: string
  status: 'queued' | 'running' | 'completed' | 'failed' | 'denied' | 'skipped'
  exit_code?: number | null
  started_at?: string | null
  finished_at?: string | null
  failure_reason?: string | null
  runtime_metrics?: Record<string, any> | null
}

interface Finding {
  id?: number
  code?: string
  title: string
  severity: 'critical' | 'high' | 'medium' | 'low' | 'info'
  rule_id?: string
  engine_key?: string
  cwe?: string
  owasp?: string
  cvss?: number
  location?: {
    file_path?: string
    endpoint?: string
    line_start?: number
    line_end?: number
  }
  description?: string
  remediation?: string
}

interface ScanReport {
  id: number
  status: string
  format: string
  metadata?: {
    content?: {
      findings?: Finding[]
      risk_summary?: {
        critical?: number
        high?: number
        medium?: number
        low?: number
        info?: number
        total?: number
      }
    }
  }
}

interface ScanRequest {
  id: number
  code: string
  status: 'queued' | 'running' | 'completed' | 'failed' | 'denied'
  progress: number
  created_at: string
  started_at?: string | null
  finished_at?: string | null
  failure_reason?: string | null
  project?: {
    id: number
    name: string
    code: string
  }
  repository?: {
    id: number
    name: string
    url: string
    default_branch?: string
    metadata?: Record<string, any>
  }
  target?: {
    id: number
    name: string
    type: string
    base_url?: string
    hostname?: string
  }
  scan_profile?: {
    id: number
    key: string
    name: string
  }
  engine_plan?: Array<{ engine_key: string }>
  scan_runs?: ScanRun[]
  reports?: ScanReport[]
}

// State
const scanRequests = ref<ScanRequest[]>([])
const isLoadingRequests = ref(false)
const isSubmitting = ref(false)
const isRerunningId = ref<number | null>(null)
const selectedRequestForDetail = ref<ScanRequest | null>(null)
const activeAiFindingKey = ref<string | null>(null)
interface AiRemediationResult {
  summary?: string
  category?: string
  attack_vector?: string
  business_impact?: string
  code_diff?: string
  mitigation_checklist?: string[]
  raw_text?: string
}
const aiRemediationData = ref<AiRemediationResult | null>(null)
const isLoadingAiRemediation = ref(false)
const rerunConfirmRequest = ref<ScanRequest | null>(null)
const expandedJobId = ref<number | null>(null)

// Ticker & Refresh
const nowTimestamp = ref<number>(Date.now())
let secondTickerTimer: any = null
let autoRefreshTimer: any = null
const autoRefreshEnabled = ref(true)
const lastSyncedAt = ref<string>('')

// Filter & Pagination for Tab 2 (My Scans)
const filterStatus = ref<'all' | 'running' | 'queued' | 'completed' | 'failed'>('all')
const searchQuery = ref('')
const currentPage = ref(1)
const pageSize = ref(10)

// Findings Modal Filter
const findingsFilter = ref<'all' | 'critical' | 'high' | 'medium' | 'low'>('all')

// Toast Alert
const alertMessage = ref<{ type: 'success' | 'danger' | 'info'; text: string } | null>(null)

function showAlert(type: 'success' | 'danger' | 'info', text: string) {
  alertMessage.value = { type, text }
  setTimeout(() => {
    if (alertMessage.value?.text === text) {
      alertMessage.value = null
    }
  }, 5000)
}

// -------------------------------------------------------------
// Form State (Tab 1: Buat Pemindaian Baru)
// -------------------------------------------------------------
type ScanType = 'repository' | 'web' | 'api' | 'container' | 'mobile'

const assetTypes: {
  key: ScanType
  title: string
  badge: string
  badgeClass: string
  icon: string
  desc: string
  placeholder: string
  engines: string[]
}[] = [
  {
    key: 'repository',
    title: 'Source Code Repositori',
    badge: 'SAST & Secrets',
    badgeClass: 'bg-azure-lt',
    icon: 'git-branch',
    desc: 'Audit celah logika kode, secret/kunci API bocor, dan paket pustaka dependensi rentan.',
    placeholder: 'https://github.com/organisasi/nama-repositori',
    engines: ['semgrep', 'gitleaks', 'trivy', 'grype', 'syft']
  },
  {
    key: 'web',
    title: 'Website / Web Application',
    badge: 'DAST Web',
    badgeClass: 'bg-blue-lt',
    icon: 'world',
    desc: 'Pemindaian dinamis aktif celah OWASP Top 10, XSS, CSRF, dan konfigurasi sertifikat TLS/SSL.',
    placeholder: 'https://app.instansi.go.id',
    engines: ['zap', 'nuclei', 'testssl']
  },
  {
    key: 'api',
    title: 'REST API Endpoint',
    badge: 'DAST API',
    badgeClass: 'bg-cyan-lt',
    icon: 'api',
    desc: 'Pengujian endpoint REST API, miskonfigurasi CORS, kebocoran header, dan enkripsi payload.',
    placeholder: 'https://api.instansi.go.id/v1',
    engines: ['nuclei', 'zap', 'testssl']
  },
  {
    key: 'container',
    title: 'Container Image',
    badge: 'Docker & OCI',
    badgeClass: 'bg-purple-lt',
    icon: 'box',
    desc: 'Inspeksi CVE paket OS container, konfigurasi Dockerfile, dan katalog SBOM aplikasi.',
    placeholder: 'docker.io/library/nginx:alpine atau ghcr.io/org/app:latest',
    engines: ['trivy', 'grype', 'hadolint']
  },
  {
    key: 'mobile',
    title: 'Aplikasi Mobile',
    badge: 'APK & IPA Binary',
    badgeClass: 'bg-pink-lt',
    icon: 'device-mobile',
    desc: 'Analisis statis binary Android (.apk, .aab) atau iOS (.ipa, .zip) menggunakan MobSF.',
    placeholder: 'Unggah file binary aplikasi mobile (.apk, .ipa, .aab)',
    engines: ['mobsf', 'semgrep', 'gitleaks', 'trivy']
  }
]

const form = reactive({
  scan_type: 'repository' as ScanType,
  project_name: '',
  asset_url: '',
  default_branch: 'main',
  is_private: false,
  access_token: '',
  auth_type: 'none' as 'none' | 'header' | 'cookie' | 'basic' | 'form_login',
  login_url_path: '/login',
  auth_header_name: 'Authorization',
  auth_header_value: '',
  auth_username: '',
  auth_password: '',
  notes: ''
})

// Mobile File Upload
const mobileFile = ref<File | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const showAuthOptions = ref(false)

const currentAssetConfig = computed(() => {
  return assetTypes.find(a => a.key === form.scan_type) || assetTypes[0]
})

function selectAssetType(type: ScanType) {
  form.scan_type = type
  if (type !== 'mobile') {
    removeMobileFile()
  }
}

function handleFileDrop(e: DragEvent) {
  isDragging.value = false
  if (e.dataTransfer?.files && e.dataTransfer.files.length > 0) {
    processMobileFile(e.dataTransfer.files[0])
  }
}

function handleFileInput(e: Event) {
  const target = e.target as HTMLInputElement
  if (target.files && target.files.length > 0) {
    processMobileFile(target.files[0])
  }
}

function processMobileFile(file: File) {
  const ext = file.name.split('.').pop()?.toLowerCase() || ''
  if (!['apk', 'ipa', 'aab', 'zip'].includes(ext)) {
    showAlert('danger', 'Format file tidak didukung. Mohon unggah file aplikasi mobile (.apk, .ipa, .aab, atau .zip).')
    return
  }
  if (file.size > 200 * 1024 * 1024) {
    showAlert('danger', 'Ukuran file melebihi batas maksimum 200MB.')
    return
  }
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

function autoFillProjectName() {
  if (form.project_name) return
  if (form.scan_type === 'repository' && form.asset_url) {
    const parts = form.asset_url.replace(/\/+$/, '').split('/')
    if (parts.length >= 2) {
      form.project_name = parts[parts.length - 1].replace(/\.git$/, '')
    }
  } else if (form.scan_type === 'web' && form.asset_url) {
    try {
      const u = new URL(form.asset_url)
      form.project_name = u.hostname
    } catch {}
  } else if (form.scan_type === 'container' && form.asset_url) {
    form.project_name = form.asset_url.split(':')[0]
  }
}

// Reset Form
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
  removeMobileFile()
  showAuthOptions.value = false
}

// Submit Scan Request
async function submitScanRequest() {
  if (!form.project_name.trim()) {
    showAlert('danger', 'Nama proyek wajib diisi.')
    return
  }

  if (form.scan_type === 'mobile') {
    if (!mobileFile.value && !form.asset_url.trim()) {
      showAlert('danger', 'Silakan unggah file binary aplikasi mobile (.apk, .ipa) atau masukkan URL aset.')
      return
    }
  } else {
    if (!form.asset_url.trim()) {
      showAlert('danger', `URL/identitas target untuk ${currentAssetConfig.value.title} wajib diisi.`)
      return
    }
  }

  isSubmitting.value = true
  try {
    let bodyPayload: any
    if (mobileFile.value) {
      const formData = new FormData()
      formData.append('scan_type', form.scan_type)
      formData.append('project_name', form.project_name.trim())
      formData.append('file', mobileFile.value)
      if (form.notes) formData.append('notes', form.notes)
      bodyPayload = formData
    } else {
      bodyPayload = {
        scan_type: form.scan_type,
        project_name: form.project_name.trim(),
        asset_url: form.asset_url.trim(),
        default_branch: form.default_branch || 'main',
        is_private: form.is_private,
        access_token: form.access_token || undefined,
        auth_type: form.auth_type,
        login_url_path: form.auth_type === 'form_login' ? form.login_url_path : undefined,
        auth_header_name: form.auth_type === 'header' ? form.auth_header_name : undefined,
        auth_header_value: form.auth_type === 'header' ? form.auth_header_value : undefined,
        auth_username: form.auth_type === 'basic' || form.auth_type === 'form_login' ? form.auth_username : undefined,
        auth_password: form.auth_type === 'basic' || form.auth_type === 'form_login' ? form.auth_password : undefined,
        notes: form.notes || undefined
      }
    }

    const res = await apiFetch('/api/my/scan-requests', {
      method: 'POST',
      body: mobileFile.value ? bodyPayload : JSON.stringify(bodyPayload)
    })

    const newScan = res?.scan_request || res?.scan_job
    showAlert('success', `Pemindaian mandiri #${newScan?.code || 'Baru'} berhasil didaftarkan ke antrean sandbox.`)
    resetForm()
    activeTab.value = 'my_scans'
    await loadMyScanRequests(false)
  } catch (err: any) {
    showAlert('danger', err?.message || 'Gagal mendaftarkan pemindaian mandiri.')
  } finally {
    isSubmitting.value = false
  }
}

// -------------------------------------------------------------
// Load Requests & Ticker (Tab 2)
// -------------------------------------------------------------
async function loadMyScanRequests(showLoading = false) {
  if (showLoading) isLoadingRequests.value = true
  try {
    const res = await apiFetch('/api/my/scan-requests')
    if (res && Array.isArray(res.scan_requests)) {
      scanRequests.value = res.scan_requests
    }
    const d = new Date()
    lastSyncedAt.value = d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' })
  } catch (err: any) {
    console.warn('[MyScanRequests] Gagal memuat riwayat:', err)
  } finally {
    if (showLoading) isLoadingRequests.value = false
  }
}

// KPIs
const kpiCounts = computed(() => {
  const total = scanRequests.value.length
  const running = scanRequests.value.filter(j => j.status === 'running').length
  const queued = scanRequests.value.filter(j => j.status === 'queued').length
  const completed = scanRequests.value.filter(j => j.status === 'completed').length
  const failed = scanRequests.value.filter(j => ['failed', 'denied'].includes(j.status)).length
  return { total, running, queued, completed, failed }
})

// Filtered & Paginated Requests (Murni Identik dengan Pekerjaan Scan)
const filteredRequests = computed(() => {
  const q = searchQuery.value.trim().toLowerCase()
  return scanRequests.value.filter(job => {
    // Status Filter
    if (filterStatus.value === 'running' && job.status !== 'running') return false
    if (filterStatus.value === 'queued' && job.status !== 'queued') return false
    if (filterStatus.value === 'completed' && job.status !== 'completed') return false
    if (filterStatus.value === 'failed' && !['failed', 'denied'].includes(job.status)) return false

    // Search Query: MURNI HANYA NAMA PROYEK (Sama persis dengan Pekerjaan Scan)
    if (!q) return true
    const projName = (job.project?.name || '').toLowerCase()
    return projName.includes(q)
  })
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredRequests.value.length / pageSize.value)))

const paginatedRequests = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredRequests.value.slice(start, start + pageSize.value)
})

watch([filterStatus, searchQuery], () => {
  currentPage.value = 1
})

// Duration Formatter
function calculateDuration(job: ScanRequest): string {
  if (job.started_at && job.finished_at) {
    const diffMs = new Date(job.finished_at).getTime() - new Date(job.started_at).getTime()
    if (diffMs > 0) {
      const totalSec = Math.round(diffMs / 1000)
      const mins = Math.floor(totalSec / 60)
      const secs = totalSec % 60
      return mins > 0 ? `${mins}m ${secs}s` : `${secs}s`
    }
  }

  if (job.status === 'running') {
    if (job.started_at) {
      const diffSec = Math.max(0, Math.round((nowTimestamp.value - new Date(job.started_at).getTime()) / 1000))
      const m = Math.floor(diffSec / 60)
      const s = diffSec % 60
      return `Berjalan: ${m > 0 ? `${m}m ${s}s` : `${s}s`}`
    }
    return 'Sedang berjalan...'
  }

  if (job.status === 'queued') {
    return 'Menunggu antrean...'
  }

  return '-'
}

function formatTime(isoStr?: string | null): string {
  if (!isoStr) return '-'
  const d = new Date(isoStr)
  return d.toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' }) +
    ' (' + d.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) + ')'
}

function isJobPartialSuccess(job: ScanRequest): boolean {
  if (job.status !== 'completed') return false
  if (job.failure_reason) return true
  if (job.scan_runs && job.scan_runs.some(r => ['failed', 'denied'].includes(r.status))) {
    return true
  }
  return false
}

function getJobStatusBadgeClass(job: ScanRequest): string {
  if (isJobPartialSuccess(job)) {
    return 'bg-warning text-warning-fg'
  }
  switch (job.status) {
    case 'completed': return 'bg-success text-success-fg'
    case 'running': return 'bg-primary text-primary-fg'
    case 'queued': return 'bg-azure text-azure-fg'
    case 'failed':
    case 'denied': return 'bg-danger text-danger-fg'
    default: return 'bg-secondary text-secondary-fg'
  }
}

function getJobStatusLabel(job: ScanRequest): string {
  if (isJobPartialSuccess(job)) return 'Selesai Parsial'
  switch (job.status) {
    case 'completed': return 'Selesai'
    case 'running': return 'Berjalan'
    case 'queued': return 'Antrean'
    case 'failed': return 'Gagal'
    case 'denied': return 'Ditolak'
    default: return job.status
  }
}

function assetBadgeInfo(job: ScanRequest) {
  if (job.repository) {
    const isMobile = job.repository.metadata?.scan_type === 'mobile' || job.repository.metadata?.is_direct_file
    return {
      type: isMobile ? 'Mobile App' : 'Repository',
      label: job.repository.name,
      badgeClass: isMobile ? 'bg-purple-lt' : 'bg-azure-lt',
      icon: isMobile ? 'device-mobile' : 'git-branch'
    }
  }

  if (job.target) {
    const isContainer = job.target.type === 'container'
    const isApi = job.target.type === 'api'
    let badgeClass = 'bg-indigo-lt'
    let typeName = 'Web Target'
    let iconName = 'world'

    if (isContainer) {
      badgeClass = 'bg-teal-lt'
      typeName = 'Container Image'
      iconName = 'box'
    } else if (isApi) {
      badgeClass = 'bg-cyan-lt'
      typeName = 'REST API'
      iconName = 'api'
    }

    return {
      type: typeName,
      label: job.target.name || job.target.base_url || job.target.hostname || 'Target',
      badgeClass,
      icon: iconName
    }
  }

  return {
    type: 'Asset',
    label: '-',
    badgeClass: 'bg-secondary-lt',
    icon: 'cube'
  }
}

function getEngineRunStatusClass(status: string): string {
  switch (status) {
    case 'completed': return 'bg-success-lt text-success'
    case 'running': return 'bg-primary-lt text-primary'
    case 'failed': return 'bg-danger-lt text-danger'
    case 'denied': return 'bg-danger-lt text-danger'
    case 'skipped': return 'bg-secondary-lt text-secondary'
    default: return 'bg-secondary-lt text-secondary'
  }
}

function getEngineBadgeClass(job: ScanRequest, engineKey: string): string {
  if (job.scan_runs && job.scan_runs.length > 0) {
    const run = job.scan_runs.find(r => r.engine_key === engineKey)
    if (run) {
      return getEngineRunStatusClass(run.status)
    }
  }
  if (job.status === 'completed') {
    return 'bg-success-lt text-success'
  }
  if (job.status === 'running') {
    return 'bg-primary-lt text-primary'
  }
  if (['failed', 'denied'].includes(job.status)) {
    return 'bg-danger-lt text-danger'
  }
  return 'bg-secondary-lt text-secondary'
}

function getJobEngines(job: ScanRequest): Array<{ engine_key: string, statusClass: string }> {
  const engineKeys: string[] = []
  if (job.engine_plan && Array.isArray(job.engine_plan) && job.engine_plan.length > 0) {
    job.engine_plan.forEach(e => {
      const key = (e as any).engine_key || e
      if (typeof key === 'string' && !engineKeys.includes(key)) engineKeys.push(key)
    })
  }
  if (job.scan_runs && Array.isArray(job.scan_runs) && job.scan_runs.length > 0) {
    job.scan_runs.forEach(r => {
      if (r.engine_key && !engineKeys.includes(r.engine_key)) engineKeys.push(r.engine_key)
    })
  }
  return engineKeys.map(key => ({
    engine_key: key,
    statusClass: getEngineBadgeClass(job, key)
  }))
}

function toggleExpandRow(jobId: number) {
  if (expandedJobId.value === jobId) {
    expandedJobId.value = null
  } else {
    expandedJobId.value = jobId
  }
}

function handleRowClick(event: MouseEvent, jobId: number) {
  const target = event.target as HTMLElement
  if (target.closest('button') || target.closest('a') || target.closest('.btn')) {
    return
  }
  const selection = window.getSelection()
  if (selection && selection.toString().trim().length > 0) {
    return
  }
  toggleExpandRow(jobId)
}


// -------------------------------------------------------------
// Rerun Confirmation
// -------------------------------------------------------------
function promptRerun(req: ScanRequest) {
  rerunConfirmRequest.value = req
}

function closeRerunModal() {
  if (isRerunningId.value !== null) return
  rerunConfirmRequest.value = null
}

async function confirmAndExecuteRerun() {
  const req = rerunConfirmRequest.value
  if (!req) return

  isRerunningId.value = req.id
  try {
    const res = await apiFetch(`/api/my/scan-requests/${req.id}/rerun`, {
      method: 'POST'
    })
    const newJob = res?.scan_request || res?.scan_job
    showAlert('success', `Pekerjaan #${req.code} berhasil di-rerun menjadi #${newJob?.code || 'baru'}.`)
    closeRerunModal()
    filterStatus.value = 'all'
    await loadMyScanRequests(false)
  } catch (err: any) {
    showAlert('danger', err?.message || 'Gagal menjalankan ulang pemindaian.')
  } finally {
    isRerunningId.value = null
  }
}

// -------------------------------------------------------------
// Detail & Findings Modal
// -------------------------------------------------------------
function openDetailModal(req: ScanRequest) {
  selectedRequestForDetail.value = req
  findingsFilter.value = 'all'
  activeAiFindingKey.value = null
  aiRemediationData.value = null
}

function closeDetailModal() {
  selectedRequestForDetail.value = null
  activeAiFindingKey.value = null
  aiRemediationData.value = null
}

const currentReport = computed(() => {
  return selectedRequestForDetail.value?.reports?.[0] || null
})

const allFindings = computed<Finding[]>(() => {
  return currentReport.value?.metadata?.content?.findings || []
})

const riskSummary = computed(() => {
  return currentReport.value?.metadata?.content?.risk_summary || {
    critical: 0,
    high: 0,
    medium: 0,
    low: 0,
    info: 0,
    total: 0
  }
})

const filteredFindings = computed(() => {
  if (findingsFilter.value === 'all') return allFindings.value
  return allFindings.value.filter(f => f.severity === findingsFilter.value)
})

function getFindingKey(finding: Finding, index: number): string {
  return finding.code || (finding.id ? `fnd-id-${finding.id}` : '') || `${finding.rule_id || 'fnd'}-${finding.location?.file_path || ''}-${finding.location?.line_start || index}`
}

function getFindingSeverityBadgeClass(severity?: string): string {
  switch (severity?.toLowerCase()) {
    case 'critical': return 'bg-danger text-danger-fg'
    case 'high': return 'bg-orange text-orange-fg'
    case 'medium': return 'bg-warning text-warning-fg'
    case 'low': return 'bg-azure text-azure-fg'
    case 'info': return 'bg-secondary text-secondary-fg'
    default: return 'bg-secondary text-secondary-fg'
  }
}

function getFindingBorderClass(severity?: string): string {
  switch (severity?.toLowerCase()) {
    case 'critical': return 'border-danger'
    case 'high': return 'border-warning'
    case 'medium': return 'border-warning'
    case 'low': return 'border-azure'
    default: return 'border-secondary'
  }
}

function generateSmartAiRemediation(finding: Finding): AiRemediationResult {
  const rule = (finding.rule_id || '').toLowerCase()
  const title = (finding.title || '').toLowerCase()

  if (rule.includes('integrity') || title.includes('integrity')) {
    return {
      category: 'Subresource Integrity (SRI)',
      summary: 'Tag skrip atau stylesheet memuat pustaka dari server CDN pihak ketiga tanpa atribut "integrity". Tanpa atribut ini, browser tidak dapat memverifikasi integritas file jika server CDN disusupi atau data diubah saat transmisi, berpotensi membuka celah Cross-Site Scripting (XSS).',
      attack_vector: 'Penyusupan skrip berbahaya via CDN yang terkompromi atau serangan Man-in-the-Middle (MitM).',
      business_impact: 'Eksekusi JavaScript tanpa izin pada browser pengunjung, pencurian cookie/token sesi, dan defacement.',
      mitigation_checklist: [
        'Hitung cryptographic digest hash (sha384 atau sha512) dari berkas yang diunduh dari CDN.',
        'Tambahkan atribut integrity="sha384-..." dan crossorigin="anonymous" pada tag <script> atau <link>.',
        'Alternatif terbaik: Unduh pustaka ke repositori lokal (self-hosted) untuk menghilangkan ketergantungan eksternal.'
      ],
      code_diff: '<!-- SEBELUM: -->\n<script src="https://cdn.example.com/library.js"><' + '/script>\n\n<!-- SESUDAH (Aman dengan SRI): -->\n<script src="https://cdn.example.com/library.js"\n        integrity="sha384-oqVuAfXRKap7fdgcCY5uykM6+R9GqQ8K/uxy9rx7HNQlGYl1kPzQho1wx4JwY8wC"\n        crossorigin="anonymous"><' + '/script>'
    }
  }

  if (rule.includes('xss') || title.includes('xss') || title.includes('cross-site')) {
    return {
      category: 'Cross-Site Scripting (XSS)',
      summary: 'Ditemukan input pengguna atau variabel dinamis yang dimasukkan ke dalam DOM/HTML tanpa sanitasi atau contextual encoding.',
      attack_vector: 'Penyisipan payload JavaScript berbahaya via parameter URL, formulir input, atau payload API.',
      business_impact: 'Pembajakan sesi pengguna (session hijacking), pencurian token autentikasi, pengalihan ke situs phishing.',
      mitigation_checklist: [
        'Gunakan context-aware output encoding (HTML, JavaScript, CSS, URL encoding).',
        'Terapkan Content Security Policy (CSP) ketat pada HTTP header server.',
        'Gunakan pustaka sanitasi HTML terpercaya seperti DOMPurify sebelum merender HTML dinamis.'
      ],
      code_diff: `// Gunakan sanitasi sebelum memasukkan konten ke DOM:\nimport DOMPurify from 'dompurify';\nelement.innerHTML = DOMPurify.sanitize(userInput);\n\n// Atau gunakan textContent secara langsung (aman dari injeksi):\nelement.textContent = userInput;`
    }
  }

  if (rule.includes('secret') || rule.includes('token') || rule.includes('key') || rule.includes('password') || rule.includes('credential')) {
    return {
      category: 'Hardcoded Secrets & Credentials',
      summary: 'Kunci rahasia (API key, private token, atau kredensial) terdeteksi tersimpan secara eksplisit dalam kode sumber repositori.',
      attack_vector: 'Ekstraksi kunci rahasia dari repositori publik atau commit history git oleh pihak tak berwenang.',
      business_impact: 'Akses tanpa izin ke infrastruktur backend, basis data produksi, atau layanan cloud pihak ketiga.',
      mitigation_checklist: [
        'Segera cabut (revoke) dan rotasikan kunci API atau kredensial yang bocor.',
        'Pindahkan kredensial ke berkas environment (.env) atau secret manager.',
        'Pastikan file konfigurasi lokal terdaftar dalam berkas .gitignore.'
      ],
      code_diff: `// SEBELUM (Hardcoded):\nconst API_KEY = "sk_live_9384729487293847";\n\n// SESUDAH (Environment Variable):\nconst API_KEY = process.env.API_SECRET_KEY;`
    }
  }

  return {
    category: finding.rule_id || 'Security Finding Guidance',
    summary: finding.title || 'Kerentanan terdeteksi pada baris kode terkait.',
    attack_vector: 'Manipulasi parameter input atau eksploitasi konfigurasi komponen target.',
    business_impact: 'Potensi gangguan integritas data, ketersediaan layanan, atau akses tidak sah.',
    mitigation_checklist: [
      'Terapkan validasi input ketat dan sanitasi pada parameter terkait.',
      'Perbarui dependensi dan library terkait ke versi stabil terbaru.',
      'Terapkan prinsip least privilege pada kontrol akses sistem.'
    ],
    code_diff: finding.location?.file_path
      ? `// Tinjau berkas: ${finding.location.file_path}${finding.location.line_start ? ` (Baris ${finding.location.line_start})` : ''}\n// Terapkan penanganan kesalahan yang aman dan validasi parameter masukan.`
      : undefined
  }
}

async function toggleAiRemediation(finding: Finding, index: number) {
  const key = getFindingKey(finding, index)
  if (activeAiFindingKey.value === key) {
    activeAiFindingKey.value = null
    aiRemediationData.value = null
    return
  }

  activeAiFindingKey.value = key
  aiRemediationData.value = null
  isLoadingAiRemediation.value = true

  try {
    const findingIdOrCode = finding.id || finding.code
    let fetchedData: any = null

    if (findingIdOrCode) {
      try {
        const res = await apiFetch(`/api/findings/${findingIdOrCode}/ai-remediation`)
        if (res?.remediation) {
          fetchedData = res.remediation
        }
      } catch (e) {
        console.warn('Backend AI remediation endpoint unavailable, using smart generator:', e)
      }
    }

    if (fetchedData && typeof fetchedData === 'object') {
      aiRemediationData.value = {
        summary: fetchedData.summary,
        category: fetchedData.category,
        attack_vector: fetchedData.attack_vector,
        business_impact: fetchedData.business_impact,
        code_diff: fetchedData.code_diff,
        mitigation_checklist: Array.isArray(fetchedData.mitigation_checklist) ? fetchedData.mitigation_checklist : [],
        raw_text: typeof fetchedData === 'string' ? fetchedData : undefined
      }
    } else if (typeof fetchedData === 'string') {
      aiRemediationData.value = {
        summary: fetchedData,
        raw_text: fetchedData
      }
    } else {
      aiRemediationData.value = generateSmartAiRemediation(finding)
    }
  } catch {
    aiRemediationData.value = generateSmartAiRemediation(finding)
  } finally {
    isLoadingAiRemediation.value = false
  }
}

function exportFindingsCsv() {
  const findings = allFindings.value
  if (!findings || findings.length === 0) {
    showAlert('info', 'Tidak ada temuan celah keamanan untuk diekspor.')
    return
  }

  const headers = ['Kode Temuan', 'Tingkat Keparahan', 'Judul Kerentanan', 'Mesin Pemindai', 'Lokasi File / Endpoint', 'CWE', 'OWASP']
  const rows = findings.map(f => [
    `"${f.code || '-'}"`,
    `"${(f.severity || 'low').toUpperCase()}"`,
    `"${(f.title || '').replace(/"/g, '""')}"`,
    `"${f.engine_key || '-'}"`,
    `"${(f.location?.file_path || f.location?.endpoint || '-').replace(/"/g, '""')}"`,
    `"${f.cwe || '-'}"`,
    `"${f.owasp || '-'}"`
  ])

  const csvContent = [headers.join(','), ...rows.map(r => r.join(','))].join('\r\n')
  const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.setAttribute('href', url)
  link.setAttribute('download', `tameng-scan-${selectedRequestForDetail.value?.code || 'report'}.csv`)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(url)
  showAlert('success', 'Laporan temuan berhasil diunduh dalam format CSV.')
}

// Download PDF Report
async function downloadReportPdfFile() {
  const reportId = currentReport.value?.id
  if (!reportId) {
    showAlert('info', 'Laporan PDF belum tersedia untuk pekerjaan pemindaian ini.')
    return
  }
  try {
    const res = await fetch(`/api/reports/${reportId}/download-pdf`, {
      headers: { Accept: 'application/pdf' },
      credentials: 'include'
    })
    if (!res.ok) throw new Error('Gagal mengunduh file PDF laporan.')
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `tameng-laporan-keamanan-${selectedRequestForDetail.value?.code || reportId}.pdf`
    document.body.appendChild(link)
    link.click()
    document.body.removeChild(link)
    URL.revokeObjectURL(url)
    showAlert('success', 'Laporan PDF berhasil diunduh.')
  } catch (err: any) {
    showAlert('danger', err?.message || 'Gagal mengunduh laporan PDF.')
  }
}

// -------------------------------------------------------------
// Lifecycle Hooks
// -------------------------------------------------------------
onMounted(async () => {
  await loadMyScanRequests(true)

  // 1-second ticker for running durations
  secondTickerTimer = setInterval(() => {
    nowTimestamp.value = Date.now()
  }, 1000)

  // Auto-refresh every 6 seconds
  autoRefreshTimer = setInterval(() => {
    if (autoRefreshEnabled.value && activeTab.value === 'my_scans') {
      loadMyScanRequests(false)
    }
  }, 6000)
})

onUnmounted(() => {
  if (secondTickerTimer) clearInterval(secondTickerTimer)
  if (autoRefreshTimer) clearInterval(autoRefreshTimer)
})
</script>

<template>
  <div class="page-body mt-0">
    <div class="container-fluid">
      <!-- Floating Toast Notification -->
      <transition name="toast-slide">
        <div
          v-if="alertMessage"
          class="toast-container position-fixed end-0 p-3"
          style="top: 72px; z-index: 1070; max-width: 480px;"
        >
          <div
            class="alert alert-dismissible shadow-lg border-0 d-flex align-items-start gap-2 mb-0 py-3"
            :class="`alert-${alertMessage.type}`"
            role="alert"
            style="backdrop-filter: blur(8px); box-shadow: 0 10px 25px rgba(0, 0, 0, 0.4) !important;"
          >
            <div class="alert-icon pt-0">
              <svg
                v-if="alertMessage.type === 'success'"
                xmlns="http://www.w3.org/2000/svg"
                class="icon text-success"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M5 12l5 5l10 -10" />
              </svg>
              <svg
                v-else-if="alertMessage.type === 'danger'"
                xmlns="http://www.w3.org/2000/svg"
                class="icon text-danger"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M12 9v4" />
                <path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0" />
                <path d="M12 16h.01" />
              </svg>
              <svg
                v-else
                xmlns="http://www.w3.org/2000/svg"
                class="icon text-info"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                stroke-width="2"
                stroke="currentColor"
                fill="none"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M12 9h.01" />
                <path d="M11 12h1v4h1" />
                <path d="M12 3c7.2 0 9 1.8 9 9s-1.8 9 -9 9s-9 -1.8 -9 -9s1.8 -9 9 -9z" />
              </svg>
            </div>
            <div class="flex-fill">
              <div class="text-secondary small">{{ alertMessage.text }}</div>
            </div>
            <button
              type="button"
              class="btn-close ms-auto"
              aria-label="Close"
              @click="alertMessage = null"
            ></button>
          </div>
        </div>
      </transition>

      <!-- Page Header -->
      <div class="page-header d-print-none mb-3">
        <div class="row g-2 align-items-center">
          <div class="col">
            <div class="page-pretitle text-secondary text-uppercase fw-bold fs-6">
              Layanan Keamanan Mandiri &middot; Pengembang &amp; Tim QA
            </div>
            <h2 class="page-title d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                <path d="M4 7v-1a2 2 0 0 1 2 -2h2" />
                <path d="M4 17v1a2 2 0 0 0 2 2h2" />
                <path d="M16 4h2a2 2 0 0 1 2 2v1" />
                <path d="M16 20h2a2 2 0 0 0 2 -2v-1" />
                <path d="M5 12l14 0" />
              </svg>
              <span>Portal Scan Mandiri</span>
            </h2>
          </div>

          
        </div>
      </div>

      <!-- Tabler Interactive Navigation Tabs (Pills) -->
      <div class="card mb-3 border-0 shadow-sm">
        <div class="card-body p-2">
          <ul class="nav nav-pills nav-fill gap-2" role="tablist">
            <li class="nav-item" role="presentation">
              <button
                type="button"
                class="nav-link py-2 px-3 fw-medium d-flex align-items-center justify-content-center gap-2"
                :class="{ active: activeTab === 'new_scan' }"
                @click="activeTab = 'new_scan'"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                  <path d="M12 5l0 14" />
                  <path d="M5 12l14 0" />
                </svg>
                <span>Buat Pemindaian Baru</span>
              </button>
            </li>
            <li class="nav-item" role="presentation">
              <button
                type="button"
                class="nav-link py-2 px-3 fw-medium d-flex align-items-center justify-content-center gap-2"
                :class="{ active: activeTab === 'my_scans' }"
                @click="activeTab = 'my_scans'"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                  <path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" />
                  <path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" />
                  <path d="M9 14l2 2l4 -4" />
                </svg>
                <span>Riwayat Pemindaian Saya</span>
                <span class="badge ms-1" :class="activeTab === 'my_scans' ? 'bg-primary-lt' : 'bg-secondary-lt'">
                  {{ scanRequests.length }}
                </span>
              </button>
            </li>
          </ul>
        </div>
      </div>

      <!-- ========================================================= -->
      <!-- TAB 1: FORM BUAT PEMINDAIAN BARU                          -->
      <!-- ========================================================= -->
      <div v-if="activeTab === 'new_scan'" class="animate-fade-in">
        <!-- 1. Asset Type Selector Cards -->
        <div class="row row-deck row-cards mb-4">
          <div class="col-12">
            <label class="form-label fw-bold text-secondary text-uppercase fs-6 mb-2">
              Langkah 1: Pilih Kategori Aset Target
            </label>
          </div>
          <div
            v-for="asset in assetTypes"
            :key="asset.key"
            class="col-12 col-sm-6 col-lg"
          >
            <div
              class="card cursor-pointer asset-select-card h-100 transition-all border"
              :class="{
                'border-primary shadow-sm bg-primary-subtle': form.scan_type === asset.key,
                'border-dashed': form.scan_type !== asset.key
              }"
              @click="selectAssetType(asset.key)"
            >
              <div class="card-body p-3 d-flex flex-column">
                <div class="d-flex align-items-center justify-content-between mb-2">
                  <span class="avatar avatar-md rounded" :class="form.scan_type === asset.key ? 'bg-primary text-white' : 'bg-body text-secondary border'">
                    <!-- Dynamic Icons -->
                    <svg v-if="asset.icon === 'git-branch'" xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M5 18a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M5 6a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M15 6a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
                    <svg v-else-if="asset.icon === 'world'" xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /><path d="M11.5 3a17 17 0 0 0 0 18" /><path d="M12.5 3a17 17 0 0 1 0 18" /></svg>
                    <svg v-else-if="asset.icon === 'api'" xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 15h-6.5a2.5 2.5 0 1 1 0 -5h.5" /><path d="M15 12v6.5a2.5 2.5 0 1 1 -5 0v-.5" /><path d="M12 9h6.5a2.5 2.5 0 1 1 0 5h-.5" /><path d="M9 12v-6.5a2.5 2.5 0 0 1 5 0v.5" /></svg>
                    <svg v-else-if="asset.icon === 'box'" xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" /><path d="M12 12l8 -4.5" /><path d="M12 12l0 9" /><path d="M12 12l-8 -4.5" /></svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" /><path d="M11 4h2" /><path d="M12 17v.01" /></svg>
                  </span>
                  <span class="badge" :class="asset.badgeClass">
                    {{ asset.badge }}
                  </span>
                </div>
                <h4 class="card-title mb-1 fs-4 fw-bold">
                  {{ asset.title }}
                </h4>
                <div class="text-secondary small mb-3 flex-fill lh-sm">
                  {{ asset.desc }}
                </div>
                <div class="d-flex flex-wrap gap-1 mt-auto">
                  <span
                    v-for="eng in asset.engines"
                    :key="eng"
                    class="badge bg-secondary-lt font-monospace px-1 py-0 text-uppercase fs-6"
                  >
                    {{ eng }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 2. Target Configuration Form -->
        <div class="card shadow-sm border mb-4">
          <div class="card-header bg-transparent py-3">
            <h3 class="card-title d-flex align-items-center gap-2 mb-0">
              <span class="badge bg-primary text-white rounded-circle p-2">2</span>
              <span>Konfigurasi &amp; Parameter {{ currentAssetConfig.title }}</span>
            </h3>
          </div>
          <div class="card-body">
            <form @submit.prevent="submitScanRequest">
              <!-- Mobile Binary Dropzone -->
              <div v-if="form.scan_type === 'mobile'" class="mb-3">
                <label class="form-label required fw-medium">
                  Berkas Binary Aplikasi Mobile (.apk, .ipa, .aab, .zip)
                </label>
                <div
                  class="dropzone-box text-center p-4 rounded border-2 border-dashed transition-all"
                  :class="{
                    'border-primary bg-primary-subtle': isDragging,
                    'border-secondary bg-body': !isDragging && !mobileFile,
                    'border-success bg-success-subtle': mobileFile
                  }"
                  @dragover.prevent="isDragging = true"
                  @dragleave.prevent="isDragging = false"
                  @drop.prevent="handleFileDrop"
                >
                  <input
                    ref="fileInputRef"
                    type="file"
                    class="d-none"
                    accept=".apk,.ipa,.aab,.zip"
                    @change="handleFileInput"
                  />

                  <div v-if="!mobileFile">
                    <div class="avatar avatar-lg bg-primary-lt rounded-circle mx-auto mb-2">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 9l5 -5l5 5" /><path d="M12 4l0 12" /></svg>
                    </div>
                    <div class="fw-bold mb-1">Tarik &amp; letakkan berkas binary mobile di sini</div>
                    <div class="text-secondary small mb-3">Mendukung format .apk, .ipa, .aab, dan .zip (Maksimal 200MB)</div>
                    <button
                      type="button"
                      class="btn btn-primary btn-sm"
                      @click="fileInputRef?.click()"
                    >
                      Pilih Berkas dari Komputer
                    </button>
                  </div>

                  <div v-else class="d-flex align-items-center justify-content-between p-2">
                    <div class="d-flex align-items-center gap-3 text-start">
                      <div class="avatar bg-success text-white rounded">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" /><path d="M11 4h2" /><path d="M12 17v.01" /></svg>
                      </div>
                      <div>
                        <div class="fw-bold font-monospace">{{ mobileFile.name }}</div>
                        <div class="text-secondary small">
                          Ukuran: {{ (mobileFile.size / (1024 * 1024)).toFixed(2) }} MB
                        </div>
                      </div>
                    </div>
                    <button
                      type="button"
                      class="btn btn-sm btn-outline-danger"
                      @click="removeMobileFile"
                    >
                      Ganti Berkas
                    </button>
                  </div>
                </div>
              </div>

              <!-- URL / Identifier Input for other types -->
              <div v-if="form.scan_type !== 'mobile' || !mobileFile" class="mb-3">
                <label class="form-label required fw-medium">
                  URL / Identitas Target {{ currentAssetConfig.title }}
                </label>
                <div class="input-group">
                  <span class="input-group-text bg-body">
                    <svg v-if="form.scan_type === 'repository'" xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M5 18a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M5 6a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M15 6a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M7 8l0 8" /><path d="M9 18h6a2 2 0 0 0 2 -2v-5" /><path d="M14 14l3 -3l3 3" /></svg>
                    <svg v-else-if="form.scan_type === 'api'" xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 15h-6.5a2.5 2.5 0 1 1 0 -5h.5" /><path d="M15 12v6.5a2.5 2.5 0 1 1 -5 0v-.5" /><path d="M12 9h6.5a2.5 2.5 0 1 1 0 5h-.5" /><path d="M9 12v-6.5a2.5 2.5 0 0 1 5 0v.5" /></svg>
                    <svg v-else-if="form.scan_type === 'container'" xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l8 4.5l0 9l-8 4.5l-8 -4.5l0 -9l8 -4.5" /></svg>
                    <svg v-else-if="form.scan_type === 'mobile'" xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M6 5a2 2 0 0 1 2 -2h8a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-8a2 2 0 0 1 -2 -2v-14z" /><path d="M11 4h2" /><path d="M12 17v.01" /></svg>
                    <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M3.6 9h16.8" /><path d="M3.6 15h16.8" /></svg>
                  </span>
                  <input
                    v-model="form.asset_url"
                    type="text"
                    class="form-control"
                    :placeholder="currentAssetConfig.placeholder"
                    @blur="autoFillProjectName"
                    required
                  />
                </div>
                <div class="form-text text-secondary small">
                  Contoh: <code>{{ currentAssetConfig.placeholder }}</code>
                </div>
              </div>

              <!-- Project Name & Branch -->
              <div class="row g-3 mb-3">
                <div class="col-md-8">
                  <label class="form-label required fw-medium">Nama Proyek / Aplikasi</label>
                  <input
                    v-model="form.project_name"
                    type="text"
                    class="form-control"
                    placeholder="Masukkan nama proyek pemindaian..."
                    required
                  />
                </div>
                <div v-if="form.scan_type === 'repository'" class="col-md-4">
                  <label class="form-label fw-medium">Default Branch</label>
                  <input
                    v-model="form.default_branch"
                    type="text"
                    class="form-control font-monospace"
                    placeholder="main"
                  />
                </div>
              </div>

              <!-- Optional Auth Toggle (Accordion) -->
              <div class="card card-sm border bg-body mb-3">
                <div
                  class="card-header cursor-pointer py-2 d-flex justify-content-between align-items-center"
                  @click="showAuthOptions = !showAuthOptions"
                >
                  <div class="d-flex align-items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon text-secondary" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
                    <span class="fw-medium small">Opsi Autentikasi / Token Kredensial (Opsional)</span>
                  </div>
                  <span class="text-secondary small">
                    {{ showAuthOptions ? 'Sembunyikan' : 'Tampilkan' }}
                  </span>
                </div>
                <div v-if="showAuthOptions" class="card-body p-3">
                  <div class="row g-3">
                    <div class="col-md-4">
                      <label class="form-label small">Tipe Autentikasi Target</label>
                      <select v-model="form.auth_type" class="form-select form-select-sm">
                        <option value="none">Tanpa Autentikasi</option>
                        <option value="header">Bearer Token / Custom Header</option>
                        <option value="cookie">Session Cookie</option>
                        <option value="basic">HTTP Basic Auth</option>
                        <option value="form_login">Form Login Endpoint</option>
                      </select>
                    </div>

                    <!-- Header Inputs -->
                    <template v-if="form.auth_type === 'header'">
                      <div class="col-md-4">
                        <label class="form-label small">Header Name</label>
                        <input v-model="form.auth_header_name" type="text" class="form-control form-control-sm font-monospace" placeholder="Authorization" />
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small">Header Value / Token</label>
                        <input v-model="form.auth_header_value" type="text" class="form-control form-control-sm font-monospace" placeholder="Bearer eyJhbGciOi..." />
                      </div>
                    </template>

                    <!-- Cookie Inputs -->
                    <template v-if="form.auth_type === 'cookie'">
                      <div class="col-md-8">
                        <label class="form-label small">Cookie String</label>
                        <input v-model="form.auth_header_value" type="text" class="form-control form-control-sm font-monospace" placeholder="session_id=abc123xyz; remember_token=..." />
                      </div>
                    </template>

                    <!-- Basic / Form Inputs -->
                    <template v-if="form.auth_type === 'basic' || form.auth_type === 'form_login'">
                      <div class="col-md-4">
                        <label class="form-label small">Username / Email</label>
                        <input v-model="form.auth_username" type="text" class="form-control form-control-sm" placeholder="user@domain.com" />
                      </div>
                      <div class="col-md-4">
                        <label class="form-label small">Password</label>
                        <input v-model="form.auth_password" type="password" class="form-control form-control-sm" placeholder="••••••••" />
                      </div>
                    </template>
                  </div>
                </div>
              </div>

              <!-- Notes -->
              <div class="mb-4">
                <label class="form-label fw-medium small text-secondary">Catatan / Deskripsi Pemindaian (Opsional)</label>
                <textarea
                  v-model="form.notes"
                  class="form-control"
                  rows="2"
                  placeholder="Tambahkan catatan konteks pengujian, nomor tiket pengajuan, atau lingkup pengujian..."
                ></textarea>
              </div>

              <!-- Submit Buttons -->
              <div class="d-flex align-items-center justify-content-between pt-2 border-top">
                <button
                  type="button"
                  class="btn btn-outline-secondary"
                  @click="resetForm"
                  :disabled="isSubmitting"
                >
                  Atur Ulang Form
                </button>
                <button
                  type="submit"
                  class="btn btn-primary d-flex align-items-center gap-2"
                  :disabled="isSubmitting"
                >
                  <svg
                    v-if="isSubmitting"
                    xmlns="http://www.w3.org/2000/svg"
                    class="icon animate-spin"
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                    <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                  </svg>
                  <svg
                    v-else
                    xmlns="http://www.w3.org/2000/svg"
                    class="icon"
                    width="20"
                    height="20"
                    viewBox="0 0 24 24"
                    stroke-width="2"
                    stroke="currentColor"
                    fill="none"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                    <path d="M7 4v16l13 -8z" />
                  </svg>
                  <span>{{ isSubmitting ? 'Mendaftarkan Sandbox...' : 'Mulai Pemindaian Mandiri Sekarang' }}</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- ========================================================= -->
      <!-- TAB 2: RIWAYAT PEMINDAIAN SAYA                            -->
      <!-- ========================================================= -->
      <div v-else class="animate-fade-in">
        <!-- ROW 1: Telemetry KPI Cards (Persis ScanJobsView) -->
        <div class="row row-deck row-cards mb-3">
          <!-- 1. Total Scan -->
          <div class="col-sm-6 col-lg-3">
            <div class="card card-sm">
              <div class="card-body">
                <div class="row align-items-center">
                  <div class="col-auto">
                    <span class="bg-primary text-white avatar">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 12h4l3 8l4 -16l3 8h4" /></svg>
                    </span>
                  </div>
                  <div class="col">
                    <div class="font-weight-medium">Total Pemindaian</div>
                    <div class="text-secondary fs-3 fw-bold">{{ kpiCounts.total }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 2. Sedang Berjalan / Antrean -->
          <div class="col-sm-6 col-lg-3">
            <div class="card card-sm">
              <div class="card-body">
                <div class="row align-items-center">
                  <div class="col-auto">
                    <span class="bg-azure text-white avatar">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-clock"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0" /><path d="M12 7v5l3 3" /></svg>
                    </span>
                  </div>
                  <div class="col">
                    <div class="font-weight-medium">Berjalan / Antrean</div>
                    <div class="text-secondary fs-3 fw-bold d-flex align-items-center gap-2">
                      <span>{{ kpiCounts.running + kpiCounts.queued }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 3. Selesai Sukses -->
          <div class="col-sm-6 col-lg-3">
            <div class="card card-sm">
              <div class="card-body">
                <div class="row align-items-center">
                  <div class="col-auto">
                    <span class="bg-success text-white avatar">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                    </span>
                  </div>
                  <div class="col">
                    <div class="font-weight-medium">Selesai Sukses</div>
                    <div class="text-secondary fs-3 fw-bold">{{ kpiCounts.completed }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- 4. Gagal / Ditolak -->
          <div class="col-sm-6 col-lg-3">
            <div class="card card-sm">
              <div class="card-body">
                <div class="row align-items-center">
                  <div class="col-auto">
                    <span class="bg-danger text-white avatar">
                      <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-tabler icons-tabler-outline icon-tabler-alert-triangle"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0" /><path d="M12 16h.01" /></svg>
                    </span>
                  </div>
                  <div class="col">
                    <div class="font-weight-medium">Gagal / Ditolak</div>
                    <div class="text-secondary fs-3 fw-bold">{{ kpiCounts.failed }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ROW 2: Master Card Pekerjaan Scan Mandiri (Identik ScanJobsView) -->
        <div class="card">
          <div class="card-header border-bottom py-2">
            <!-- Status Filter Tabs -->
            <ul class="nav nav-pills card-header-pills me-auto">
              <li class="nav-item">
                <button
                  class="nav-link"
                  :class="{ active: filterStatus === 'all' }"
                  @click="filterStatus = 'all'"
                >
                  Semua ({{ kpiCounts.total }})
                </button>
              </li>
              <li class="nav-item">
                <button
                  class="nav-link"
                  :class="{ active: filterStatus === 'running' }"
                  @click="filterStatus = 'running'"
                >
                  <span class="status-dot status-blue me-1"></span>
                  Berjalan ({{ kpiCounts.running }})
                </button>
              </li>
              <li class="nav-item">
                <button
                  class="nav-link"
                  :class="{ active: filterStatus === 'queued' }"
                  @click="filterStatus = 'queued'"
                >
                  <span class="status-dot status-azure me-1"></span>
                  Antrean ({{ kpiCounts.queued }})
                </button>
              </li>
              <li class="nav-item">
                <button
                  class="nav-link"
                  :class="{ active: filterStatus === 'completed' }"
                  @click="filterStatus = 'completed'"
                >
                  <span class="status-dot status-green me-1"></span>
                  Selesai ({{ kpiCounts.completed }})
                </button>
              </li>
              <li class="nav-item">
                <button
                  class="nav-link"
                  :class="{ active: filterStatus === 'failed' }"
                  @click="filterStatus = 'failed'"
                >
                  <span class="status-dot status-red me-1"></span>
                  Gagal ({{ kpiCounts.failed }})
                </button>
              </li>
            </ul>

            <!-- Restyled Modern Search Input -->
            <div class="card-actions my-1">
              <div class="search-box-wrapper position-relative">
                <div class="input-icon">
                  <span class="input-icon-addon text-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                  </span>
                  <input
                    type="text"
                    v-model="searchQuery"
                    class="form-control form-control-sm modern-search-input"
                    :class="{ 'has-query': searchQuery }"
                    placeholder="Cari nama proyek..."
                    @keydown.esc="searchQuery = ''"
                  />
                  <!-- Tombol Hapus Cepat (X) -->
                  <button
                    v-if="searchQuery"
                    type="button"
                    class="btn btn-sm btn-link p-0 text-muted search-clear-btn"
                    @click="searchQuery = ''"
                    title="Hapus pencarian (Esc)"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Master Table -->
          <div class="table-responsive">
            <table class="table table-vcenter table-hover card-table">
              <thead>
                <tr>
                  <th style="width: 40px;"></th>
                  <th>Kode & Waktu Scan</th>
                  <th>Proyek & Target Aset</th>
                  <th>Profil & Mesin</th>
                  <th style="min-width: 140px;">Progress</th>
                  <th>Status</th>
                  <th class="text-end">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <!-- Empty State (Identik dengan ScanJobsView) -->
                <tr v-if="filteredRequests.length === 0">
                  <td colspan="7" class="text-center py-5">
                    <div class="empty">
                      <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 8l.01 0" /><path d="M12 12l0 4" /></svg>
                      </div>
                      <p class="empty-title">Tidak ada pekerjaan scan ditemukan</p>
                      <p class="empty-subtitle text-secondary">
                        Tidak ada hasil yang cocok dengan filter atau kata kunci pencarian saat ini.
                      </p>
                      <div class="empty-action">
                        <button class="btn btn-primary" @click="activeTab = 'new_scan'">
                          Mulai Pemindaian Pertama
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>

                <!-- Rows -->
                <template v-for="job in paginatedRequests" :key="job.id">
                  <tr
                    class="scan-job-row"
                    :class="{ 'table-active': expandedJobId === job.id }"
                    @click="handleRowClick($event, job.id)"
                  >
                    <!-- Expand Trigger -->
                    <td>
                      <button
                        class="btn btn-icon btn-ghost-secondary btn-sm"
                        @click.stop="toggleExpandRow(job.id)"
                        :title="expandedJobId === job.id ? 'Tutup rincian' : 'Buka log rincian mesin'"
                      >
                        <svg
                          xmlns="http://www.w3.org/2000/svg"
                          class="icon icon-tabler"
                          :style="{ transform: expandedJobId === job.id ? 'rotate(90deg)' : 'rotate(0deg)', transition: 'transform 0.2s' }"
                          width="24"
                          height="24"
                          viewBox="0 0 24 24"
                          stroke-width="2"
                          stroke="currentColor"
                          fill="none"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        >
                          <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                          <path d="M9 6l6 6l-6 6" />
                        </svg>
                      </button>
                    </td>

                    <!-- 1. Kode & Waktu -->
                    <td>
                      <div class="d-flex flex-column">
                        <span class="fw-bold font-monospace text-primary">
                          #{{ job.code }}
                        </span>
                        <div class="text-secondary small font-monospace mt-1">
                          {{ formatTime(job.started_at || job.created_at) }}
                        </div>
                        <div class="small text-muted mt-1">
                          <span class="badge badge-outline text-secondary font-monospace">
                            {{ calculateDuration(job) }}
                          </span>
                        </div>
                      </div>
                    </td>

                    <!-- 2. Proyek & Target Aset -->
                    <td>
                      <div class="d-flex flex-column">
                        <div class="fw-medium text-truncate" style="max-width: 220px;" :title="job.project?.name">
                          {{ job.project?.name || 'Proyek Mandiri' }}
                        </div>
                        <div class="mt-1 d-flex align-items-center gap-1">
                          <span class="badge" :class="assetBadgeInfo(job).badgeClass">
                            {{ assetBadgeInfo(job).type }}
                          </span>
                          <span
                            class="text-secondary small font-monospace text-truncate"
                            style="max-width: 180px;"
                            :title="assetBadgeInfo(job).label"
                          >
                            {{ assetBadgeInfo(job).label }}
                          </span>
                        </div>
                      </div>
                    </td>

                    <!-- 3. Profil & Mesin Scanner (Indikator dinamis seperti pada gambar) -->
                    <td>
                      <div class="d-flex flex-column gap-1">
                        <span class="badge bg-blue-lt text-truncate" style="max-width: 220px;" :title="job.scan_profile?.name">
                          {{ job.scan_profile?.name || 'Scan Mandiri' }}
                        </span>
                        <!-- Engine Badges List with dynamic execution status colors -->
                        <div class="d-flex flex-wrap gap-1 mt-1">
                          <template v-if="getJobEngines(job).length > 0">
                            <span
                              v-for="eng in getJobEngines(job)"
                              :key="eng.engine_key"
                              class="badge font-monospace px-1 py-0 fs-6"
                              :class="eng.statusClass"
                            >
                              {{ eng.engine_key }}
                            </span>
                          </template>
                          <span v-else class="text-secondary small font-monospace">-</span>
                        </div>
                      </div>
                    </td>

                    <!-- 4. Progress -->
                    <td>
                      <div class="d-flex flex-column gap-1">
                        <div class="d-flex justify-content-between text-secondary small font-monospace">
                          <span>{{ job.progress }}%</span>
                          <span v-if="job.status === 'running'" class="text-primary fw-medium">Scanning</span>
                        </div>
                        <div class="progress progress-sm">
                          <div
                            class="progress-bar"
                            :class="{
                              'bg-warning': isJobPartialSuccess(job),
                              'bg-success': job.status === 'completed' && !isJobPartialSuccess(job),
                              'bg-primary progress-bar-striped progress-bar-animated': job.status === 'running',
                              'bg-azure': job.status === 'queued',
                              'bg-danger': ['failed', 'denied'].includes(job.status)
                            }"
                            :style="{ width: `${job.progress}%` }"
                          ></div>
                        </div>
                      </div>
                    </td>

                    <!-- 5. Status -->
                    <td>
                      <span class="badge d-inline-flex align-items-center gap-1" :class="getJobStatusBadgeClass(job)">
                        <svg
                          v-if="isJobPartialSuccess(job)"
                          xmlns="http://www.w3.org/2000/svg"
                          class="icon icon-xs"
                          width="14"
                          height="14"
                          viewBox="0 0 24 24"
                          stroke-width="2"
                          stroke="currentColor"
                          fill="none"
                          stroke-linecap="round"
                          stroke-linejoin="round"
                        >
                          <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                          <path d="M12 9v4" />
                          <path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0" />
                          <path d="M12 16h.01" />
                        </svg>
                        {{ getJobStatusLabel(job) }}
                      </span>
                    </td>

                    <!-- 6. Aksi -->
                    <td class="text-end" @click.stop>
                      <div class="btn-list flex-nowrap justify-content-end">
                        <!-- Rerun Button -->
                        <button
                          class="btn btn-sm btn-outline-secondary"
                          :disabled="isRerunningId === job.id || job.status === 'running'"
                          @click="promptRerun(job)"
                          title="Jalankan ulang pemindaian ini"
                        >
                          <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="icon"
                            :class="{ 'animate-spin': isRerunningId === job.id }"
                            width="18"
                            height="18"
                            viewBox="0 0 24 24"
                            stroke-width="2"
                            stroke="currentColor"
                            fill="none"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                          >
                            <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                            <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                            <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                          </svg>
                          <span class="d-none d-lg-inline ms-1">Rerun</span>
                        </button>

                        <!-- Detail Temuan Modal Button -->
                        <button
                          class="btn btn-sm btn-outline-primary"
                          @click="openDetailModal(job)"
                          title="Buka rincian kerentanan temuan"
                        >
                          Temuan
                        </button>

                        <!-- Detail Inspector Toggle -->
                        <button
                          class="btn btn-sm"
                          :class="expandedJobId === job.id ? 'btn-primary' : 'btn-ghost-primary'"
                          @click="toggleExpandRow(job.id)"
                          title="Buka log rincian mesin"
                        >
                          Log Mesin
                        </button>
                      </div>
                    </td>
                  </tr>

                  <!-- EXPANDED ACCORDION: Mesin Execution Inspector (Persis ScanJobsView) -->
                  <tr v-if="expandedJobId === job.id" class="bg-light-subtle">
                    <td colspan="7" class="p-3">
                      <div class="card card-sm">
                        <div class="card-status-top bg-azure"></div>
                        <div class="card-header bg-transparent py-2">
                          <h4 class="card-title text-azure d-flex align-items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 4m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M7 8h10" /><path d="M7 12h10" /><path d="M7 16h10" /></svg>
                            Rincian Eksekusi Mesin Pemindai Sandbox (#{{ job.code }})
                          </h4>
                          <div class="card-actions">
                            <span class="badge bg-secondary text-secondary-fg">
                              {{ job.scan_runs?.length || 0 }} Mesin Terdaftar
                            </span>
                          </div>
                        </div>

                        <div class="card-body p-0">
                          <!-- If No Run Yet -->
                          <div v-if="!job.scan_runs || job.scan_runs.length === 0" class="p-4 text-center text-secondary">
                            Belum ada log eksekusi kontainer (sedang dalam antrean penjadwalan worker).
                          </div>

                          <!-- Scan Runs Table -->
                          <div v-else class="table-responsive">
                            <table class="table table-vcenter table-striped table-sm mb-0">
                              <thead>
                                <tr>
                                  <th>Mesin Pemindai</th>
                                  <th>Status Eksekusi</th>
                                  <th>Exit Code</th>
                                  <th>Durasi Eksekusi</th>
                                  <th>Temuan (Findings)</th>
                                  <th>Image Sandbox</th>
                                </tr>
                              </thead>
                              <tbody>
                                <tr v-for="run in job.scan_runs" :key="run.id">
                                  <td>
                                    <span class="fw-bold font-monospace">{{ run.engine_key }}</span>
                                  </td>
                                  <td>
                                    <span class="badge" :class="getEngineRunStatusClass(run.status)">
                                      {{ run.status.toUpperCase() }}
                                    </span>
                                    <span v-if="run.failure_reason" class="text-danger small ms-2">
                                      ({{ run.failure_reason }})
                                    </span>
                                  </td>
                                  <td>
                                    <span class="badge badge-outline font-monospace">
                                      {{ run.exit_code !== null ? run.exit_code : '-' }}
                                    </span>
                                  </td>
                                  <td>
                                    <span class="text-secondary font-monospace small">
                                      {{ run.runtime_metrics?.duration_ms ? `${(run.runtime_metrics.duration_ms / 1000).toFixed(2)} detik` : '-' }}
                                    </span>
                                  </td>
                                  <td>
                                    <span
                                      class="badge"
                                      :class="(run.runtime_metrics?.finding_count ?? 0) > 0 ? 'bg-danger text-danger-fg' : 'bg-success-lt text-success'"
                                    >
                                      {{ run.runtime_metrics?.finding_count ?? 0 }} Temuan
                                    </span>
                                  </td>
                                  <td>
                                    <span class="text-muted font-monospace small">
                                      {{ (run as any).command_spec?.container_image || 'docker' }}
                                    </span>
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                          </div>
                        </div>

                        <!-- Footer Log Failure Warning if any -->
                        <div v-if="job.failure_reason" class="card-footer bg-danger-lt py-2 text-danger small">
                          <strong>Catatan Kegagalan:</strong> {{ job.failure_reason }}
                        </div>
                      </div>
                    </td>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>

          <!-- Card Footer: Pagination (Persis ScanJobsView) -->
          <div class="card-footer d-flex align-items-center justify-content-between py-2">
            <div class="text-secondary small">
              Menampilkan
              <span class="fw-bold">{{ filteredRequests.length === 0 ? 0 : (currentPage - 1) * pageSize + 1 }}</span>
              -
              <span class="fw-bold">{{ Math.min(currentPage * pageSize, filteredRequests.length) }}</span>
              dari <span class="fw-bold">{{ filteredRequests.length }}</span> pekerjaan scan
            </div>

            <ul class="pagination pagination-sm m-0">
              <li class="page-item" :class="{ disabled: currentPage === 1 }">
                <button class="page-link" @click="currentPage--" :disabled="currentPage === 1">
                  Sebelumnya
                </button>
              </li>
              <li
                v-for="p in totalPages"
                :key="p"
                class="page-item"
                :class="{ active: p === currentPage }"
              >
                <button class="page-link" @click="currentPage = p">{{ p }}</button>
              </li>
              <li class="page-item" :class="{ disabled: currentPage === totalPages || totalPages === 0 }">
                <button class="page-link" @click="currentPage++" :disabled="currentPage === totalPages || totalPages === 0">
                  Berikutnya
                </button>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL RINCIAN TEMUAN & AI REMEDIATION                      -->
    <!-- ========================================================= -->
    <div
      v-if="selectedRequestForDetail"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.7); z-index: 1060;"
      @click.self="closeDetailModal"
    >
      <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" role="document">
        <div class="modal-content shadow-lg border-0">
          <div class="modal-header border-bottom">
            <div class="d-flex align-items-center gap-3">
              <div class="avatar avatar-md bg-primary-lt text-primary rounded">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
              </div>
              <div>
                <div class="d-flex align-items-center gap-2">
                  <h3 class="modal-title mb-0 fw-bold">Rincian Temuan Celah Keamanan</h3>
                  <span class="badge bg-primary-lt font-monospace fw-bold">#{{ selectedRequestForDetail.code }}</span>
                </div>
                <div class="text-secondary small mt-1 d-flex flex-wrap align-items-center gap-2">
                  <span>Proyek: <strong class="text-body">{{ selectedRequestForDetail.project?.name || 'Proyek Mandiri' }}</strong></span>
                  <span class="text-muted">&bull;</span>
                  <span>Target: <code class="text-primary font-monospace">{{ selectedRequestForDetail.repository?.url || selectedRequestForDetail.target?.base_url || selectedRequestForDetail.repository?.name || '-' }}</code></span>
                </div>
              </div>
            </div>
            <button type="button" class="btn-close" aria-label="Close" @click="closeDetailModal"></button>
          </div>

          <div class="modal-body p-3">
            <!-- Risk Metrics & Export Toolbar -->
            <div class="card card-sm bg-body-tertiary border mb-3">
              <div class="card-body p-2 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                <!-- Risk Metrics Badges with status dots -->
                <div class="d-flex flex-wrap align-items-center gap-2">
                  <span class="badge bg-red-lt text-red border border-danger-subtle px-2 py-1 fs-6 d-inline-flex align-items-center gap-1">
                    <span class="status-dot status-red"></span>
                    Critical: <strong>{{ riskSummary.critical || 0 }}</strong>
                  </span>
                  <span class="badge bg-orange-lt text-orange border border-warning-subtle px-2 py-1 fs-6 d-inline-flex align-items-center gap-1">
                    <span class="status-dot status-orange"></span>
                    High: <strong>{{ riskSummary.high || 0 }}</strong>
                  </span>
                  <span class="badge bg-yellow-lt text-yellow border border-yellow-subtle px-2 py-1 fs-6 d-inline-flex align-items-center gap-1">
                    <span class="status-dot status-yellow"></span>
                    Medium: <strong>{{ riskSummary.medium || 0 }}</strong>
                  </span>
                  <span class="badge bg-azure-lt text-azure border border-azure-subtle px-2 py-1 fs-6 d-inline-flex align-items-center gap-1">
                    <span class="status-dot status-azure"></span>
                    Low: <strong>{{ riskSummary.low || 0 }}</strong>
                  </span>
                </div>

                <!-- Export Actions -->
                <div class="d-flex align-items-center gap-2 ms-auto">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-1"
                    @click="exportFindingsCsv"
                    title="Ekspor temuan ke file CSV"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 11l5 5l5 -5" /><path d="M12 4l0 12" /></svg>
                    <span>Export CSV</span>
                  </button>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-primary d-flex align-items-center gap-1"
                    @click="downloadReportPdfFile"
                    title="Unduh laporan resmi PDF"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M12 17v-6" /><path d="M9.5 14.5l2.5 2.5l2.5 -2.5" /></svg>
                    <span>Unduh PDF</span>
                  </button>
                </div>
              </div>
            </div>

            <!-- Severity Filter Tabs (nav-pills style matching Tabler) -->
            <ul class="nav nav-pills mb-3 gap-1" role="tablist">
              <li class="nav-item">
                <button
                  class="nav-link py-1 px-3"
                  :class="{ active: findingsFilter === 'all' }"
                  @click="findingsFilter = 'all'"
                >
                  Semua ({{ allFindings.length }})
                </button>
              </li>
              <li class="nav-item">
                <button
                  class="nav-link py-1 px-3"
                  :class="{ active: findingsFilter === 'critical' }"
                  @click="findingsFilter = 'critical'"
                >
                  <span class="status-dot status-red me-1"></span>
                  Critical ({{ riskSummary.critical || 0 }})
                </button>
              </li>
              <li class="nav-item">
                <button
                  class="nav-link py-1 px-3"
                  :class="{ active: findingsFilter === 'high' }"
                  @click="findingsFilter = 'high'"
                >
                  <span class="status-dot status-orange me-1"></span>
                  High ({{ riskSummary.high || 0 }})
                </button>
              </li>
              <li class="nav-item">
                <button
                  class="nav-link py-1 px-3"
                  :class="{ active: findingsFilter === 'medium' }"
                  @click="findingsFilter = 'medium'"
                >
                  <span class="status-dot status-yellow me-1"></span>
                  Medium ({{ riskSummary.medium || 0 }})
                </button>
              </li>
              <li class="nav-item">
                <button
                  class="nav-link py-1 px-3"
                  :class="{ active: findingsFilter === 'low' }"
                  @click="findingsFilter = 'low'"
                >
                  <span class="status-dot status-azure me-1"></span>
                  Low ({{ riskSummary.low || 0 }})
                </button>
              </li>
            </ul>

            <!-- Findings List -->
            <div v-if="filteredFindings.length === 0" class="card card-sm border border-dashed py-5 text-center">
              <div class="avatar avatar-md bg-success-lt rounded-circle mx-auto mb-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
              </div>
              <div class="fw-bold">Tidak Ada Temuan Celah Keamanan</div>
              <div class="text-secondary small">Kategori ini tidak memiliki laporan kerentanan aktif.</div>
            </div>

            <div v-else class="d-flex flex-column gap-2">
              <div
                v-for="(finding, fIndex) in filteredFindings"
                :key="getFindingKey(finding, fIndex)"
                class="card card-sm border shadow-none mb-1"
                :class="getFindingBorderClass(finding.severity)"
                style="border-left-width: 4px !important;"
              >
                <div class="card-body p-3">
                  <!-- Header: Severity, Rule, Engine, CWE, OWASP -->
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                      <span
                        class="badge flex-shrink-0 font-monospace fw-bold"
                        :class="getFindingSeverityBadgeClass(finding.severity)"
                      >
                        {{ (finding.severity || 'low').toUpperCase() }}
                      </span>
                      <span v-if="finding.rule_id" class="badge bg-secondary-lt font-monospace small">
                        {{ finding.rule_id }}
                      </span>
                      <span class="badge bg-blue-lt font-monospace small">
                        {{ finding.engine_key || 'Scanner' }}
                      </span>
                    </div>

                    <div class="d-flex flex-wrap gap-1 ms-auto flex-shrink-0">
                      <span v-if="finding.cwe" class="badge bg-secondary-lt text-secondary font-monospace small">
                        {{ finding.cwe }}
                      </span>
                      <span v-if="finding.owasp" class="badge bg-secondary-lt text-secondary font-monospace small">
                        {{ finding.owasp }}
                      </span>
                    </div>
                  </div>

                  <!-- Title / Issue Description -->
                  <h4 class="card-title mb-2 text-reset lh-base fw-bold">
                    {{ finding.title }}
                  </h4>

                  <!-- Location Box -->
                  <div
                    v-if="finding.location?.file_path || finding.location?.endpoint"
                    class="p-2 rounded bg-body-tertiary border font-monospace small mb-2 d-flex align-items-center gap-2"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-secondary flex-shrink-0" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
                    <span class="text-truncate text-secondary">
                      Lokasi: <strong class="text-body">{{ finding.location?.file_path || finding.location?.endpoint }}</strong>
                    </span>
                    <span v-if="finding.location?.line_start" class="badge bg-secondary-lt font-monospace ms-auto flex-shrink-0">
                      Baris {{ finding.location.line_start }}<span v-if="finding.location.line_end && finding.location.line_end !== finding.location.line_start">-{{ finding.location.line_end }}</span>
                    </span>
                  </div>

                  <!-- Description (if any additional) -->
                  <p v-if="finding.description && finding.description !== finding.title" class="text-secondary small mb-2 lh-sm">
                    {{ finding.description }}
                  </p>

                  <!-- Action Row: AI Remediation Trigger -->
                  <div class="d-flex justify-content-between align-items-center pt-2 mt-2 border-top">
                    <div class="text-muted small">
                      <span v-if="finding.cvss" class="me-2">CVSS: <strong class="text-danger">{{ finding.cvss }}</strong></span>
                    </div>

                    <button
                      type="button"
                      class="btn btn-sm d-flex align-items-center gap-1"
                      :class="activeAiFindingKey === getFindingKey(finding, fIndex) ? 'btn-primary' : 'btn-outline-primary'"
                      @click="toggleAiRemediation(finding, fIndex)"
                    >
                      <!-- Close Icon when open -->
                      <svg v-if="activeAiFindingKey === getFindingKey(finding, fIndex)" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                      <!-- Sparkle Icon when closed -->
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l1.5 5.5l5.5 1.5l-5.5 1.5l-1.5 5.5l-1.5 -5.5l-5.5 -1.5l5.5 -1.5z" /></svg>
                      <span>{{ activeAiFindingKey === getFindingKey(finding, fIndex) ? 'Tutup Rekomendasi' : 'Rekomendasi AI' }}</span>
                    </button>
                  </div>

                  <!-- AI Remediation Panel -->
                  <div
                    v-if="activeAiFindingKey === getFindingKey(finding, fIndex)"
                    class="mt-3 card border-primary shadow-sm"
                  >
                    <div class="card-header bg-primary-lt py-2 d-flex align-items-center justify-content-between">
                      <div class="fw-bold text-primary small d-flex align-items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-primary" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3l1.5 5.5l5.5 1.5l-5.5 1.5l-1.5 5.5l-1.5 -5.5l-5.5 -1.5l5.5 -1.5z" /></svg>
                        <span>Panduan Mitigasi Cepat (AI Cyber Assistant)</span>
                        <span v-if="aiRemediationData?.category" class="badge bg-primary text-primary-fg font-monospace ms-1">{{ aiRemediationData.category }}</span>
                      </div>
                      <button type="button" class="btn-close" aria-label="Close" @click="activeAiFindingKey = null"></button>
                    </div>

                    <div class="card-body p-3">
                      <!-- Loading State -->
                      <div v-if="isLoadingAiRemediation" class="text-secondary small py-3 d-flex align-items-center justify-content-center gap-2">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span>Menganalisis kode dan menyusun panduan mitigasi AI...</span>
                      </div>

                      <!-- Content State -->
                      <div v-else-if="aiRemediationData" class="d-flex flex-column gap-3">
                        <!-- Summary & Business Impact -->
                        <div v-if="aiRemediationData.summary" class="text-body small lh-base">
                          <strong class="text-dark d-block mb-1">Analisis Celah Keamanan:</strong>
                          <p class="mb-0 text-secondary" style="line-height: 1.5;">{{ aiRemediationData.summary }}</p>
                        </div>

                        <!-- Mitigation Checklist -->
                        <div v-if="aiRemediationData.mitigation_checklist?.length" class="border rounded p-3 bg-body-tertiary">
                          <div class="text-primary fw-bold small mb-2 d-flex align-items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-success" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
                            <span>Langkah Mitigasi yang Disarankan:</span>
                          </div>
                          <ul class="list-unstyled mb-0 small text-secondary d-flex flex-column gap-1">
                            <li v-for="(step, sIdx) in aiRemediationData.mitigation_checklist" :key="sIdx" class="d-flex align-items-start gap-2">
                              <span class="badge bg-success-lt text-success px-1 py-0 flex-shrink-0 mt-1">&check;</span>
                              <span>{{ step }}</span>
                            </li>
                          </ul>
                        </div>

                        <!-- Code Diff / Patch Example -->
                        <div v-if="aiRemediationData.code_diff">
                          <div class="text-secondary fw-bold small mb-1">Rekomendasi Perbaikan Kode (Patch / Kode Aman):</div>
                          <pre class="bg-dark text-light p-3 rounded font-monospace small mb-0 overflow-auto" style="max-height: 250px;"><code>{{ aiRemediationData.code_diff }}</code></pre>
                        </div>
                      </div>

                      <!-- Fallback empty state -->
                      <div v-else class="text-secondary small py-2">
                        Panduan mitigasi belum tersedia untuk jenis temuan ini.
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer d-flex justify-content-between align-items-center py-2">
            <div class="text-secondary small">
              Menampilkan <strong>{{ filteredFindings.length }}</strong> temuan celah keamanan
            </div>
            <button type="button" class="btn btn-secondary px-4" @click="closeDetailModal">
              Tutup
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL KONFIRMASI RERUN                                    -->
    <!-- ========================================================= -->
    <div
      v-if="rerunConfirmRequest"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      style="background-color: rgba(0, 0, 0, 0.65); z-index: 1060;"
      @click.self="closeRerunModal"
    >
      <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content shadow-lg border-0">
          <button type="button" class="btn-close" aria-label="Close" @click="closeRerunModal"></button>
          <div class="modal-status bg-primary"></div>
          <div class="modal-body text-center py-4">
            <div class="avatar avatar-lg bg-primary-lt rounded-circle mx-auto mb-3 shadow-sm">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg text-primary" width="28" height="28" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" /><path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" /></svg>
            </div>
            <h3 class="modal-title mb-1">Rerun Pemindaian Mandiri?</h3>
            <div class="text-secondary small mb-3">
              Pekerjaan akan didaftarkan kembali ke sandbox antrean dengan profil dan target aset yang sama.
            </div>

            <div class="card card-sm bg-body text-start border mb-0">
              <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Kode Scan:</span>
                  <span class="font-monospace fw-bold small text-primary">#{{ rerunConfirmRequest.code }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-1 border-bottom">
                  <span class="text-secondary small">Proyek:</span>
                  <span class="fw-medium small text-truncate" style="max-width: 170px;">{{ rerunConfirmRequest.project?.name || '-' }}</span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-secondary small">Target:</span>
                  <span class="fw-medium small font-monospace text-truncate" style="max-width: 170px;">{{ rerunConfirmRequest.repository?.name || rerunConfirmRequest.target?.name || '-' }}</span>
                </div>
              </div>
            </div>
          </div>
          <div class="modal-footer bg-transparent border-top-0 pt-0">
            <div class="w-100">
              <div class="row g-2">
                <div class="col-6">
                  <button
                    type="button"
                    class="btn btn-secondary w-100"
                    :disabled="isRerunningId !== null"
                    @click="closeRerunModal"
                  >
                    Batal
                  </button>
                </div>
                <div class="col-6">
                  <button
                    type="button"
                    class="btn btn-primary w-100"
                    :disabled="isRerunningId !== null"
                    @click="confirmAndExecuteRerun"
                  >
                    <svg
                      v-if="isRerunningId !== null"
                      xmlns="http://www.w3.org/2000/svg"
                      class="icon animate-spin me-1"
                      width="18"
                      height="18"
                      viewBox="0 0 24 24"
                      stroke-width="2"
                      stroke="currentColor"
                      fill="none"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    >
                      <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                      <path d="M20 11a8.1 8.1 0 0 0 -15.5 -2m-.5 -4v4h4" />
                      <path d="M4 13a8.1 8.1 0 0 0 15.5 2m.5 4v-4h-4" />
                    </svg>
                    <span>{{ isRerunningId !== null ? 'Memproses...' : 'Ya, Rerun' }}</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}

/* Transitions */
.animate-fade-in {
  animation: fadeIn 0.2s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}

.toast-slide-enter-active,
.toast-slide-leave-active {
  transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
}

.toast-slide-enter-from {
  opacity: 0;
  transform: translateY(-16px) scale(0.96);
}

.toast-slide-leave-to {
  opacity: 0;
  transform: translateY(-12px) scale(0.96);
}

/* Asset Select Card */
.asset-select-card {
  transition: all 0.2s ease;
  user-select: none;
}

.asset-select-card:hover {
  transform: translateY(-2px);
  border-color: var(--tblr-primary) !important;
  box-shadow: 0 4px 12px rgba(var(--tblr-primary-rgb), 0.15) !important;
}

/* Restyled Search Bar */
.search-box-wrapper {
  display: flex;
  align-items: center;
}

.modern-search-input {
  width: 270px;
  border-radius: 8px;
  padding-right: 34px;
  background-color: rgba(var(--tblr-body-bg-rgb), 0.7);
  font-size: 0.825rem;
  border: 1px solid var(--tblr-border-color);
}

.modern-search-input:focus {
  border-color: var(--tblr-primary);
  background-color: var(--tblr-bg-surface);
  box-shadow: 0 0 0 3px rgba(var(--tblr-primary-rgb), 0.18);
}

.search-clear-btn {
  position: absolute;
  right: 8px;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: rgba(var(--tblr-body-color-rgb), 0.1);
  z-index: 5;
  transition: all 0.15s ease;
}

.search-clear-btn:hover {
  background: rgba(214, 57, 57, 0.2);
  color: var(--tblr-danger) !important;
}

/* Scan Job Clickable Row */
.scan-job-row {
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.scan-job-row:hover > td {
  background-color: rgba(var(--tblr-primary-rgb), 0.05);
}

.scan-job-row.table-active > td {
  background-color: rgba(var(--tblr-primary-rgb), 0.08);
}
</style>
