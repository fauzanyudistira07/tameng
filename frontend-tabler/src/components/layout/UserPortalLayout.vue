<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTheme } from '../../composables/useTheme'
import { useAuth } from '../../composables/useAuth'
import { apiFetch } from '../../services/api'

const route = useRoute()
const router = useRouter()
const { isDark, toggleTheme } = useTheme()
const {
  currentUser,
  userInitials,
  roleDisplayName,
  roleBadgeClass,
  isAdmin,
  isAnalystOrAdmin,
  assignedProjects,
  assignedProjectNames,
  handleLogout
} = useAuth()

// Navigasi menu utama User Workspace (5 alur utama non-admin, tanpa redundansi pengaturan)
const navItems = [
  {
    label: 'Ruang Kerja',
    path: '/workspace',
    icon: 'home'
  },
  {
    label: 'Tiket Celah Saya',
    path: '/workspace/tickets',
    icon: 'checklist'
  },
  {
    label: 'Berkas & Repositori',
    path: '/workspace/files',
    icon: 'folder'
  },
  {
    label: 'Pengajuan Scan',
    path: '/workspace/scans',
    icon: 'scan'
  },
  {
    label: 'Laporan Keamanan',
    path: '/workspace/reports',
    icon: 'report'
  }
]

// Live Quick Search State
const searchQuery = ref('')
const searchContainerRef = ref<HTMLElement | null>(null)
const isDropdownOpen = ref(false)
const isLoadingSearchData = ref(false)
const hasLoadedSearchData = ref(false)
const searchFindings = ref<any[]>([])
const searchRepos = ref<any[]>([])

async function loadSearchData() {
  if (hasLoadedSearchData.value || isLoadingSearchData.value) return
  isLoadingSearchData.value = true
  try {
    const [findingsRes, reposRes] = await Promise.all([
      apiFetch('/api/findings').catch(() => ({ findings: [] })),
      apiFetch('/api/repositories').catch(() => ({ repositories: [] }))
    ])
    searchFindings.value = findingsRes?.findings || []
    searchRepos.value = reposRes?.repositories || []
    hasLoadedSearchData.value = true
  } catch (err) {
    console.error('Gagal memuat data pencarian:', err)
  } finally {
    isLoadingSearchData.value = false
  }
}

function onInputFocus() {
  isDropdownOpen.value = true
  loadSearchData()
}

function onInputChange() {
  isDropdownOpen.value = true
  loadSearchData()
}

const filteredFindings = computed(() => {
  const q = searchQuery.value.toLowerCase().trim()
  if (!q) return []
  return searchFindings.value
    .filter(f => {
      const titleMatch = f.title?.toLowerCase().includes(q)
      const codeMatch = f.code?.toLowerCase().includes(q)
      const cveMatch = f.cve?.toLowerCase().includes(q)
      const fileMatch = f.normalization_metadata?.location?.file?.toLowerCase().includes(q)
      const projMatch = f.project?.name?.toLowerCase().includes(q)
      return titleMatch || codeMatch || cveMatch || fileMatch || projMatch
    })
    .slice(0, 5)
})

const filteredRepos = computed(() => {
  const q = searchQuery.value.toLowerCase().trim()
  if (!q) return []
  return searchRepos.value
    .filter(r => {
      const nameMatch = r.name?.toLowerCase().includes(q)
      const urlMatch = r.url?.toLowerCase().includes(q)
      const projMatch = r.project?.name?.toLowerCase().includes(q)
      return nameMatch || urlMatch || projMatch
    })
    .slice(0, 4)
})

const filteredFiles = computed(() => {
  const q = searchQuery.value.toLowerCase().trim()
  if (!q) return []
  const fileMap = new Map<string, { file: string; projectName: string; count: number }>()
  searchFindings.value.forEach(f => {
    const file = f.normalization_metadata?.location?.file
    if (file && file.toLowerCase().includes(q)) {
      const existing = fileMap.get(file)
      if (existing) {
        existing.count++
      } else {
        fileMap.set(file, {
          file,
          projectName: f.project?.name || 'Umum',
          count: 1
        })
      }
    }
  })
  return Array.from(fileMap.values()).slice(0, 4)
})

const totalResultsCount = computed(() => {
  return filteredFindings.value.length + filteredRepos.value.length + filteredFiles.value.length
})

function selectFinding(f: any) {
  isDropdownOpen.value = false
  searchQuery.value = ''
  router.push({
    path: '/workspace/tickets',
    query: { q: f.code || f.title }
  })
}

