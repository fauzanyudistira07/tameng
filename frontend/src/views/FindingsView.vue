<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { exportFindingsToCsv } from '../services/exportCsvService'
import { getFindingAiRemediation, getFindings, updateFindingStatus } from '../services/findingService'
import { authState } from '../stores/authStore'

const findings = ref<any[]>([])
const summary = ref({ total: 0, critical: 0, high: 0, medium: 0, low: 0, informational: 0 })
const isLoading = ref(true)
const errorMessage = ref('')
const successMessage = ref('')
const updatingId = ref<any>(null)
const selectedFinding = ref<any>(null)
const searchQuery = ref('')
const filterSeverity = ref('all')
const filterStatus = ref('all')
const filterEngine = ref('all')

const currentPage = ref(1)
const pageSize = ref(10)

// Triage Notes Dialog State
const triageFinding = ref<any>(null)
const triageStatus = ref('open')
const triageNotes = ref('')
const isSavingTriage = ref(false)

const canTriage = computed(() =>
  ['super_admin', 'security_admin', 'security_analyst'].includes(authState.user?.role?.name),
)

const filteredFindings = computed(() => {
  const query = (searchQuery.value || '').trim().toLowerCase()
  return (findings.value || []).filter((f) => {
    const matchesSeverity = filterSeverity.value === 'all' || f.severity === filterSeverity.value
    const matchesStatus = filterStatus.value === 'all' || f.status === filterStatus.value
    const matchesEngine = filterEngine.value === 'all' || f.engine_key === filterEngine.value
    if (!matchesSeverity || !matchesStatus || !matchesEngine) return false

    if (!query) return true
    const title = (f.title ?? '').toLowerCase()
    const ruleId = (f.rule_id ?? '').toLowerCase()
    const filePath = (f.file_path ?? '').toLowerCase()
    const cwe = (f.cwe ?? '').toLowerCase()

    return title.includes(query) || ruleId.includes(query) || filePath.includes(query) || cwe.includes(query)
  })
})

const paginatedFindings = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredFindings.value.slice(start, start + pageSize.value)
})

watch([searchQuery, filterSeverity, filterStatus, filterEngine], () => {
  currentPage.value = 1
})

