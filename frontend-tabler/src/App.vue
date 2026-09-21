<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useTheme } from './composables/useTheme'
import AppSidebar from './components/layout/AppSidebar.vue'
import AppHeader from './components/layout/AppHeader.vue'
import AppFooter from './components/layout/AppFooter.vue'
import UserPortalLayout from './components/layout/UserPortalLayout.vue'
import ModalReport from './components/modals/ModalReport.vue'
import ModalProfile from './components/modals/ModalProfile.vue'

const route = useRoute()
const { initTheme } = useTheme()

onMounted(() => {
  initTheme()
})
</script>

<template>
  <!-- Full-page / Blank Layout (e.g. Login page) -->
  <template v-if="route.meta.layout === 'blank'">
    <router-view />
  </template>

  <!-- Dedicated User Portal Workspace Layout (Personal Workspace) -->
  <template v-else-if="route.meta.layout === 'user'">
    <UserPortalLayout>
      <router-view />
    </UserPortalLayout>
  </template>

  <!-- Default Authenticated Admin SOC Layout with Sidebar, Header & Footer (Preserved 100%) -->
  <template v-else>
    <div class="page">
      <AppSidebar />
      <AppHeader />
      <div class="page-wrapper">
        <router-view />
        <AppFooter />
      </div>
    </div>
    <ModalReport />
    <ModalProfile />
  </template>
</template>

<style>
/* Pastikan seluruh container mengambil tinggi penuh viewport agar footer selalu berada di bawah */
html,
body {
  height: 100%;
  min-height: 100vh;
}

