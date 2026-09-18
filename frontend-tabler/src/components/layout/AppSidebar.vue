<script setup lang="ts">
import { watch } from "vue";
import { useRoute } from "vue-router";
import { useTheme } from "../../composables/useTheme";
import { useAuth } from "../../composables/useAuth";
import { useSidebar } from "../../composables/useSidebar";

const route = useRoute();
const { isMobileSidebarOpen, closeMobileSidebar } = useSidebar();
const { toggleTheme } = useTheme();
const { currentUser, userInitials, roleDisplayName } = useAuth();

// Tutup drawer secara otomatis jika rute berpindah
watch(() => route.path, () => {
  closeMobileSidebar();
});
</script>

<template>
  <!-- Backdrop saat drawer terbuka di layar mobile -->
  <div
    v-if="isMobileSidebarOpen"
    class="sidebar-backdrop d-lg-none"
    @click="closeMobileSidebar"
  ></div>

  <aside
    class="navbar navbar-vertical navbar-expand-lg"
    :class="{ show: isMobileSidebarOpen }"
    data-bs-theme="dark"
  >
    <div class="container-fluid px-2">
      <!-- Mobile Drawer Header dengan Tombol Tutup (X) -->
      <div class="d-flex align-items-center justify-content-between w-100 d-lg-none px-2 py-3 border-bottom border-dark">
        <div class="d-flex align-items-center gap-2">
          <span class="avatar avatar-xs bg-primary text-primary-fg rounded">
            <!-- Shield SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
              <path stroke="none" d="M0 0h24v24H0z" fill="none" />
              <path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" />
              <path d="M12 11m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
            </svg>
          </span>
          <span class="fs-3 fw-bold text-white lh-1">TAMENG</span>
        </div>
        <button
          type="button"
          class="btn-close btn-close-white"
          aria-label="Tutup Menu"
          @click="closeMobileSidebar"
        ></button>
      </div>

      <!-- Desktop Brand Logo TAMENG -->
      <h1 class="navbar-brand navbar-brand-autodark px-2 py-3 d-none d-lg-flex">
        <router-link
          to="/"
          class="d-flex align-items-center gap-2 text-decoration-none"
        >
          <span class="avatar avatar-sm bg-primary text-primary-fg rounded">
            <!-- Shield SVG -->
            <svg
              xmlns="http://www.w3.org/2000/svg"
              class="icon"
              width="24"
              height="24"
              viewBox="0 0 24 24"
              stroke-width="2"
              stroke="currentColor"
              fill="none"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path stroke="none" d="M0 0h24v24H0z" fill="none" />
              <path
                d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
              />
              <path d="M12 11m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
              <path d="M12 12l0 2.5" />
            </svg>
          </span>
          <span class="d-flex flex-column text-start">
            <span class="fs-2 fw-bold text-reset lh-1">TAMENG</span>
            <span class="fs-6 text-muted mt-1">Sistem Keamanan Siber</span>
          </span>
        </router-link>
      </h1>

      <div
        class="collapse navbar-collapse"
        :class="{ show: isMobileSidebarOpen }"
        id="sidebar-menu"
      >
        <ul class="navbar-nav pt-lg-2">
          <!-- 1. Operasional Scan -->
          <li class="nav-item">
            <router-link
              class="nav-link"
              :class="{ active: route.path === '/' }"
              to="/"
            >
              <span class="nav-link-icon d-md-none d-lg-inline-block">
                <!-- icon: layout-dashboard -->
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M4 4h6v8h-6z" />
                  <path d="M4 16h6v4h-6z" />
                  <path d="M14 12h6v8h-6z" />
                  <path d="M14 4h6v4h-6z" />
                </svg>
              </span>
              <span class="nav-link-title"> Dasbor Keamanan </span>
            </router-link>
          </li>
          <li class="nav-item">
            <router-link
              class="nav-link"
              :class="{ active: route.path === '/scan-jobs' }"
              to="/scan-jobs"
            >
              <span class="nav-link-icon d-md-none d-lg-inline-block">
                <!-- icon: activity -->
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M3 12h4l3 8l4 -16l3 8h4" />
                </svg>
              </span>
              <span class="nav-link-title"> Pekerjaan Scan </span>
            </router-link>
          </li>
          <li class="nav-item">
            <router-link
              class="nav-link"
              :class="{ active: route.path === '/scan-mandiri' || route.path === '/scan-saya' }"
              to="/scan-mandiri"
            >
              <span class="nav-link-icon d-md-none d-lg-inline-block">
                <!-- icon: scan -->
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M4 7v-1a2 2 0 0 1 2 -2h2" />
                  <path d="M4 17v1a2 2 0 0 0 2 2h2" />
                  <path d="M16 4h2a2 2 0 0 1 2 2v1" />
                  <path d="M16 20h2a2 2 0 0 0 2 -2v-1" />
                  <path d="M5 12l14 0" />
                </svg>
              </span>
              <span class="nav-link-title"> Scan Mandiri </span>
            </router-link>
          </li>

          <!-- 2. Inventaris & Aset -->
          <li class="nav-item dropdown" :class="{ active: ['/projects', '/repositories', '/targets'].includes(route.path) }">
            <a
              class="nav-link dropdown-toggle"
              :class="{ active: ['/projects', '/repositories', '/targets'].includes(route.path) }"
              href="#navbar-assets"
              data-bs-toggle="dropdown"
              data-bs-auto-close="false"
              role="button"
              :aria-expanded="['/projects', '/repositories', '/targets'].includes(route.path) ? 'true' : 'false'"
            >
              <span class="nav-link-icon d-md-none d-lg-inline-block">
                <!-- icon: folder -->
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path
                    d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2"
                  />
                </svg>
              </span>
              <span class="nav-link-title"> Inventaris & Aset </span>
            </a>
            <div class="dropdown-menu" :class="{ show: ['/projects', '/repositories', '/targets'].includes(route.path) }">
              <div class="dropdown-menu-columns">
                <div class="dropdown-menu-column">
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/projects' }"
                    to="/projects"
                  >
                    Proyek
                  </router-link>
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/repositories' }"
                    to="/repositories"
                  >
                    Repositori Kode
                  </router-link>
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/targets' }"
                    to="/targets"
                  >
                    Target Web, API & APK
                  </router-link>
                </div>
              </div>
            </div>
          </li>

          <!-- 3. Tata Kelola & Mesin -->
          <li
            class="nav-item dropdown"
            :class="{ active: ['/scopes', '/authorizations', '/engines'].includes(route.path) }"
          >
            <a
              class="nav-link dropdown-toggle"
              :class="{ active: ['/scopes', '/authorizations', '/engines'].includes(route.path) }"
              href="#navbar-governance"
              data-bs-toggle="dropdown"
              data-bs-auto-close="false"
              role="button"
              :aria-expanded="['/scopes', '/authorizations', '/engines'].includes(route.path) ? 'true' : 'false'"
            >
              <span class="nav-link-icon d-md-none d-lg-inline-block">
                <!-- icon: shield-check -->
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path
                    d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"
                  />
                  <path d="M9 12l2 2l4 -4" />
                </svg>
              </span>
              <span class="nav-link-title"> Tata Kelola & Mesin </span>
            </a>
            <div
              class="dropdown-menu"
              :class="{ show: ['/scopes', '/authorizations', '/engines'].includes(route.path) }"
            >
              <div class="dropdown-menu-columns">
                <div class="dropdown-menu-column">
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/scopes' }"
                    to="/scopes"
                  >
                    Ruang Lingkup (Scope)
                  </router-link>
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/authorizations' }"
                    to="/authorizations"
                  >
                    Otorisasi Scan
                  </router-link>
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/engines' }"
                    to="/engines"
                  >
                    Engine Registry
                  </router-link>
                </div>
              </div>
            </div>
          </li>

          <!-- 4. Analisis & Laporan -->
          <li
            class="nav-item dropdown"
            :class="{ active: ['/findings', '/reports'].includes(route.path) }"
          >
            <a
              class="nav-link dropdown-toggle"
              :class="{ active: ['/findings', '/reports'].includes(route.path) }"
              href="#navbar-reports"
              data-bs-toggle="dropdown"
              data-bs-auto-close="false"
              role="button"
              :aria-expanded="['/findings', '/reports'].includes(route.path) ? 'true' : 'false'"
            >
              <span class="nav-link-icon d-md-none d-lg-inline-block">
                <!-- icon: file-analytics -->
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  class="icon"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                  stroke-width="2"
                  stroke="currentColor"
                  fill="none"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                  <path
                    d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"
                  />
                  <path d="M9 17l0 -5" />
                  <path d="M12 17l0 -1" />
                  <path d="M15 17l0 -3" />
                </svg>
              </span>
              <span class="nav-link-title"> Analisis & Laporan </span>
            </a>
            <div
              class="dropdown-menu"
              :class="{ show: ['/findings', '/reports'].includes(route.path) }"
            >
              <div class="dropdown-menu-columns">
                <div class="dropdown-menu-column">
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/findings' }"
                    to="/findings"
                  >
                    Temuan Kerentanan
                  </router-link>
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/reports' }"
                    to="/reports"
                  >
                    Laporan Keamanan
                  </router-link>
                </div>
              </div>
            </div>
          </li>

          <!-- 5. Administrasi & Audit -->
          <li
            class="nav-item dropdown"
            :class="{ active: ['/audit-logs', '/users'].includes(route.path) }"
          >
            <a
              class="nav-link dropdown-toggle"
              :class="{ active: ['/audit-logs', '/users'].includes(route.path) }"
              href="#navbar-admin"
              data-bs-toggle="dropdown"
              data-bs-auto-close="false"
              role="button"
              :aria-expanded="['/audit-logs', '/users'].includes(route.path) ? 'true' : 'false'"
            >
              <span class="nav-link-icon d-md-none d-lg-inline-block">
                <!-- icon: users -->
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="24"
                  height="24"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  class="icon icon-tabler icons-tabler-outline icon-tabler-users"
                >
                  <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                  <path d="M5 7a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" />
                  <path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" />
                  <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                  <path d="M21 21v-2a4 4 0 0 0 -3 -3.85" />
                </svg>
              </span>
              <span class="nav-link-title"> Administrasi & Audit </span>
            </a>
            <div
              class="dropdown-menu"
              :class="{ show: ['/audit-logs', '/users'].includes(route.path) }"
            >
              <div class="dropdown-menu-columns">
                <div class="dropdown-menu-column">
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/audit-logs' }"
                    to="/audit-logs"
                  >
                    Log Audit Forensik
                  </router-link>
                  <router-link
                    class="dropdown-item"
                    :class="{ active: route.path === '/users' }"
                    to="/users"
                  >
                    Manajemen Pengguna
                  </router-link>
                </div>
              </div>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </aside>
