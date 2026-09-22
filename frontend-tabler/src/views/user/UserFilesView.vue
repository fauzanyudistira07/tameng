<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch } from '../../services/api'
import { useAuth } from '../../composables/useAuth'

const route = useRoute()
const router = useRouter()
const { assignedProjects } = useAuth()

interface Project {
  id: number
  name: string
  code: string
  criticality?: string
}

interface RepositoryItem {
  id: number
  name: string
  url: string
  default_branch: string
  project_id: number
  project?: {
    id: number
    name: string
  }
  created_at: string
}

interface TargetItem {
  id: number
  name: string
  type: 'web' | 'api' | 'mobile' | 'container'
  base_url?: string
  project_id: number
  project?: {
    id: number
    name: string
  }
  created_at: string
}

const projects = ref<Project[]>([])
const repositories = ref<RepositoryItem[]>([])
const targets = ref<TargetItem[]>([])
const isLoading = ref(true)
const searchQuery = ref('')
const selectedProjectFilter = ref<number | 'all'>('all')
const selectedTypeFilter = ref<string>('all')
const viewMode = ref<'grid' | 'table'>('grid')

// Upload Modal
const isUploadModalOpen = ref(false)
const uploadScanType = ref<'repository' | 'mobile'>('repository')
const uploadProjectName = ref('')
const uploadFile = ref<File | null>(null)
const isSubmittingUpload = ref(false)
const uploadMessage = ref<{ type: 'success' | 'danger'; text: string } | null>(null)

async function loadData() {
  isLoading.value = true
  try {
    const [projRes, repoRes, targetRes] = await Promise.all([
      apiFetch('/api/projects').catch(() => ({ projects: [] })),
      apiFetch('/api/repositories').catch(() => ({ repositories: [] })),
      apiFetch('/api/targets').catch(() => ({ targets: [] }))
    ])

    projects.value = projRes?.projects || []
    repositories.value = repoRes?.repositories || []
    targets.value = targetRes?.targets || []

    if (projects.value.length > 0 && !uploadProjectName.value) {
      uploadProjectName.value = projects.value[0].name
    }
  } catch (err: any) {
    console.error('Gagal memuat data berkas:', err)
  } finally {
    isLoading.value = false
  }
}

watch(
  () => route.query.q,
  (newQ) => {
    if (typeof newQ === 'string') {
      searchQuery.value = newQ.trim()
    } else {
      searchQuery.value = ''
    }
  },
  { immediate: true }
)

onMounted(() => {
  loadData()
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
})

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && isUploadModalOpen.value) {
    isUploadModalOpen.value = false
  }
}

// Unified file/asset representation
interface UnifiedAsset {
  id: string
  rawId: number
  name: string
  type: 'git' | 'archive' | 'mobile' | 'web' | 'api'
  typeLabel: string
  projectId?: number
  projectName: string
  location: string
  updatedAt: string
  url?: string
  defaultBranch?: string
}

const allAssets = computed<UnifiedAsset[]>(() => {
  const list: UnifiedAsset[] = []

  repositories.value.forEach(r => {
    const isZip = r.url?.endsWith('.zip') || r.name?.toLowerCase().includes('zip')
    const finalUrl = r.url && (r.url.startsWith('http://') || r.url.startsWith('https://'))
      ? r.url
      : (r.name.includes('/') ? `https://github.com/${r.name}.git` : `https://github.com/${r.name}`)

    list.push({
      id: `repo-${r.id}`,
      rawId: r.id,
      name: r.name,
      type: isZip ? 'archive' : 'git',
      typeLabel: isZip ? 'Arsip Source (ZIP)' : 'Git Repository',
      projectId: r.project_id,
      projectName: r.project?.name || 'Proyek',
      location: r.default_branch ? `Branch: ${r.default_branch}` : (r.url || 'Default'),
      updatedAt: r.created_at,
      url: finalUrl,
      defaultBranch: r.default_branch || 'main'
    })
  })

  targets.value.forEach(t => {
    let tType: 'mobile' | 'web' | 'api' = 'web'
    let label = 'Web Target'
    if (t.type === 'mobile') {
      tType = 'mobile'
      label = 'Paket Mobile (APK)'
    } else if (t.type === 'api') {
      tType = 'api'
      label = 'REST API'
    }

    list.push({
      id: `target-${t.id}`,
      rawId: t.id,
      name: t.name,
      type: tType,
      typeLabel: label,
      projectId: t.project_id,
      projectName: t.project?.name || 'Proyek',
      location: t.base_url || 'Target Endpoint',
      updatedAt: t.created_at,
      url: t.base_url || '',
      defaultBranch: 'main'
    })
  })

  return list
})

