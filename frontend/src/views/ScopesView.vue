<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { getProjects } from '../services/projectService'
import { createScope, getScopes, updateScope } from '../services/scopeService'
import { getTargets } from '../services/targetService'
import { authState } from '../stores/authStore'

const scopes = ref<any[]>([])
const projects = ref<any[]>([])
const targets = ref<any[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const editingId = ref<any>(null)
const searchQuery = ref('')
const testInput = ref('')
const testResult = ref<{ allowed: boolean; matchingRule: any | null } | null>(null)

const currentPage = ref(1)
const pageSize = ref(10)

const form = reactive({
  project_id: '',
  target_id: '',
  type: 'hostname',
  pattern: '',
  effect: 'allow',
  status: 'active',
  reason: '',
})

const canManage = computed(() => ['super_admin', 'security_admin'].includes(authState.user?.role?.name))

const filteredScopes = computed(() => {
  const query = (searchQuery.value || '').trim().toLowerCase()
  return (scopes.value || []).filter((s) => {
    if (!query) return true
    const pattern = (s.pattern ?? '').toLowerCase()
    const projCode = (s.project?.code ?? s.project?.name ?? '').toLowerCase()
    const targetName = (s.target?.name ?? '').toLowerCase()
    const type = (s.type ?? '').toLowerCase()
    return pattern.includes(query) || projCode.includes(query) || targetName.includes(query) || type.includes(query)
  })
})

const paginatedScopes = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredScopes.value.slice(start, start + pageSize.value)
})

watch(searchQuery, () => {
  currentPage.value = 1
})

function resetForm() {
  editingId.value = null
  form.project_id = projects.value[0]?.id ?? ''
  form.target_id = ''
  form.type = 'hostname'
  form.pattern = ''
  form.effect = 'allow'
  form.status = 'active'
  form.reason = ''
}

function editScope(scope: any) {
  editingId.value = scope.id
  form.project_id = scope.project_id
  form.target_id = scope.target_id ?? ''
  form.type = scope.type
  form.pattern = scope.pattern
  form.effect = scope.effect
  form.status = scope.status
  form.reason = scope.reason ?? ''
}

