<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuth } from '../composables/useAuth'

const router = useRouter()
const { currentUser, roleDisplayName, roleBadgeClass, isDeveloper, isAuditor, handleLogout } = useAuth()

const homePath = computed(() => {
  if (isDeveloper.value) return '/findings'
  if (isAuditor.value) return '/reports'
  return '/'
})

const homeLabel = computed(() => {
  if (isDeveloper.value) return 'Ke Tiket Perbaikan Celah'
  if (isAuditor.value) return 'Ke Laporan Keamanan'
  return 'Ke Dasbor Keamanan'
})

function goHome() {
  router.push(homePath.value)
}
</script>

<template>
  <div class="page page-center">
    <div class="container-tight py-4">
      <div class="empty">
        <div class="empty-header text-danger fs-1 fw-bold">403</div>
        <p class="empty-title fw-bold">Akses Dibatasi untuk Peran Anda</p>
        <p class="empty-subtitle text-secondary">
          Akun Anda terdaftar dengan peran
          <span class="badge ms-1 me-1" :class="roleBadgeClass">
            {{ roleDisplayName }}
          </span>
          yang tidak memiliki hak wewenang untuk mengakses halaman atau modul ini.
        </p>

        <div class="card card-sm mb-4 border-dashed border-danger-subtle bg-danger-subtle text-start p-3">
          <div class="d-flex align-items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon text-danger" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /><path d="M12 11m-1 0a1 1 0 1 0 2 0a1 1 0 1 0 -2 0" /><path d="M12 12l0 2.5" /></svg>
            <div>
              <div class="fw-semibold text-danger">Prinsip Akses Berbasis Tugas (Least Privilege)</div>
              <div class="text-secondary small mt-1">
                Akses modul ini dibatasi khusus untuk Administrator Keamanan / Personel SOC sesuai kebijakan tata kelola TAMENG.
              </div>
            </div>
          </div>
        </div>

        <div class="empty-action d-flex flex-wrap justify-content-center gap-2">
          <button type="button" class="btn btn-primary" @click="goHome">
            <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l14 0" /><path d="M5 12l6 6" /><path d="M5 12l6 -6" /></svg>
            {{ homeLabel }}
          </button>
          <button type="button" class="btn btn-outline-secondary" @click="handleLogout">
            Keluar Akun
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
