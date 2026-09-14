<script setup lang="ts">
import { ref, reactive, watch, onMounted, onUnmounted } from 'vue'
import { useAuth } from '../../composables/useAuth'

const {
  currentUser,
  userInitials,
  roleDisplayName,
  isProfileModalOpen,
  closeProfileModal,
  loadUser,
  updateProfile
} = useAuth()

const isEditing = ref(false)
const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')

const editForm = reactive({
  name: '',
  username: '',
  email: '',
  phone: '',
  department: '',
  password: '',
  password_confirmation: ''
})

function startEditing() {
  errorMessage.value = ''
  successMessage.value = ''
  editForm.name = currentUser.value?.name || ''
  editForm.username = currentUser.value?.username || ''
  editForm.email = currentUser.value?.email || ''
  editForm.phone = currentUser.value?.phone || ''
  editForm.department = currentUser.value?.department || ''
  editForm.password = ''
  editForm.password_confirmation = ''
  isEditing.value = true
}

function cancelEditing() {
  isEditing.value = false
  errorMessage.value = ''
  successMessage.value = ''
}

async function handleSaveProfile() {
  errorMessage.value = ''
  successMessage.value = ''

  if (!editForm.name.trim()) {
    errorMessage.value = 'Nama lengkap wajib diisi.'
    return
  }
  if (!editForm.email.trim()) {
    errorMessage.value = 'Email wajib diisi.'
    return
  }
  if (editForm.password && editForm.password.length < 8) {
    errorMessage.value = 'Kata sandi baru minimal 8 karakter.'
    return
  }
  if (editForm.password && editForm.password !== editForm.password_confirmation) {
    errorMessage.value = 'Konfirmasi kata sandi tidak cocok.'
    return
  }

  isSaving.value = true
  try {
    await updateProfile({
      name: editForm.name,
      username: editForm.username,
      email: editForm.email,
      phone: editForm.phone,
      department: editForm.department,
      password: editForm.password || undefined,
      password_confirmation: editForm.password_confirmation || undefined
    })
    successMessage.value = 'Profil berhasil diperbarui!'
    setTimeout(() => {
      isEditing.value = false
      successMessage.value = ''
    }, 1000)
  } catch (err: any) {
    errorMessage.value = err?.data?.message || err?.message || 'Gagal memperbarui profil.'
  } finally {
    isSaving.value = false
  }
}

watch(isProfileModalOpen, (isOpen) => {
  if (typeof document !== 'undefined') {
    if (isOpen) {
      document.body.classList.add('modal-open')
      isEditing.value = false
      errorMessage.value = ''
      successMessage.value = ''
      loadUser()
    } else {
      document.body.classList.remove('modal-open')
    }
  }
})

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && isProfileModalOpen.value) {
    if (isEditing.value) {
      cancelEditing()
    } else {
      closeProfileModal()
    }
  }
}

function formatDateTime(dateStr?: string | null): string {
  if (!dateStr) return 'Belum ada catatan aktivitas'
  try {
    const d = new Date(dateStr)
    return d.toLocaleString('id-ID', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    }) + ' WIB'
  } catch {
    return dateStr
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleKeydown)
  if (typeof document !== 'undefined') {
    document.body.classList.remove('modal-open')
  }
})
</script>

