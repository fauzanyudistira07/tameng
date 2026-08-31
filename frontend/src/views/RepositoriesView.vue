<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { getProjects } from '../services/projectService'
import {
  attachRepositoryWorkspace,
  clearRepositoryWorkspace,
  cloneRepositoryWorkspace,
  createRepository,
  getRepositories,
  updateRepository,
  verifyRepository,
} from '../services/repositoryService'
import { authState } from '../stores/authStore'

const repositories = ref<any[]>([])
const projects = ref<any[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const cloneSavingId = ref<any>(null)
const workspaceSavingId = ref<any>(null)
const errorMessage = ref('')
const successMessage = ref('')
const editingId = ref<any>(null)
const workspaceEditingId = ref<any>(null)
const searchQuery = ref('')

const currentPage = ref(1)
const pageSize = ref(10)

const form = reactive({
  project_id: '',
  provider: 'github',
  name: '',
  url: '',
  default_branch: 'main',
  is_private: false,
  access_token: '',
})
const workspaceForm = reactive({ local_path: '' })

const canManage = computed(() => ['super_admin', 'security_admin'].includes(authState.user?.role?.name))

const filteredRepositories = computed(() => {
  const query = (searchQuery.value || '').trim().toLowerCase()
  return (repositories.value || []).filter((r) => {
    if (!query) return true
    const name = (r.name ?? '').toLowerCase()
    const url = (r.url ?? '').toLowerCase()
    const projCode = (r.project?.code ?? r.project?.name ?? '').toLowerCase()
    return name.includes(query) || url.includes(query) || projCode.includes(query)
  })
})

const paginatedRepositories = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredRepositories.value.slice(start, start + pageSize.value)
})

watch(searchQuery, () => {
  currentPage.value = 1
})

function resetForm() {
  editingId.value = null
  form.project_id = projects.value[0]?.id ?? ''
  form.provider = 'github'
  form.name = ''
  form.url = ''
  form.default_branch = 'main'
  form.is_private = false
  form.access_token = ''
}

function editRepository(repository: any) {
  editingId.value = repository.id
  form.project_id = repository.project_id
  form.provider = repository.provider
  form.name = repository.name
  form.url = repository.url
  form.default_branch = repository.default_branch
  form.is_private = Boolean(repository.metadata?.is_private)
  form.access_token = ''
}

function editWorkspace(repository: any) {
  workspaceEditingId.value = repository.id
  workspaceForm.local_path = repository.metadata?.local_path ?? ''
}

async function loadData() {
  if (!repositories.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''
  try {
    const [projectList, repositoryList] = await Promise.all([getProjects(), getRepositories()])
    projects.value = projectList
    repositories.value = repositoryList
    if (!form.project_id) form.project_id = projectList[0]?.id ?? ''
  } catch (error) {
    console.error('Gagal memuat repositori.', error)
    errorMessage.value = 'Gagal memuat data repositori.'
  } finally {
    isLoading.value = false
  }
}

async function submitRepository() {
  isSaving.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const payload = {
      project_id: form.project_id,
      provider: form.provider,
      name: form.name,
      url: form.url,
      default_branch: form.default_branch,
      is_private: form.is_private,
      access_token: form.is_private && form.access_token ? form.access_token : undefined,
    }

    if (editingId.value) {
      await updateRepository(editingId.value, payload)
      successMessage.value = 'Repositori berhasil diperbarui!'
    } else {
      await createRepository(payload)
      successMessage.value = 'Repositori baru berhasil ditambahkan!'
    }
    resetForm()
    await loadData()
  } catch (error) {
    console.error('Gagal menyimpan repositori.', error)
    errorMessage.value = 'Gagal menyimpan repositori. Pastikan data terisi dengan benar.'
  } finally {
    isSaving.value = false
  }
}

async function setVerification(repository: any, status: string) {
  errorMessage.value = ''
  try {
    await verifyRepository(repository.id, status)
    successMessage.value = `Status verifikasi repositori ${repository.name} diperbarui ke ${status}.`
    await loadData()
  } catch (error) {
    errorMessage.value = 'Gagal memperbarui status verifikasi repositori.'
  }
}

async function cloneWorkspace(repository: any) {
  cloneSavingId.value = repository.id
  errorMessage.value = ''
  successMessage.value = ''

  try {
    await cloneRepositoryWorkspace(repository.id)
    successMessage.value = `Workspace ${repository.name} berhasil di-clone/sync dan siap dieksekusi!`
    await loadData()
  } catch (error: any) {
    console.error('Gagal clone workspace repositori.', error)
    const msg = error?.response?.data?.message || error?.response?.data?.errors?.repository?.[0]
    errorMessage.value = msg || 'Gagal clone/pull repositori. Pastikan URL GitHub dan token akses valid.'
  } finally {
    cloneSavingId.value = null
  }
}

