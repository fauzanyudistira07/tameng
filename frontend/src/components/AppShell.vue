<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { authState, logoutUser } from '../stores/authStore'
import { prefetchAppData, prefetchRouteData } from '../services/prefetchService'

const router = useRouter()

const user = computed(() => authState.user)
const userRole = computed(() => user.value?.role?.name ?? '')
const roleDisplayName = computed(() => user.value?.role?.display_name ?? 'Pengguna')

// Permissions based on Role
const canAccessDashboard = computed(() => ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'].includes(userRole.value))
const canAccessScanJobs = computed(() => ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'].includes(userRole.value))
const canAccessFindings = computed(() => ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer', 'developer'].includes(userRole.value))
const canAccessReports = computed(() => ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'].includes(userRole.value))
const canAccessAssets = computed(() => ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'].includes(userRole.value))
const canAccessGovernance = computed(() => ['super_admin', 'security_admin', 'security_analyst', 'auditor', 'viewer'].includes(userRole.value))
const canAccessAudit = computed(() => ['super_admin', 'security_admin', 'auditor'].includes(userRole.value))
const canAccessUsers = computed(() => ['super_admin', 'security_admin'].includes(userRole.value))

onMounted(() => {
  if (userRole.value) {
    prefetchAppData(userRole.value)
  }
})

async function submitKeluar() {
  await logoutUser()
  router.push({ name: 'login' })
}

function quickNewScan() {
  if (userRole.value === 'developer') {
    router.push({ name: 'my-scan-requests' })
  } else {
    router.push({ name: 'scan-jobs' })
  }
}
</script>

<template>
  <div class="app-shell">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <div class="brand">
          <div class="brand-mark">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
          </div>
          <div class="brand-info">
            <strong>TAMENG</strong>
            <span>Teknologi Analisis & Monitoring Keamanan</span>
          </div>
        </div>
      </div>

      <!-- Navigation Menu Groups -->
      <!-- 1. Operasional Scan -->
      <div class="nav-group">
        <div class="nav-group-title">Operasional Scan</div>
        <nav class="nav">
          <router-link
            v-if="canAccessDashboard"
            :to="{ name: 'dashboard' }"
            active-class="active"
            @mouseenter="prefetchRouteData('dashboard')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="3" y="3" width="7" height="7"></rect>
              <rect x="14" y="3" width="7" height="7"></rect>
              <rect x="14" y="14" width="7" height="7"></rect>
              <rect x="3" y="14" width="7" height="7"></rect>
            </svg>
            <span>Dasbor Keamanan</span>
          </router-link>

          <router-link
            :to="{ name: 'my-scan-requests' }"
            active-class="active"
            @mouseenter="prefetchRouteData('my-scan-requests')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"></circle>
              <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            <span>Scan Mandiri</span>
            <span v-if="userRole === 'developer'" class="nav-badge">Dev</span>
          </router-link>

          <router-link
            v-if="canAccessScanJobs"
            :to="{ name: 'scan-jobs' }"
            active-class="active"
            @mouseenter="prefetchRouteData('scan-jobs')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
            </svg>
            <span>Pekerjaan Scan</span>
          </router-link>
        </nav>
      </div>

      <!-- 2. Manajemen Aset -->
      <div v-if="canAccessAssets" class="nav-group">
        <div class="nav-group-title">Inventaris & Aset</div>
        <nav class="nav">
          <router-link
            :to="{ name: 'projects' }"
            active-class="active"
            @mouseenter="prefetchRouteData('projects')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
            </svg>
            <span>Proyek</span>
          </router-link>

          <router-link
            :to="{ name: 'repositories' }"
            active-class="active"
            @mouseenter="prefetchRouteData('repositories')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="18" cy="18" r="3"></circle>
              <circle cx="6" cy="6" r="3"></circle>
              <path d="M13 6h3a2 2 0 0 1 2 2v7"></path>
              <line x1="6" y1="9" x2="6" y2="21"></line>
            </svg>
            <span>Repositori Kode</span>
          </router-link>

          <router-link
            :to="{ name: 'targets' }"
            active-class="active"
            @mouseenter="prefetchRouteData('targets')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="22" y1="12" x2="18" y2="12"></line>
              <line x1="6" y1="12" x2="2" y2="12"></line>
              <line x1="12" y1="6" x2="12" y2="2"></line>
              <line x1="12" y1="22" x2="12" y2="18"></line>
            </svg>
            <span>Target Web & API</span>
          </router-link>
        </nav>
      </div>

      <!-- 3. Tata Kelola & Kebijakan -->
      <div v-if="canAccessGovernance" class="nav-group">
        <div class="nav-group-title">Tata Kelola & Izin</div>
        <nav class="nav">
          <router-link
            :to="{ name: 'scopes' }"
            active-class="active"
            @mouseenter="prefetchRouteData('scopes')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
            </svg>
            <span>Ruang Lingkup (Scope)</span>
          </router-link>

          <router-link
            :to="{ name: 'authorizations' }"
            active-class="active"
            @mouseenter="prefetchRouteData('authorizations')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M21 2l-2 2m-1.5 1.5L14 9l-3-3L8 9 5 6 2 9l3 3-3 3 3 3 3-3 3 3 3-3 3.5-3.5L21 2z"></path>
            </svg>
            <span>Otorisasi Scan</span>
          </router-link>

          <router-link
            :to="{ name: 'engines' }"
            active-class="active"
            @mouseenter="prefetchRouteData('engines')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="4" y="4" width="16" height="16" rx="2" ry="2"></rect>
              <rect x="9" y="9" width="6" height="6"></rect>
              <line x1="9" y1="1" x2="9" y2="4"></line>
              <line x1="15" y1="1" x2="15" y2="4"></line>
              <line x1="9" y1="20" x2="9" y2="23"></line>
              <line x1="15" y1="20" x2="15" y2="23"></line>
              <line x1="20" y1="9" x2="23" y2="9"></line>
              <line x1="20" y1="14" x2="23" y2="14"></line>
              <line x1="1" y1="9" x2="4" y2="9"></line>
              <line x1="1" y1="14" x2="4" y2="14"></line>
            </svg>
            <span>Engine Registry</span>
          </router-link>
        </nav>
      </div>

      <!-- 4. Analisis & Kepatuhan -->
      <div class="nav-group">
        <div class="nav-group-title">Analisis & Audit</div>
        <nav class="nav">
          <router-link
            v-if="canAccessFindings"
            :to="{ name: 'findings' }"
            active-class="active"
            @mouseenter="prefetchRouteData('findings')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
            <span>Temuan Kerentanan</span>
          </router-link>

          <router-link
            v-if="canAccessReports"
            :to="{ name: 'reports' }"
            active-class="active"
            @mouseenter="prefetchRouteData('reports')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
              <line x1="16" y1="13" x2="8" y2="13"></line>
              <line x1="16" y1="17" x2="8" y2="17"></line>
              <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            <span>Laporan Keamanan</span>
          </router-link>

          <router-link
            v-if="canAccessAudit"
            :to="{ name: 'audit-logs' }"
            active-class="active"
            @mouseenter="prefetchRouteData('audit-logs')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"></path>
            </svg>
            <span>Log Audit Forensik</span>
          </router-link>
        </nav>
      </div>

      <!-- 5. Administrasi -->
      <div v-if="canAccessUsers" class="nav-group" style="margin-top: auto;">
        <div class="nav-group-title">Administrasi</div>
        <nav class="nav">
          <router-link
            :to="{ name: 'users' }"
            active-class="active"
            @mouseenter="prefetchRouteData('users')"
          >
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
              <circle cx="9" cy="7" r="4"></circle>
              <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
              <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            <span>Manajemen Pengguna</span>
          </router-link>
        </nav>
      </div>
    </aside>

    <!-- Main Content Area -->
    <main class="content">
      <header class="topbar">
        <div class="topbar-title-area">
          <p class="eyebrow">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
            </svg>
            <slot name="eyebrow">Sistem TAMENG — Pertahanan Siber Terpadu</slot>
          </p>
          <h1><slot name="title">Pusat Kendali Keamanan</slot></h1>
        </div>

        <div class="user-actions">
          <button class="btn btn-primary-glow" type="button" @click="quickNewScan">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="12" y1="5" x2="12" y2="19"></line>
              <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            <span>+ Scan Baru</span>
          </button>

          <div class="current-user-card">
            <div class="user-avatar">
              {{ user?.name ? user.name.slice(0, 2).toUpperCase() : 'TA' }}
            </div>
            <div class="user-meta">
              <strong>{{ user?.name ?? 'Pengguna' }}</strong>
              <span class="role-pill">{{ roleDisplayName }}</span>
            </div>
          </div>

          <button class="secondary-button btn-sm" type="button" @click="submitKeluar">
            Keluar
          </button>
        </div>
      </header>

      <slot />
    </main>
  </div>
</template>