function selectRepo(r: any) {
  isDropdownOpen.value = false
  searchQuery.value = ''
  router.push({
    path: '/workspace/files',
    query: { q: r.name }
  })
}

function selectFile(fileItem: any) {
  isDropdownOpen.value = false
  searchQuery.value = ''
  router.push({
    path: '/workspace/tickets',
    query: { q: fileItem.file }
  })
}

function clearSearch() {
  searchQuery.value = ''
  isDropdownOpen.value = false
}

function onQuickSearch() {
  if (!searchQuery.value.trim()) return
  isDropdownOpen.value = false
  router.push({
    path: '/workspace/tickets',
    query: { q: searchQuery.value.trim() }
  })
}

function getSeverityBadge(sev?: string) {
  switch (sev?.toLowerCase()) {
    case 'critical': return 'bg-danger text-danger-fg'
    case 'high': return 'bg-warning text-warning-fg'
    case 'medium': return 'bg-yellow text-yellow-fg'
    case 'low': return 'bg-info text-info-fg'
    default: return 'bg-secondary text-secondary-fg'
  }
}

function handleDocumentClick(e: MouseEvent) {
  if (searchContainerRef.value && !searchContainerRef.value.contains(e.target as Node)) {
    isDropdownOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleDocumentClick)
})

onUnmounted(() => {
  document.removeEventListener('click', handleDocumentClick)
})
</script>