function startScanForAsset(item: UnifiedAsset) {
  let scanType = 'repository'
  if (item.type === 'web') scanType = 'web'
  else if (item.type === 'api') scanType = 'api'
  else if (item.type === 'mobile') scanType = 'mobile'

  let targetUrl = item.url || ''
  if (!targetUrl) {
    if (item.type === 'git' || item.type === 'archive') {
      targetUrl = item.name.includes('/') ? `https://github.com/${item.name}.git` : `https://github.com/${item.name}`
    } else {
      targetUrl = item.location || item.name
    }
  }

  router.push({
    path: '/workspace/scans',
    query: {
      action: 'new',
      scan_type: scanType,
      project_name: item.projectName || item.name,
      asset_url: targetUrl,
      branch: item.defaultBranch || 'main'
    }
  })
}

const filteredAssets = computed(() => {
  return allAssets.value.filter(a => {
    if (selectedProjectFilter.value !== 'all' && a.projectId !== selectedProjectFilter.value) {
      return false
    }

    if (selectedTypeFilter.value !== 'all') {
      if (selectedTypeFilter.value === 'git' && a.type !== 'git') return false
      if (selectedTypeFilter.value === 'archive' && a.type !== 'archive') return false
      if (selectedTypeFilter.value === 'mobile' && a.type !== 'mobile') return false
      if (selectedTypeFilter.value === 'web_api' && a.type !== 'web' && a.type !== 'api') return false
    }

    if (!searchQuery.value.trim()) return true
    const q = searchQuery.value.toLowerCase().trim()
    return a.name.toLowerCase().includes(q) ||
      a.projectName.toLowerCase().includes(q) ||
      a.location.toLowerCase().includes(q)
  })
})

function selectProjectFolder(pid: number | 'all') {
  selectedProjectFilter.value = pid
}

function onFileSelect(e: Event) {
  const target = e.target as HTMLInputElement
  if (target.files && target.files[0]) {
    uploadFile.value = target.files[0]
  }
}

async function submitUploadScan() {
  if (!uploadFile.value && uploadScanType.value !== 'repository') {
    uploadMessage.value = { type: 'danger', text: 'Silakan pilih berkas yang akan diunggah.' }
    return
  }

  isSubmittingUpload.value = true
  uploadMessage.value = null

  try {
    const formData = new FormData()
    formData.append('scan_type', uploadScanType.value)
    formData.append('project_name', uploadProjectName.value || 'Workspace Upload')

    if (uploadFile.value) {
      if (uploadScanType.value === 'mobile') {
        formData.append('mobile_file', uploadFile.value)
      } else {
        formData.append('file', uploadFile.value)
      }
    }

    const res = await apiFetch('/api/my/scan-requests', {
      method: 'POST',
      body: formData
    })

    uploadMessage.value = { type: 'success', text: 'Berkas berhasil diunggah dan permohonan scan telah dikirim ke Admin SOC!' }
    setTimeout(() => {
      isUploadModalOpen.value = false
      uploadFile.value = null
      loadData()
    }, 1500)
  } catch (err: any) {
    uploadMessage.value = { type: 'danger', text: err?.message || 'Gagal mengunggah berkas.' }
  } finally {
    isSubmittingUpload.value = false
  }
}

function formatDate(d?: string) {
  if (!d) return '-'
  try {
    return new Date(d).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
  } catch {
    return d
  }
}
</script>