async function submitWorkspace(repository: any) {
  workspaceSavingId.value = repository.id
  errorMessage.value = ''

  try {
    await attachRepositoryWorkspace(repository.id, workspaceForm.local_path)
    workspaceEditingId.value = null
    workspaceForm.local_path = ''
    successMessage.value = `Workspace path berhasil dipasang ke repositori ${repository.name}.`
    await loadData()
  } catch (error) {
    console.error('Gagal memasang workspace repositori.', error)
    errorMessage.value = 'Gagal memasang workspace. Path harus ada di dalam workspace root backend.'
  } finally {
    workspaceSavingId.value = null
  }
}

async function detachWorkspace(repository: any) {
  workspaceSavingId.value = repository.id
  errorMessage.value = ''

  try {
    await clearRepositoryWorkspace(repository.id)
    successMessage.value = `Workspace dilepas dari repositori ${repository.name}.`
    await loadData()
  } catch (error) {
    console.error('Gagal melepas workspace repositori.', error)
    errorMessage.value = 'Gagal melepas workspace repositori.'
  } finally {
    workspaceSavingId.value = null
  }
}

onMounted(loadData)
</script>

<template>
  <AppShell>
    <template #eyebrow>Inventaris Kode & Manajemen Workspace</template>
    <template #title>Repositori Source Code</template>

    <div v-if="successMessage" class="status-panel success fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <section class="two-column">
      <!-- Left: Repositories List -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="18" cy="18" r="3"/><circle cx="6" cy="6" r="3"/><path d="M13 6h3a2 2 0 0 1 2 2v7"/><line x1="6" y1="9" x2="6" y2="21"/></svg>
            Daftar Repositori
          </h2>
          <span class="badge">{{ filteredRepositories.length }} Terdaftar</span>
        </div>

        <div class="table-toolbar">
          <div class="search-input-wrapper">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input v-model="searchQuery" placeholder="Cari nama repositori atau proyek..." />
          </div>
        </div>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nama Repositori</th>
                <th>Proyek</th>
                <th>Akses</th>
                <th>Branch</th>
                <th>Verifikasi</th>
                <th>Workspace Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="repository in paginatedRepositories" :key="repository.id">
                <td>
                  <strong style="color: var(--text-main);">{{ repository.name }}</strong>
                  <div style="font-size: 11px; color: var(--text-dim); max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    {{ repository.url }}
                  </div>
                </td>
                <td><span class="code-text" style="font-size: 12px;">{{ repository.project?.code }}</span></td>
                <td>
                  <span class="badge" :class="repository.metadata?.is_private ? 'is-info' : 'is-success'">
                    {{ repository.metadata?.is_private ? '🔒 Privat' : '🌐 Publik' }}
                  </span>
                </td>
                <td><span class="run-pill" style="font-size: 11px;">{{ repository.default_branch }}</span></td>
                <td>
                  <span class="badge" :class="repository.verification_status === 'verified' ? 'is-success' : 'is-muted'">
                    {{ repository.verification_status }}
                  </span>
                </td>
                <td>
                  <span
                    class="badge"
                    :class="repository.metadata?.scanner_execution_ready ? 'is-success' : 'is-muted'"
                  >
                    {{ repository.metadata?.workspace_status ?? 'detached' }}
                  </span>
                </td>
                <td class="table-actions">
                  <button class="small-button secondary-button" :disabled="!canManage" @click="editRepository(repository)">
                    Edit
                  </button>
                  <button
                    v-if="repository.verification_status !== 'verified'"
                    class="small-button btn-success"
                    :disabled="!canManage"
                    @click="setVerification(repository, 'verified')"
                  >
                    Verifikasi
                  </button>
                  <button
                    class="small-button btn-primary-glow"
                    :disabled="!canManage || cloneSavingId === repository.id"
                    @click="cloneWorkspace(repository)"
                  >
                    {{ cloneSavingId === repository.id ? 'Syncing...' : 'Clone/Sync' }}
                  </button>
                  <button class="small-button secondary-button" :disabled="!canManage" @click="editWorkspace(repository)">
                    Path
                  </button>
                </td>
              </tr>
              <tr v-if="!filteredRepositories.length && !isLoading">
                <td colspan="7" style="text-align: center; color: var(--text-dim); padding: 20px;">
                  Belum ada repositori yang terdaftar.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="filteredRepositories.length"
          :page-size-options="[5, 10, 20, 50]"
        />
      </div>

      <!-- Right: Form -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"/></svg>
            {{ editingId ? 'Ubah Repositori' : 'Tambah Repositori Baru' }}
          </h2>
          <span class="badge is-info">Security Admin+</span>
        </div>

        <form class="entity-form" @submit.prevent="submitRepository">
          <label>
            <span>Proyek Induk</span>
            <select v-model="form.project_id" :disabled="!canManage" required>
              <option v-for="project in projects" :key="project.id" :value="project.id">
                {{ project.code }} - {{ project.name }}
              </option>
            </select>
          </label>

          <label>
            <span>Provider Git</span>
            <select v-model="form.provider" :disabled="!canManage" required>
              <option value="github">GitHub</option>
              <option value="gitlab">GitLab</option>
              <option value="bitbucket">Bitbucket</option>
            </select>
          </label>

          <label>
            <span>Nama Repositori</span>
            <input v-model="form.name" placeholder="api-service" :disabled="!canManage" required />
          </label>

          <label>
            <span>URL Clone HTTPS</span>
            <input v-model="form.url" placeholder="https://github.com/org/repo" :disabled="!canManage" required type="url" />
          </label>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
            <label>
              <span>Default Branch</span>
              <input v-model="form.default_branch" placeholder="main" :disabled="!canManage" required />
            </label>

            <label>
              <span>Visibilitas Akses</span>
              <select v-model="form.is_private" :disabled="!canManage">
                <option :value="false">🌐 Publik (Public)</option>
                <option :value="true">🔒 Privat (Private)</option>
              </select>
            </label>
          </div>

          <label v-if="form.is_private">
            <span style="display: flex; justify-content: space-between; align-items: center;">
              <span>GitHub Personal Access Token (PAT)</span>
              <strong style="color: var(--severity-critical); font-size: 11px;">*Wajib untuk Private</strong>
            </span>
            <input
              v-model="form.access_token"
              type="password"
              placeholder="ghp_xxxxxxxxxxxx atau github_pat_xxxx"
              :disabled="!canManage"
              :required="form.is_private && !editingId"
            />
            <small style="color: var(--text-dim); font-size: 11px; margin-top: 4px; display: block;">
              {{ editingId ? 'Kosongkan jika tidak ingin mengubah token yang tersimpan.' : 'Token GitHub dengan izin akses read repository.' }}
            </small>
          </label>

          <div class="form-actions">
            <button class="btn btn-primary-glow" type="submit" :disabled="!canManage || isSaving">
              {{ isSaving ? 'Menyimpan...' : 'Simpan Repositori' }}
            </button>
            <button class="secondary-button" type="button" @click="resetForm">Reset</button>
          </div>
        </form>
      </div>
    </section>

    <!-- Workspace Root & Directory Attach Inspector -->
    <section class="panel">
      <div class="panel-heading">
        <h2>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>
          Status Workspace Lokal
        </h2>
        <span>Sandbox Filesystem Path</span>
      </div>

      <div class="profile-list">
        <article v-for="repository in repositories" :key="`workspace-${repository.id}`">
          <div style="display: flex; justify-content: space-between; align-items: center;">
            <h3>{{ repository.name }}</h3>
            <span class="badge" :class="repository.metadata?.scanner_execution_ready ? 'is-success' : 'is-muted'">
              {{ repository.metadata?.scanner_execution_ready ? 'Ready for Real Scanner' : 'Not Attached' }}
            </span>
          </div>

          <p class="code-text" style="font-size: 12px; color: var(--tameng-sapphire); word-break: break-all;">
            {{ repository.metadata?.local_path ?? 'Belum ada workspace path terpasang.' }}
          </p>

          <div v-if="workspaceEditingId === repository.id" class="fade-in" style="margin-top: 10px; background: var(--bg-surface-elevated); padding: 14px; border-radius: 8px; border: 1px solid var(--border-subtle);">
            <form class="entity-form" @submit.prevent="submitWorkspace(repository)">
              <label>
                <span>Ubah Path Lokal Manual</span>
                <input v-model="workspaceForm.local_path" :disabled="!canManage" required />
              </label>
              <div class="form-actions" style="margin-top: 12px;">
                <button class="btn btn-sm btn-primary-glow" type="submit" :disabled="!canManage || workspaceSavingId === repository.id">
                  {{ workspaceSavingId === repository.id ? 'Menyimpan...' : 'Pasang Workspace' }}
                </button>
                <button class="btn btn-sm secondary-button" type="button" @click="workspaceEditingId = null">Batal</button>
                <button
                  class="btn btn-sm btn-danger"
                  type="button"
                  :disabled="!canManage || workspaceSavingId === repository.id"
                  @click="detachWorkspace(repository)"
                >
                  Lepas
                </button>
              </div>
            </form>
          </div>
        </article>
      </div>
    </section>
  </AppShell>
</template>
