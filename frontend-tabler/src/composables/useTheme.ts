import { ref } from 'vue'

const isDark = ref(false)

export function useTheme() {
  function initTheme() {
    const savedTheme = localStorage.getItem('tablerTheme')
    if (savedTheme === 'dark') {
      isDark.value = true
      document.body.setAttribute('data-bs-theme', 'dark')
      document.documentElement.setAttribute('data-bs-theme', 'dark')
    } else if (savedTheme === 'light') {
      isDark.value = false
      document.body.removeAttribute('data-bs-theme')
      document.documentElement.removeAttribute('data-bs-theme')
    } else {
      // Check current DOM attribute if already set
      const current = document.body.getAttribute('data-bs-theme') || document.documentElement.getAttribute('data-bs-theme')
      isDark.value = current === 'dark'
    }
  }

  function toggleTheme(e?: Event) {
    if (e) e.preventDefault()
    const currentlyDark = document.body.getAttribute('data-bs-theme') === 'dark' || document.documentElement.getAttribute('data-bs-theme') === 'dark'
    if (currentlyDark) {
      document.body.removeAttribute('data-bs-theme')
      document.documentElement.removeAttribute('data-bs-theme')
      localStorage.setItem('tablerTheme', 'light')
      isDark.value = false
    } else {
      document.body.setAttribute('data-bs-theme', 'dark')
      document.documentElement.setAttribute('data-bs-theme', 'dark')
      localStorage.setItem('tablerTheme', 'dark')
      isDark.value = true
    }
  }

  return {
    isDark,
    initTheme,
    toggleTheme
  }
}
