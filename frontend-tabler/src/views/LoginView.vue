<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { loginUser, requestPasswordReset, resetPasswordWithToken } from '../services/api'
import { useAuth } from '../composables/useAuth'
import { useTheme } from '../composables/useTheme'

const router = useRouter()
const { loadUser } = useAuth()
const { isDark, initTheme } = useTheme()

// Initialize theme state immediately
initTheme()

const coverImage = computed(() => {
  return isDark.value
    ? '/static/photos/Cover_Login_Dark.jpeg'
    : '/static/photos/Cover_Login_Light.jpeg'
})

const iconImage = computed(() => {
  return isDark.value
    ? '/static/Icon_Dark.png'
    : '/static/Icon_Light.png'
})

// Login State
const email = ref('')
const password = ref('')
const rememberMe = ref(false)
const showPassword = ref(false)
const isLoading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

// Forgot Password State
const isForgotMode = ref(false)
const forgotStep = ref<'request' | 'reset'>('request')
const forgotEmail = ref('')
const resetToken = ref('')
const newPassword = ref('')
const newPasswordConfirm = ref('')
const showNewPassword = ref(false)
const isForgotLoading = ref(false)
const forgotError = ref('')
const forgotSuccess = ref('')

onMounted(() => {
  initTheme()
  // Load Remember Me data if present
  const savedEmail = localStorage.getItem('tameng_remember_email')
  const savedRemember = localStorage.getItem('tameng_remember_me')
  if (savedRemember === 'true' && savedEmail) {
    email.value = savedEmail
    rememberMe.value = true
  }
})

// Handlers for Login
async function handleSubmit() {
  errorMessage.value = ''
  successMessage.value = ''
  if (!email.value || !password.value) {
    errorMessage.value = 'Email address and password are required.'
    return
  }

  isLoading.value = true
  try {
    await loginUser(email.value.trim(), password.value, rememberMe.value)
    const user = await loadUser(true)
    const role = user?.role?.name || ''
    if (role === 'developer' || role === 'viewer') {
      router.push({ name: 'workspace' })
    } else if (role === 'auditor') {
      router.push({ name: 'reports' })
    } else {
      router.push({ name: 'dashboard' })
    }
  } catch (err: any) {
    errorMessage.value = err?.message || 'Invalid email address or password. Please try again.'
  } finally {
    isLoading.value = false
  }
}

// Handlers for Forgot Password
function openForgot() {
  isForgotMode.value = true
  forgotStep.value = 'request'
  forgotEmail.value = email.value.trim()
  resetToken.value = ''
  newPassword.value = ''
  newPasswordConfirm.value = ''
  forgotError.value = ''
  forgotSuccess.value = ''
}

function closeForgot() {
  isForgotMode.value = false
  forgotError.value = ''
  forgotSuccess.value = ''
}

async function handleSendResetCode() {
  forgotError.value = ''
  forgotSuccess.value = ''

  if (!forgotEmail.value) {
    forgotError.value = 'Silakan masukkan alamat email Anda.'
    return
  }

  isForgotLoading.value = true
  try {
    const res = await requestPasswordReset(forgotEmail.value.trim())
    forgotSuccess.value = res.message || 'Kode verifikasi telah dikirim ke alamat email Anda.'
    resetToken.value = ''
    forgotStep.value = 'reset'
  } catch (err: any) {
    forgotError.value = err?.message || 'Gagal membuat permintaan reset password.'
  } finally {
    isForgotLoading.value = false
  }
}

async function handleResetPassword() {
  forgotError.value = ''
  if (!resetToken.value.trim()) {
    forgotError.value = 'Silakan masukkan kode verifikasi.'
    return
  }
  if (!newPassword.value || newPassword.value.length < 8) {
    forgotError.value = 'Password baru minimal harus 8 karakter.'
    return
  }
  if (newPassword.value !== newPasswordConfirm.value) {
    forgotError.value = 'Konfirmasi password tidak cocok.'
    return
  }

  isForgotLoading.value = true
  try {
    const res = await resetPasswordWithToken({
      email: forgotEmail.value.trim(),
      token: resetToken.value.trim(),
      password: newPassword.value,
      password_confirmation: newPasswordConfirm.value,
    })
    // Successfully reset! Back to login view with success banner
    isForgotMode.value = false
    email.value = forgotEmail.value.trim()
    password.value = ''
    successMessage.value = res.message || 'Password berhasil direset! Silakan login dengan password baru Anda.'
    errorMessage.value = ''
  } catch (err: any) {
    forgotError.value = err?.message || 'Gagal mereset password.'
  } finally {
    isForgotLoading.value = false
  }
}
</script>