<template>
  <div class="user-workspace-shell">
    <!-- Top Workspace Header (Solid Opaque, Sticky, Zero Bleed) -->
    <header class="workspace-header sticky-top">
      <!-- Main Top Bar -->
      <div class="workspace-top-bar border-bottom">
        <div class="container-xl">
          <div class="d-flex align-items-center justify-content-between py-2 gap-2">
            <!-- Left: Logo & Workspace Label -->
            <div class="d-flex align-items-center gap-3">
              <router-link to="/workspace" class="d-flex align-items-center gap-2 text-decoration-none">
                <img
                  :src="isDark ? '/static/Icon_Dark.png' : '/static/Icon_Light.png'"
                  alt="TAMENG"
                  class="workspace-logo"
                />
                <div class="d-flex flex-column">
                  <span class="fw-bold tracking-tight text-reset fs-3 lh-1">TAMENG</span>
                  <span class="badge bg-teal-lt text-teal text-uppercase px-1 py-0 mt-1" style="font-size: 0.65rem; letter-spacing: 0.05em; width: fit-content;">
                    Personal Workspace
                  </span>
                </div>
              </router-link>
            </div>

            <!-- Center: Quick Search Bar (Desktop) -->
            <div ref="searchContainerRef" class="d-none d-md-flex flex-grow-1 mx-3 position-relative" style="max-width: 460px;">
              <div class="input-icon w-100">
                <span class="input-icon-addon">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon text-muted" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                </span>
                <input
                  v-model="searchQuery"
                  type="text"
                  class="form-control form-control-sm bg-body"
                  placeholder="Cari tiket celah, repositori, file..."
                  @focus="onInputFocus"
                  @input="onInputChange"
                  @keydown.enter="onQuickSearch"
                  @keydown.esc="clearSearch"
                />
                <span v-if="searchQuery" class="input-icon-addon input-icon-addon-end cursor-pointer" @click="clearSearch" title="Hapus pencarian">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-muted" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                </span>
              </div>

              <!-- Live Search Dropdown Popover -->
              <div
                v-if="isDropdownOpen && searchQuery.trim().length > 0"
                class="search-results-dropdown shadow-lg rounded-3 border"
              >
                <!-- Loading State -->
                <div v-if="isLoadingSearchData" class="p-3 text-center text-muted">
                  <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                  <span style="font-size: 0.85rem;">Mencari data...</span>
                </div>

                <!-- Empty State -->
                <div v-else-if="totalResultsCount === 0" class="p-3 text-center text-muted">
                  <div class="small fw-medium">Tidak ada hasil yang cocok dengan "{{ searchQuery }}"</div>
                  <div class="text-muted mt-1" style="font-size: 0.75rem;">Coba judul celah, berkas, CVE, atau repositori.</div>
                </div>

                <!-- Results Grouped by Category -->
                <div v-else class="search-results-body py-1">
                  <!-- 1. Tiket Celah -->
                  <div v-if="filteredFindings.length > 0" class="search-category-group">
                    <div class="search-category-header px-3 py-1 d-flex align-items-center justify-content-between text-uppercase fw-bold text-muted">
                      <span>Tiket Celah</span>
                      <span class="badge bg-primary-lt">{{ filteredFindings.length }}</span>
                    </div>
                    <div
                      v-for="finding in filteredFindings"
                      :key="'f-' + finding.id"
                      class="search-result-item px-3 py-2 cursor-pointer border-bottom-subtle text-start"
                      @click="selectFinding(finding)"
                    >
                      <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                        <span class="badge" :class="getSeverityBadge(finding.severity)">
                          {{ finding.severity?.toUpperCase() || 'INFO' }}
                        </span>
                        <span class="text-muted text-truncate" style="font-size: 0.72rem;">{{ finding.project?.name || finding.code }}</span>
                      </div>
                      <div class="fw-medium text-body text-truncate" style="font-size: 0.85rem;" :title="finding.title">
                        {{ finding.title }}
                      </div>
                      <div v-if="finding.normalization_metadata?.location?.file" class="text-muted text-truncate font-monospace mt-1" style="font-size: 0.72rem;">
                        📄 {{ finding.normalization_metadata.location.file }}
                      </div>
                    </div>
                  </div>

                  <!-- 2. Repositori -->
                  <div v-if="filteredRepos.length > 0" class="search-category-group">
                    <div class="search-category-header px-3 py-1 d-flex align-items-center justify-content-between text-uppercase fw-bold text-muted">
                      <span>Repositori</span>
                      <span class="badge bg-teal-lt">{{ filteredRepos.length }}</span>
                    </div>
                    <div
                      v-for="repo in filteredRepos"
                      :key="'r-' + repo.id"
                      class="search-result-item px-3 py-2 cursor-pointer border-bottom-subtle text-start"
                      @click="selectRepo(repo)"
                    >
                      <div class="d-flex align-items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon text-teal flex-shrink-0" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 7m0 2a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2z" /><path d="M8 7v-2a2 2 0 0 1 2 -2h4a2 2 0 0 1 2 2v2" /></svg>
                        <div class="flex-grow-1 min-w-0">
                          <div class="fw-medium text-body text-truncate" style="font-size: 0.85rem;">{{ repo.name }}</div>
                          <div class="text-muted text-truncate" style="font-size: 0.72rem;">Proyek: {{ repo.project?.name || '-' }} • Branch: {{ repo.default_branch || 'main' }}</div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- 3. Berkas / File Terkait -->
                  <div v-if="filteredFiles.length > 0" class="search-category-group">
                    <div class="search-category-header px-3 py-1 d-flex align-items-center justify-content-between text-uppercase fw-bold text-muted">
                      <span>Berkas Celah</span>
                      <span class="badge bg-purple-lt">{{ filteredFiles.length }}</span>
                    </div>
                    <div
                      v-for="fileItem in filteredFiles"
                      :key="'fl-' + fileItem.file"
                      class="search-result-item px-3 py-2 cursor-pointer border-bottom-subtle text-start"
                      @click="selectFile(fileItem)"
                    >
                      <div class="d-flex align-items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon text-purple flex-shrink-0" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /></svg>
                        <div class="flex-grow-1 min-w-0">
                          <div class="fw-medium text-body text-truncate font-monospace" style="font-size: 0.8rem;">{{ fileItem.file }}</div>
                          <div class="text-muted text-truncate" style="font-size: 0.72rem;">Proyek: {{ fileItem.projectName }} ({{ fileItem.count }} celah)</div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Dropdown Footer -->
                <div class="search-dropdown-footer p-2 bg-body-tertiary border-top d-flex align-items-center justify-content-between">
                  <span class="text-muted" style="font-size: 0.75rem;">
                    Tekan <kbd class="bg-body text-body border px-1">Enter ↵</kbd> untuk hasil lengkap
                  </span>
                  <button
                    type="button"
                    class="btn btn-sm btn-link p-0 text-decoration-none text-primary"
                    style="font-size: 0.75rem;"
                    @click="onQuickSearch"
                  >
                    Buka di Tiket Celah →
                  </button>
                </div>
              </div>
            </div>

            <!-- Right: Actions & Profile -->
            <div class="d-flex align-items-center gap-2">
              <!-- Tombol Aksi Cepat: + Scan Mandiri -->
              <router-link
                to="/workspace/scans"
                class="btn btn-primary btn-sm d-none d-sm-inline-flex align-items-center gap-1 shadow-sm"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 5l0 14" /><path d="M5 12l14 0" /></svg>
                <span>Scan Baru</span>
              </router-link>

              <!-- Theme Toggle -->
              <button
                type="button"
                class="btn btn-icon btn-ghost-secondary btn-sm"
                :title="isDark ? 'Beralih ke mode terang' : 'Beralih ke mode gelap'"
                @click="toggleTheme"
              >
                <!-- Sun / Moon -->
                <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7" /></svg>
                <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454z" /></svg>
              </button>

              <!-- User Profile Dropdown -->
              <div class="dropdown">
                <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown" aria-label="Buka profil">
                  <span class="avatar avatar-sm bg-teal-lt text-teal fw-bold">
                    {{ userInitials }}
                  </span>
                  <div class="d-none d-lg-block ps-2 text-start">
                    <div class="fw-semibold text-truncate" style="max-width: 140px;">
                      {{ currentUser?.name || 'Pengguna' }}
                    </div>
                    <div class="text-secondary small mt-0" style="font-size: 0.72rem;">
                      {{ roleDisplayName }}
                    </div>
                  </div>
                </a>
                <div class="dropdown-menu dropdown-menu-end shadow-lg" style="min-width: 250px;">
                  <div class="dropdown-header d-flex flex-column gap-1 pb-2">
                    <div class="fw-bold text-truncate">{{ currentUser?.name }}</div>
                    <div class="small text-muted text-truncate">{{ currentUser?.email }}</div>
                    <div class="mt-1">
                      <span class="badge" :class="roleBadgeClass">{{ roleDisplayName }}</span>
                    </div>
                    <div v-if="assignedProjects.length > 0" class="text-secondary small mt-1">
                      <strong>{{ assignedProjects.length }} Proyek Ditugaskan:</strong>
                      <div class="text-truncate text-muted" :title="assignedProjectNames" style="max-width: 210px;">
                        {{ assignedProjectNames }}
                      </div>
                    </div>
                  </div>
                  <div class="dropdown-divider m-0"></div>

                  <!-- Link Khusus Admin/Analyst jika ingin beralih ke Admin SOC -->
                  <router-link
                    v-if="isAnalystOrAdmin"
                    to="/"
                    class="dropdown-item text-primary"
                  >
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon dropdown-item-icon text-primary" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v8h-6z" /><path d="M4 16h6v4h-6z" /><path d="M14 12h6v8h-6z" /><path d="M14 4h6v4h-6z" /></svg>
                    Buka Dasbor SOC Admin
                  </router-link>
                  <div v-if="isAnalystOrAdmin" class="dropdown-divider m-0"></div>

                  <router-link to="/workspace/profile" class="dropdown-item">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon dropdown-item-icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 10m-3 0a3 3 0 1 0 6 0a3 3 0 1 0 -6 0" /><path d="M6.168 18.849a4 4 0 0 1 3.832 -2.849h4a4 4 0 0 1 3.834 2.855" /></svg>
                    Profil & Pengaturan Akun
                  </router-link>

                  <div class="dropdown-divider m-0"></div>
                  <a href="#" class="dropdown-item text-danger" @click.prevent="handleLogout">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon dropdown-item-icon text-danger" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" /></svg>
                    Keluar (Logout)
                  </a>
                </div>
              </div>

            </div>
          </div>
        </div>
      </div>

      <!-- Horizontal Navigation Tabs (Clean, Single Line, No Wrap) -->
      <div class="workspace-nav-bar border-bottom">
        <div class="container-xl">
          <nav class="d-flex align-items-center gap-1 py-1 overflow-x-auto text-nowrap">
            <router-link
              v-for="item in navItems"
              :key="item.path"
              :to="item.path"
              class="workspace-nav-link"
              :class="{ active: route.path === item.path || (item.path !== '/workspace' && route.path.startsWith(item.path)) }"
            >
              <!-- Icon Home -->
              <svg v-if="item.icon === 'home'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l-2 0l9 -9l9 9l-2 0" /><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7" /><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6" /></svg>

              <!-- Icon Checklist (Tiket) -->
              <svg v-else-if="item.icon === 'checklist'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3.5 5.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 11.5l1.5 1.5l2.5 -2.5" /><path d="M3.5 17.5l1.5 1.5l2.5 -2.5" /><path d="M11 6l9 0" /><path d="M11 12l9 0" /><path d="M11 18l9 0" /></svg>

              <!-- Icon Folder (Berkas & Repo) -->
              <svg v-else-if="item.icon === 'folder'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>

              <!-- Icon Scan Mandiri -->
              <svg v-else-if="item.icon === 'scan'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>

              <!-- Icon Laporan -->
              <svg v-else-if="item.icon === 'report'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 17l0 -5" /><path d="M12 17l0 -1" /><path d="M15 17l0 -3" /></svg>

              <span>{{ item.label }}</span>
            </router-link>
          </nav>
        </div>
      </div>
    </header>

    <!-- Main Workspace Area -->
    <main class="workspace-main-content">
      <div class="container-xl py-3 py-md-4">
        <slot />
      </div>
    </main>

    <!-- Workspace Footer -->
    <footer class="workspace-footer border-top py-3 text-muted mt-auto">
      <div class="container-xl">
        <div class="d-flex flex-column flex-sm-row align-items-center justify-content-between gap-2 text-center text-sm-start small">
          <div>
            &copy; 2026 <strong>TAMENG Security Workspace</strong> &bull; Ruang Kerja Personal & Pemindaian Mandiri.
          </div>
          <div class="d-flex align-items-center gap-3">
            <span class="d-inline-flex align-items-center gap-1 text-success">
              <span class="badge badge-dot bg-success"></span>
              Sistem Operasional & Siap Digunakan
            </span>
          </div>
        </div>
      </div>
    </footer>
  </div>