.bg-surface {
  background-color: var(--tblr-bg-surface, var(--tblr-card-bg, #182433)) !important;
}

.bg-surface-secondary {
  background-color: var(--tblr-bg-surface-secondary, var(--tblr-body-bg, #0b1727)) !important;
}

#app {
  min-height: 100vh;
  height: 100%;
  display: flex;
  flex-direction: column;
}

.page {
  min-height: 100vh !important;
  display: flex !important;
  flex-direction: column !important;
  flex: 1 1 auto;
}

.navbar {
  min-height: auto !important;
}

.page-wrapper {
  display: flex !important;
  flex-direction: column !important;
  flex: 1 0 auto !important;
  min-height: calc(100vh - 3.5rem) !important;
}

@media (min-width: 992px) {
  .navbar-vertical.navbar-expand-lg ~ .navbar,
  .navbar-vertical.navbar-expand-lg ~ .page-wrapper {
    margin-left: 16.5rem !important;
  }
}

@media (max-width: 991.98px) {
  .navbar-expand-lg.navbar-vertical ~ .navbar,
  .navbar-expand-lg.navbar-vertical ~ .page-wrapper,
  .navbar-vertical ~ .page,
  .navbar-vertical ~ .page-wrapper,
  .page {
    margin-inline-start: 0 !important;
    margin-left: 0 !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
  }

  .page-wrapper {
    margin-left: 0 !important;
    margin-inline-start: 0 !important;
    width: 100% !important;
    max-width: 100vw !important;
    overflow-x: hidden;
  }
}

/* Konten utama mengisi sisa ruang vertikal agar footer terdorong ke paling bawah */
.page-wrapper > :first-child:not(.footer),
.page-body {
  flex: 1 0 auto;
  margin-top: 0 !important;
}

/* Footer selalu menempel di bagian paling bawah */
.page-wrapper > footer,
footer.footer,
.footer {
  margin-top: auto !important;
  width: 100%;
}

/* Sembunyikan icon reveal bawaan browser (Edge/IE) agar konsisten menggunakan icon kustom di paling belakang */
input::-ms-reveal,
input::-ms-clear {
  display: none !important;
}

/* Kolom nomor urut tabel (# dan angka baris 1, 2, 3...) berwarna putih terang */
.col-index,
th.col-index,
td.col-index,
.table th.col-index,
.table td.col-index {
  color: #ffffff !important;
  font-weight: 600;
}

[data-bs-theme="dark"] .col-index,
[data-bs-theme="dark"] th.col-index,
[data-bs-theme="dark"] td.col-index,
[data-bs-theme="dark"] .table th[style*="width: 40px"],
[data-bs-theme="dark"] .table td.col-index {
  color: #ffffff !important;
}

:root:not([data-bs-theme="dark"]) body:not([data-bs-theme="dark"]) .col-index:not(.text-white) {
  color: #182433 !important;
}

/* Tombol expand chevron panah (>) pada tabel berwarna putih terang */
.table .scan-job-row td:first-child .btn,
.table .scan-job-row td:first-child .btn svg,
[data-bs-theme="dark"] .table .scan-job-row td:first-child .btn,
[data-bs-theme="dark"] .table .scan-job-row td:first-child .btn svg,
[data-bs-theme="dark"] .table td:first-child .btn-ghost-secondary svg {
  color: #ffffff !important;
  stroke: #ffffff !important;
}

/* Sembunyikan search box desktop pada mobile (< 768px) */
@media (max-width: 767.98px) {
  .navbar #navbar-menu,
  #navbar-menu {
    display: none !important;
  }
}

/* Proteksi Responsif Global Mobile */
@media (max-width: 575.98px) {
  .modal-dialog {
    margin: 0.5rem auto !important;
    max-width: calc(100vw - 1rem) !important;
  }
}

.table-responsive {
  -webkit-overflow-scrolling: touch;
}

/* Hilangkan outline dan box-shadow pada seluruh tombol */
.btn,
button {
  outline: none !important;
}

.btn:focus,
.btn:focus-visible,
.btn:active,
button:focus,
button:focus-visible,
button:active {
  outline: none !important;
  box-shadow: none !important;
}

/* Normalisasi Margin & Ukuran Icon di Seluruh Tombol */
.btn {
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 0.375rem !important;
}

.btn .icon,
.btn svg.icon {
  margin: 0 !important;
  vertical-align: middle !important;
  flex-shrink: 0;
}

.btn-sm {
  gap: 0.35rem !important;
}

.btn-sm .icon,
.btn-sm svg.icon {
  width: 0.95rem !important;
  height: 0.95rem !important;
  min-width: 0.95rem !important;
  margin: 0 !important;
}

.btn-xs {
  gap: 0.25rem !important;
}

.btn-xs .icon,
.btn-xs svg.icon {
  width: 0.85rem !important;
  height: 0.85rem !important;
  min-width: 0.85rem !important;
  margin: 0 !important;
}

/* Tombol icon saja tanpa teks */
.btn-icon .icon,
.btn-icon svg.icon,
.btn:has(> svg:only-child) svg,
.btn > svg:only-child {
  margin: 0 !important;
}

/* Pemisahan antar tombol di dalam aksi tabel */
.table td .btn-group {
  display: inline-flex !important;
  align-items: center !important;
  gap: 0.375rem !important;
}

.table td .btn-group > .btn {
  border-radius: var(--tblr-border-radius, 4px) !important;
  margin: 0 !important;
}

/* Peningkatan kontras avatar icon pada kartu statistik (Light & Dark Mode) */
.card .avatar.bg-primary-lt {
  background-color: rgba(32, 107, 196, 0.15) !important;
  border: 1px solid rgba(32, 107, 196, 0.35) !important;
  color: #206bc4 !important;
}

.card .avatar.bg-danger-lt {
  background-color: rgba(214, 57, 57, 0.15) !important;
  border: 1px solid rgba(214, 57, 57, 0.35) !important;
  color: #d63939 !important;
}

.card .avatar.bg-warning-lt {
  background-color: rgba(247, 103, 7, 0.15) !important;
  border: 1px solid rgba(247, 103, 7, 0.35) !important;
  color: #f76707 !important;
}

.card .avatar.bg-yellow-lt {
  background-color: rgba(245, 159, 0, 0.15) !important;
  border: 1px solid rgba(245, 159, 0, 0.35) !important;
  color: #f59f00 !important;
}

.card .avatar.bg-info-lt {
  background-color: rgba(66, 153, 225, 0.15) !important;
  border: 1px solid rgba(66, 153, 225, 0.35) !important;
  color: #4299e1 !important;
}

.card .avatar.bg-secondary-lt {
  background-color: rgba(108, 117, 125, 0.15) !important;
  border: 1px solid rgba(108, 117, 125, 0.35) !important;
  color: #6c757d !important;
}

[data-bs-theme="dark"] .card .avatar.bg-primary-lt {
  background-color: rgba(32, 107, 196, 0.25) !important;
  border: 1px solid rgba(66, 153, 225, 0.45) !important;
  color: #60a5fa !important;
}

[data-bs-theme="dark"] .card .avatar.bg-danger-lt {
  background-color: rgba(214, 57, 57, 0.25) !important;
  border: 1px solid rgba(248, 113, 113, 0.45) !important;
  color: #f87171 !important;
}

[data-bs-theme="dark"] .card .avatar.bg-warning-lt {
  background-color: rgba(247, 103, 7, 0.25) !important;
  border: 1px solid rgba(251, 146, 60, 0.45) !important;
  color: #fb923c !important;
}

[data-bs-theme="dark"] .card .avatar.bg-yellow-lt {
  background-color: rgba(245, 159, 0, 0.25) !important;
  border: 1px solid rgba(252, 211, 77, 0.45) !important;
  color: #fcd34d !important;
}

[data-bs-theme="dark"] .card .avatar.bg-info-lt {
  background-color: rgba(66, 153, 225, 0.25) !important;
  border: 1px solid rgba(96, 165, 250, 0.45) !important;
  color: #93c5fd !important;
}

[data-bs-theme="dark"] .card .avatar.bg-secondary-lt {
  background-color: rgba(108, 117, 125, 0.25) !important;
  border: 1px solid rgba(156, 163, 175, 0.45) !important;
  color: #d1d5db !important;
}

/* Pastikan icon di dalam avatar selalu tajam dan berukuran pas */
.card .avatar .icon {
  width: 1.15rem !important;
  height: 1.15rem !important;
  stroke-width: 2.2 !important;
}
</style>