</template>

<style scoped>
/* Sidebar desktop width optimal agar label menu panjang tidak berhimpitan */
@media (min-width: 992px) {
  .navbar-vertical.navbar-expand-lg {
    width: 16.5rem !important;
  }
}

/* Sidebar mobile drawer */
@media (max-width: 991.98px) {
  .navbar-vertical.navbar-expand-lg {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    bottom: 0 !important;
    width: min(300px, 85vw) !important;
    max-width: 85vw !important;
    height: 100vh !important;
    z-index: 1060 !important;
    transform: translateX(-100%);
    transition: transform 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: none;
    display: flex !important;
    flex-direction: column !important;
    overflow-y: auto !important;
  }

  .navbar-vertical.navbar-expand-lg.show {
    transform: translateX(0) !important;
    box-shadow: 0 0 35px rgba(0, 0, 0, 0.75) !important;
  }

  .navbar-vertical.navbar-expand-lg .navbar-collapse {
    display: block !important;
    visibility: visible !important;
  }
}

.sidebar-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  backdrop-filter: blur(4px);
  z-index: 1055;
  transition: opacity 0.2s ease;
}

/* Penataan Nav Link agar fleksibel dan rapi */
:deep(.navbar-nav .nav-link) {
  display: flex !important;
  align-items: center !important;
  padding: 0.55rem 0.75rem !important;
  border-radius: var(--tblr-border-radius, 6px);
  width: 100% !important;
}