async function loadData() {
  if (!scopes.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''
  try {
    const [projectList, targetList, scopeList] = await Promise.all([getProjects(), getTargets(), getScopes()])
    projects.value = projectList
    targets.value = targetList
    scopes.value = scopeList
    if (!form.project_id) form.project_id = projectList[0]?.id ?? ''
  } catch (error) {
    console.error('Gagal memuat ruang lingkup.', error)
    errorMessage.value = 'Gagal memuat daftar ruang lingkup.'
  } finally {
    isLoading.value = false
  }
}

async function submitScope() {
  isSaving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  const payload = { ...form, target_id: form.target_id || null, reason: form.reason || null }
  try {
    if (editingId.value) {
      await updateScope(editingId.value, payload)
      successMessage.value = 'Aturan ruang lingkup berhasil diperbarui!'
    } else {
      await createScope(payload)
      successMessage.value = 'Aturan ruang lingkup baru berhasil dibuat!'
    }
    resetForm()
    await loadData()
  } catch (error) {
    console.error('Gagal menyimpan ruang lingkup.', error)
    errorMessage.value = 'Gagal menyimpan ruang lingkup. Pastikan seluruh field wajib terisi.'
  } finally {
    isSaving.value = false
  }
}

function simulateTest() {
  if (!testInput.value) {
    testResult.value = null
    return
  }
  const match = scopes.value.find((s) => {
    if (s.status !== 'active') return false
    return testInput.value.includes(s.pattern) || s.pattern.includes(testInput.value)
  })
  if (match) {
    testResult.value = {
      allowed: match.effect === 'allow',
      matchingRule: match,
    }
  } else {
    testResult.value = {
      allowed: false,
      matchingRule: null,
    }
  }
}

onMounted(loadData)
</script>

<template>
  <AppShell>
    <template #eyebrow>Kebijakan Eksekusi & Batasan Batas Akses</template>
    <template #title>Ruang Lingkup (Scope Rules)</template>

    <div v-if="successMessage" class="status-panel success fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <section class="two-column">
      <!-- Left: Scopes List -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            Aturan Ruang Lingkup
          </h2>
          <span class="badge">{{ filteredScopes.length }} Aturan Aktif</span>
        </div>

        <div class="table-toolbar">
          <div class="search-input-wrapper">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input v-model="searchQuery" placeholder="Cari pola, proyek, atau tipe scope..." />
          </div>
        </div>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Efek</th>
                <th>Pola Target</th>
                <th>Tipe</th>
                <th>Proyek</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="scope in paginatedScopes" :key="scope.id">
                <td>
                  <span class="badge" :class="scope.effect === 'allow' ? 'is-success' : 'is-danger'">
                    {{ scope.effect === 'allow' ? '✓ ALLOW' : '✕ DENY' }}
                  </span>
                </td>
                <td>
                  <strong class="code-text" style="color: var(--text-main); font-size: 12.5px;">{{ scope.pattern }}</strong>
                  <div v-if="scope.reason" style="font-size: 11px; color: var(--text-dim); margin-top: 2px;">
                    {{ scope.reason }}
                  </div>
                </td>
                <td><span class="run-pill" style="font-size: 11px;">{{ scope.type }}</span></td>
                <td><span class="code-text" style="font-size: 12px;">{{ scope.project?.code }}</span></td>
                <td>
                  <span class="badge" :class="scope.status === 'active' ? 'is-success' : 'is-muted'">
                    {{ scope.status }}
                  </span>
                </td>
                <td class="table-actions">
                  <button class="small-button secondary-button" :disabled="!canManage" @click="editScope(scope)">
                    Edit
                  </button>
                </td>
              </tr>
              <tr v-if="!filteredScopes.length && !isLoading">
                <td colspan="6" style="text-align: center; color: var(--text-dim); padding: 20px;">
                  Belum ada aturan ruang lingkup.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="filteredScopes.length"
          :page-size-options="[5, 10, 20, 50]"
        />
      </div>

      <!-- Right: Form -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"/></svg>
            {{ editingId ? 'Ubah Aturan Scope' : 'Tambah Aturan Ruang Lingkup' }}
          </h2>
          <span class="badge is-info">Wajib Sebelum Otorisasi</span>
        </div>

        <form class="entity-form" @submit.prevent="submitScope">
          <label>
            <span>Proyek Induk</span>
            <select v-model="form.project_id" :disabled="!canManage" required>
              <option v-for="project in projects" :key="project.id" :value="project.id">
                {{ project.code }} - {{ project.name }}
              </option>
            </select>
          </label>

          <label>
            <span>Target Terkait (Opsional)</span>
            <select v-model="form.target_id" :disabled="!canManage">
              <option value="">Berlaku untuk seluruh aset proyek</option>
              <option v-for="target in targets" :key="target.id" :value="target.id">
                {{ target.name }} ({{ target.type }})
              </option>
            </select>
          </label>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <label>
              <span>Tipe Pola</span>
              <select v-model="form.type" :disabled="!canManage">
                <option value="hostname">Hostname</option>
                <option value="url">Full URL</option>
                <option value="path">Path URI</option>
                <option value="api_route">API Route</option>
              </select>
            </label>

            <label>
              <span>Efek Kebijakan</span>
              <select v-model="form.effect" :disabled="!canManage">
                <option value="allow">Izinkan (ALLOW)</option>
                <option value="deny">Tolak (DENY)</option>
              </select>
            </label>
          </div>

          <label>
            <span>Pola String / Regex</span>
            <input v-model="form.pattern" placeholder="Contoh: https://github.com/my-org/* atau api.domain.com" :disabled="!canManage" required />
          </label>

          <label>
            <span>Alasan / Catatan Kebijakan</span>
            <textarea v-model="form.reason" placeholder="Keterangan batasan domain atau endpoint..." :disabled="!canManage" />
          </label>

          <div class="form-actions">
            <button class="btn btn-primary-glow" type="submit" :disabled="!canManage || isSaving">
              {{ isSaving ? 'Menyimpan...' : 'Simpan Ruang Lingkup' }}
            </button>
            <button class="secondary-button" type="button" @click="resetForm">Reset</button>
          </div>
        </form>
      </div>
    </section>

    <!-- Scope Simulator Tool -->
    <section class="panel">
      <div class="panel-heading">
        <h2>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          Simulator Evaluasi Scope (Zero-Trust Sandbox)
        </h2>
        <span>Uji URL/Endpoint terhadap Aturan Scope Aktif</span>
      </div>

      <div style="display: flex; gap: 12px; align-items: flex-end; max-width: 680px;">
        <label style="flex: 1;">
          <span>Masukkan URL / Hostname Uji Coba:</span>
          <input v-model="testInput" placeholder="Contoh: https://github.com/org/repo atau https://api.domain.com/v1" />
        </label>
        <button class="btn btn-primary-glow" type="button" @click="simulateTest">Uji Evaluasi</button>
      </div>

      <div v-if="testResult !== null" class="fade-in" style="margin-top: 16px;">
        <div class="status-panel" :class="testResult.allowed ? 'success' : 'error'">
          <strong v-if="testResult.allowed">✓ ACCESS ALLOWED:</strong>
          <strong v-else>✕ ACCESS DENIED (Outside Scope):</strong>
          <span v-if="testResult.matchingRule">
            Mencocoki aturan {{ testResult.matchingRule.pattern }} ({{ testResult.matchingRule.effect }}) pada proyek {{ testResult.matchingRule.project?.code }}.
          </span>
          <span v-else>
            Tidak ada aturan ALLOW aktif yang mencocoki target ini. Sesuai prinsip *Outside Scope = Deny*, pemindaian akan ditolak.
          </span>
        </div>
      </div>
    </section>
  </AppShell>
</template>
