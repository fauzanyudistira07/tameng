<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useTheme } from '../../composables/useTheme'
import { useAuth } from '../../composables/useAuth'

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

const isMobileNavOpen = ref(false)
const searchQuery = ref('')

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
    label: 'Scan Mandiri',
    path: '/workspace/scans',
    icon: 'scan'
  },
  {
    label: 'Laporan Keamanan',
    path: '/workspace/reports',
    icon: 'report'
  }
]

watch(() => route.path, () => {
  isMobileNavOpen.value = false
})

function onQuickSearch() {
  if (!searchQuery.value.trim()) return
  router.push({
    path: '/workspace/tickets',
    query: { q: searchQuery.value.trim() }
  })
}
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
            <div class="d-none d-md-flex flex-grow-1 mx-3" style="max-width: 440px;">
              <div class="input-icon w-100">
                <span class="input-icon-addon">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon text-muted" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                </span>
                <input
                  v-model="searchQuery"
                  type="text"
                  class="form-control form-control-sm bg-body"
                  placeholder="Cari tiket celah, repositori, file..."
                  @keydown.enter="onQuickSearch"
                />
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

              <!-- Hamburger Button for Mobile -->
              <button
                type="button"
                class="btn btn-icon btn-ghost-secondary d-md-none"
                aria-label="Toggle Navigation"
                @click="isMobileNavOpen = !isMobileNavOpen"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 6l16 0" /><path d="M4 12l16 0" /><path d="M4 18l16 0" /></svg>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Horizontal Navigation Tabs (Clean, Single Line, No Wrap) -->
      <div class="workspace-nav-bar border-bottom">
        <div class="container-xl">
          <nav class="d-none d-md-flex align-items-center gap-1 py-1 overflow-x-auto text-nowrap">
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
              <svg v-else-if="item.icon === 'folder'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>

              <!-- Icon Scan Mandiri -->
              <svg v-else-if="item.icon === 'scan'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>

              <!-- Icon Laporan -->
              <svg v-else-if="item.icon === 'report'" xmlns="http://www.w3.org/2000/svg" class="icon icon-xs me-1" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 17l0 -5" /><path d="M12 17l0 -1" /><path d="M15 17l0 -3" /></svg>

              <span>{{ item.label }}</span>
            </router-link>
          </nav>

          <!-- Mobile Dropdown Navigation -->
          <div v-if="isMobileNavOpen" class="d-md-none py-2 border-top">
            <div class="input-icon mb-2">
              <span class="input-icon-addon">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
              </span>
              <input
                v-model="searchQuery"
                type="text"
                class="form-control form-control-sm"
                placeholder="Cari tiket, repositori, file..."
                @keydown.enter="onQuickSearch"
              />
            </div>
            <div class="list-group list-group-flush">
              <router-link
                v-for="item in navItems"
                :key="item.path"
                :to="item.path"
                class="list-group-item list-group-item-action py-2 px-1 border-0"
                :class="{ 'fw-bold text-primary': route.path === item.path }"
              >
                {{ item.label }}
              </router-link>
            </div>
          </div>
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
</style>
