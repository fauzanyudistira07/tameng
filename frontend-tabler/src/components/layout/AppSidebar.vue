<script setup lang="ts">
import { ref } from "vue";
import { useRoute } from "vue-router";
import { useTheme } from "../../composables/useTheme";
import { useAuth } from "../../composables/useAuth";

const route = useRoute();
const isMobileSidebarOpen = ref(false);
const { toggleTheme } = useTheme();
const { currentUser, userInitials, roleDisplayName } = useAuth();
</script>

<template>
  <aside class="navbar navbar-vertical navbar-expand" data-bs-theme="dark">
    <div class="container-fluid">
      <button
        class="navbar-toggler"
        type="button"
        @click="isMobileSidebarOpen = !isMobileSidebarOpen"
        aria-label="Toggle navigation"
      >
        <span class="navbar-toggler-icon"></span>
      </button>

      <!-- Brand Logo TAMENG -->
      <h1 class="navbar-brand navbar-brand-autodark px-2 py-3">
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
          <li class="nav-item dropdown">
            <a
              class="nav-link dropdown-toggle"
              href="#navbar-assets"
              data-bs-toggle="dropdown"
              data-bs-auto-close="false"
              role="button"
              aria-expanded="false"
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
            <div class="dropdown-menu">
              <div class="dropdown-menu-columns">
                <div class="dropdown-menu-column">
                  <a class="dropdown-item" href="/projects"> Proyek </a>
                  <a class="dropdown-item" href="/repositories">
                    Repositori Kode
                  </a>
                  <a class="dropdown-item" href="/targets">
                    Target Web & API
                  </a>
                </div>
              </div>
            </div>
          </li>

          <!-- 3. Tata Kelola & Mesin -->
          <li class="nav-item dropdown">
            <a
              class="nav-link dropdown-toggle"
              href="#navbar-governance"
              data-bs-toggle="dropdown"
              data-bs-auto-close="false"
              role="button"
              aria-expanded="false"
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
            <div class="dropdown-menu">
              <div class="dropdown-menu-columns">
                <div class="dropdown-menu-column">
                  <a class="dropdown-item" href="/scopes">
                    Ruang Lingkup (Scope)
                  </a>
                  <a class="dropdown-item" href="/authorizations">
                    Otorisasi Scan
                  </a>
                  <a class="dropdown-item" href="/engines"> Engine Registry </a>
                </div>
              </div>
            </div>
          </li>

          <!-- 4. Analisis & Laporan -->
          <li class="nav-item dropdown">
            <a
              class="nav-link dropdown-toggle"
              href="#navbar-reports"
              data-bs-toggle="dropdown"
              data-bs-auto-close="false"
              role="button"
              aria-expanded="false"
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
            <div class="dropdown-menu">
              <div class="dropdown-menu-columns">
                <div class="dropdown-menu-column">
                  <a class="dropdown-item" href="/findings">
                    Temuan Kerentanan
                  </a>
                  <a class="dropdown-item" href="/reports">
                    Laporan Keamanan
                  </a>
                </div>
              </div>
            </div>
          </li>

          <!-- 5. Administrasi & Audit -->
          <li
            class="nav-item dropdown"
            :class="{ active: route.path === '/users' }"
          >
            <a
              class="nav-link dropdown-toggle"
              :class="{ active: route.path === '/users' }"
              href="#navbar-admin"
              data-bs-toggle="dropdown"
              data-bs-auto-close="false"
              role="button"
              aria-expanded="false"
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
              :class="{ show: route.path === '/users' }"
            >
              <div class="dropdown-menu-columns">
                <div class="dropdown-menu-column">
                  <a class="dropdown-item" href="/audit-logs">
                    Log Audit Forensik
                  </a>
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