<template>
  <!-- Modal Backdrop -->
  <div
    v-if="isProfileModalOpen"
    class="modal-backdrop fade show"
    style="z-index: 1050;"
    @click="closeProfileModal"
  ></div>

  <!-- Profile Modal: Security Identity & Personel Profile -->
  <div
    class="modal modal-blur fade"
    :class="{ 'show d-block': isProfileModalOpen }"
    id="modal-profile"
    tabindex="-1"
    role="dialog"
    :aria-hidden="!isProfileModalOpen"
    style="z-index: 1055;"
    @click.self="closeProfileModal"
  >
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 620px;" role="document">
      <div class="modal-content shadow-lg border-0">
        <!-- Header -->
        <div class="modal-header border-bottom py-3 px-4 bg-body-tertiary">
          <div class="d-flex align-items-center gap-2">
            <svg v-if="!isEditing" xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3" /></svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" /><path d="M16 5l3 3" /></svg>
            <h3 class="modal-title fw-bold text-uppercase tracking-wider text-body mb-0">
              {{ isEditing ? 'EDIT PROFIL PERSONEL' : 'IDENTITAS PERSONEL & PROFIL SOC' }}
            </h3>
          </div>
          <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal"
            aria-label="Tutup"
            @click="closeProfileModal"
          ></button>
        </div>

        <!-- Body -->
        <div class="modal-body p-4">
          <!-- Alert Feedback -->
          <div v-if="errorMessage" class="alert alert-danger alert-dismissible mb-3" role="alert">
            <div class="d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 8l0 4" /><path d="M12 16l.01 0" /></svg>
              <span>{{ errorMessage }}</span>
            </div>
          </div>
          <div v-if="successMessage" class="alert alert-success mb-3" role="alert">
            <div class="d-flex align-items-center gap-2">
              <svg xmlns="http://www.w3.org/2000/svg" class="icon alert-icon" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
              <span>{{ successMessage }}</span>
            </div>
          </div>

          <!-- VIEW MODE -->
          <template v-if="!isEditing">
            <!-- Top Card: Profile Header -->
            <div class="card border mb-3 bg-surface">
              <div class="card-body p-3 p-md-4">
                <div class="d-flex align-items-center gap-3">
                  <!-- Avatar Tabler -->
                  <span
                    class="avatar avatar-xl bg-primary-lt text-primary fw-bold rounded-circle flex-shrink-0 shadow-sm"
                    style="width: 72px; height: 72px; font-size: 1.6rem;"
                  >
                    {{ userInitials }}
                  </span>

                  <!-- Name & Role -->
                  <div class="d-flex flex-column text-truncate flex-grow-1">
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                      <h2 class="fw-bold fs-2 text-uppercase tracking-wide text-body mb-0 text-truncate">
                        {{ currentUser?.name || 'SYSTEM ADMIN' }}
                      </h2>
                      <span class="badge bg-purple-lt fw-semibold">
                        {{ roleDisplayName || 'Super Admin' }}
                      </span>
                    </div>

                    <div class="text-secondary small mb-1 d-flex align-items-center gap-2 flex-wrap">
                      <span v-if="currentUser?.username" class="text-muted fw-medium">@{{ currentUser.username }}</span>
                      <span v-if="currentUser?.username">•</span>
                      <span>{{ currentUser?.email || 'admin@secsys.local' }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Section: Detail Personel & Kontak -->
            <div class="card border mb-3">
              <div class="card-header py-2 px-3 bg-body-tertiary">
                <h4 class="card-title fs-5 mb-0 d-flex align-items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" class="icon text-secondary" width="20" height="20" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 3m0 3a3 3 0 0 1 3 -3h8a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-8a3 3 0 0 1 -3 -3z" /><path d="M9 7h6" /><path d="M9 11h6" /><path d="M10 15h4" /></svg>
                  Detail Personel & Kontak
                </h4>
              </div>
              <div class="card-body p-3">
                <div class="row g-3">
                  <div class="col-sm-6">
                    <div class="text-secondary small mb-1">Departemen / Unit Kerja</div>
                    <div class="fw-semibold text-body d-flex align-items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-muted" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M3 21l18 0" /><path d="M9 8l1 0" /><path d="M9 12l1 0" /><path d="M9 16l1 0" /><path d="M14 8l1 0" /><path d="M14 12l1 0" /><path d="M14 16l1 0" /><path d="M5 21v-16a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v16" /></svg>
                      {{ currentUser?.department || 'Puslitbang / Tim CSIRT' }}
                    </div>
                  </div>

                  <div class="col-sm-6">
                    <div class="text-secondary small mb-1">Kontak Telepon / Darurat</div>
                    <div class="fw-semibold text-body d-flex align-items-center gap-1">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-muted" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 4h4l2 5l-2.5 1.5a11 11 0 0 0 5 5l1.5 -2.5l5 2v4a2 2 0 0 1 -2 2a16 16 0 0 1 -15 -15a2 2 0 0 1 2 -2" /></svg>
                      {{ currentUser?.phone || '+62 811-1234-5678' }}
                    </div>
                  </div>

                  <div class="col-sm-6">
                    <div class="text-secondary small mb-1">Aktivitas Terakhir (Login)</div>
                    <div class="fw-semibold text-body d-flex align-items-center gap-1 small">
                      <svg xmlns="http://www.w3.org/2000/svg" class="icon icon-xs text-muted" width="16" height="16" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 7l0 5l3 3" /></svg>
                      {{ formatDateTime(currentUser?.last_login_at) }}
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>

          <!-- EDIT MODE FORM -->
          <template v-else>
            <form @submit.prevent="handleSaveProfile">
              <div class="card border mb-3">
                <div class="card-header py-2 px-3 bg-body-tertiary">
                  <h4 class="card-title fs-5 mb-0 d-flex align-items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon text-primary" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0" /><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2" /></svg>
                    Informasi Data Diri
                  </h4>
                </div>
                <div class="card-body p-3">
                  <div class="row g-3">
                    <div class="col-sm-6">
                      <label class="form-label required">Nama Lengkap</label>
                      <input
                        v-model="editForm.name"
                        type="text"
                        class="form-control"
                        placeholder="Nama lengkap personel"
                        required
                      />
                    </div>

                    <div class="col-sm-6">
                      <label class="form-label">Username</label>
                      <div class="input-group">
                        <span class="input-group-text">@</span>
                        <input
                          v-model="editForm.username"
                          type="text"
                          class="form-control"
                          placeholder="username"
                        />
                      </div>
                    </div>

                    <div class="col-sm-6">
                      <label class="form-label required">Email Resmi</label>
                      <input
                        v-model="editForm.email"
                        type="email"
                        class="form-control"
                        placeholder="nama@instansi.go.id"
                        required
                      />
                    </div>

                    <div class="col-sm-6">
                      <label class="form-label">No. Telepon / WhatsApp</label>
                      <input
                        v-model="editForm.phone"
                        type="text"
                        class="form-control"
                        placeholder="+62 812-xxxx-xxxx"
                      />
                    </div>

                    <div class="col-12">
                      <label class="form-label">Departemen / Unit Kerja</label>
                      <input
                        v-model="editForm.department"
                        type="text"
                        class="form-control"
                        placeholder="Contoh: Puslitbang / Tim CSIRT"
                      />
                    </div>
                  </div>
                </div>
              </div>

              <!-- Password Change (Optional) -->
              <div class="card border mb-0">
                <div class="card-header py-2 px-3 bg-body-tertiary">
                  <h4 class="card-title fs-5 mb-0 d-flex align-items-center gap-2">
                    <svg xmlns="http://www.w3.org/2000/svg" class="icon text-secondary" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z" /><path d="M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0" /><path d="M8 11v-4a4 4 0 1 1 8 0v4" /></svg>
                    Ubah Kata Sandi <span class="text-secondary fw-normal fs-6">(Opsional)</span>
                  </h4>
                </div>
                <div class="card-body p-3">
                  <div class="row g-3">
                    <div class="col-sm-6">
                      <label class="form-label">Kata Sandi Baru</label>
                      <input
                        v-model="editForm.password"
                        type="password"
                        class="form-control"
                        placeholder="Minimal 8 karakter"
                        autocomplete="new-password"
                      />
                    </div>
                    <div class="col-sm-6">
                      <label class="form-label">Konfirmasi Kata Sandi Baru</label>
                      <input
                        v-model="editForm.password_confirmation"
                        type="password"
                        class="form-control"
                        placeholder="Ulangi kata sandi baru"
                        autocomplete="new-password"
                      />
                    </div>
                    <div class="col-12 mt-1">
                      <small class="text-muted">
                        Biarkan kedua kolom kata sandi kosong jika Anda tidak bermaksud mengganti kata sandi.
                      </small>
                    </div>
                  </div>
                </div>
              </div>
            </form>
          </template>
        </div>

        <!-- Footer -->
        <div class="modal-footer border-top py-2 px-4 bg-body-tertiary d-flex justify-content-between">
          <template v-if="!isEditing">
            <button
              type="button"
              class="btn btn-outline-primary d-inline-flex align-items-center gap-1"
              @click="startEditing"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M7 7h-1a2 2 0 0 0 -2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2 -2v-1" /><path d="M20.385 6.585a2.1 2.1 0 0 0 -2.97 -2.97l-8.415 8.385v3h3l8.385 -8.415z" /><path d="M16 5l3 3" /></svg>
              Edit Profil
            </button>
            <button
              type="button"
              class="btn btn-secondary"
              data-bs-dismiss="modal"
              @click="closeProfileModal"
            >
              Tutup
            </button>
          </template>

          <template v-else>
            <button
              type="button"
              class="btn btn-link link-secondary px-0"
              :disabled="isSaving"
              @click="cancelEditing"
            >
              Batal
            </button>
            <button
              type="button"
              class="btn btn-primary d-inline-flex align-items-center gap-1"
              :disabled="isSaving"
              @click="handleSaveProfile"
            >
              <span v-if="isSaving" class="spinner-border spinner-border-sm me-1" role="status"></span>
              <svg v-else xmlns="http://www.w3.org/2000/svg" class="icon" width="18" height="18" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none"/><path d="M5 12l5 5l10 -10" /></svg>
              {{ isSaving ? 'Menyimpan...' : 'Simpan Perubahan' }}
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
