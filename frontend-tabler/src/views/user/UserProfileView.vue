<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useAuth } from '../../composables/useAuth'

const {
  currentUser,
  userInitials,
  roleDisplayName,
  roleBadgeClass,
  assignedProjects,
  updateProfile,
  handleLogout,
  loadUser
} = useAuth()

// Profile Form State
const name = ref('')
const username = ref('')
const email = ref('')
const phone = ref('')
const department = ref('')
const isUpdatingProfile = ref(false)
const profileAlert = ref<{ type: 'success' | 'danger'; text: string } | null>(null)

// Password Form State
const newPassword = ref('')
const confirmPassword = ref('')
const isUpdatingPassword = ref(false)
const passwordAlert = ref<{ type: 'success' | 'danger'; text: string } | null>(null)

function initForm() {
  if (currentUser.value) {
    name.value = currentUser.value.name || ''
    username.value = currentUser.value.username || ''
    email.value = currentUser.value.email || ''
    phone.value = currentUser.value.phone || ''
    department.value = currentUser.value.department || ''
  }
}

onMounted(async () => {
  await loadUser()
  initForm()
})

async function submitProfile() {
  if (!name.value.trim()) {
    profileAlert.value = { type: 'danger', text: 'Nama lengkap wajib diisi.' }
    return
  }

  isUpdatingProfile.value = true
  profileAlert.value = null

  try {
    await updateProfile({
      name: name.value.trim(),
      email: email.value.trim(),
      username: username.value.trim() || undefined,
      phone: phone.value.trim() || undefined,
      department: department.value.trim() || undefined
    })

    profileAlert.value = { type: 'success', text: 'Profil pribadi berhasil diperbarui!' }
    setTimeout(() => {
      if (profileAlert.value?.type === 'success') profileAlert.value = null
    }, 4000)
  } catch (err: any) {
    profileAlert.value = { type: 'danger', text: err?.message || 'Gagal memperbarui profil.' }
  } finally {
    isUpdatingProfile.value = false
  }
}

async function submitPassword() {
  if (!newPassword.value) {
    passwordAlert.value = { type: 'danger', text: 'Kata sandi baru wajib diisi.' }
    return
  }
  if (newPassword.value.length < 10) {
    passwordAlert.value = { type: 'danger', text: 'Kata sandi baru minimal 10 karakter.' }
    return
  }
  if (newPassword.value !== confirmPassword.value) {
    passwordAlert.value = { type: 'danger', text: 'Konfirmasi kata sandi tidak cocok.' }
    return
  }

  isUpdatingPassword.value = true
  passwordAlert.value = null

  try {
    await updateProfile({
      name: name.value.trim() || currentUser.value?.name || '',
      email: email.value.trim() || currentUser.value?.email || '',
      password: newPassword.value,
      password_confirmation: confirmPassword.value
    })

    passwordAlert.value = { type: 'success', text: 'Kata sandi Anda berhasil diperbarui!' }
    newPassword.value = ''
    confirmPassword.value = ''
    setTimeout(() => {
      if (passwordAlert.value?.type === 'success') passwordAlert.value = null
    }, 4000)
  } catch (err: any) {
    passwordAlert.value = { type: 'danger', text: err?.message || 'Gagal memperbarui kata sandi.' }
  } finally {
    isUpdatingPassword.value = false
  }
}
</script>

