<script setup lang="ts">
import { ref } from "vue"
import { useTheme } from "../../composables/useTheme"
import { useSearch } from "../../composables/useSearch"
import { useNotifications } from "../../composables/useNotifications"
import { useAuth } from "../../composables/useAuth"
import { useSidebar } from "../../composables/useSidebar"

const { toggleMobileSidebar } = useSidebar()
const isMobileSearchOpen = ref(false)
const { toggleTheme } = useTheme()
const {
  currentUser,
  userInitials,
  roleDisplayName,
  openProfileModal,
  handleLogout
} = useAuth()
const {
  searchQuery,
  isSearchOpen,
  searchInputRef,
  selectedIndex,
  searchResults,
  resultsByCategory,
  isLoadingTameng,
  clearSearch
} = useSearch()

const {
  notifications,
  activeFilter,
  unreadCount,
  starredCount,
  filteredNotifications,
  isLoading: isLoadingNotifs,
  markAsRead,
  markAllAsRead,
  toggleStar,
  removeNotification,
  handleNotificationClick
} = useNotifications()

function handleSelect(url?: string) {
  if (url) {
    window.location.href = url
  }
}
</script>

<template>
      <header class="navbar navbar-expand d-flex flex-wrap p-0 d-print-none border-bottom">
        <div class="container-fluid px-2 px-md-3 py-2">
          <!-- Tombol Hamburger Navigasi Mobile (< 992px) -->
          <button
            class="btn btn-icon btn-ghost-secondary d-lg-none me-2 p-1"
            type="button"
            aria-label="Buka Menu Navigasi"
            @click="toggleMobileSidebar"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
              <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
              <path d="M4 6l16 0"/>
              <path d="M4 12l16 0"/>
              <path d="M4 18l16 0"/>
            </svg>
          </button>

          <!-- Brand Logo & Nama TAMENG di Mobile (< 992px) -->
          <router-link
            to="/"
            class="d-lg-none d-flex align-items-center gap-2 text-decoration-none me-auto me-sm-2 flex-shrink-0"
          >
            <span class="avatar avatar-xs bg-primary text-primary-fg rounded">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                <path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" />
                <path d="M12 11m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" />
              </svg>
            </span>
            <span class="fw-bold fs-3 text-reset lh-1">TAMENG</span>
          </router-link>

          <div class="navbar-nav flex-row order-md-last ms-auto align-items-center gap-1">
            <!-- Tombol Search Toggle Mobile (< 768px) -->
            <button
              type="button"
              class="nav-link px-2 d-md-none border-0 bg-transparent text-secondary"
              aria-label="Cari di TAMENG"
              @click="isMobileSearchOpen = !isMobileSearchOpen"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
            </button>

            <div class="d-flex">
              <a href="?theme=dark" @click="toggleTheme" class="nav-link px-1 px-sm-2 hide-theme-dark" title="Enable dark mode" data-bs-toggle="tooltip"
		   data-bs-placement="bottom">
                <!-- Download SVG icon from http://tabler-icons.io/i/moon -->
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454z" /></svg>
              </a>
              <a href="?theme=light" @click="toggleTheme" class="nav-link px-1 px-sm-2 hide-theme-light" title="Enable light mode" data-bs-toggle="tooltip"
		   data-bs-placement="bottom">
                <!-- Download SVG icon from http://tabler-icons.io/i/sun -->
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7" /></svg>
              </a>
            </div>
            <div class="nav-item dropdown d-flex me-1 me-sm-3">
              <a
                href="#"
                class="nav-link px-1 px-sm-2 position-relative"
                data-bs-toggle="dropdown"
                data-bs-auto-close="outside"
                tabindex="-1"
                aria-label="Notifikasi Keamanan"
              >
                <!-- Download SVG icon from http://tabler-icons.io/i/bell -->
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="22" height="22" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" /><path d="M9 17v1a3 3 0 0 0 6 0v-1" /></svg>
                <span v-if="unreadCount > 0" class="badge bg-red"></span>
              </a>

              <div class="dropdown-menu dropdown-menu-arrow dropdown-menu-end dropdown-menu-card" style="min-width: min(380px, calc(100vw - 1rem)); max-width: min(440px, calc(100vw - 1rem));">
                <div class="card">
                  <div class="card-header d-flex align-items-center justify-content-between py-2 px-3">
                      <div class="d-flex align-items-center gap-2">
                        <h3 class="card-title mb-0">Notifikasi</h3>
                        <span v-if="unreadCount > 0" class="badge bg-red-lt">{{ unreadCount }} Baru</span>
                      </div>
                      <div class="card-actions">
                        <button
                          v-if="unreadCount > 0"
                          type="button"
                          class="btn btn-sm btn-link text-decoration-none p-0 text-muted"
                          style="font-size: 0.75rem;"
                          @click.stop="markAllAsRead"
                        >
                          Tandai semua dibaca
                        </button>
                      </div>
                    </div>

                    <!-- Filter Tabs: Semua, Belum Dibaca, Berbintang -->
                    <div class="card-body p-2 border-bottom bg-light-subtle">
                      <div class="nav nav-pills nav-fill" role="tablist">
                        <button
                          type="button"
                          class="nav-link py-1 px-2 small"
                          :class="{ 'active': activeFilter === 'all' }"
                          @click.stop="activeFilter = 'all'"
                        >
                          Semua ({{ notifications.length }})
                        </button>
                        <button
                          type="button"
                          class="nav-link py-1 px-2 small"
                          :class="{ 'active': activeFilter === 'unread' }"
                          @click.stop="activeFilter = 'unread'"
                        >
                          Belum Dibaca ({{ unreadCount }})
                        </button>
                        <button
                          type="button"
                          class="nav-link py-1 px-2 small"
                          :class="{ 'active': activeFilter === 'starred' }"
                          @click.stop="activeFilter = 'starred'"
                        >
                          Berbintang ({{ starredCount }})
                        </button>
                      </div>
                    </div>

                    <!-- List of Notifications -->
                    <div class="list-group list-group-flush list-group-hoverable" style="max-height: 380px; overflow-y: auto;">
                      <div v-if="isLoadingNotifs && notifications.length === 0" class="p-4 text-center text-muted">
                        <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                        <div class="small">Memuat notifikasi keamanan TAMENG...</div>
                      </div>

                      <div v-else-if="filteredNotifications.length === 0" class="p-4 text-center text-muted">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg mb-2 text-secondary" width="32" height="32" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6" /><path d="M9 17v1a3 3 0 0 0 6 0v-1" /></svg>
                        <div class="fw-semibold">Tidak ada notifikasi</div>
                        <div class="small mt-1 text-secondary">
                          {{ activeFilter === 'unread' ? 'Semua notifikasi sudah dibaca.' : activeFilter === 'starred' ? 'Belum ada notifikasi yang ditandai bintang.' : 'Kotak masuk notifikasi kosong.' }}
                        </div>
                      </div>

                      <div
                        v-for="notif in filteredNotifications"
                        :key="notif.id"
                        class="list-group-item list-group-item-action cursor-pointer position-relative py-2 px-3"
                        :class="{ 'bg-primary-subtle bg-opacity-10': !notif.isRead }"
                        style="cursor: pointer;"
                        @click="handleNotificationClick(notif)"
                      >
                        <div class="row align-items-start g-2">
                          <div class="col-auto pt-1">
                            <span class="status-dot d-block" :class="notif.statusDot"></span>
                          </div>
                          <div class="col text-truncate">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                              <span class="badge badge-sm" :class="notif.badgeClass">{{ notif.badge }}</span>
                              <span class="text-secondary" style="font-size: 0.7rem;">{{ notif.time }}</span>
                            </div>
                            <div class="d-block text-body fw-semibold text-truncate" style="font-size: 0.85rem;">
                              {{ notif.title }}
                            </div>
                            <div class="d-block text-secondary text-truncate small mt-1" style="font-size: 0.75rem;">
                              {{ notif.description }}
                            </div>
                          </div>
                          <div class="col-auto d-flex flex-column gap-1 align-items-center pt-1">
                            <!-- Star button -->
                            <button
                              type="button"
                              class="btn btn-icon btn-sm btn-ghost-secondary border-0 p-0"
                              :class="{ 'text-yellow': notif.isStarred, 'text-muted': !notif.isStarred }"
                              :title="notif.isStarred ? 'Hapus bintang' : 'Beri bintang'"
                              @click.stop="toggleStar(notif.id)"
                            >
                              <svg xmlns="http://www.w3.org/2000/svg" class="icon" :fill="notif.isStarred ? 'currentColor' : 'none'" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 17.75l-6.172 3.245l1.179 -6.873l-5 -4.867l6.9 -1l3.086 -6.253l3.086 6.253l6.9 1l-5 4.867l1.179 6.873z" /></svg>
                            </button>
                            <!-- Remove button -->
                            <button
                              type="button"
                              class="btn btn-icon btn-sm btn-ghost-secondary border-0 p-0 text-muted"
                              title="Hapus notifikasi"
                              @click.stop="removeNotification(notif.id)"
                            >
                              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Card Footer: Link to audit logs -->
                    <div class="card-footer py-2 px-3 text-center bg-transparent border-top">
                      <a href="/audit-logs" class="text-secondary small fw-medium text-decoration-none">
                        Lihat Seluruh Log Audit Keamanan &rarr;
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            <div class="nav-item dropdown">
              <a href="#" class="nav-link d-flex lh-1 text-reset p-0" data-bs-toggle="dropdown" aria-label="Buka menu profil">
                <span class="avatar avatar-sm bg-primary-lt text-primary fw-bold">
                  {{ userInitials }}
                </span>
                <div class="d-none d-xl-block ps-2">
                  <div class="fw-semibold text-truncate" style="max-width: 140px;">{{ currentUser?.name || 'System Admin' }}</div>
                  <div class="mt-1 small text-secondary" style="font-size: 0.75rem;">{{ roleDisplayName }}</div>
                </div>
              </a>
              <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                <a
                  href="#"
                  class="dropdown-item"
                  data-bs-toggle="modal"
                  data-bs-target="#modal-profile"
                  @click.prevent="openProfileModal"
                >
                  Profile
                </a>
                <div class="dropdown-divider m-0" style="margin: 0 !important;"></div>
                <a href="#" class="dropdown-item" @click.prevent="handleLogout">Logout</a>
              </div>
            </div>
          </div>
          <!-- Search Box Desktop & Tablet (>= 768px) -->
          <div class="collapse navbar-collapse d-none d-md-flex" id="navbar-menu">
            <div class="search-container position-relative my-2 my-md-0 flex-grow-1 flex-md-grow-0 order-first order-md-last me-auto" style="width: 100%; max-width: 480px;">
              <div class="input-icon">
                <span class="input-icon-addon">
                  <!-- Download SVG icon from http://tabler-icons.io/i/search -->
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                </span>
                <input
                  ref="searchInputRef"
                  v-model="searchQuery"
                  type="text"
                  class="form-control"
                  placeholder="Cari modul, celah, target, aset..."
                  aria-label="Cari di TAMENG"
                  @focus="isSearchOpen = true"
                />
                <button
                  v-if="searchQuery"
                  type="button"
                  class="input-icon-addon btn-link border-0 bg-transparent p-0"
                  style="pointer-events: auto !important; cursor: pointer; z-index: 5;"
                  title="Hapus pencarian"
                  aria-label="Hapus pencarian"
                  @click.stop.prevent="clearSearch"
                >
                  <!-- icon: x -->
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon text-muted" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
                </button>
              </div>

              <!-- Dropdown Pop-up Hasil Pencarian Real-Time -->
              <div
                v-if="isSearchOpen && searchQuery.trim()"
                class="card shadow-lg mt-1 p-2"
                style="position: absolute; top: 100%; left: 0; right: 0; z-index: 1060; background-color: var(--tblr-bg-surface); border: 1px solid var(--tblr-border-color); max-height: 420px; overflow-y: auto;"
              >
                <div v-if="isLoadingTameng && searchResults.length === 0" class="p-3 text-center text-muted">
                  <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                  <div class="small">Memuat data langsung dari backend TAMENG...</div>
                </div>

                <div v-else-if="searchResults.length === 0" class="p-3 text-center text-muted">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg mb-2 text-secondary" width="32" height="32" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                  <div>Tidak ada hasil untuk "<strong>{{ searchQuery }}</strong>"</div>
                  <div class="small mt-1 text-secondary">Coba gunakan kata kunci: <em>scan, api, sql, audit, user, scope, mobile</em></div>
                </div>

                <div v-else>
                  <div class="px-2 py-1 border-bottom mb-2 text-muted small fw-medium">
                    Hasil Pencarian ({{ searchResults.length }})
                  </div>

                  <div v-for="(items, category) in resultsByCategory" :key="category" class="mb-2">
                    <div class="text-uppercase text-secondary fw-bold px-2 py-1" style="font-size: 0.65rem; letter-spacing: 0.08em;">
                      {{ category }}
                    </div>
                    <a
                      v-for="item in items"
                      :key="item.id"
                      :href="item.url"
                      class="dropdown-item d-flex align-items-center gap-2 rounded px-2 py-2 mb-1"
                      :class="{ 'active': searchResults[selectedIndex]?.id === item.id }"
                      style="cursor: pointer; text-decoration: none;"
                      @click="handleSelect(item.url)"
                    >
                      <!-- Dynamic Tabler SVG Icons -->
                      <span class="text-secondary flex-shrink-0">
                        <svg v-if="item.iconType === 'dashboard'" xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 4h6v8h-6z" /><path d="M4 16h6v4h-6z" /><path d="M14 12h6v8h-6z" /><path d="M14 4h6v4h-6z" /></svg>
                        <svg v-else-if="item.iconType === 'scan'" xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M4 7v-1a2 2 0 0 1 2 -2h2" /><path d="M4 17v1a2 2 0 0 0 2 2h2" /><path d="M16 4h2a2 2 0 0 1 2 2v1" /><path d="M16 20h2a2 2 0 0 0 2 -2v-1" /><path d="M5 12l14 0" /></svg>
                        <svg v-else-if="item.iconType === 'bug'" xmlns="http://www.w3.org/2000/svg" class="icon text-danger" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 9v-1a3 3 0 0 1 6 0v1" /><path d="M8 9h8a6 6 0 0 1 1 3v3a5 5 0 0 1 -10 0v-3a6 6 0 0 1 1 -3" /><path d="M3 13l4 0" /><path d="M17 13l4 0" /><path d="M12 20l0 -6" /><path d="M4 19l3.35 -2" /><path d="M20 19l-3.35 -2" /><path d="M4 7l3.75 1.5" /><path d="M20 7l-3.75 1.5" /></svg>
                        <svg v-else-if="item.iconType === 'target'" xmlns="http://www.w3.org/2000/svg" class="icon text-success" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 12m-5 0a5 5 0 1 0 10 0a5 5 0 1 0 -10 0" /><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /></svg>
                        <svg v-else-if="item.iconType === 'shield'" xmlns="http://www.w3.org/2000/svg" class="icon text-azure" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M9 12l2 2l4 -4" /></svg>
                        <svg v-else-if="item.iconType === 'folder'" xmlns="http://www.w3.org/2000/svg" class="icon text-warning" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
                        <svg v-else-if="item.iconType === 'report'" xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z" /><path d="M9 17l0 -5" /><path d="M12 17l0 -1" /><path d="M15 17l0 -3" /></svg>
                        <svg v-else-if="item.iconType === 'user'" xmlns="http://www.w3.org/2000/svg" class="icon text-info" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 7m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -4 0" /><path d="M3 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /><path d="M16 3.13a4 4 0 0 1 0 7.75" /><path d="M21 21v-2a4 4 0 0 0 -3 -3.85" /></svg>
                        <svg v-else-if="item.iconType === 'cpu'" xmlns="http://www.w3.org/2000/svg" class="icon text-teal" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 5m0 1a1 1 0 0 1 1 -1h12a1 1 0 0 1 1 1v12a1 1 0 0 1 -1 1h-12a1 1 0 0 1 -1 -1z" /><path d="M9 9h6v6h-6z" /><path d="M3 10h2" /><path d="M3 14h2" /><path d="M10 3v2" /><path d="M14 3v2" /><path d="M21 10h-2" /><path d="M21 14h-2" /><path d="M14 21v-2" /><path d="M10 21v-2" /></svg>
                        <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon text-muted" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M9 5h-2a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-12a2 2 0 0 0 -2 -2h-2" /><path d="M9 3m0 2a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v0a2 2 0 0 1 -2 2h-2a2 2 0 0 1 -2 -2z" /><path d="M9 12l.01 0" /><path d="M13 12l2 0" /><path d="M9 16l.01 0" /><path d="M13 16l2 0" /></svg>
                      </span>

                      <!-- Title & Subtitle -->
                      <div class="d-flex flex-column flex-grow-1 text-truncate">
                        <span class="fw-semibold text-reset">{{ item.title }}</span>
                        <span class="text-secondary small text-truncate" style="font-size: 0.75rem;">{{ item.subtitle }}</span>
                      </div>

                      <!-- Badge / Severity Tag -->
                      <span v-if="item.badge" class="badge badge-sm flex-shrink-0" :class="item.badgeClass">
                        {{ item.badge }}
                      </span>
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Bar Pencarian Mobile (< 768px) yang muncul saat tombol cari ditekan -->
        <div v-if="isMobileSearchOpen" class="d-md-none w-100 px-3 py-2 border-top bg-body">
          <div class="search-container position-relative w-100">
            <div class="input-icon">
              <span class="input-icon-addon">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
              </span>
              <input
                v-model="searchQuery"
                type="text"
                class="form-control form-control-sm"
                placeholder="Cari modul, celah, target..."
                @focus="isSearchOpen = true"
              />
              <button
                v-if="searchQuery"
                type="button"
                class="input-icon-addon btn-link border-0 bg-transparent p-0"
                style="pointer-events: auto !important; cursor: pointer; z-index: 5;"
                @click.stop.prevent="clearSearch"
              >
                <svg xmlns="http://www.w3.org/2000/svg" class="icon text-muted" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M18 6l-12 12" /><path d="M6 6l12 12" /></svg>
              </button>
            </div>

            <!-- Dropdown Hasil Pencarian Mobile -->
            <div
              v-if="isSearchOpen && searchQuery.trim()"
              class="card shadow-lg mt-1 p-2"
              style="position: absolute; top: 100%; left: 0; right: 0; z-index: 1060; background-color: var(--tblr-bg-surface); border: 1px solid var(--tblr-border-color); max-height: 360px; overflow-y: auto;"
            >
              <div v-if="isLoadingTameng && searchResults.length === 0" class="p-3 text-center text-muted">
                <div class="spinner-border spinner-border-sm text-primary mb-2" role="status"></div>
                <div class="small">Memuat data langsung dari backend TAMENG...</div>
              </div>

              <div v-else-if="searchResults.length === 0" class="p-3 text-center text-muted">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-lg mb-2 text-secondary" width="32" height="32" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M10 10m-7 0a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></svg>
                <div>Tidak ada hasil untuk "<strong>{{ searchQuery }}</strong>"</div>
              </div>

              <div v-else>
                <div class="px-2 py-1 border-bottom mb-2 text-muted small fw-medium">
                  Hasil Pencarian ({{ searchResults.length }})
                </div>

                <div v-for="(items, category) in resultsByCategory" :key="category" class="mb-2">
                  <div class="text-uppercase text-secondary fw-bold px-2 py-1" style="font-size: 0.65rem; letter-spacing: 0.08em;">
                    {{ category }}
                  </div>
                  <a
                    v-for="item in items"
                    :key="item.id"
                    :href="item.url"
                    class="dropdown-item d-flex align-items-center gap-2 rounded px-2 py-2 mb-1"
                    :class="{ 'active': searchResults[selectedIndex]?.id === item.id }"
                    style="cursor: pointer; text-decoration: none;"
                    @click="handleSelect(item.url)"
                  >
                    <div class="d-flex flex-column flex-grow-1 text-truncate">
                      <span class="fw-semibold text-reset">{{ item.title }}</span>
                      <span class="text-secondary small text-truncate" style="font-size: 0.75rem;">{{ item.subtitle }}</span>
                    </div>
                    <span v-if="item.badge" class="badge badge-sm flex-shrink-0" :class="item.badgeClass">
                      {{ item.badge }}
                    </span>
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </header>
</template>
