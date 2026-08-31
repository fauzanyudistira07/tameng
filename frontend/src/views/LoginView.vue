<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { loginUser } from '../stores/authStore'

const router = useRouter()
const email = ref('admin@secsys.local')
const password = ref('password')
const isSubmitting = ref(false)
const errorMessage = ref('')

async function submitLogin() {
  isSubmitting.value = true
  errorMessage.value = ''

  try {
    const user = await loginUser({
      email: email.value,
      password: password.value,
    })

    router.push({ name: user.role?.name === 'developer' ? 'my-scan-requests' : 'dashboard' })
  } catch (error) {
    console.error('Gagal masuk.', error)
    errorMessage.value = 'Gagal masuk. Periksa kembali email dan kata sandi Anda.'
  } finally {
    isSubmitting.value = false
  }
}

function fillCredentials(fillEmail: string, fillPass: string) {
  email.value = fillEmail
  password.value = fillPass
}
</script>

<template>
  <main class="login-page">
    <section class="login-panel fade-in">
      <div class="brand login-brand">
        <div class="brand-mark" style="width: 52px; height: 52px; font-size: 24px;">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
          </svg>
        </div>
        <div class="brand-info">
          <strong style="font-size: 22px; color: var(--tameng-navy); letter-spacing: 0.5px;">TAMENG</strong>
          <span style="font-size: 11px; color: var(--tameng-gold); font-weight: 700;">Teknologi Analisis dan Monitoring Keamanan</span>
        </div>
      </div>

      <p class="eyebrow">Autentikasi Akses Platform Pertahanan Siber</p>
      <h1>Masuk ke Pusat Kendali</h1>

      <form class="login-form" @submit.prevent="submitLogin">
        <label>
          <span>Email Pengguna / Analis Keamanan</span>
          <input v-model="email" autocomplete="email" placeholder="admin@secsys.local" type="email" required />
        </label>

        <label>
          <span>Kata Sandi Akses</span>
          <input
            v-model="password"
            autocomplete="current-password"
            placeholder="••••••••••••"
            type="password"
            required
          />
        </label>

        <div v-if="errorMessage" class="status-panel error fade-in">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
          <span>{{ errorMessage }}</span>
        </div>

        <button class="btn btn-primary-glow" type="submit" :disabled="isSubmitting" style="min-height: 46px; margin-top: 6px; font-size: 14px;">
          {{ isSubmitting ? 'Mengautentikasi Kredensial...' : 'Masuk ke Platform TAMENG' }}
        </button>
      </form>

      <!-- Quick Demo Credentials Helper for Local Dev -->
      <div class="demo-credentials">
        <strong>Pintasan Kredensial Pengujian:</strong>
        <div class="demo-role-pills">
          <button type="button" @click="fillCredentials('admin@secsys.local', 'password')">
            Super Admin
          </button>
        </div>
      </div>
    </section>
  </main>
</template>
