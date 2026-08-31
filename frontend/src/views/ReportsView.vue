<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { exportFindingsToCsv } from '../services/exportCsvService'
import { createReport, downloadReportPdf, getReport, getReports } from '../services/reportService'
import { getScanJobs } from '../services/scanJobService'
import { authState } from '../stores/authStore'

const reports = ref<any[]>([])
const scanJobs = ref<any[]>([])
const selectedReport = ref<any>(null)
const isLoading = ref(true)
const isSaving = ref(false)
const isViewing = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const copiedJson = ref(false)
const isDownloadingPdf = ref(false)
const form = reactive({ scan_job_id: '' })

const currentPage = ref(1)
const pageSize = ref(10)

const canGenerateReport = computed(() =>
  ['super_admin', 'security_admin', 'security_analyst'].includes(authState.user?.role?.name),
)

const completedScanJobs = computed(() =>
  scanJobs.value.filter((job) => ['completed', 'failed'].includes(job.status)),
)

const paginatedReports = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return reports.value.slice(start, start + pageSize.value)
})

const reportJson = computed(() =>
  selectedReport.value ? JSON.stringify(selectedReport.value.metadata?.content ?? selectedReport.value, null, 2) : '',
)

const selectedContent = computed(() => selectedReport.value?.metadata?.content ?? null)
const selectedRiskSummary = computed(() => selectedContent.value?.risk_summary ?? selectedReport.value?.metadata?.risk_summary ?? {})
const selectedFindings = computed(() => selectedContent.value?.findings ?? [])
const selectedExecutionSummary = computed(() => selectedContent.value?.execution_summary ?? {})

function resetForm() {
  form.scan_job_id = completedScanJobs.value[0]?.id ?? ''
}

async function loadData() {
  if (!reports.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''
  try {
    const [reportList, scanJobList] = await Promise.all([getReports(), getScanJobs()])
    reports.value = reportList
    scanJobs.value = scanJobList
    if (!form.scan_job_id) resetForm()
    if (!selectedReport.value && reportList.length > 0) {
      await viewReport(reportList[0].id)
    }
  } catch (error) {
    console.error('Gagal memuat laporan.', error)
    errorMessage.value = 'Gagal memuat laporan.'
  } finally {
    isLoading.value = false
  }
}

async function submitReport() {
  if (!form.scan_job_id) {
    errorMessage.value = 'Pilih pekerjaan scan yang sudah selesai terlebih dahulu.'
    return
  }

  isSaving.value = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const report = await createReport({ scan_job_id: Number(form.scan_job_id) })
    successMessage.value = `Laporan #${report.id} berhasil digenerate!`
    await loadData()
    await viewReport(report.id)
  } catch (error) {
    console.error('Gagal membuat laporan.', error)
    errorMessage.value = 'Gagal membuat laporan. Pastikan scan telah selesai.'
  } finally {
    isSaving.value = false
  }
}

async function viewReport(id: number) {
  isViewing.value = true
  try {
    selectedReport.value = await getReport(id)
  } catch (error) {
    console.error('Gagal melihat laporan.', error)
    errorMessage.value = 'Gagal melihat detail laporan.'
  } finally {
    isViewing.value = false
  }
}

function copyJson() {
  navigator.clipboard.writeText(reportJson.value)
  copiedJson.value = true
  setTimeout(() => (copiedJson.value = false), 2000)
}

async function downloadPdf(report = selectedReport.value) {
  if (!report) return

  isDownloadingPdf.value = true
  errorMessage.value = ''

  try {
    const blob = await downloadReportPdf(report.id)
    const url = URL.createObjectURL(new Blob([blob], { type: 'application/pdf' }))
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `secsys-report-${report.scan_job?.code ?? report.id}.pdf`
    anchor.click()
    URL.revokeObjectURL(url)
  } catch (error) {
    console.error('Gagal mengunduh PDF.', error)
    errorMessage.value = 'Gagal mengunduh PDF laporan.'
  } finally {
    isDownloadingPdf.value = false
  }
}

function exportReportCsv() {
  if (!selectedFindings.value.length) {
    errorMessage.value = 'Tidak ada data temuan pada laporan ini untuk diekspor.'
    setTimeout(() => (errorMessage.value = ''), 3000)
    return
  }
  exportFindingsToCsv(selectedFindings.value, `tameng-laporan-${selectedReport.value?.scan_job?.code ?? selectedReport.value?.id}`)
}

onMounted(loadData)
</script>

