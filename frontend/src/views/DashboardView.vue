<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AppShell from '../components/AppShell.vue'
import { getOverview } from '../services/overviewService'

const router = useRouter()

type OverviewCounts = {
  projects: number
  active_scans: number
  critical_findings: number
  high_findings: number
  medium_findings: number
  low_findings: number
}

type ScanProfile = {
  key: string
  name: string
  description: string | null
  engine_keys: string[]
  active_testing: boolean
}

type QueueTelemetry = {
  pending_jobs: number
  failed_jobs: number
  active_scans: number
  status: string
  label: string
}

type SystemOverview = {
  principles: string[]
  counts: OverviewCounts
  queue_telemetry?: QueueTelemetry
  scan_profiles: ScanProfile[]
}

const fallbackCounts: OverviewCounts = {
  projects: 0,
  active_scans: 0,
  critical_findings: 0,
  high_findings: 0,
  medium_findings: 0,
  low_findings: 0,
}

const fallbackQueue: QueueTelemetry = {
  pending_jobs: 0,
  failed_jobs: 0,
  active_scans: 0,
  status: 'ready',
  label: 'Queue Worker Siap',
}

const fallbackPrinciples = [
  'NO VERIFIED TARGET = NO EXECUTION',
  'OUTSIDE SCOPE = DENY',
  'AI IS ADVISORY ONLY',
]

const workflowSteps = [
  { id: 1, title: 'Proyek & Aset', desc: 'Daftarkan repositori atau target Web/API' },
  { id: 2, title: 'Verifikasi Kepemilikan', desc: 'Validasi integritas target & commit' },
  { id: 3, title: 'Aturan Ruang Lingkup', desc: 'Definisikan pola ALLOW / DENY' },
  { id: 4, title: 'Otorisasi Imutabel', desc: 'Snapshot kebijakan & jendela waktu aktif' },
  { id: 5, title: 'Eksekusi Guarded', desc: 'Docker sandbox (--network none, read-only)' },
  { id: 6, title: 'Normalisasi & Laporan', desc: 'Deduplikasi SHA-256 & mitigasi risiko' },
]

const overview = ref<SystemOverview | null>(null)
const isLoading = ref(true)
const errorMessage = ref('')

const counts = computed(() => overview.value?.counts ?? fallbackCounts)
const queue = computed(() => overview.value?.queue_telemetry ?? fallbackQueue)
const principles = computed(() => overview.value?.principles ?? fallbackPrinciples)
const profiles = computed(() => overview.value?.scan_profiles ?? [])

const totalFindings = computed(() => {
  return (
    (counts.value.critical_findings || 0) +
    (counts.value.high_findings || 0) +
    (counts.value.medium_findings || 0) +
    (counts.value.low_findings || 0)
  )
})

const securityScore = computed(() => {
  if (totalFindings.value === 0) return 98
  const deduction =
    (counts.value.critical_findings || 0) * 25 +
    (counts.value.high_findings || 0) * 12 +
    (counts.value.medium_findings || 0) * 4 +
    (counts.value.low_findings || 0) * 1
  return Math.max(15, 100 - deduction)
})

const securityGrade = computed(() => {
  const score = securityScore.value
  if (score >= 90) return { label: 'Optimal (A+)', color: 'var(--status-success)' }
  if (score >= 75) return { label: 'Perlu Perhatian (B)', color: 'var(--severity-medium)' }
  if (score >= 50) return { label: 'Rentan (C)', color: 'var(--severity-high)' }
  return { label: 'Kritis (F)', color: 'var(--severity-critical)' }
})

onMounted(async () => {
  try {
    overview.value = await getOverview()
  } catch (error) {
    console.error('Gagal memuat ringkasan sistem.', error)
    errorMessage.value = 'Gagal memuat ringkasan sistem. Pastikan koneksi backend aktif.'
  } finally {
    isLoading.value = false
  }
})
</script>

