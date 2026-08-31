<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { createAuthorization, getAuthorizations, getScanProfiles } from '../services/authorizationService'
import { getProjects } from '../services/projectService'
import { getRepositories } from '../services/repositoryService'
import { getTargets } from '../services/targetService'
import { authState } from '../stores/authStore'

const authorizations = ref<any[]>([])
const projects = ref<any[]>([])
const repositories = ref<any[]>([])
const targets = ref<any[]>([])
const scanProfiles = ref<any[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const searchQuery = ref('')

const currentPage = ref(1)
const pageSize = ref(10)

const form = reactive({
  project_id: '',
  repository_id: '',
  target_id: '',
  scan_profile_id: '',
  valid_from: '',
  valid_until: '',
  max_concurrency: 1,
  rate_limit_per_minute: 60,
  status: 'active',
  notes: '',
})

const canManage = computed(() => ['super_admin', 'security_admin'].includes(authState.user?.role?.name))
const verifiedRepositori = computed(() => repositories.value.filter((item) => item.verification_status === 'verified'))
const verifiedTarget = computed(() => targets.value.filter((item) => item.verification_status === 'verified'))

const filteredAuthorizations = computed(() => {
  const query = (searchQuery.value || '').trim().toLowerCase()
  return (authorizations.value || []).filter((a) => {
    if (!query) return true
    const code = (a.code ?? '').toLowerCase()
    const projCode = (a.project?.code ?? a.project?.name ?? '').toLowerCase()
    const repoName = (a.repository?.name ?? '').toLowerCase()
    const targetName = (a.target?.name ?? '').toLowerCase()
    return code.includes(query) || projCode.includes(query) || repoName.includes(query) || targetName.includes(query)
  })
})

const paginatedAuthorizations = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredAuthorizations.value.slice(start, start + pageSize.value)
})

watch(searchQuery, () => {
  currentPage.value = 1
})

function resetForm() {
  form.project_id = projects.value[0]?.id ?? ''
  form.repository_id = ''
  form.target_id = ''
  form.scan_profile_id = scanProfiles.value[0]?.id ?? ''
  form.valid_from = new Date().toISOString().slice(0, 16)
  form.valid_until = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString().slice(0, 16)
  form.max_concurrency = 1
  form.rate_limit_per_minute = 60
  form.status = 'active'
  form.notes = ''
}