<template>
  <div :class="['d-flex flex-column flex-fill min-vh-100', isDark ? '' : 'bg-white']">
    <div class="row g-0 flex-fill">
      <!-- Left Column: Authentication Form -->
      <div class="col-12 col-lg-6 col-xl-4 border-top-wide border-primary d-flex flex-column justify-content-center">
        <div class="container container-tight my-5 px-lg-5">
          <!-- Logo TAMENG -->
          <div class="text-center mb-4">
            <a href="." class="navbar-brand d-inline-block">
              <img
                :src="iconImage"
                height="180"
                alt="TAMENG"
                style="height: 180px; max-height: 180px; width: auto; object-fit: contain;"
              />
            </a>
          </div>

          <!-- ================= VIEW 1: SIGN IN ================= -->
          <template v-if="!isForgotMode">
            <h2 class="h3 text-center mb-3">
              Login to your account
            </h2>

            <!-- Success Alert -->
            <div v-if="successMessage" class="alert alert-success alert-dismissible mb-3" role="alert">
              <div class="d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon text-success" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                  <path d="M5 12l5 5l10 -10" />
                </svg>
                <span>{{ successMessage }}</span>
              </div>
            </div>

            <!-- Error Alert -->
            <div v-if="errorMessage" class="alert alert-danger alert-dismissible mb-3" role="alert">
              <div class="d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                  <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                  <path d="M12 8l0 4" />
                  <path d="M12 16l.01 0" />
                </svg>
                <span>{{ errorMessage }}</span>
              </div>
            </div>

            <form @submit.prevent="handleSubmit" autocomplete="off" novalidate>
              <div class="mb-3">
                <label class="form-label">Email address</label>
                <input
                  v-model="email"
                  type="email"
                  class="form-control"
                  placeholder="your@email.com"
                  autocomplete="email"
                  required
                />
              </div>
              <div class="mb-2">
                <label class="form-label">
                  Password
                  <span class="form-label-description">
                    <a href="./forgot-password.html" @click.prevent="openForgot">I forgot password</a>
                  </span>
                </label>
                <div class="input-group input-group-flat">
                  <input
                    :type="showPassword ? 'text' : 'password'"
                    v-model="password"
                    class="form-control"
                    placeholder="Your password"
                    autocomplete="current-password"
                    required
                  />
                  <span class="input-group-text">
                    <a
                      href="#"
                      class="link-secondary"
                      :title="showPassword ? 'Hide password' : 'Show password'"
                      data-bs-toggle="tooltip"
                      @click.prevent="showPassword = !showPassword"
                    >
                      <svg v-if="!showPassword" xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                        <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                      </svg>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" />
                        <path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" />
                        <path d="M3 3l18 18" />
                      </svg>
                    </a>
                  </span>
                </div>
              </div>
              <div class="mb-2">
                <label class="form-check">
                  <input v-model="rememberMe" type="checkbox" class="form-check-input" />
                  <span class="form-check-label">Remember me on this device</span>
                </label>
              </div>
              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="isLoading">
                  <span v-if="isLoading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  Sign in
                </button>
              </div>
            </form>
          </template>

          <!-- ================= VIEW 2: FORGOT PASSWORD ================= -->
          <template v-else>
            <h2 class="h3 text-center mb-3">Forgot password</h2>
            <p class="text-secondary mb-4 text-center">
              {{ forgotStep === 'request'
                ? 'Masukkan alamat email akun Anda untuk mendapatkan kode verifikasi reset password.'
                : 'Masukkan kode verifikasi dan tentukan password baru akun Anda.'
              }}
            </p>

            <!-- Forgot Error Alert -->
            <div v-if="forgotError" class="alert alert-danger alert-dismissible mb-3" role="alert">
              <div class="d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                  <path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
                  <path d="M12 8l0 4" />
                  <path d="M12 16l.01 0" />
                </svg>
                <span>{{ forgotError }}</span>
              </div>
            </div>

            <!-- Forgot Success / Verification Code Notice -->
            <div v-if="forgotSuccess" class="alert alert-success mb-3" role="alert">
              <div class="d-flex align-items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon text-success mt-1" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                  <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                  <path d="M5 12l5 5l10 -10" />
                </svg>
                <div>
                  <div class="fw-bold">{{ forgotSuccess }}</div>
                  <div class="text-secondary small mt-1">
                    Silakan periksa kotak masuk (Inbox) atau folder Spam pada email Anda untuk melihat kode 6-digit.
                  </div>
                </div>
              </div>
            </div>

            <!-- Step 1: Request Code Form -->
            <form v-if="forgotStep === 'request'" @submit.prevent="handleSendResetCode" autocomplete="off" novalidate>
              <div class="mb-3">
                <label class="form-label">Email address</label>
                <input
                  v-model="forgotEmail"
                  type="email"
                  class="form-control"
                  placeholder="your@email.com"
                  autocomplete="email"
                  required
                />
              </div>
              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="isForgotLoading">
                  <span v-if="isForgotLoading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  Send reset code
                </button>
              </div>
            </form>

            <!-- Step 2: Enter Code & New Password Form -->
            <form v-else @submit.prevent="handleResetPassword" autocomplete="off" novalidate>
              <div class="mb-3">
                <label class="form-label">Kode Verifikasi (6-Digit)</label>
                <input
                  v-model="resetToken"
                  type="text"
                  class="form-control font-monospace text-center fs-3 tracking-widest"
                  placeholder="123456"
                  maxlength="6"
                  required
                />
              </div>
              <div class="mb-3">
                <label class="form-label">Password Baru (min. 8 karakter)</label>
                <div class="input-group input-group-flat">
                  <input
                    :type="showNewPassword ? 'text' : 'password'"
                    v-model="newPassword"
                    class="form-control"
                    placeholder="Password baru"
                    required
                  />
                  <span class="input-group-text">
                    <a
                      href="#"
                      class="link-secondary"
                      @click.prevent="showNewPassword = !showNewPassword"
                    >
                      <svg v-if="!showNewPassword" xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0" />
                        <path d="M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6" />
                      </svg>
                      <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round">
                        <path stroke="none" d="M0 0h24v24H0z" fill="none"/>
                        <path d="M10.585 10.587a2 2 0 0 0 2.829 2.828" />
                        <path d="M16.681 16.673a8.717 8.717 0 0 1 -4.681 1.327c-3.6 0 -6.6 -2 -9 -6c1.272 -2.12 2.712 -3.678 4.32 -4.674m2.86 -1.146a9.055 9.055 0 0 1 1.82 -.18c3.6 0 6.6 2 9 6c-.666 1.11 -1.379 2.067 -2.138 2.87" />
                        <path d="M3 3l18 18" />
                      </svg>
                    </a>
                  </span>
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Konfirmasi Password Baru</label>
                <input
                  :type="showNewPassword ? 'text' : 'password'"
                  v-model="newPasswordConfirm"
                  class="form-control"
                  placeholder="Ulangi password baru"
                  required
                />
              </div>
              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="isForgotLoading">
                  <span v-if="isForgotLoading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  Simpan Password Baru
                </button>
              </div>
              <div class="text-center text-secondary small mt-2">
                Tidak menerima email?
                <a href="#" @click.prevent="forgotStep = 'request'" class="ms-1">Kirim ulang kode</a>
              </div>
            </form>

            <div class="text-center text-secondary mt-3">
              Forget it, <a href="#" @click.prevent="closeForgot">send me back</a> to the sign in screen.
            </div>
          </template>
        </div>
      </div>

      <!-- Right Column: Cover Photo -->
      <div class="col-12 col-lg-6 col-xl-8 d-none d-lg-block">
        <div
          class="bg-cover h-100 min-vh-100"
          :style="{ backgroundImage: `url(${coverImage})` }"
        ></div>
      </div>
    </div>
  </div>
</template>

<style>
/* Sembunyikan icon reveal password bawaan browser (Edge/IE) agar hanya icon kustom di paling belakang yang aktif */
input::-ms-reveal,
input::-ms-clear {
  display: none !important;
}
</style>
