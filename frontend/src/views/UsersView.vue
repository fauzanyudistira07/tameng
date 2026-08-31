<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import AppShell from '../components/AppShell.vue'
import TamengPagination from '../components/TamengPagination.vue'
import { createUser, getRoles, getUsers, updateUser } from '../services/userService'
import { authState } from '../stores/authStore'

const users = ref<any[]>([])
const roles = ref<any[]>([])
const isLoading = ref(true)
const isSaving = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const editingUserId = ref<any>(null)
const searchQuery = ref('')

const currentPage = ref(1)
const pageSize = ref(10)

const form = reactive({
  name: '',
  email: '',
  password: '',
  role_id: '',
  status: 'active',
})

const canManagePengguna = computed(() => authState.user?.role?.name === 'super_admin')

const filteredUsers = computed(() => {
  const query = (searchQuery.value || '').trim().toLowerCase()
  return (users.value || []).filter((u) => {
    if (!query) return true
    const name = (u.name ?? '').toLowerCase()
    const email = (u.email ?? '').toLowerCase()
    const role = (u.role?.display_name ?? u.role?.name ?? '').toLowerCase()
    return name.includes(query) || email.includes(query) || role.includes(query)
  })
})

const paginatedUsers = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value
  return filteredUsers.value.slice(start, start + pageSize.value)
})

watch(searchQuery, () => {
  currentPage.value = 1
})

function resetForm() {
  editingUserId.value = null
  form.name = ''
  form.email = ''
  form.password = ''
  form.role_id = roles.value[0]?.id ?? ''
  form.status = 'active'
}

function editUser(user: any) {
  editingUserId.value = user.id
  form.name = user.name
  form.email = user.email
  form.password = ''
  form.role_id = user.role_id
  form.status = user.status
}

async function loadPengguna() {
  if (!users.value.length) {
    isLoading.value = true
  }
  errorMessage.value = ''

  try {
    const [roleList, userList] = await Promise.all([getRoles(), getUsers()])
    roles.value = roleList
    users.value = userList
    if (!form.role_id && roleList.length > 0) {
      form.role_id = roleList[0].id
    }
  } catch (error) {
    console.error('Gagal memuat pengguna.', error)
    errorMessage.value = 'Gagal memuat data pengguna.'
  } finally {
    isLoading.value = false
  }
}

async function submitUser() {
  isSaving.value = true
  errorMessage.value = ''
  successMessage.value = ''

  const payload = {
    name: form.name,
    email: form.email,
    role_id: form.role_id,
    status: form.status,
    password: form.password || undefined,
  }

  try {
    if (editingUserId.value) {
      await updateUser(editingUserId.value, payload)
      successMessage.value = `Akun ${form.name} berhasil diperbarui!`
    } else {
      await createUser(payload)
      successMessage.value = `Akun ${form.name} berhasil dibuat!`
    }

    resetForm()
    await loadPengguna()
  } catch (error) {
    console.error('Gagal menyimpan pengguna.', error)
    errorMessage.value = 'Gagal menyimpan pengguna. Periksa panjang password dan email unik.'
  } finally {
    isSaving.value = false
  }
}

onMounted(loadPengguna)
</script>

<template>
  <AppShell>
    <template #eyebrow>Role-Based Access Control & Manajemen Akun</template>
    <template #title>Manajemen Pengguna TAMENG</template>

    <div v-if="successMessage" class="status-panel success fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      <span>{{ successMessage }}</span>
    </div>

    <div v-if="errorMessage" class="status-panel error fade-in">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
      <span>{{ errorMessage }}</span>
    </div>

    <section class="two-column">
      <!-- Left: Users Table -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            Daftar Akun Pengguna
          </h2>
          <span class="badge">{{ filteredUsers.length }} Akun</span>
        </div>

        <div class="table-toolbar">
          <div class="search-input-wrapper">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input v-model="searchQuery" placeholder="Cari nama, email, atau peran..." />
          </div>
        </div>

        <div class="table-responsive">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nama Pengguna</th>
                <th>Email</th>
                <th>Peran (RBAC)</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="user in paginatedUsers" :key="user.id">
                <td><strong style="color: var(--text-main);">{{ user.name }}</strong></td>
                <td class="code-text" style="font-size: 12px; color: var(--tameng-sapphire);">{{ user.email }}</td>
                <td><span class="badge is-info">{{ user.role?.display_name ?? 'User' }}</span></td>
                <td>
                  <span class="badge" :class="user.status === 'active' ? 'is-success' : 'is-muted'">
                    {{ user.status }}
                  </span>
                </td>
                <td class="table-actions">
                  <button
                    class="small-button secondary-button"
                    type="button"
                    :disabled="!canManagePengguna"
                    @click="editUser(user)"
                  >
                    Edit
                  </button>
                </td>
              </tr>
              <tr v-if="!filteredUsers.length && !isLoading">
                <td colspan="5" style="text-align: center; color: var(--text-dim); padding: 20px;">
                  Tidak ada pengguna terdaftar.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <TamengPagination
          v-model:currentPage="currentPage"
          v-model:pageSize="pageSize"
          :total-items="filteredUsers.length"
          :page-size-options="[5, 10, 20, 50]"
        />
      </div>

      <!-- Right: User Form -->
      <div class="panel">
        <div class="panel-heading">
          <h2>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
            {{ editingUserId ? 'Ubah Pengguna' : 'Tambah Pengguna Baru' }}
          </h2>
          <span class="badge is-danger">Khusus Super Admin</span>
        </div>

        <form class="entity-form" @submit.prevent="submitUser">
          <label>
            <span>Nama Lengkap</span>
            <input v-model="form.name" placeholder="Nama Admin / Analyst" :disabled="!canManagePengguna" required />
          </label>

          <label>
            <span>Email Login</span>
            <input v-model="form.email" placeholder="user@secsys.local" :disabled="!canManagePengguna" required type="email" />
          </label>

          <label>
            <span>Kata Sandi {{ editingUserId ? '(Kosongkan jika tidak diubah)' : '' }}</span>
            <input
              v-model="form.password"
              placeholder="Minimal 8-10 karakter"
              :disabled="!canManagePengguna"
              :required="!editingUserId"
              minlength="8"
              type="password"
            />
          </label>

          <label>
            <span>Peran Sistem (Role)</span>
            <select v-model="form.role_id" :disabled="!canManagePengguna" required>
              <option v-for="role in roles" :key="role.id" :value="role.id">
                {{ role.display_name }} ({{ role.description }})
              </option>
            </select>
          </label>

          <label>
            <span>Status Akun</span>
            <select v-model="form.status" :disabled="!canManagePengguna">
              <option value="active">Aktif</option>
              <option value="inactive">Nonaktif</option>
            </select>
          </label>

          <div class="form-actions">
            <button class="btn btn-primary-glow" type="submit" :disabled="!canManagePengguna || isSaving">
              {{ isSaving ? 'Menyimpan...' : 'Simpan Pengguna' }}
            </button>
            <button class="secondary-button" type="button" @click="resetForm">Reset</button>
          </div>
        </form>
      </div>
    </section>
  </AppShell>
</template>