<template>
  <AppShell>
    <template #eyebrow>Security Operations Center & Control Plane</template>
    <template #title>Ringkasan Postur Keamanan TAMENG</template>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <!-- Top Key Metrics Cards -->
    <section class="metrics">
      <div class="stat-card" style="border-top: 3px solid var(--accent-cyan)">
        <span>Proyek Terdaftar</span>
        <strong>{{ counts.projects }}</strong>
        <div style="font-size: 11px; color: var(--text-dim); margin-top: 4px;">Aset & Repositori Aktif</div>
      </div>

      <div class="stat-card" style="border-top: 3px solid var(--tameng-sapphire)">
        <span>Scan Aktif / Berjalan</span>
        <div style="display: flex; align-items: baseline; gap: 8px;">
          <strong>{{ counts.active_scans }}</strong>
          <span v-if="counts.active_scans > 0" class="pulse-dot" style="background: var(--accent-cyan)"></span>
        </div>
        <div style="font-size: 11px; color: var(--text-dim); margin-top: 4px;">Dalam Antrean / Engine Run</div>
      </div>

      <div class="stat-card" style="border-top: 3px solid var(--severity-critical)">
        <span>Temuan Kritis</span>
        <strong style="color: var(--severity-critical)">{{ counts.critical_findings }}</strong>
        <div style="font-size: 11px; color: var(--severity-critical); margin-top: 4px;">Perlu Remedi Segera</div>
      </div>

      <div class="stat-card" style="border-top: 3px solid var(--severity-high)">
        <span>Temuan Tinggi</span>
        <strong style="color: var(--severity-high)">{{ counts.high_findings }}</strong>
        <div style="font-size: 11px; color: var(--severity-high); margin-top: 4px;">Risiko Signifikan</div>
      </div>

      <div class="stat-card" style="border-top: 3px solid var(--severity-medium)">
        <span>Temuan Sedang</span>
        <strong style="color: var(--severity-medium)">{{ counts.medium_findings }}</strong>
        <div style="font-size: 11px; color: var(--text-dim); margin-top: 4px;">Kerentanan Moderat</div>
      </div>

      <div class="stat-card" style="border-top: 3px solid var(--severity-low)">
        <span>Temuan Rendah</span>
        <strong style="color: var(--severity-low)">{{ counts.low_findings }}</strong>
        <div style="font-size: 11px; color: var(--text-dim); margin-top: 4px;">Minor / Best Practice</div>
      </div>
    </section>

    <!-- Posture Gauge & Severity Distribution -->
    <section class="two-column">
      <!-- Posture Score & Guardrails -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Postur Keamanan & Guardrails
          </h2>
          <span class="badge is-success">Deterministik Enforced</span>
        </div>

        <div style="display: grid; grid-template-columns: 140px 1fr; gap: 24px; align-items: center; margin-bottom: 24px; padding: 16px; background: var(--bg-surface-elevated); border-radius: 10px; border: 1px solid var(--border-subtle);">
          <div style="text-align: center; border-right: 1px solid var(--border-subtle); padding-right: 16px;">
            <div style="font-size: 38px; font-weight: 800; font-family: var(--font-mono); color: var(--text-main);">
              {{ securityScore }}<span style="font-size: 16px; color: var(--text-dim)">/100</span>
            </div>
            <span class="badge" :style="{ borderColor: securityGrade.color, color: securityGrade.color, background: 'var(--bg-surface)' }">
              {{ securityGrade.label }}
            </span>
          </div>

          <div>
            <h4 style="font-size: 14px; font-weight: 700; color: var(--text-main); margin-bottom: 6px;">Ringkasan Status Temuan</h4>
            <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 10px;">
              Total <strong>{{ totalFindings }}</strong> temuan aktif teridentifikasi di seluruh repositori dan target yang dipindai.
            </p>
            <!-- Distribution Progress Bar -->
            <div style="display: flex; height: 10px; border-radius: 9999px; overflow: hidden; background: #dbe6f5; border: 1px solid var(--border-subtle);">
              <div :style="{ width: `${totalFindings ? (counts.critical_findings / totalFindings) * 100 : 0}%`, background: 'var(--severity-critical)' }" title="Kritis"></div>
              <div :style="{ width: `${totalFindings ? (counts.high_findings / totalFindings) * 100 : 0}%`, background: 'var(--severity-high)' }" title="Tinggi"></div>
              <div :style="{ width: `${totalFindings ? (counts.medium_findings / totalFindings) * 100 : 0}%`, background: 'var(--severity-medium)' }" title="Sedang"></div>
              <div :style="{ width: `${totalFindings ? (counts.low_findings / totalFindings) * 100 : 0}%`, background: 'var(--severity-low)' }" title="Rendah"></div>
            </div>
          </div>
        </div>

        <!-- Queue Engine Health Telemetry -->
        <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: #f8fafc; border-radius: 8px; border: 1px solid var(--border-subtle); margin-bottom: 20px;">
          <div style="display: flex; align-items: center; gap: 10px;">
            <span class="pulse-dot" :style="{ background: queue.status === 'warning' ? 'var(--severity-critical)' : (queue.status === 'busy' ? 'var(--accent-cyan)' : 'var(--status-success)') }"></span>
            <div>
              <div style="font-size: 12.5px; font-weight: 700; color: var(--text-main);">Status Antrean Queue: {{ queue.label }}</div>
              <div style="font-size: 11px; color: var(--text-dim);">{{ queue.pending_jobs }} pending | {{ queue.failed_jobs }} failed | {{ queue.active_scans }} scan aktif</div>
            </div>
          </div>
          <span class="badge" :class="queue.status === 'warning' ? 'is-danger' : (queue.status === 'busy' ? 'is-info' : 'is-success')">
            {{ queue.status.toUpperCase() }}
          </span>
        </div>

        <h3 style="font-size: 13.5px; font-weight: 700; color: var(--text-main); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">
          Prinsip Eksekusi Utama (Immutable Policies)
        </h3>

        <div style="display: grid; gap: 8px;">
          <div v-for="principle in principles" :key="principle" style="display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: var(--bg-surface-elevated); border-radius: 8px; border: 1px solid var(--border-subtle);">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--tameng-sapphire)" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span style="font-size: 12.5px; font-family: var(--font-mono); font-weight: 600; color: var(--text-main);">{{ principle }}</span>
          </div>
        </div>
      </div>

      <!-- Scan Profiles Active Matrix -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="4" width="16" height="16" rx="2"/><line x1="9" y1="9" x2="15" y2="9"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="11" y2="17"/></svg>
            Profil Pemindaian Aktif
          </h2>
          <span class="badge">{{ profiles.length }} Profil</span>
        </div>

        <div class="profile-list">
          <article v-for="profile in profiles" :key="profile.key">
            <h3>
              <span>{{ profile.name }}</span>
              <span class="badge" :class="profile.active_testing ? 'is-danger' : 'is-success'">
                {{ profile.active_testing ? 'Active Testing' : 'Passive Scan' }}
              </span>
            </h3>
            <p>{{ profile.description }}</p>
            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px;">
              <span v-for="engine in profile.engine_keys" :key="engine" class="run-pill">
                {{ engine }}
              </span>
            </div>
          </article>
        </div>
      </div>
    </section>

    <!-- Deterministic Workflow Pipeline -->
    <section class="panel">
      <div class="panel-heading">
        <h2>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg>
          Alur Kerja Pemindaian Deterministik
        </h2>
        <span>Zero-Trust Execution Pipeline</span>
      </div>

      <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
        <div v-for="step in workflowSteps" :key="step.id" class="entity-card" style="position: relative;">
          <div style="display: flex; align-items: center; justify-content: space-between;">
            <span style="width: 24px; height: 24px; border-radius: 50%; background: var(--tameng-sapphire); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 11px;">
              {{ step.id }}
            </span>
            <span class="badge is-info">Stage {{ step.id }}</span>
          </div>
          <h3 style="font-size: 13.5px; margin-top: 4px;">{{ step.title }}</h3>
          <p style="font-size: 12px;">{{ step.desc }}</p>
        </div>
      </div>
    </section>
  </AppShell>
</template>
