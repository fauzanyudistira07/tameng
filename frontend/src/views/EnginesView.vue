<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import AppShell from '../components/AppShell.vue'
import {
  checkSecurityEngineHealth,
  getScanProfiles,
  getSecurityEngines,
  toggleSecurityEngine,
} from '../services/securityEngineService'
import { authState } from '../stores/authStore'

const engines = ref<any[]>([])
const scanProfiles = ref<any[]>([])
const selectedEngine = ref<any>(null)
const isLoading = ref(true)
const isCheckingHealth = ref<Record<number, boolean>>({})
const isToggling = ref<Record<number, boolean>>({})
const errorMessage = ref('')
const successMessage = ref('')

const selectedDomain = ref('ALL')
const selectedResourceClass = ref('ALL')
const searchQuery = ref('')

const domains = [
  { key: 'ALL', label: 'Semua' },
  { key: 'SOURCE_CODE', label: 'Source Code (SAST)' },
  { key: 'SECRET', label: 'Secrets' },
  { key: 'DEPENDENCY', label: 'Dependency (SCA)' },
  { key: 'SBOM', label: 'SBOM' },
  { key: 'CONTAINER', label: 'Container' },
  { key: 'IAC', label: 'IaC & K8s' },
  { key: 'WEB', label: 'Web (DAST)' },
  { key: 'API', label: 'API' },
  { key: 'MOBILE', label: 'Mobile' },
  { key: 'TLS', label: 'TLS' },
]

const resourceClasses = [
  { key: 'ALL', label: 'Semua Kelas' },
  { key: 'LIGHT', label: 'LIGHT' },
  { key: 'MEDIUM', label: 'MEDIUM' },
  { key: 'HEAVY', label: 'HEAVY' },
]

const summary = computed(() => {
  const list = Array.isArray(engines.value) ? engines.value : []
  const total = list.length
  const active = list.filter((e) => Boolean(e?.enabled)).length
  const disabled = list.filter((e) => !e?.enabled).length
  return { total, active, disabled }
})

const filteredEngines = computed(() => {
  const list = Array.isArray(engines.value) ? engines.value : []
  return list.filter((engine) => {
    if (!engine) return false
    if (selectedDomain.value !== 'ALL' && engine.domain !== selectedDomain.value) {
      return false
    }
    if (selectedResourceClass.value !== 'ALL' && engine.resource_class !== selectedResourceClass.value) {
      return false
    }
    if (searchQuery.value) {
      const q = searchQuery.value.toLowerCase()
      return (
        (engine.name && engine.name.toLowerCase().includes(q)) ||
        (engine.code && engine.code.toLowerCase().includes(q)) ||
        (engine.container_image && engine.container_image.toLowerCase().includes(q)) ||
        (engine.description && engine.description.toLowerCase().includes(q))
      )
    }
    return true
  })
})

const activeProfilesForSelected = computed(() => {
  if (!selectedEngine.value || !Array.isArray(scanProfiles.value)) return []
  return scanProfiles.value.filter((p) =>
    Array.isArray(p?.engines) && p.engines.some((e: any) => e?.code === selectedEngine.value?.code),
  )
})

const canManageEngines = computed(() =>
  ['super_admin', 'security_admin'].includes(authState.user?.role?.name),
)

async function loadData() {
  if (!engines.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''

  try {
    const [engineRes, profiles] = await Promise.all([
      getSecurityEngines(),
      getScanProfiles(),
    ])
    engines.value = Array.isArray(engineRes?.data) ? engineRes.data : Array.isArray(engineRes) ? engineRes : []
    scanProfiles.value = Array.isArray(profiles) ? profiles : []
    if (!selectedEngine.value && engines.value.length > 0) {
      selectedEngine.value = engines.value.find((e: any) => e?.enabled) || engines.value[0]
    }
  } catch (error) {
    console.error('Gagal memuat katalog engine.', error)
    errorMessage.value = 'Gagal memuat katalog security engines.'
  } finally {
    isLoading.value = false
  }
}

async function toggleEngine(engine: any) {
  if (!canManageEngines.value || !engine?.id) return
  isToggling.value[engine.id] = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const res = await toggleSecurityEngine(engine.id)
    engine.enabled = res.data.enabled
    engine.status = res.data.status
    successMessage.value = res.message
    setTimeout(() => (successMessage.value = ''), 4000)
  } catch (error) {
    console.error('Gagal toggle engine.', error)
    errorMessage.value = `Gagal mengubah status engine ${engine.name}.`
  } finally {
    isToggling.value[engine.id] = false
  }
}