<template>
  <div class="user-profile-view">
    <!-- Breadcrumb & Header -->
    <div class="mb-4">
      <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-1">
          <li class="breadcrumb-item">
            <router-link to="/workspace" class="text-decoration-none">Ruang Kerja</router-link>
          </li>
          <li class="breadcrumb-item active" aria-current="page">Pengaturan Akun</li>
        </ol>
      </nav>
      <h1 class="h2 mb-0 fw-bold">Profil & Pengaturan Akun</h1>
      <p class="text-secondary small mb-0 mt-1">
        Kelola informasi pribadi, keamanan kata sandi, dan periksa proyek yang ditugaskan kepada Anda.
      </p>
    </div>

    <div class="row row-cards">
      <!-- Left Column: User Card & Assigned Projects (4 cols) -->
      <div class="col-lg-4">
        <!-- User Identity Card -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-body text-center p-4">
            <span class="avatar avatar-xl bg-teal-lt text-teal fw-bold mb-3 shadow-sm rounded-circle" style="font-size: 1.5rem;">
              {{ userInitials }}
            </span>
            <h3 class="h3 mb-1 fw-bold text-reset">{{ currentUser?.name }}</h3>
            <p class="text-muted small mb-2">{{ currentUser?.email }}</p>

            <div class="d-flex justify-content-center gap-2 mb-3">
              <span class="badge" :class="roleBadgeClass">{{ roleDisplayName }}</span>
              <span class="badge bg-success-lt text-success">Akun Aktif</span>
            </div>

            <div class="border-top pt-3 text-start small text-secondary d-flex flex-column gap-2">
              <div class="d-flex justify-content-between">
                <span>Username:</span>
                <strong class="text-reset font-monospace">{{ currentUser?.username || '-' }}</strong>
              </div>
              <div class="d-flex justify-content-between">
                <span>Departemen:</span>
                <strong class="text-reset">{{ currentUser?.department || '-' }}</strong>
              </div>
              <div class="d-flex justify-content-between">
                <span>Telepon:</span>
                <strong class="text-reset">{{ currentUser?.phone || '-' }}</strong>
              </div>
            </div>

            <div class="border-top pt-3 mt-3">
              <button type="button" class="btn btn-outline-danger btn-sm w-100" @click="handleLogout">
                Keluar Akun (Logout)
              </button>
            </div>
          </div>
        </div>

        <!-- Assigned Projects Card -->
        <div class="card border-0 shadow-sm">
          <div class="card-header py-3 border-bottom">
            <h4 class="card-title mb-0 fw-bold">Proyek Ditugaskan ({{ assignedProjects.length }})</h4>
          </div>
          <div class="card-body p-3">
            <div v-if="assignedProjects.length === 0" class="text-muted small text-center py-3">
              Belum ada proyek yang ditugaskan ke akun Anda.
            </div>
            <div v-else class="list-group list-group-flush">
              <div
                v-for="p in assignedProjects"
                :key="p.id"
                class="list-group-item p-2 px-0 d-flex align-items-center justify-content-between"
              >
                <div class="d-flex align-items-center gap-2">
                  <span class="avatar avatar-xs bg-yellow-lt text-yellow rounded">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></svg>
                  </span>
                  <div>
                    <div class="fw-semibold text-reset" style="font-size: 0.88rem;">{{ p.name }}</div>
                    <div class="text-muted small font-monospace">{{ p.code }}</div>
                  </div>
                </div>
                <span class="badge bg-secondary-lt text-secondary" style="font-size: 0.7rem;">
                  {{ p.access_level || 'Member' }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right Column: Edit Profile & Change Password (8 cols) -->
      <div class="col-lg-8">
        <!-- 1. Edit Profile Information -->
        <div class="card border-0 shadow-sm mb-4">
          <div class="card-header py-3 border-bottom">
            <h4 class="card-title mb-0 fw-bold">Informasi Pribadi</h4>
          </div>
          <div class="card-body p-4">
            <div v-if="profileAlert" class="alert py-2 mb-3" :class="profileAlert.type === 'success' ? 'alert-success' : 'alert-danger'">
              {{ profileAlert.text }}
            </div>

            <form @submit.prevent="submitProfile">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label required">Nama Lengkap</label>
                  <input v-model="name" type="text" class="form-control" placeholder="Nama Anda..." />
                </div>

                <div class="col-md-6">
                  <label class="form-label">Username</label>
                  <input v-model="username" type="text" class="form-control" placeholder="Username..." />
                </div>

                <div class="col-md-6">
                  <label class="form-label required">Alamat Email</label>
                  <input v-model="email" type="email" class="form-control" placeholder="Email..." />
                </div>

                <div class="col-md-6">
                  <label class="form-label">Nomor Telepon</label>
                  <input v-model="phone" type="text" class="form-control" placeholder="Contoh: 08123456789" />
                </div>

                <div class="col-md-12">
                  <label class="form-label">Departemen / Divisi</label>
                  <input v-model="department" type="text" class="form-control" placeholder="Contoh: Tim Backend, DevOps, Mobile..." />
                </div>
              </div>

              <div class="mt-4 text-end">
                <button
                  type="submit"
                  class="btn btn-primary d-inline-flex align-items-center gap-2"
                  :disabled="isUpdatingProfile"
                >
                  <span v-if="isUpdatingProfile" class="spinner-border spinner-border-sm" role="status"></span>
                  <span>Simpan Perubahan Profil</span>
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- 2. Change Password -->
        <div class="card border-0 shadow-sm">
          <div class="card-header py-3 border-bottom">
            <h4 class="card-title mb-0 fw-bold">Keamanan Kata Sandi</h4>
          </div>
          <div class="card-body p-4">
            <div v-if="passwordAlert" class="alert py-2 mb-3" :class="passwordAlert.type === 'success' ? 'alert-success' : 'alert-danger'">
              {{ passwordAlert.text }}
            </div>

            <form @submit.prevent="submitPassword">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label required">Kata Sandi Baru</label>
                  <input
                    v-model="newPassword"
                    type="password"
                    class="form-control"
                    placeholder="Minimal 10 karakter..."
                    autocomplete="new-password"
                  />
                </div>

                <div class="col-md-6">
                  <label class="form-label required">Ulangi Kata Sandi Baru</label>
                  <input
                    v-model="confirmPassword"
                    type="password"
                    class="form-control"
                    placeholder="Ketik ulang kata sandi baru..."
                    autocomplete="new-password"
                  />
                </div>
              </div>

              <div class="form-text small text-muted mt-2">
                Kata sandi minimal 10 karakter dan tidak boleh sama dengan salah satu dari 3 kata sandi terakhir Anda.
              </div>

              <div class="mt-4 text-end">
                <button
                  type="submit"
                  class="btn btn-outline-primary d-inline-flex align-items-center gap-2"
                  :disabled="isUpdatingPassword"
                >
                  <span v-if="isUpdatingPassword" class="spinner-border spinner-border-sm" role="status"></span>
                  <span>Perbarui Kata Sandi</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