/* Judul teks menu: fleksibel, rapi, dan tidak menabrak chevron */
:deep(.navbar-nav .nav-link .nav-link-title) {
  flex: 1 1 auto !important;
  min-width: 0 !important;
  white-space: nowrap !important;
  overflow: hidden !important;
  text-overflow: ellipsis !important;
  margin-right: 0.5rem !important;
}

/* Icon menu tidak menciut saat teks panjang */
:deep(.navbar-nav .nav-link .nav-link-icon) {
  flex-shrink: 0 !important;
  margin-right: 0.65rem !important;
}

/* Dropdown toggle container */
:deep(.navbar-nav .nav-link.dropdown-toggle) {
  position: relative !important;
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
}

/* Tanda panah dropdown (chevron) rapi di sisi kanan dengan jarak yang proporsional */
:deep(.navbar-nav .nav-link.dropdown-toggle::after) {
  content: "" !important;
  display: inline-block !important;
  margin-left: auto !important;
  margin-right: 0.2rem !important;
  flex-shrink: 0 !important;
  width: 0.42rem !important;
  height: 0.42rem !important;
  border-bottom: 2px solid currentColor !important;
  border-left: 2px solid currentColor !important;
  border-top: 0 !important;
  border-right: 0 !important;
  transform: rotate(-45deg) !important;
  transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.15s ease !important;
  vertical-align: middle !important;
  opacity: 0.75 !important;
}

/* Hover state pada toggle dropdown */
:deep(.navbar-nav .nav-link.dropdown-toggle:hover::after) {
  opacity: 1 !important;
}

/* Rotasi chevron saat dropdown terbuka / aktif (berputar ke atas) */
:deep(.navbar-nav .nav-item.dropdown.show > .nav-link.dropdown-toggle::after),
:deep(.navbar-nav .nav-link.dropdown-toggle[aria-expanded="true"]::after),
:deep(.navbar-nav .nav-link.dropdown-toggle.show::after),
:deep(.navbar-nav .nav-item.dropdown:has(.dropdown-menu.show) > .nav-link.dropdown-toggle::after) {
  transform: rotate(135deg) !important;
  opacity: 1 !important;
}
</style>
