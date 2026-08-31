<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { getProjects } from '../services/projectService'
import { createTarget, getTargets, updateTarget, verifyTarget } from '../services/targetService'
import { authState } from '../stores/authStore'

const targets = ref<any[]>([])
const projects = ref<any[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const editingId = ref<any>(null)
const searchQuery = ref('')

const currentPage = ref(1)
const pageSize = ref(10)

const form = reactive({ project_id: '', type: 'web', name: '', base_url: '', hostname: '' })
const canManage = computed(() => ['super_admin', 'security_admin'].includes(authState.user?.role?.name))

const filteredTargets = computed(() => {
  const query = (searchQuery.value || '').trim().toLowerCase()
  return (targets.value || []).filter((t) => {
    if (!query) return true
    const name = (t.name ?? '').toLowerCase()
    const hostname = (t.hostname ?? '').toLowerCase()
    const baseUrl = (t.base_url ?? '').toLowerCase()
    return name.includes(query) || hostname.includes(query) || baseUrl.includes(query)
  })
})

const paginatedTargets = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredTargets.value.slice(start, start + pageSize.value)
})

watch(searchQuery, () => {
  currentPage.value = 1
})

function resetForm() {
  editingId.value = null
  form.project_id = projects.value[0]?.id ?? ''
  form.type = 'web'
  form.name = ''
  form.base_url = ''
  form.hostname = ''
}

function editTarget(target: any) {
  editingId.value = target.id
  form.project_id = target.project_id
  form.type = target.type
  form.name = target.name
  form.base_url = target.base_url ?? ''
  form.hostname = target.hostname ?? ''
}

async function loadData() {
  if (!targets.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''
  try {
    const [projectList, targetList] = await Promise.all([getProjects(), getTargets()])
    projects.value = projectList
    targets.value = targetList
    if (!form.project_id) form.project_id = projectList[0]?.id ?? ''
  } catch (error) {
    console.error('Gagal memuat target.', error)
    errorMessage.value = 'Gagal memuat target.'
  } finally {
    isLoading.value = false
  }
}

async function submitTarget() {
  isSaving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    if (editingId.value) {
      await updateTarget(editingId.value, form)
      successMessage.value = 'Target berhasil diperbarui!'
    } else {
      await createTarget(form)
      successMessage.value = 'Target baru berhasil ditambahkan!'
    }
    resetForm()
    await loadData()
  } catch (error) {
    console.error('Gagal menyimpan target.', error)
    errorMessage.value = 'Gagal menyimpan target. Pastikan URL dan data terisi dengan benar.'
  } finally {
    isSaving.value = false
  }
}

async function setVerification(target: any, status: string) {
  errorMessage.value = ''
  try {
    await verifyTarget(target.id, status)
    successMessage.value = `Status verifikasi target ${target.name} diubah ke ${status}.`
    await loadData()
  } catch (error) {
    errorMessage.value = 'Gagal memverifikasi target.'
  }
}

onMounted(loadData)
</script>

<template>
  <AppShell>
    <template #eyebrow>Inventaris Host, URL & Endpoint</template>
    <template #title>Target Web & API</template>

    <div v-if="successMessage" class="status-panel success fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <section class="two-column">
      <!-- Left: Targets List -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/><line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/><line x1="12" y1="22" x2="12" y2="18"/></svg>
            Daftar Target Terdaftar
          </h2>
          <span class="badge">{{ filteredTargets.length }} Target</span>
        </div>

        <div class="table-toolbar">
          <div class="search-input-wrapper">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input v-model="searchQuery" placeholder="Cari nama, URL, atau hostname..." />
          </div>
        </div>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nama Target</th>
                <th>Proyek</th>
                <th>Tipe</th>
                <th>Hostname / Base URL</th>
                <th>Verifikasi</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="target in paginatedTargets" :key="target.id">
                <td><strong style="color: var(--text-main);">{{ target.name }}</strong></td>
                <td><span class="code-text" style="font-size: 12px;">{{ target.project?.code }}</span></td>
                <td><span class="badge is-info">{{ target.type }}</span></td>
                <td>
                  <span class="code-text" style="font-size: 12px; color: var(--text-muted);">
                    {{ target.base_url || target.hostname || '-' }}
                  </span>
                </td>
                <td>
                  <span class="badge" :class="target.verification_status === 'verified' ? 'is-success' : 'is-muted'">
                    {{ target.verification_status }}
                  </span>
                </td>
                <td class="table-actions">
                  <button class="small-button secondary-button" :disabled="!canManage" @click="editTarget(target)">
                    Edit
                  </button>
                  <button
                    v-if="target.verification_status !== 'verified'"
                    class="small-button btn-success"
                    :disabled="!canManage"
                    @click="setVerification(target, 'verified')"
                  >
                    Verifikasi
                  </button>
                </td>
              </tr>
              <tr v-if="!filteredTargets.length && !isLoading">
                <td colspan="6" style="text-align: center; color: var(--text-dim); padding: 20px;">
                  Belum ada target Web/API yang terdaftar.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="filteredTargets.length"
          :page-size-options="[5, 10, 20, 50]"
        />
      </div>

      <!-- Right: Target Form -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            {{ editingId ? 'Ubah Target' : 'Tambah Target Baru' }}
          </h2>
          <span class="badge is-info">Security Admin+</span>
        </div>

        <form class="entity-form" @submit.prevent="submitTarget">
          <label>
            <span>Proyek Induk</span>
            <select v-model="form.project_id" :disabled="!canManage" required>
              <option v-for="project in projects" :key="project.id" :value="project.id">
                {{ project.code }} - {{ project.name }}
              </option>
            </select>
          </label>

          <label>
            <span>Tipe Target</span>
            <select v-model="form.type" :disabled="!canManage">
              <option value="web">Web Application</option>
              <option value="api">API Endpoint (REST / OpenAPI)</option>
            </select>
          </label>

          <label>
            <span>Nama Target</span>
            <input v-model="form.name" placeholder="Contoh: Production API Gateway" :disabled="!canManage" required />
          </label>

          <label>
            <span>URL Dasar (Base URL)</span>
            <input v-model="form.base_url" placeholder="https://api.domain.com" :disabled="!canManage" type="url" />
          </label>

          <label>
            <span>Hostname</span>
            <input v-model="form.hostname" placeholder="api.domain.com" :disabled="!canManage" />
          </label>

          <div class="form-actions">
            <button class="btn btn-primary-glow" type="submit" :disabled="!canManage || isSaving">
              {{ isSaving ? 'Menyimpan...' : 'Simpan Target' }}
            </button>
            <button class="secondary-button" type="button" @click="resetForm">Reset</button>
          </div>
        </form>
      </div>
    </section>
  </AppShell>
</template>