async function loadData() {
  if (!authorizations.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''
  try {
    const [projectList, repositoryList, targetList, profileList, authorizationList] = await Promise.all([
      getProjects(),
      getRepositories(),
      getTargets(),
      getScanProfiles(),
      getAuthorizations(),
    ])
    projects.value = projectList
    repositories.value = repositoryList
    targets.value = targetList
    scanProfiles.value = profileList
    authorizations.value = authorizationList
    if (!form.project_id) resetForm()
  } catch (error) {
    console.error('Gagal memuat otorisasi.', error)
    errorMessage.value = 'Gagal memuat daftar otorisasi.'
  } finally {
    isLoading.value = false
  }
}

async function submitOtorisasi() {
  isSaving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  const payload = {
    ...form,
    repository_id: form.repository_id || null,
    target_id: form.target_id || null,
    notes: form.notes || null,
  }
  try {
    const auth = await createAuthorization(payload)
    successMessage.value = `Otorisasi ${auth.code} berhasil dibuat!`
    resetForm()
    await loadData()
  } catch (error) {
    console.error('Gagal membuat otorisasi.', error)
    errorMessage.value = 'Gagal membuat otorisasi. Pastikan aset terverifikasi dan ada ruang lingkup allow aktif.'
  } finally {
    isSaving.value = false
  }
}

onMounted(loadData)
</script>

<template>
  <AppShell>
    <template #eyebrow>Tata Kelola Keamanan & Izin Eksekusi Imutabel</template>
    <template #title>Otorisasi Pemindaian TAMENG</template>

    <div v-if="successMessage" class="status-panel success fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <section class="two-column">
      <!-- Left: Authorizations List -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-1.5 1.5L14 9l-3-3L8 9 5 6 2 9l3 3-3 3 3 3 3-3 3 3 3-3 3.5-3.5L21 2z"/></svg>
            Sertifikat Otorisasi Aktif
          </h2>
          <span class="badge">{{ filteredAuthorizations.length }} Otorisasi</span>
        </div>

        <div class="table-toolbar">
          <div class="search-input-wrapper">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input v-model="searchQuery" placeholder="Cari kode otorisasi atau proyek..." />
          </div>
        </div>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Kode</th>
                <th>Proyek</th>
                <th>Aset Terverifikasi</th>
                <th>Profil Scan</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="authorization in paginatedAuthorizations" :key="authorization.id">
                <td>
                  <strong class="code-text" style="color: var(--tameng-sapphire); font-size: 12.5px;">{{ authorization.code }}</strong>
                  <div style="font-size: 11px; color: var(--text-dim); margin-top: 2px;">
                    Jendela: {{ authorization.valid_from?.slice(0, 10) }} s/d {{ authorization.valid_until?.slice(0, 10) }}
                  </div>
                </td>
                <td><span class="code-text" style="font-size: 12px;">{{ authorization.project?.code }}</span></td>
                <td>
                  <div style="font-weight: 700; color: var(--text-main);">{{ authorization.repository?.name ?? authorization.target?.name ?? '-' }}</div>
                  <div style="font-size: 11px; color: var(--text-dim);">{{ authorization.repository ? 'Repositori' : 'Target URL' }}</div>
                </td>
                <td><span class="badge is-info">{{ authorization.scan_profile?.name }}</span></td>
                <td>
                  <span class="badge" :class="authorization.status === 'active' ? 'is-success' : 'is-muted'">
                    {{ authorization.status }}
                  </span>
                </td>
              </tr>
              <tr v-if="!filteredAuthorizations.length && !isLoading">
                <td colspan="5" style="text-align: center; color: var(--text-dim); padding: 20px;">
                  Belum ada otorisasi terdaftar.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="filteredAuthorizations.length"
          :page-size-options="[5, 10, 20, 50]"
        />
      </div>

      <!-- Right: Form -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"/></svg>
            Buat Otorisasi Baru
          </h2>
          <span class="badge is-info">Immutable Policy Decision</span>
        </div>

        <form class="entity-form" @submit.prevent="submitOtorisasi">
          <label>
            <span>Proyek Induk</span>
            <select v-model="form.project_id" :disabled="!canManage" required>
              <option v-for="project in projects" :key="project.id" :value="project.id">
                {{ project.code }} - {{ project.name }}
              </option>
            </select>
          </label>

          <label>
            <span>Repositori Terverifikasi</span>
            <select v-model="form.repository_id" :disabled="!canManage">
              <option value="">Tidak ada repositori</option>
              <option v-for="repository in verifiedRepositori" :key="repository.id" :value="repository.id">
                {{ repository.name }} ({{ repository.provider }})
              </option>
            </select>
          </label>

          <label>
            <span>Target Web/API Terverifikasi</span>
            <select v-model="form.target_id" :disabled="!canManage">
              <option value="">Tidak ada target Web/API</option>
              <option v-for="target in verifiedTarget" :key="target.id" :value="target.id">
                {{ target.name }} ({{ target.type }})
              </option>
            </select>
          </label>

          <label>
            <span>Profil Pemindaian</span>
            <select v-model="form.scan_profile_id" :disabled="!canManage" required>
              <option v-for="profile in scanProfiles" :key="profile.id" :value="profile.id">
                {{ profile.name }}
              </option>
            </select>
          </label>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <label>
              <span>Berlaku Mulai</span>
              <input v-model="form.valid_from" :disabled="!canManage" type="datetime-local" required />
            </label>

            <label>
              <span>Berlaku Sampai</span>
              <input v-model="form.valid_until" :disabled="!canManage" type="datetime-local" required />
            </label>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <label>
              <span>Maks. Konkurensi</span>
              <input v-model.number="form.max_concurrency" :disabled="!canManage" min="1" max="10" type="number" required />
            </label>

            <label>
              <span>Batas Rate / Menit</span>
              <input v-model.number="form.rate_limit_per_minute" :disabled="!canManage" min="1" max="600" type="number" required />
            </label>
          </div>

          <label>
            <span>Catatan Otorisasi</span>
            <textarea v-model="form.notes" placeholder="Catatan approval atau persetujuan audit..." :disabled="!canManage" />
          </label>

          <div class="form-actions">
            <button class="btn btn-primary-glow" type="submit" :disabled="!canManage || isSaving">
              {{ isSaving ? 'Membuat...' : 'Terbitkan Otorisasi' }}
            </button>
            <button class="secondary-button" type="button" @click="resetForm">Reset</button>
          </div>
        </form>
      </div>
    </section>
  </AppShell>
</template>