async function loadTemuan() {
  if (!findings.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''
  try {
    const data = await getFindings()
    findings.value = data.findings ?? []
    summary.value = data.summary ?? { total: 0, critical: 0, high: 0, medium: 0, low: 0, informational: 0 }
  } catch (error) {
    console.error('Gagal memuat temuan.', error)
    errorMessage.value = 'Gagal memuat temuan.'
  } finally {
    isLoading.value = false
  }
}

function severityLabel(severity: string) {
  return (
    {
      critical: 'Kritis',
      high: 'Tinggi',
      medium: 'Sedang',
      low: 'Rendah',
      informational: 'Informasi',
    }[severity] ?? severity
  )
}

function statusLabel(status: string) {
  return (
    {
      open: 'Terbuka',
      reviewing: 'Ditinjau',
      in_progress: 'Dalam Proses',
      accepted: 'Diterima (Risk Accepted)',
      false_positive: 'False Positive',
      resolved: 'Terselesaikan',
      fixed: 'Selesai',
    }[status] ?? status
  )
}

function statusClass(status: string) {
  return (
    {
      open: 'is-danger',
      reviewing: 'is-info',
      in_progress: 'is-info',
      accepted: 'is-muted',
      false_positive: 'is-muted',
      resolved: 'is-success',
      fixed: 'is-success',
    }[status] ?? 'is-muted'
  )
}

function engineLabel(engine: string) {
  return (
    {
      semgrep: 'Semgrep (SAST)',
      gitleaks: 'Gitleaks (Secrets)',
      trivy: 'Trivy (Dependencies)',
      osv: 'OSV Scanner (CVE)',
    }[engine] ?? engine
  )
}

function locationLabel(finding: any) {
  const location = finding.file_path ?? finding.endpoint ?? '-'
  if (finding.line_start) return `${location}:${finding.line_start}`
  return location
}

function openTriageModal(finding: any) {
  triageFinding.value = finding
  triageStatus.value = finding.status || 'open'
  triageNotes.value = finding.normalization_metadata?.triage_notes || ''
}

async function submitTriage() {
  if (!triageFinding.value) return
  isSavingTriage.value = true
  errorMessage.value = ''

  try {
    await updateFindingStatus(triageFinding.value.id, {
      status: triageStatus.value,
      resolution_notes: triageNotes.value,
    })
    triageFinding.value.status = triageStatus.value
    if (!triageFinding.value.normalization_metadata) {
      triageFinding.value.normalization_metadata = {}
    }
    triageFinding.value.normalization_metadata.triage_notes = triageNotes.value
    successMessage.value = `Status temuan ${triageFinding.value.code} diperbarui ke ${statusLabel(triageStatus.value)}.`
    setTimeout(() => (successMessage.value = ''), 4000)
    triageFinding.value = null
    await loadTemuan()
  } catch (error) {
    console.error('Gagal memperbarui status temuan.', error)
    errorMessage.value = 'Gagal menyimpan pembaruan triage temuan.'
  } finally {
    isSavingTriage.value = false
  }
}

function exportCsv() {
  exportFindingsToCsv(filteredFindings.value, 'tameng-temuan-keamanan')
}

onMounted(loadTemuan)
</script>

<template>
  <AppShell>
    <template #eyebrow>Triage Studio & Mitigasi Kerentanan</template>
    <template #title>Temuan Keamanan (Findings)</template>

    <!-- Top Severity Metrics -->
    <section class="metrics">
      <div class="stat-card" style="border-top: 3px solid var(--accent-cyan)">
        <span>Total Temuan</span>
        <strong>{{ summary.total }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--severity-critical)">
        <span>Kritis</span>
        <strong style="color: var(--severity-critical)">{{ summary.critical }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--severity-high)">
        <span>Tinggi</span>
        <strong style="color: var(--severity-high)">{{ summary.high }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--severity-medium)">
        <span>Sedang</span>
        <strong style="color: var(--severity-medium)">{{ summary.medium }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--severity-low)">
        <span>Rendah</span>
        <strong style="color: var(--severity-low)">{{ summary.low }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--severity-info)">
        <span>Info</span>
        <strong style="color: var(--severity-info)">{{ summary.informational }}</strong>
      </div>
    </section>

    <div v-if="successMessage" class="status-panel success fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <!-- Main Findings Panel -->
    <section class="panel">
      <div class="panel-heading" style="display: flex; justify-content: space-between; align-items: center;">
        <h2>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          Temuan Ternormalisasi (Deduplicated)
        </h2>
        <div style="display: flex; gap: 8px; align-items: center;">
          <span class="badge">{{ filteredFindings.length }} Total Temuan</span>
          <button class="small-button secondary-button" type="button" @click="exportCsv">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            <span>Ekspor CSV</span>
          </button>
        </div>
      </div>

      <!-- Advanced Filter Toolbar -->
      <div class="table-toolbar">
        <div class="search-input-wrapper">
          <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
          <input v-model="searchQuery" placeholder="Cari judul, file path, CWE, rule ID..." />
        </div>

        <div class="filter-group">
          <!-- Severity Filter -->
          <button class="filter-pill" :class="{ active: filterSeverity === 'all' }" type="button" @click="filterSeverity = 'all'">Semua</button>
          <button class="filter-pill" :class="{ active: filterSeverity === 'critical' }" type="button" @click="filterSeverity = 'critical'">Kritis</button>
          <button class="filter-pill" :class="{ active: filterSeverity === 'high' }" type="button" @click="filterSeverity = 'high'">Tinggi</button>
          <button class="filter-pill" :class="{ active: filterSeverity === 'medium' }" type="button" @click="filterSeverity = 'medium'">Sedang</button>
          <button class="filter-pill" :class="{ active: filterSeverity === 'low' }" type="button" @click="filterSeverity = 'low'">Rendah</button>
        </div>

        <div class="filter-group">
          <!-- Status Filter -->
          <select v-model="filterStatus" style="width: auto; min-height: 36px; padding: 6px 12px; font-size: 12px;">
            <option value="all">Semua Status</option>
            <option value="open">Terbuka</option>
            <option value="reviewing">Ditinjau</option>
            <option value="in_progress">Dalam Proses</option>
            <option value="accepted">Diterima</option>
            <option value="false_positive">False Positive</option>
            <option value="resolved">Terselesaikan</option>
          </select>

          <!-- Engine Filter -->
          <select v-model="filterEngine" style="width: auto; min-height: 36px; padding: 6px 12px; font-size: 12px;">
            <option value="all">Semua Engine</option>
            <option value="gitleaks">Gitleaks</option>
            <option value="semgrep">Semgrep</option>
            <option value="trivy">Trivy</option>
            <option value="osv">OSV Scanner</option>
          </select>
        </div>
      </div>

      <!-- Data Table -->
      <div class="table-responsive">
        <table class="data-table">
          <thead>
            <tr>
              <th>Kode</th>
              <th>Risiko</th>
              <th>Judul Kerentanan</th>
              <th>Engine</th>
              <th>Proyek</th>
              <th>Lokasi File / Endpoint</th>
              <th>Status Triage</th>
              <th>Aksi & Panduan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="finding in paginatedFindings" :key="finding.id">
              <td class="code-text" style="font-size: 12px; color: var(--tameng-sapphire);">{{ finding.code }}</td>
              <td><span class="badge" :class="finding.severity">{{ severityLabel(finding.severity) }}</span></td>
              <td>
                <div style="font-weight: 700; color: var(--text-main);">{{ finding.title }}</div>
                <small v-if="finding.rule_id" style="color: var(--text-dim); font-family: var(--font-mono); font-size: 11px;">
                  {{ finding.rule_id }}
                </small>
              </td>
              <td><span class="run-pill">{{ finding.engine_key }}</span></td>
              <td>{{ finding.project?.code ?? '-' }}</td>
              <td class="code-text" style="font-size: 12px; max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                {{ locationLabel(finding) }}
              </td>
              <td>
                <span class="badge" :class="statusClass(finding.status)">{{ statusLabel(finding.status) }}</span>
                <div v-if="finding.normalization_metadata?.triage_notes" style="font-size: 10.5px; color: var(--text-muted); margin-top: 2px;">
                  💬 {{ finding.normalization_metadata.triage_notes }}
                </div>
              </td>
              <td class="table-actions">
                <button class="small-button secondary-button" type="button" @click="selectedFinding = finding">
                  Detail
                </button>

                <button
                  v-if="canTriage"
                  class="small-button secondary-button"
                  type="button"
                  @click="openTriageModal(finding)"
                >
                  Triage
                </button>
              </td>
            </tr>
            <tr v-if="!filteredFindings.length && !isLoading">
              <td colspan="8" style="text-align: center; color: var(--text-dim); padding: 32px;">
                Tidak ada temuan yang cocok dengan kriteria pencarian atau filter.
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination Component -->
      <TamengPagination
        v-model:currentPage="currentPage"
        v-model:pageSize="pageSize"
        :total-items="filteredFindings.length"
        :page-size-options="[10, 20, 50, 100]"
      />
    </section>

    <!-- Finding Detail Modal -->
    <div v-if="selectedFinding" class="modal-backdrop fade-in" @click.self="selectedFinding = null">
      <div class="modal-dialog">
        <div class="modal-header">
          <div style="display: flex; align-items: center; gap: 8px;">
            <span class="badge" :class="selectedFinding.severity">{{ severityLabel(selectedFinding.severity) }}</span>
            <h3 style="font-size: 16px; color: var(--tameng-navy);">{{ selectedFinding.title }}</h3>
          </div>
          <button class="small-button secondary-button" type="button" @click="selectedFinding = null">✕</button>
        </div>

        <div class="modal-body">
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px; background: var(--bg-surface-elevated); padding: 12px 14px; border-radius: 8px; border: 1px solid var(--border-subtle);">
            <div><span style="font-size: 11px; color: var(--text-dim)">Kode:</span> <strong class="code-text" style="display: block; color: var(--tameng-sapphire)">{{ selectedFinding.code }}</strong></div>
            <div><span style="font-size: 11px; color: var(--text-dim)">Engine:</span> <strong style="display: block; color: var(--text-main)">{{ engineLabel(selectedFinding.engine_key) }}</strong></div>
            <div><span style="font-size: 11px; color: var(--text-dim)">Rule ID:</span> <strong class="code-text" style="display: block; font-size: 12px; color: var(--text-main)">{{ selectedFinding.rule_id ?? '-' }}</strong></div>
            <div><span style="font-size: 11px; color: var(--text-dim)">Status:</span> <strong style="display: block; color: var(--text-main);">{{ statusLabel(selectedFinding.status) }}</strong></div>
          </div>

          <h4 style="font-size: 13px; font-weight: 700; color: var(--tameng-navy); margin-bottom: 6px;">Lokasi File / Target</h4>
          <div class="code-snippet" style="margin-bottom: 16px;">
            {{ locationLabel(selectedFinding) }}
          </div>

          <h4 style="font-size: 13px; font-weight: 700; color: var(--tameng-navy); margin-bottom: 6px;">Bukti Kerentanan & Detail</h4>
          <div class="code-snippet" style="margin-bottom: 16px; white-space: pre-wrap; font-size: 12px;">
            {{ selectedFinding.evidences?.[0]?.content ?? selectedFinding.evidence ?? 'Bukti teridentifikasi oleh scanner engine terisolasi.' }}
          </div>

          <div v-if="selectedFinding.normalization_metadata?.triage_notes" class="inline-alert" style="margin-bottom: 16px; background: #f8fafc; border-left: 3px solid var(--tameng-sapphire);">
            <strong>Catatan Triage:</strong> {{ selectedFinding.normalization_metadata.triage_notes }}
            <div style="font-size: 11px; color: var(--text-dim); margin-top: 2px;">
              Oleh: {{ selectedFinding.normalization_metadata.triaged_by ?? 'Security Team' }}
            </div>
          </div>

          <div v-if="selectedFinding.cwe || selectedFinding.owasp" style="display: flex; gap: 8px; flex-wrap: wrap;">
            <span v-if="selectedFinding.cwe" class="run-pill">CWE: {{ selectedFinding.cwe }}</span>
            <span v-if="selectedFinding.owasp" class="run-pill">OWASP: {{ selectedFinding.owasp }}</span>
          </div>
        </div>

        <div class="modal-footer" style="display: flex; justify-content: flex-end;">
          <button class="btn btn-secondary" type="button" @click="selectedFinding = null">Tutup</button>
        </div>
      </div>
    </div>

    <!-- Triage Status Modal -->
    <div v-if="triageFinding" class="modal-backdrop fade-in" @click.self="triageFinding = null">
      <div class="modal-dialog">
        <div class="modal-header">
          <h3 style="font-size: 15px; color: var(--tameng-navy);">Triage Status Temuan: {{ triageFinding.code }}</h3>
          <button class="small-button secondary-button" type="button" @click="triageFinding = null">✕</button>
        </div>

        <form @submit.prevent="submitTriage">
          <div class="modal-body">
            <label style="display: block; margin-bottom: 12px;">
              <span style="font-size: 12px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 4px;">Status Baru:</span>
              <select v-model="triageStatus" required style="width: 100%; min-height: 38px;">
                <option value="open">Terbuka (Open)</option>
                <option value="reviewing">Sedang Ditinjau (Reviewing)</option>
                <option value="in_progress">Dalam Proses Perbaikan (In Progress)</option>
                <option value="resolved">Terselesaikan (Resolved)</option>
                <option value="accepted">Diterima Sebagai Risiko (Risk Accepted)</option>
                <option value="false_positive">Bukan Kerentanan (False Positive)</option>
              </select>
            </label>

            <label style="display: block;">
              <span style="font-size: 12px; font-weight: 600; color: var(--text-main); display: block; margin-bottom: 4px;">Catatan Mitigasi / Alasan:</span>
              <textarea
                v-model="triageNotes"
                placeholder="Tuliskan catatan analisis, alasan false positive, atau link PR perbaikan..."
                rows="4"
                style="width: 100%; font-size: 12.5px;"
              ></textarea>
            </label>
          </div>

          <div class="modal-footer">
            <button class="btn btn-primary-glow" type="submit" :disabled="isSavingTriage">
              {{ isSavingTriage ? 'Menyimpan...' : 'Simpan Triage' }}
            </button>
            <button class="btn btn-secondary" type="button" @click="triageFinding = null">Batal</button>
          </div>
        </form>
      </div>
    </div>
  </AppShell>
</template>
