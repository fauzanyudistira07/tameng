<script setup lang="ts">
import { watch, onMounted, onUnmounted } from 'vue'
import { useAuth } from '../../composables/useAuth'

const {
  currentUser,
  userInitials,
  roleDisplayName,
  isProfileModalOpen,
  closeProfileModal
} = useAuth()

watch(isProfileModalOpen, (isOpen) => {
  if (typeof document !== 'undefined') {
    if (isOpen) {
      document.body.classList.add('modal-open')
    } else {
      document.body.classList.remove('modal-open')
    }
  }
})

function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && isProfileModalOpen.value) {
    closeProfileModal()
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

  <!-- Profile Modal: Security Identity -->
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
    <div class="modal-dialog modal-dialog-centered" style="max-width: 520px;" role="document">
      <div class="modal-content shadow-lg border-0">
        <!-- Header -->
        <div class="modal-header border-bottom py-3 px-4">
          <div>
            <h3 class="modal-title fw-bold text-uppercase tracking-wider text-body mb-0">SECURITY IDENTITY</h3>
            <div class="text-secondary small mt-1">Account & access information</div>
          </div>
          <button
            type="button"
            class="btn-close"
            data-bs-dismiss="modal"
            aria-label="Close"
            @click="closeProfileModal"
          ></button>
        </div>

        <!-- Body: Single Main Profile Card -->
        <div class="modal-body p-4">
          <div class="card border mb-0">
            <div class="card-body p-4">
              <div class="d-flex align-items-center gap-4">
                <!-- Avatar Tabler -->
                <span
                  class="avatar avatar-xl bg-primary-lt text-primary fw-bold rounded flex-shrink-0"
                  style="width: 80px; height: 80px; font-size: 1.75rem;"
                >
                  {{ userInitials }}
                </span>

                <!-- Identity Information -->
                <div class="d-flex flex-column text-truncate">
                  <h2 class="fw-bold fs-2 text-uppercase tracking-wide text-body mb-1 text-truncate">
                    {{ currentUser?.name || 'SYSTEM ADMIN' }}
                  </h2>
                  <div class="text-secondary fs-4 mb-2">
                    {{ roleDisplayName || 'Super Administrator' }}
                  </div>
                  <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-success-lt d-inline-flex align-items-center gap-1">
                      <span class="status-dot status-dot-animated bg-success"></span>
                      ACTIVE
                    </span>
                    <span class="text-secondary small">•</span>
                    <span class="badge bg-success-lt">
                      VERIFIED
                    </span>
                  </div>
                  <div class="text-secondary small mb-1">
                    {{ currentUser?.email || 'admin@secsys.local' }}
                  </div>
                  <div class="text-muted small">
                    User ID #{{ currentUser?.id || 1 }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