</template>

<style scoped>
.user-workspace-shell {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background-color: var(--tblr-body-bg, #0b1727) !important;
  color: var(--tblr-body-color, #dce1e7);
}

.workspace-header {
  position: sticky;
  top: 0;
  z-index: 1030;
  background-color: var(--tblr-bg-surface, #182433) !important;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.15);
}

.workspace-top-bar {
  background-color: var(--tblr-bg-surface, #182433) !important;
}

.workspace-logo {
  height: 40px;
  max-height: 44px;
  width: auto;
  object-fit: contain;
  transition: transform 0.2s ease;
}

.workspace-logo:hover {
  transform: scale(1.04);
}

.workspace-nav-bar {
  background-color: var(--tblr-bg-surface-secondary, var(--tblr-body-bg, #111e2e)) !important;
}

.workspace-nav-link {
  display: inline-flex;
  align-items: center;
  padding: 0.5rem 0.9rem;
  font-size: 0.875rem;
  font-weight: 500;
  color: var(--tblr-secondary, #94a3b8);
  text-decoration: none;
  border-radius: 6px;
  white-space: nowrap;
  transition: all 0.15s ease-in-out;
}

.workspace-nav-link:hover {
  color: var(--tblr-primary, #0054a6);
  background-color: rgba(var(--tblr-primary-rgb, 0, 84, 166), 0.12);
}

.workspace-nav-link.active {
  color: var(--tblr-primary, #0054a6);
  background-color: rgba(var(--tblr-primary-rgb, 0, 84, 166), 0.18);
  font-weight: 600;
}

:deep(.dropdown-menu) {
  background-color: var(--tblr-bg-surface, #182433) !important;
  border-color: var(--tblr-border-color, rgba(255, 255, 255, 0.12)) !important;
}

.workspace-main-content {
  flex: 1 0 auto;
  background-color: var(--tblr-body-bg, #0b1727) !important;
}

.workspace-footer {
  background-color: var(--tblr-bg-surface, #182433) !important;
}

.search-results-dropdown {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  background-color: var(--tblr-bg-surface, #182433) !important;
  border-color: var(--tblr-border-color, rgba(255, 255, 255, 0.12)) !important;
  z-index: 1060;
  max-height: 480px;
  overflow-y: auto;
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.35), 0 8px 10px -6px rgba(0, 0, 0, 0.2);
}

.search-category-header {
  font-size: 0.68rem;
  letter-spacing: 0.05em;
  background-color: rgba(var(--tblr-body-color-rgb, 220, 225, 231), 0.04);
}

.search-result-item {
  transition: background-color 0.15s ease-in-out;
}

.search-result-item:hover {
  background-color: rgba(var(--tblr-primary-rgb, 0, 84, 166), 0.12);
}

.cursor-pointer {
  cursor: pointer;
}

.border-bottom-subtle {
  border-bottom: 1px solid var(--tblr-border-color-translucent, rgba(255, 255, 255, 0.06));
}

.input-icon-addon-end {
  right: 8px;
  left: auto;
  pointer-events: auto;
}
</style>
