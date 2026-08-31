<script setup lang="ts">
import { useRouter } from 'vue-router'
import { authState, logoutUser } from '../stores/authStore'

const router = useRouter()

async function submitKeluar() {
  await logoutUser()
  router.push({ name: 'login' })
}

function goAuthorizedHome() {
  const roleName = authState.user?.role?.name
  if (roleName === 'developer') {
    router.push({ name: 'my-scan-requests' })
  } else {
    router.push({ name: 'dashboard' })
  }
}
</script>

<template>
  <main class="login-page">
    <section class="login-panel fade-in" style="text-align: center;">
      <div style="width: 56px; height: 56px; border-radius: 50%; background: var(--severity-critical-bg); border: 2px solid var(--severity-critical-border); color: var(--severity-critical); display: flex; align-items: center; justify-content: center; margin: 0 auto 18px;">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
        </svg>
      </div>

      <p class="eyebrow" style="color: var(--severity-critical);">403 Access Forbidden</p>
      <h1 style="color: var(--text-main);">Akses Ditolak</h1>

      <p style="font-size: 13.5px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.6;">
        Akun Anda (<strong style="color: var(--text-main)">{{ authState.user?.name ?? 'User' }}</strong> - 
        <span class="badge is-info">{{ authState.user?.role?.display_name ?? 'Tanpa Peran' }}</span>) 
        tidak memiliki izin otorisasi untuk mengakses halaman ini.
      </p>

      <div style="display: flex; gap: 12px; justify-content: center;">
        <button class="btn btn-primary-glow" type="button" @click="goAuthorizedHome">
          Kembali ke Dasbor Saya
        </button>
        <button class="secondary-button" type="button" @click="submitKeluar">
          Keluar
        </button>
      </div>
    </section>
  </main>
</template>
