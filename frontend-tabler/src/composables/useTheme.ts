import { ref, computed } from 'vue'

export type ThemeMode = 'dark' | 'light' | 'cyber' | 'system'

const activeTheme = ref<ThemeMode>('dark')
const effectiveTheme = ref<'dark' | 'light' | 'cyber'>('dark')
let mediaQueryListenerAttached = false

export function useTheme() {
  function getSystemPreferredTheme(): 'dark' | 'light' {
    if (typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
      return 'dark'
    }
    return 'light'
  }

  function applyDOMTheme(theme: ThemeMode) {
    if (typeof document === 'undefined') return

    let resolved: 'dark' | 'light' | 'cyber' = 'dark'

    if (theme === 'system') {
      resolved = getSystemPreferredTheme()
    } else {
      resolved = theme
    }

    effectiveTheme.value = resolved

    if (resolved === 'cyber') {
      document.body.setAttribute('data-bs-theme', 'dark')
      document.documentElement.setAttribute('data-bs-theme', 'dark')
      document.body.setAttribute('data-theme', 'cyber')
      document.documentElement.setAttribute('data-theme', 'cyber')
    } else if (resolved === 'dark') {
      document.body.setAttribute('data-bs-theme', 'dark')
      document.documentElement.setAttribute('data-bs-theme', 'dark')
      document.body.removeAttribute('data-theme')
      document.documentElement.removeAttribute('data-theme')
    } else {
      // Light mode
      document.body.removeAttribute('data-bs-theme')
      document.documentElement.removeAttribute('data-bs-theme')
      document.body.removeAttribute('data-theme')
      document.documentElement.removeAttribute('data-theme')
    }
  }

  function initTheme(preferredFromUser?: string) {
    let chosenTheme: ThemeMode = 'dark'

    if (preferredFromUser && ['dark', 'light', 'cyber', 'system'].includes(preferredFromUser)) {
      chosenTheme = preferredFromUser as ThemeMode
    } else if (typeof localStorage !== 'undefined') {
      const saved = localStorage.getItem('tamengTheme') || localStorage.getItem('tablerTheme')
      if (saved && ['dark', 'light', 'cyber', 'system'].includes(saved)) {
        chosenTheme = saved as ThemeMode
      }
    }

    activeTheme.value = chosenTheme
    applyDOMTheme(chosenTheme)

    // Listen to OS theme changes if on system mode
    if (typeof window !== 'undefined' && window.matchMedia && !mediaQueryListenerAttached) {
      mediaQueryListenerAttached = true
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (activeTheme.value === 'system') {
          applyDOMTheme('system')
        }
      })
    }
  }

  function setTheme(newTheme: ThemeMode, syncBackend = true) {
    activeTheme.value = newTheme
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem('tamengTheme', newTheme)
      localStorage.setItem('tablerTheme', newTheme === 'light' ? 'light' : 'dark')
    }
    applyDOMTheme(newTheme)

    if (syncBackend && typeof fetch !== 'undefined') {
      // Background sync to user preference in backend
      fetch('/api/user', {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        credentials: 'include',
        body: JSON.stringify({ theme: newTheme })
      }).catch(() => {
        // Silently ignore if guest or network error
      })
    }
  }

  function toggleTheme(e?: Event) {
    if (e) e.preventDefault()
    // Cycle: dark -> light -> cyber -> dark
    if (activeTheme.value === 'dark') {
      setTheme('light')
    } else if (activeTheme.value === 'light') {
      setTheme('cyber')
    } else {
      setTheme('dark')
    }
  }

  const isDark = computed(() => effectiveTheme.value === 'dark' || effectiveTheme.value === 'cyber')

  return {
    activeTheme,
    effectiveTheme,
    isDark,
    initTheme,
    setTheme,
    toggleTheme
  }
}