async function performHealthCheck(engine: any) {
  if (!engine?.id) return
  isCheckingHealth.value[engine.id] = true
  errorMessage.value = ''
  successMessage.value = ''

  try {
    const res = await checkSecurityEngineHealth(engine.id)
    engine.status = res.data.status
    engine.last_health_check = res.data.last_health_check
    successMessage.value = res.message
    setTimeout(() => (successMessage.value = ''), 4000)
  } catch (error) {
    console.error('Gagal cek health engine.', error)
    errorMessage.value = `Gagal memeriksa health container engine ${engine.name}.`
  } finally {
    isCheckingHealth.value[engine.id] = false
  }
}

function selectEngine(engine: any) {
  selectedEngine.value = engine
}

onMounted(loadData)
</script>

<template>
  <AppShell>
    <template #eyebrow>Modular Engine Layer & Execution Controls</template>
    <template #title>Security Engines Registry</template>

    <!-- Top Metrics Overview -->
    <section class="metrics">
      <div class="stat-card">
        <span>Total Terdaftar</span>
        <strong>{{ summary.total }}</strong>
      </div>
      <div class="stat-card">
        <span>Aktif & Siap Eksekusi</span>
        <strong style="color: var(--status-success)">{{ summary.active }}</strong>
      </div>
      <div class="stat-card">
        <span>Nonaktif (Pending Adapter)</span>
        <strong style="color: var(--text-dim)">{{ summary.disabled }}</strong>
      </div>
    </section>

    <!-- Alert Notifications -->
    <div v-if="successMessage" class="status-panel success fade-in">
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <span>{{ errorMessage }}</span>
    </div>

    <!-- Filter Toolbar -->
    <div class="panel" style="padding: 16px 20px; margin-bottom: 20px;">
      <div class="table-toolbar" style="margin-bottom: 12px;">
        <div class="search-input-wrapper" style="flex: 1; max-width: 380px;">
          <input v-model="searchQuery" type="text" placeholder="Cari nama engine, kode, atau container image..." />
        </div>

        <!-- Resource Class Filter Pills -->
        <div class="filter-group">
          <button
            v-for="rc in resourceClasses"
            :key="rc.key"
            type="button"
            class="filter-pill"
            :class="{ active: selectedResourceClass === rc.key }"
            @click="selectedResourceClass = rc.key"
          >
            {{ rc.label }}
          </button>
        </div>
      </div>

      <!-- Domain Navigation Pills -->
      <div style="display: flex; gap: 6px; overflow-x: auto; padding-top: 8px; border-top: 1px solid var(--border-subtle); flex-wrap: wrap;">
        <button
          v-for="dom in domains"
          :key="dom.key"
          type="button"
          class="small-button secondary-button"
          :style="{
            background: selectedDomain === dom.key ? 'var(--tameng-sapphire)' : '#ffffff',
            color: selectedDomain === dom.key ? '#ffffff' : 'var(--tameng-navy)',
            borderColor: selectedDomain === dom.key ? 'var(--tameng-sapphire)' : 'var(--border-card)',
            fontWeight: selectedDomain === dom.key ? '700' : '500',
          }"
          @click="selectedDomain = dom.key"
        >
          {{ dom.label }}
        </button>
      </div>
    </div>

    <!-- Main Two-Column View -->
    <section class="two-column">
      <!-- Left: Engine Cards Grid -->
      <div class="panel">
        <div class="panel-heading">
          <h2>Daftar Engine</h2>
          <span class="badge">{{ filteredEngines.length }} Engine</span>
        </div>

        <div class="card-grid" style="max-height: 680px; overflow-y: auto; padding-right: 4px;">
          <article
            v-for="engine in filteredEngines"
            :key="engine.id"
            class="entity-card"
            :style="{
              borderLeft: selectedEngine?.id === engine.id ? '4px solid var(--tameng-sapphire)' : undefined,
              background: selectedEngine?.id === engine.id ? '#f0f9ff' : undefined,
              opacity: engine.enabled ? '1' : '0.65',
              cursor: 'pointer'
            }"
            @click="selectEngine(engine)"
          >
            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px;">
              <div>
                <div style="display: flex; align-items: center; gap: 8px;">
                  <h3 style="margin: 0; font-size: 15px;">{{ engine.name }}</h3>
                  <span class="code-text" style="font-size: 11px; color: var(--text-dim);">({{ engine.code }})</span>
                </div>
                <div style="margin-top: 4px; display: flex; gap: 6px; flex-wrap: wrap;">
                  <span class="badge" style="background: #e0f2fe; color: #0369a1; border-color: #bae6fd;">
                    {{ engine.domain }}
                  </span>
                  <span class="badge">
                    {{ engine.resource_class }}
                  </span>
                </div>
              </div>

              <!-- Live Status & Switch -->
              <div style="display: flex; align-items: center; gap: 8px;">
                <span class="badge" :class="engine.enabled ? 'is-success' : 'is-muted'">
                  {{ engine.status }}
                </span>
                <button
                  v-if="canManageEngines"
                  class="btn-sm"
                  :class="engine.enabled ? 'secondary-button' : 'btn-primary-glow'"
                  type="button"
                  :disabled="isToggling[engine.id]"
                  style="font-size: 11px; padding: 0 10px; min-height: 28px;"
                  @click.stop="toggleEngine(engine)"
                >
                  {{ engine.enabled ? 'Nonaktifkan' : 'Aktifkan' }}
                </button>
              </div>
            </div>

            <p style="font-size: 12.5px; color: var(--text-muted); margin: 6px 0; line-height: 1.4;">
              {{ engine.description }}
            </p>

            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; color: var(--text-dim); border-top: 1px solid var(--border-subtle); padding-top: 8px;">
              <span class="code-text" style="color: var(--tameng-sapphire);">
                {{ engine.container_image }}
              </span>
              <span>{{ engine.cpu_limit }} CPU | {{ engine.memory_limit_mb }} MB</span>
            </div>
          </article>

          <div v-if="!filteredEngines.length && !isLoading" class="status-panel">
            Tidak ada engine yang cocok dengan kriteria filter.
          </div>
        </div>
      </div>

      <!-- Right: Selected Engine Detailed Inspector -->
      <div v-if="selectedEngine" class="panel">
        <div class="panel-heading">
          <div>
            <h2>Detail Engine</h2>
            <span style="font-size: 12px; color: var(--text-muted); display: block; margin-top: 2px;">
              {{ selectedEngine.name }} (v{{ selectedEngine.version }})
            </span>
          </div>

          <div class="table-actions">
            <button
              class="btn-sm secondary-button"
              type="button"
              :disabled="isCheckingHealth[selectedEngine.id]"
              @click="performHealthCheck(selectedEngine)"
            >
              {{ isCheckingHealth[selectedEngine.id] ? 'Memeriksa...' : 'Cek Status Image' }}
            </button>
          </div>
        </div>

        <div style="display: flex; flex-direction: column; gap: 16px;">
          <!-- Specifications Card -->
          <div class="entity-card">
            <h4 style="font-size: 13.5px; font-weight: 700; color: var(--tameng-navy);">Spesifikasi Sandbox & Batasan Resource</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 12.5px; margin-top: 6px;">
              <div><strong>Domain:</strong> {{ selectedEngine.domain }}</div>
              <div><strong>Kategori:</strong> {{ selectedEngine.category }}</div>
              <div><strong>Resource Class:</strong> <span class="badge">{{ selectedEngine.resource_class }}</span></div>
              <div><strong>Batas Waktu:</strong> {{ selectedEngine.default_timeout }} Detik</div>
              <div><strong>Batas CPU:</strong> {{ selectedEngine.cpu_limit }} Cores</div>
              <div><strong>Batas RAM:</strong> {{ selectedEngine.memory_limit_mb }} MB</div>
              <div><strong>Adapter Version:</strong> v{{ selectedEngine.adapter_version }}</div>
              <div><strong>Target Tipe:</strong> {{ (selectedEngine.supported_targets || []).join(', ') || 'Semua' }}</div>
            </div>
          </div>

          <!-- Docker Image Spec -->
          <div class="entity-card">
            <h4 style="font-size: 13.5px; font-weight: 700; color: var(--tameng-navy);">Container Image</h4>
            <div style="margin-top: 6px;">
              <span class="code-text" style="font-size: 12px; color: var(--tameng-sapphire); display: block; word-break: break-all;">
                {{ selectedEngine.container_image }}
              </span>
              <span style="font-size: 11.5px; color: var(--text-dim); display: block; margin-top: 4px;">
                Flags: <code>--rm --security-opt=no-new-privileges :ro workspace</code>
              </span>
            </div>
          </div>

          <!-- Active Scan Profiles Featuring this Engine -->
          <div class="entity-card">
            <h4 style="font-size: 13.5px; font-weight: 700; color: var(--tameng-navy);">Profil Pemindaian Terkait</h4>
            <div style="display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px;">
              <span
                v-for="profile in activeProfilesForSelected"
                :key="profile.code"
                class="badge is-info"
              >
                {{ profile.name }}
              </span>
              <span v-if="!activeProfilesForSelected.length" style="font-size: 12px; color: var(--text-dim);">
                Tidak ada profil khusus.
              </span>
            </div>
          </div>

          <!-- JSON Schema Preview -->
          <h4 style="font-size: 13.5px; font-weight: 700; color: var(--tameng-navy); margin-top: 4px;">Metadata Registri JSON</h4>
          <pre class="json-preview" style="max-height: 220px;">{{ JSON.stringify(selectedEngine, null, 2) }}</pre>
        </div>
      </div>
    </section>
  </AppShell>
</template>
