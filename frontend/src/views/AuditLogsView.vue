<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { getAuditLogs } from '../services/auditLogService'

const auditLogs = ref<any[]>([])
const selectedLog = ref<any>(null)
const isLoading = ref(true)
const errorMessage = ref('')
const searchQuery = ref('')
const filterResult = ref('all')

const currentPage = ref(1)
const pageSize = ref(10)

const summary = computed(() => ({
  total: auditLogs.value.length,
  success: auditLogs.value.filter((log) => log.result === 'success').length,
  failed: auditLogs.value.filter((log) => log.result === 'failed').length,
  actions: new Set(auditLogs.value.map((log) => log.action)).size,
}))

const filteredLogs = computed(() => {
  const query = (searchQuery.value || '').trim().toLowerCase()
  return (auditLogs.value || []).filter((log) => {
    const matchesResult = filterResult.value === 'all' || log.result === filterResult.value
    if (!matchesResult) return false

    if (!query) return true
    const action = (log.action ?? '').toLowerCase()
    const userName = (log.user?.name ?? '').toLowerCase()
    const projCode = (log.project?.code ?? log.project?.name ?? '').toLowerCase()
    const ip = (log.actor_ip ?? '').toLowerCase()

    return action.includes(query) || userName.includes(query) || projCode.includes(query) || ip.includes(query)
  })
})

const paginatedLogs = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredLogs.value.slice(start, start + pageSize.value)
})

watch([searchQuery, filterResult], () => {
  currentPage.value = 1
})

const metadataJson = computed(() =>
  selectedLog.value ? JSON.stringify(selectedLog.value.metadata ?? {}, null, 2) : '',
)

async function loadAuditLogs() {
  if (!auditLogs.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''

  try {
    const logs = await getAuditLogs()
    auditLogs.value = logs
    selectedLog.value = logs[0] ?? null
  } catch (error) {
    console.error('Gagal memuat log audit.', error)
    errorMessage.value = 'Gagal memuat catatan log audit forensik.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadAuditLogs)
</script>

<template>
  <AppShell>
    <template #eyebrow>Audit Kepatuhan & Jejak Forensik Sistem</template>
    <template #title>Log Audit Forensik TAMENG</template>

    <!-- Top Metrics -->
    <section class="metrics">
      <div class="stat-card" style="border-top: 3px solid var(--accent-cyan)">
        <span>Total Event</span>
        <strong>{{ summary.total }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--status-success)">
        <span>Aksi Sukses</span>
        <strong style="color: var(--status-success)">{{ summary.success }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--severity-critical)">
        <span>Aksi Gagal</span>
        <strong style="color: var(--severity-critical)">{{ summary.failed }}</strong>
      </div>
      <div class="stat-card" style="border-top: 3px solid var(--tameng-sapphire)">
        <span>Variasi Aksi</span>
        <strong>{{ summary.actions }}</strong>
      </div>
    </section>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <section class="two-column">
      <!-- Left: Audit Logs Table -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 14"/></svg>
            Aktivitas Forensik Terbaru
          </h2>
          <span class="badge">{{ filteredLogs.length }} Event</span>
        </div>

        <div class="table-toolbar">
          <div class="search-input-wrapper">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input v-model="searchQuery" placeholder="Cari nama aksi, pengguna, atau IP..." />
          </div>

          <div class="filter-group">
            <button class="filter-pill" :class="{ active: filterResult === 'all' }" type="button" @click="filterResult = 'all'">Semua</button>
            <button class="filter-pill" :class="{ active: filterResult === 'success' }" type="button" @click="filterResult = 'success'">Sukses</button>
            <button class="filter-pill" :class="{ active: filterResult === 'failed' }" type="button" @click="filterResult = 'failed'">Gagal</button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Waktu</th>
                <th>Aksi</th>
                <th>Hasil</th>
                <th>Pengguna</th>
                <th>IP Actor</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="log in paginatedLogs"
                :key="log.id"
                :style="{ background: selectedLog?.id === log.id ? 'rgba(29, 78, 216, 0.08)' : undefined }"
              >
                <td style="font-size: 11.5px; color: var(--text-muted); font-family: var(--font-mono);">
                  {{ log.created_at }}
                </td>
                <td><strong class="code-text" style="color: var(--text-main); font-size: 12.5px;">{{ log.action }}</strong></td>
                <td>
                  <span class="badge" :class="log.result === 'success' ? 'is-success' : 'is-danger'">
                    {{ log.result }}
                  </span>
                </td>
                <td style="font-size: 12px;">{{ log.user?.name ?? 'System Worker' }}</td>
                <td class="code-text" style="font-size: 11px; color: var(--text-dim);">{{ log.actor_ip ?? '127.0.0.1' }}</td>
                <td class="table-actions">
                  <button class="small-button secondary-button" type="button" @click="selectedLog = log">
                    Detail
                  </button>
                </td>
              </tr>
              <tr v-if="!filteredLogs.length && !isLoading">
                <td colspan="6" style="text-align: center; color: var(--text-dim); padding: 20px;">
                  Tidak ada catatan log audit yang cocok.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination Component -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="filteredLogs.length"
          :page-size-options="[10, 20, 50, 100]"
        />
      </div>

      <!-- Right: Event Detail & JSON Metadata -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            Detail Event Audit #{{ selectedLog?.id ?? '-' }}
          </h2>
          <span class="badge" :class="selectedLog?.result === 'success' ? 'is-success' : 'is-danger'">
            {{ selectedLog?.result ?? '-' }}
          </span>
        </div>

        <div v-if="selectedLog" class="profile-list">
          <article>
            <h3 style="color: var(--tameng-navy);">{{ selectedLog.action }}</h3>
            <p><strong>Timestamp:</strong> {{ selectedLog.created_at }}</p>
            <p><strong>Actor:</strong> {{ selectedLog.user?.name ?? 'System Internal' }} (IP: {{ selectedLog.actor_ip ?? 'localhost' }})</p>
          </article>

          <article v-if="selectedLog.authorization || selectedLog.scan_job">
            <h3 style="color: var(--tameng-navy);">Referensi Objek Terkait</h3>
            <p v-if="selectedLog.authorization">Otorisasi: <strong class="code-text" style="color: var(--tameng-sapphire)">{{ selectedLog.authorization?.code }}</strong></p>
            <p v-if="selectedLog.scan_job">Pekerjaan Scan: <strong class="code-text" style="color: var(--tameng-sapphire)">{{ selectedLog.scan_job?.code }}</strong></p>
          </article>

          <h3 style="font-size: 13.5px; font-weight: 700; color: var(--tameng-navy); margin-top: 8px;">Metadata Payload</h3>
          <pre class="json-preview">{{ metadataJson }}</pre>
        </div>

        <div v-else class="status-panel">Pilih baris log audit untuk melihat detail metadata.</div>
      </div>
    </section>
  </AppShell>
</template>