<template>
  <AppShell>
    <template #eyebrow>Hasil Asesmen & Pelaporan Kepatuhan</template>
    <template #title>Laporan Keamanan (Reports)</template>

    <div v-if="successMessage" class="status-panel success fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <!-- Main Reports Split Section -->
    <section class="two-column">
      <!-- Left: Published Reports List -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Daftar Laporan Terbit
          </h2>
          <span class="badge">{{ reports.length }} Laporan</span>
        </div>

        <div class="profile-list" style="max-height: 480px; overflow-y: auto;">
          <article
            v-for="report in paginatedReports"
            :key="report.id"
            :style="{
              borderLeft: selectedReport?.id === report.id ? '3px solid var(--tameng-sapphire)' : undefined,
              background: selectedReport?.id === report.id ? '#f0f9ff' : undefined,
              cursor: 'pointer'
            }"
            @click="viewReport(report.id)"
          >
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
              <div>
                <span class="code-text" style="font-weight: 700; color: var(--text-main);">{{ report.scan_job?.code ?? `Job #${report.scan_job_id}` }}</span>
                <div style="font-size: 12px; color: var(--text-muted); margin-top: 2px;">
                  Format: {{ report.format }} | Diterbitkan: {{ report.generated_at }}
                </div>
              </div>
              <span class="badge is-success">Terbit</span>
            </div>

            <div style="margin-top: 8px; font-size: 12px; color: var(--text-dim); display: flex; justify-content: space-between; align-items: center;">
              <span>Total Temuan: <strong>{{ report.metadata?.finding_count ?? 0 }}</strong></span>
              <div class="table-actions">
                <button class="small-button secondary-button" type="button" @click.stop="viewReport(report.id)">
                  Lihat
                </button>
                <button
                  class="small-button secondary-button"
                  type="button"
                  :disabled="isDownloadingPdf"
                  @click.stop="downloadPdf(report)"
                >
                  PDF
                </button>
              </div>
            </div>
          </article>

          <div v-if="!reports.length && !isLoading" class="status-panel">
            Belum ada laporan keamanan yang diterbitkan.
          </div>
        </div>

        <!-- Pagination Controls -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="reports.length"
          :page-size-options="[5, 10, 20]"
        />
      </div>

      <!-- Right: Generate New Report Form -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Generate Laporan Baru
          </h2>
          <span class="badge is-info">Standardized Format</span>
        </div>

        <form class="entity-form" @submit.prevent="submitReport">
          <label>
            <span>Pilih Pekerjaan Scan yang Selesai</span>
            <select v-model="form.scan_job_id" :disabled="!canGenerateReport" required>
              <option v-for="job in completedScanJobs" :key="job.id" :value="job.id">
                {{ job.code }} - {{ job.project?.code }} ({{ job.status }})
              </option>
            </select>
          </label>

          <div v-if="!completedScanJobs.length" class="status-panel info" style="margin-top: 12px;">
            Menunggu pekerjaan scan selesai untuk dapat di-generate laporannya.
          </div>

          <div class="form-actions">
            <button
              class="btn btn-primary-glow"
              type="submit"
              :disabled="!canGenerateReport || isSaving || !completedScanJobs.length"
            >
              {{ isSaving ? 'Membuat Laporan...' : 'Generate Laporan JSON' }}
            </button>
            <button class="secondary-button" type="button" @click="resetForm">Reset</button>
          </div>
        </form>
      </div>
    </section>

    <!-- Selected Report Detailed Document View -->
    <section v-if="selectedReport" class="panel">
      <div class="panel-heading">
        <div>
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
            {{ selectedContent?.title ?? 'Laporan Asesmen Keamanan' }}
          </h2>
          <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 2px;">
            Laporan #{{ selectedReport.id }} | Dibuat: {{ selectedReport.generated_at }}
          </span>
        </div>

        <div class="table-actions">
          <button class="btn btn-sm secondary-button" type="button" @click="exportReportCsv">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span>Ekspor CSV</span>
          </button>
          <button class="btn btn-sm secondary-button" type="button" @click="copyJson">
            {{ copiedJson ? 'Tersalin!' : 'Salin JSON' }}
          </button>
          <button class="btn btn-sm secondary-button" type="button" :disabled="isDownloadingPdf" @click="downloadPdf()">
            {{ isDownloadingPdf ? 'Mengunduh...' : 'Unduh PDF' }}
          </button>
        </div>
      </div>

      <!-- Risk Matrix Metrics -->
      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-bottom: 24px;">
        <div class="stat-card" style="border-top: 2px solid var(--severity-critical); padding: 12px 16px;">
          <span>Kritis</span>
          <strong style="color: var(--severity-critical)">{{ selectedRiskSummary.critical ?? 0 }}</strong>
        </div>
        <div class="stat-card" style="border-top: 2px solid var(--severity-high); padding: 12px 16px;">
          <span>Tinggi</span>
          <strong style="color: var(--severity-high)">{{ selectedRiskSummary.high ?? 0 }}</strong>
        </div>
        <div class="stat-card" style="border-top: 2px solid var(--severity-medium); padding: 12px 16px;">
          <span>Sedang</span>
          <strong style="color: var(--severity-medium)">{{ selectedRiskSummary.medium ?? 0 }}</strong>
        </div>
        <div class="stat-card" style="border-top: 2px solid var(--severity-low); padding: 12px 16px;">
          <span>Rendah</span>
          <strong style="color: var(--severity-low)">{{ selectedRiskSummary.low ?? 0 }}</strong>
        </div>
        <div class="stat-card" style="border-top: 2px solid var(--severity-info); padding: 12px 16px;">
          <span>Info</span>
          <strong style="color: var(--severity-info)">{{ selectedRiskSummary.informational ?? 0 }}</strong>
        </div>
      </div>

      <!-- Asset & Execution Info -->
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
        <div class="entity-card">
          <h4 style="font-size: 13.5px; font-weight: 700; color: var(--tameng-navy);">Aset & Target Terverifikasi</h4>
          <p style="font-size: 13px;"><strong>Proyek:</strong> {{ selectedContent?.project?.name ?? '-' }} ({{ selectedContent?.project?.code }})</p>
          <p style="font-size: 13px;"><strong>Repositori:</strong> {{ selectedContent?.repository?.name ?? selectedReport.scan_job?.repository?.name ?? '-' }}</p>
          <p style="font-size: 13px;"><strong>Target Web/API:</strong> {{ selectedContent?.target?.name ?? selectedReport.scan_job?.target?.name ?? '-' }}</p>
        </div>

        <div class="entity-card">
          <h4 style="font-size: 13.5px; font-weight: 700; color: var(--tameng-navy);">Eksekusi & Engine Telemetry</h4>
          <p style="font-size: 13px;"><strong>Mode:</strong> {{ selectedReport.metadata?.scanner_execution ? 'Docker Sandbox Terisolasi' : 'Simulasi Guarded' }}</p>
          <p style="font-size: 13px;"><strong>Hasil Orkestrasi:</strong> {{ selectedExecutionSummary.outcome ?? selectedReport.status }}</p>
          <div style="display: flex; gap: 4px; flex-wrap: wrap; margin-top: 4px;">
            <span v-for="engine in selectedExecutionSummary.completed_engines ?? []" :key="engine" class="badge is-success">
              OK {{ engine }}
            </span>
          </div>
        </div>
      </div>

      <!-- Findings in Report -->
      <h3 style="font-size: 14.5px; font-weight: 700; color: var(--tameng-navy); margin-bottom: 12px;">Daftar Temuan Keamanan</h3>
      <div class="table-responsive" style="margin-bottom: 24px;">
        <table class="data-table">
          <thead>
            <tr>
              <th>Kode</th>
              <th>Risiko</th>
              <th>Judul</th>
              <th>Lokasi</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="finding in selectedFindings" :key="finding.code">
              <td class="code-text" style="font-size: 12px; color: var(--tameng-sapphire)">{{ finding.code }}</td>
              <td><span class="badge" :class="finding.severity">{{ finding.severity }}</span></td>
              <td style="font-weight: 600; color: var(--text-main);">{{ finding.title }}</td>
              <td class="code-text" style="font-size: 12px;">
                {{ finding.location?.file_path ?? finding.location?.endpoint ?? '-' }}
              </td>
              <td><span class="badge">{{ finding.status }}</span></td>
            </tr>
            <tr v-if="!selectedFindings.length">
              <td colspan="5" style="text-align: center; color: var(--text-dim); padding: 18px;">
                Tidak ada temuan kerentanan pada laporan ini.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Raw JSON Report Inspector -->
      <h3 style="font-size: 14.5px; font-weight: 700; color: var(--tameng-navy); margin-bottom: 12px;">Dokumen JSON Lengkap</h3>
      <pre class="json-preview">{{ reportJson }}</pre>
    </section>
  </AppShell>
</template>