<template>
  <div class="user-files-view">
    <!-- Breadcrumb & Header -->
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
      <div>
        <nav aria-label="breadcrumb">
          <ol class="breadcrumb mb-1">
            <li class="breadcrumb-item">
              <router-link to="/workspace" class="text-decoration-none">Ruang Kerja</router-link>
            </li>
            <li class="breadcrumb-item active" aria-current="page">
              {{ selectedProjectFilter === 'all' ? 'Semua Berkas & Repositori' : 'Proyek Terpilih' }}
            </li>
          </ol>
        </nav>
        <h1 class="h2 mb-0 fw-bold">Berkas & Repositori Saya</h1>
        <p class="text-secondary small mb-0 mt-1">
          Jelajahi repositori kode, paket aplikasi, dan target pada proyek yang ditugaskan kepada Anda.
        </p>
      </div>

      <!-- Action: Upload / Add File -->
      <div class="d-flex align-items-center gap-2">
        <button
          type="button"
          class="btn btn-primary d-inline-flex align-items-center gap-2 shadow-sm"
          @click="isUploadModalOpen = true"
        >
          <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2" /><path d="M7 9l5 -5l5 5" /><path d="M12 4l0 12" /></svg>
          <span>Upload Berkas / Scan</span>
        </button>
      </div>
    </div>

    <!-- Section 1: Project Folders (Google Drive Style Folders) -->
    <div class="mb-4">
      <div class="d-flex align-items-center justify-content-between mb-2">
        <h3 class="fw-bold mb-0 text-secondary" style="font-size: 0.85rem; letter-spacing: 0.05em; text-transform: uppercase;">
          Folder Proyek Saya
        </h3>
        <button
          v-if="selectedProjectFilter !== 'all'"
          type="button"
          class="btn btn-sm btn-link text-decoration-none p-0"
          @click="selectProjectFolder('all')"
        >
          Lihat Semua Folder
        </button>
      </div>

      <div class="row row-cards g-2">
        <!-- All Projects Folder -->
        <div class="col-6 col-md-4 col-lg-3">
          <div
            class="card folder-card border shadow-sm p-3 cursor-pointer"
            :class="{ 'border-primary bg-primary-lt': selectedProjectFilter === 'all' }"
            @click="selectProjectFolder('all')"
          >
            <div class="d-flex align-items-center gap-2">
              <span class="avatar avatar-sm bg-azure-lt text-azure rounded">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
              </span>
              <div class="text-truncate">
                <div class="fw-bold text-truncate" style="font-size: 0.9rem;">Semua Proyek</div>
                <div class="text-secondary small">{{ allAssets.length }} Berkas</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Individual Project Folders -->
        <div
          v-for="p in projects"
          :key="p.id"
          class="col-6 col-md-4 col-lg-3"
        >
          <div
            class="card folder-card border shadow-sm p-3 cursor-pointer"
            :class="{ 'border-primary bg-primary-lt': selectedProjectFilter === p.id }"
            @click="selectProjectFolder(p.id)"
          >
            <div class="d-flex align-items-center gap-2">
              <span class="avatar avatar-sm bg-yellow-lt text-yellow rounded">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
              </span>
              <div class="text-truncate">
                <div class="fw-bold text-truncate" style="font-size: 0.9rem;">{{ p.name }}</div>
                <div class="text-secondary small">{{ allAssets.filter(a => a.projectId === p.id).length }} Berkas</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 2: Files & Assets Toolbar -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-body p-2 p-md-3">
        <div class="row g-2 align-items-center justify-content-between">
          <!-- Search -->
          <div class="col-12 col-md-5">
            <div class="input-icon">
              <span class="input-icon-addon">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
              </span>
              <input
                v-model="searchQuery"
                type="text"
                class="form-control"
                placeholder="Cari nama repositori, berkas, atau proyek..."
              />
            </div>
          </div>

          <!-- Type Filters & View Toggle -->
          <div class="col-12 col-md-auto d-flex align-items-center gap-2 flex-wrap">
            <select v-model="selectedTypeFilter" class="form-select form-select-sm" style="width: auto;">
              <option value="all">Semua Tipe Berkas</option>
              <option value="git">Git Repositories</option>
              <option value="archive">Source Archive (ZIP)</option>
              <option value="mobile">Paket Mobile (APK)</option>
              <option value="web_api">Target Web & API</option>
            </select>

            <!-- View Switch: Grid vs Table -->
            <div class="btn-group btn-group-sm">
              <button
                type="button"
                class="btn"
                :class="viewMode === 'grid' ? 'btn-primary' : 'btn-secondary'"
                title="Tampilan Kotak (Grid)"
                @click="viewMode = 'grid'"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M14 4m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M4 14m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /><path d="M14 14m0 1a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v4a1 1 0 0 1 -1 1h-4a1 1 0 0 1 -1 -1z" /></svg>
              </button>
              <button
                type="button"
                class="btn"
                :class="viewMode === 'table' ? 'btn-primary' : 'btn-secondary'"
                title="Tampilan Tabel"
                @click="viewMode = 'table'"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 5a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v14a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-14z" /><path d="M3 10h18" /><path d="M10 3v18" /></svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="isLoading" class="p-5 text-center text-muted card border-0 shadow-sm">
      <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
      <div>Memuat daftar berkas dan repositori Anda...</div>
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredAssets.length === 0" class="card border-0 shadow-sm p-5 text-center text-muted">
      <div class="avatar avatar-xl bg-azure-lt text-azure rounded-circle mx-auto mb-3">
        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg" width="36" height="36" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
      </div>
      <h3 class="fw-bold mb-1">Belum Ada Berkas Ditemukan</h3>
      <p class="text-secondary small mb-3">Tidak ada berkas atau repositori yang cocok dengan filter atau pencarian Anda.</p>
      <div>
        <button type="button" class="btn btn-primary btn-sm" @click="isUploadModalOpen = true">
          Upload Berkas Pertama
        </button>
      </div>
    </div>

    <!-- Section 3A: Grid View -->
    <div v-else-if="viewMode === 'grid'" class="row row-cards">
      <div
        v-for="item in filteredAssets"
        :key="item.id"
        class="col-sm-6 col-lg-4"
      >
        <div class="card h-100 border-0 shadow-sm file-card p-3 d-flex flex-column justify-content-between">
          <div>
            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
              <span
                class="avatar avatar-md rounded"
                :class="{
                  'bg-azure-lt text-azure': item.type === 'git',
                  'bg-orange-lt text-orange': item.type === 'archive',
                  'bg-teal-lt text-teal': item.type === 'mobile',
                  'bg-cyan-lt text-cyan': item.type === 'web' || item.type === 'api'
                }"
              >
                <!-- Git Icon -->
                <svg v-if="item.type === 'git'" xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 19c-4.3 1.4 -4.3 -2.5 -6 -3m12 5v-3.5c0 -1 .1 -1.4 -.5 -2c2.8 -.3 5.5 -1.4 5.5 -6a4.6 4.6 0 0 0 -1.3 -3.2a4.2 4.2 0 0 0 -.1 -3.2s-1.1 -.3 -3.5 1.3a12.3 12.3 0 0 0 -6.2 0c-2.4 -1.6 -3.5 -1.3 -3.5 -1.3a4.2 4.2 0 0 0 -.1 3.2a4.6 4.6 0 0 0 -1.3 3.2c0 4.6 2.7 5.7 5.5 6c-.6 .6 -.6 1.2 -.5 2v3.5" /></svg>
                <!-- ZIP Icon -->
                <svg v-else-if="item.type === 'archive'" xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M10 12l4 0" /><path d="M10 16l4 0" /></svg>
                <!-- APK Icon -->
                <svg v-else-if="item.type === 'mobile'" xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 10l0 6" /><path d="M20 10l0 6" /><path d="M7 9h10v8a1 1 0 0 1 -1 1h-8a1 1 0 0 1 -1 -1v-8a5 5 0 0 1 10 0" /><path d="M8 3l1 2" /><path d="M16 3l-1 2" /><path d="M9 18l0 3" /><path d="M15 18l0 3" /></svg>
                <!-- Web Icon -->
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M19.5 7a9 9 0 0 0 -7.5 -4a8.991 8.991 0 0 0 -7.484 4" /><path d="M11.5 3a16.989 16.989 0 0 0 -1.826 6" /><path d="M12.5 3a16.989 16.989 0 0 1 1.828 6" /><path d="M19.5 17a9 9 0 0 1 -7.5 4a8.991 8.991 0 0 1 -7.484 -4" /><path d="M11.5 21a16.989 16.989 0 0 1 -1.826 -6" /><path d="M12.5 21a16.989 16.989 0 0 0 1.828 -6" /><path d="M2 10l1 4l1.5 -4l1.5 4l1 -4" /><path d="M17 10l1 4l1.5 -4l1.5 4l1 -4" /><path d="M9.5 10l1 4l1.5 -4l1.5 4l1 -4" /></svg>
              </span>

              <span class="badge bg-secondary-lt text-secondary" style="font-size: 0.7rem;">
                {{ item.typeLabel }}
              </span>
            </div>

            <div class="fw-bold text-reset mb-1 text-truncate" :title="item.name" style="font-size: 1rem;">
              {{ item.name }}
            </div>

            <div class="text-secondary small mb-2 text-truncate" :title="item.location">
              {{ item.location }}
            </div>
          </div>

          <div class="pt-2 border-top d-flex align-items-center justify-content-between mt-3">
            <div class="small text-muted">
              Proyek: <strong>{{ item.projectName }}</strong>
            </div>

            <button
              type="button"
              class="btn btn-sm btn-outline-primary d-inline-flex align-items-center gap-1"
              title="Ajukan scan untuk aset ini"
              @click="startScanForAsset(item)"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3" /><path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" /></svg>
              <span>Scan</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Section 3B: Table View -->
    <div v-else class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-vcenter table-hover mb-0">
          <thead>
            <tr>
              <th>Nama Berkas / Aset</th>
              <th>Tipe</th>
              <th>Proyek</th>
              <th>Cabang / Lokasi</th>
              <th>Tanggal Ditambahkan</th>
              <th class="w-1">Aksi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in filteredAssets" :key="item.id">
              <td>
                <div class="d-flex align-items-center gap-2">
                  <span
                    class="avatar avatar-xs rounded"
                    :class="{
                      'bg-azure-lt text-azure': item.type === 'git',
                      'bg-orange-lt text-orange': item.type === 'archive',
                      'bg-teal-lt text-teal': item.type === 'mobile',
                      'bg-cyan-lt text-cyan': item.type === 'web' || item.type === 'api'
                    }"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
                  </span>
                  <span class="fw-semibold text-reset">{{ item.name }}</span>
                </div>
              </td>
              <td>
                <span class="badge bg-secondary-lt text-secondary small">{{ item.typeLabel }}</span>
              </td>
              <td>
                <span class="fw-medium">{{ item.projectName }}</span>
              </td>
              <td class="text-secondary small font-monospace">
                {{ item.location }}
              </td>
              <td class="text-secondary small">
                {{ formatDate(item.updatedAt) }}
              </td>
              <td>
                <button
                  type="button"
                  class="btn btn-sm btn-ghost-primary d-inline-flex align-items-center gap-1"
                  title="Ajukan scan untuk aset ini"
                  @click="startScanForAsset(item)"
                >
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="14" height="14" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 13a8 8 0 0 1 7 7a6 6 0 0 0 3 -5a9 9 0 0 0 6 -8a3 3 0 0 0 -3 -3a9 9 0 0 0 -8 6a6 6 0 0 0 -5 3" /><path d="M7 14a6 6 0 0 0 -3 6a6 6 0 0 0 6 -3" /></svg>
                  <span>Scan</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Upload & Scan Modal -->
    <div
      v-if="isUploadModalOpen"
      class="modal modal-blur fade show d-block"
      tabindex="-1"
      role="dialog"
      style="background: rgba(0, 0, 0, 0.5);"
      @click.self="isUploadModalOpen = false"
    >
      <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow-lg">
          <div class="modal-header">
            <h5 class="modal-title fw-bold">Upload Berkas / Jalankan Scan Mandiri</h5>
            <button type="button" class="btn-close" aria-label="Close" @click="isUploadModalOpen = false"></button>
          </div>
          <div class="modal-body">
            <div v-if="uploadMessage" class="alert" :class="uploadMessage.type === 'success' ? 'alert-success' : 'alert-danger'">
              {{ uploadMessage.text }}
            </div>

            <div class="mb-3">
              <label class="form-label required">Tipe Pemindaian</label>
              <select v-model="uploadScanType" class="form-select">
                <option value="repository">Arsip Source Code (ZIP / Folder)</option>
                <option value="mobile">Aplikasi Mobile Android (APK)</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label required">Nama Proyek</label>
              <select v-if="projects.length > 0" v-model="uploadProjectName" class="form-select">
                <option v-for="p in projects" :key="p.id" :value="p.name">{{ p.name }}</option>
              </select>
              <input
                v-else
                v-model="uploadProjectName"
                type="text"
                class="form-control"
                placeholder="Masukkan nama proyek..."
              />
            </div>

            <div class="mb-3">
              <label class="form-label required">
                Pilih Berkas {{ uploadScanType === 'mobile' ? 'APK' : 'ZIP / Source' }}
              </label>
              <input
                type="file"
                class="form-control"
                :accept="uploadScanType === 'mobile' ? '.apk' : '.zip,.tar.gz,.tgz'"
                @change="onFileSelect"
              />
              <div class="form-text small text-muted">
                {{ uploadScanType === 'mobile' ? 'Maksimal 200MB file .apk untuk static security analysis MobSF.' : 'Maksimal 200MB file .zip berisi kode sumber proyek.' }}
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" @click="isUploadModalOpen = false">
              Batal
            </button>
            <button
              type="button"
              class="btn btn-primary d-inline-flex align-items-center gap-2"
              :disabled="isSubmittingUpload"
              @click="submitUploadScan"
            >
              <span v-if="isSubmittingUpload" class="spinner-border spinner-border-sm" role="status"></span>
              <span>Upload & Jalankan Scan</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}
.folder-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.folder-card:hover {
  transform: translateY(-2px);
}
.file-card {
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.file-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06) !important;
}
</style>
