import { ref } from 'vue'

const isMobileSidebarOpen = ref(false)

export function useSidebar() {
  function toggleMobileSidebar() {
    isMobileSidebarOpen.value = !isMobileSidebarOpen.value
  }

  function closeMobileSidebar() {
    isMobileSidebarOpen.value = false
  }

  function openMobileSidebar() {
    isMobileSidebarOpen.value = true
  }

  return {
    isMobileSidebarOpen,
    toggleMobileSidebar,
    closeMobileSidebar,
    openMobileSidebar
  }
}
