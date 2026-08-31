<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { createProject, getProjects, updateProject } from '../services/projectService'
import { getUsers } from '../services/userService'
import { authState } from '../stores/authStore'

const projects = ref<any[]>([])
const users = ref<any[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const editingProjectId = ref<any>(null)
const searchQuery = ref('')

const currentPage = ref(1)
const pageSize = ref(10)

const form = reactive({
  name: '',
  code: '',
  description: '',
  criticality: 'medium',
  status: 'active',
  owner_id: '',
})

const canManageProjects = computed(() =>
  ['super_admin', 'security_admin'].includes(authState.user?.role?.name),
)

const filteredProjects = computed(() => {
  const query = (searchQuery.value || '').trim().toLowerCase()
  return (projects.value || []).filter((p) => {
    if (!query) return true
    const name = (p.name ?? '').toLowerCase()
    const code = (p.code ?? '').toLowerCase()
    const ownerName = (p.owner?.name ?? '').toLowerCase()
    return name.includes(query) || code.includes(query) || ownerName.includes(query)
  })
})

const paginatedProjects = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredProjects.value.slice(start, start + pageSize.value)
})

watch(searchQuery, () => {
  currentPage.value = 1
})

function resetForm() {
  editingProjectId.value = null
  form.name = ''
  form.code = ''
  form.description = ''
  form.criticality = 'medium'
  form.status = 'active'
  form.owner_id = ''
}

function editProyek(project: any) {
  editingProjectId.value = project.id
  form.name = project.name
  form.code = project.code
  form.description = project.description ?? ''
  form.criticality = project.criticality
  form.status = project.status
  form.owner_id = project.owner_id ?? ''
}

async function loadProyek() {
  if (!projects.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''

  try {
    const [projectList, userList] = await Promise.all([
      getProjects(),
      canManageProjects.value ? getUsers() : Promise.resolve([]),
    ])
    projects.value = projectList
    users.value = userList
  } catch (error) {
    console.error('Gagal memuat proyek.', error)
    errorMessage.value = 'Gagal memuat daftar proyek.'
  } finally {
    isLoading.value = false
  }
}

async function submitProyek() {
  isSaving.value = true
  errorMessage.value = ''
  successMessage.value = ''

  const payload = {
    name: form.name,
    code: form.code,
    description: form.description || null,
    criticality: form.criticality,
    status: form.status,
    owner_id: form.owner_id || null,
  }

  try {
    if (editingProjectId.value) {
      await updateProject(editingProjectId.value, payload)
      successMessage.value = `Proyek ${form.name} berhasil diperbarui!`
    } else {
      await createProject(payload)
      successMessage.value = `Proyek ${form.name} berhasil dibuat!`
    }

    resetForm()
    await loadProyek()
  } catch (error) {
    console.error('Gagal menyimpan proyek.', error)
    errorMessage.value = 'Gagal menyimpan proyek. Pastikan kode unik dan field wajib terisi.'
  } finally {
    isSaving.value = false
  }
}

onMounted(loadProyek)
</script>

<template>
  <AppShell>
    <template #eyebrow>Portofolio Aset & Manajemen Ruang Lingkup</template>
    <template #title>Manajemen Proyek</template>

    <div v-if="successMessage" class="status-panel success fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <section class="two-column">
      <!-- Left: Projects List -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            Daftar Proyek
          </h2>
          <span class="badge">{{ filteredProjects.length }} Proyek</span>
        </div>

        <div class="table-toolbar">
          <div class="search-input-wrapper">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input v-model="searchQuery" placeholder="Cari nama atau kode proyek..." />
          </div>
        </div>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nama Proyek</th>
                <th>Kode</th>
                <th>Kritikalitas</th>
                <th>Status</th>
                <th>Owner</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="project in paginatedProjects" :key="project.id">
                <td>
                  <strong style="color: var(--text-main);">{{ project.name }}</strong>
                  <div v-if="project.description" style="font-size: 11px; color: var(--text-dim); max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    {{ project.description }}
                  </div>
                </td>
                <td><span class="code-text" style="color: var(--tameng-sapphire); font-size: 12px;">{{ project.code }}</span></td>
                <td><span class="badge" :class="project.criticality">{{ project.criticality }}</span></td>
                <td><span class="badge" :class="project.status === 'active' ? 'is-success' : 'is-muted'">{{ project.status }}</span></td>
                <td style="font-size: 12px;">{{ project.owner?.name ?? 'Unassigned' }}</td>
                <td class="table-actions">
                  <button
                    class="small-button secondary-button"
                    type="button"
                    :disabled="!canManageProjects"
                    @click="editProyek(project)"
                  >
                    Edit
                  </button>
                </td>
              </tr>
              <tr v-if="!filteredProjects.length && !isLoading">
                <td colspan="6" style="text-align: center; color: var(--text-dim); padding: 20px;">
                  Belum ada proyek terdaftar.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="filteredProjects.length"
          :page-size-options="[5, 10, 20, 50]"
        />
      </div>

      <!-- Right: Create/Edit Form -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"/></svg>
            {{ editingProjectId ? 'Ubah Informasi Proyek' : 'Buat Proyek Baru' }}
          </h2>
          <span class="badge is-info">Security Admin+</span>
        </div>

        <form class="entity-form" @submit.prevent="submitProyek">
          <label>
            <span>Nama Proyek</span>
            <input v-model="form.name" placeholder="Contoh: Identity Provider Service" :disabled="!canManageProjects" required />
          </label>

          <label>
            <span>Kode Unik Proyek</span>
            <input v-model="form.code" placeholder="PRJ-IDP-01" :disabled="!canManageProjects" required />
          </label>

          <label>
            <span>Deskripsi Proyek</span>
            <textarea v-model="form.description" placeholder="Keterangan singkat arsitektur atau tim pengelola..." :disabled="!canManageProjects" />
          </label>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <label>
              <span>Kritikalitas</span>
              <select v-model="form.criticality" :disabled="!canManageProjects">
                <option value="low">Rendah (Low)</option>
                <option value="medium">Sedang (Medium)</option>
                <option value="high">Tinggi (High)</option>
                <option value="critical">Kritis (Critical)</option>
              </select>
            </label>

            <label>
              <span>Status</span>
              <select v-model="form.status" :disabled="!canManageProjects">
                <option value="active">Aktif</option>
                <option value="inactive">Nonaktif</option>
                <option value="archived">Diarsipkan</option>
              </select>
            </label>
          </div>

          <label>
            <span>Pemilik Proyek (Owner)</span>
            <select v-model="form.owner_id" :disabled="!canManageProjects">
              <option value="">Tanpa pemilik (Unassigned)</option>
              <option v-for="user in users" :key="user.id" :value="user.id">
                {{ user.name }} ({{ user.role?.display_name ?? 'User' }})
              </option>
            </select>
          </label>

          <div class="form-actions">
            <button class="btn btn-primary-glow" type="submit" :disabled="!canManageProjects || isSaving">
              {{ isSaving ? 'Menyimpan...' : 'Simpan Proyek' }}
            </button>
            <button class="secondary-button" type="button" @click="resetForm">Reset</button>
          </div>
        </form>
      </div>
    </section>
  </AppShell>
</template>
