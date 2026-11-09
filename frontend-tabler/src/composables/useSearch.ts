import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { apiFetch } from '../services/api'

export interface SearchItem {
  id: string
  title: string
  subtitle: string
  category: 'Halaman' | 'Celah Keamanan' | 'Aset & Repositori' | 'Pekerjaan Scan'
  url?: string
  badge?: string
  badgeClass?: string
  iconType: 'dashboard' | 'scan' | 'folder' | 'target' | 'shield' | 'bug' | 'report' | 'user' | 'cpu' | 'audit'
}

// 1. Modul & Halaman Inti TAMENG
const MODULE_PAGES: SearchItem[] = [
  {
    id: 'page-dashboard',
    title: 'Dasbor Keamanan',
    subtitle: 'Overview metrik insiden & status keamanan real-time',
    category: 'Halaman',
    url: '/',
    badge: 'Modul',
    badgeClass: 'bg-azure-lt',
    iconType: 'dashboard'
  },
  {
    id: 'page-scan-jobs',
    title: 'Pekerjaan Scan',
    subtitle: 'Monitoring antrean dan riwayat pekerjaan pemindaian',
    category: 'Halaman',
    url: '/scan-jobs',
    badge: 'Modul',
    badgeClass: 'bg-azure-lt',
    iconType: 'scan'
  },
  {
    id: 'page-scan-saya',
    title: 'Scan Mandiri',
    subtitle: 'Pengajuan pemindaian kode dan target mandiri',
    category: 'Halaman',
    url: '/scan-saya',
    badge: 'Dev',
    badgeClass: 'bg-blue-lt',
    iconType: 'scan'
  },
  {
    id: 'page-projects',
    title: 'Proyek',
    subtitle: 'Daftar grup proyek aplikasi dan unit terlindungi',
    category: 'Halaman',
    url: '/projects',
    badge: 'Aset',
    badgeClass: 'bg-purple-lt',
    iconType: 'folder'
  },
  {
    id: 'page-repositories',
    title: 'Repositori Kode',
    subtitle: 'Manajemen repositori Git sumber kode untuk SAST',
    category: 'Halaman',
    url: '/repositories',
    badge: 'Aset',
    badgeClass: 'bg-purple-lt',
    iconType: 'folder'
  },
  {
    id: 'page-targets',
    title: 'Target Web & API',
    subtitle: 'Endpoint URL dan IP host untuk pengujian DAST',
    category: 'Halaman',
    url: '/targets',
    badge: 'Aset',
    badgeClass: 'bg-purple-lt',
    iconType: 'target'
  },
  {
    id: 'page-scopes',
    title: 'Ruang Lingkup (Scope)',
    subtitle: 'Aturan batasan domain dan subnet yang diizinkan dipindai',
    category: 'Halaman',
    url: '/scopes',
    badge: 'Tata Kelola',
    badgeClass: 'bg-cyan-lt',
    iconType: 'shield'
  },
  {
    id: 'page-authorizations',
    title: 'Otorisasi Scan',
    subtitle: 'Surat persetujuan formal dan izin audit keamanan',
    category: 'Halaman',
    url: '/authorizations',
    badge: 'Tata Kelola',
    badgeClass: 'bg-cyan-lt',
    iconType: 'shield'
  },
  {
    id: 'page-engines',
    title: 'Engine Registry',
    subtitle: '20 Mesin scan: ZAP, Semgrep, Trivy, MobSF, Gitleaks, dsb.',
    category: 'Halaman',
    url: '/engines',
    badge: 'Mesin',
    badgeClass: 'bg-teal-lt',
    iconType: 'cpu'
  },
  {
    id: 'page-findings',
    title: 'Temuan Kerentanan',
    subtitle: 'Database seluruh celah keamanan (CVE, CWE, OWASP)',
    category: 'Halaman',
    url: '/findings',
    badge: 'Analisis',
    badgeClass: 'bg-red-lt',
    iconType: 'bug'
  },
  {
    id: 'page-reports',
    title: 'Laporan Keamanan',
    subtitle: 'Dokumen eksekutif & teknis hasil audit siap unduh PDF/Excel',
    category: 'Halaman',
    url: '/reports',
    badge: 'Laporan',
    badgeClass: 'bg-indigo-lt',
    iconType: 'report'
  },
  {
    id: 'page-audit-logs',
    title: 'Log Audit Forensik',
    subtitle: 'Jejak rekam aktivitas sistem dan pengguna yang immutable',
    category: 'Halaman',
    url: '/audit-logs',
    badge: 'Audit',
    badgeClass: 'bg-secondary-lt',
    iconType: 'audit'
  },
  {
    id: 'page-users',
    title: 'Manajemen Pengguna',
    subtitle: 'Pengaturan akun staf SOC dan hak akses RBAC',
    category: 'Halaman',
    url: '/users',
    badge: 'Admin',
    badgeClass: 'bg-yellow-lt',
    iconType: 'user'
  }
]

const searchResults = ref<SearchItem[]>([])
const isLoadingTameng = ref(false)
let debounceTimer: any = null

export function useSearch() {
  const searchQuery = ref('')
  const isSearchOpen = ref(false)
  const searchInputRef = ref<HTMLInputElement | null>(null)
  const selectedIndex = ref(0)

  async function executeSearch(query: string) {
    const q = query.trim()
    if (!q) {
      searchResults.value = []
      isLoadingTameng.value = false
      return
    }

    // 1. Instant local matching for static modules
    const lowerQ = q.toLowerCase()
    const localMatches = MODULE_PAGES.filter(p =>
      p.title.toLowerCase().includes(lowerQ) ||
      p.subtitle.toLowerCase().includes(lowerQ) ||
      p.url?.toLowerCase().includes(lowerQ)
    )
    if (searchResults.value.length === 0) {
      searchResults.value = localMatches
    }

    isLoadingTameng.value = true

    // 2. Fetch comprehensive results from Go Backend Unified Search API
    try {
      const res = await apiFetch(`/api/search?q=${encodeURIComponent(q)}`)
      if (res?.results && Array.isArray(res.results)) {
        searchResults.value = res.results
      }
    } catch (err) {
      console.warn('[Search] Go search error, fallback to local matches:', err)
      searchResults.value = localMatches
    } finally {
      isLoadingTameng.value = false
    }
  }

  // Watch search input with debounce
  watch([searchQuery, isSearchOpen], ([query, open]) => {
    if (!open && !query) {
      searchResults.value = []
      return
    }

    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(() => {
      executeSearch(query)
    }, 150)
  })

  const resultsByCategory = computed(() => {
    const groups: { [key: string]: SearchItem[] } = {}
    for (const item of searchResults.value) {
      if (!groups[item.category]) groups[item.category] = []
      groups[item.category].push(item)
    }
    return groups
  })

  function clearSearch() {
    searchQuery.value = ''
    isSearchOpen.value = false
    searchResults.value = []
    selectedIndex.value = 0
    searchInputRef.value?.focus()
  }

  function handleKeydown(e: KeyboardEvent) {
    if (!isSearchOpen.value) return

    if (e.key === 'Escape') {
      isSearchOpen.value = false
      searchInputRef.value?.blur()
    } else if (e.key === 'ArrowDown') {
      e.preventDefault()
      if (searchResults.value.length > 0) {
        selectedIndex.value = (selectedIndex.value + 1) % searchResults.value.length
      }
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      if (searchResults.value.length > 0) {
        selectedIndex.value = (selectedIndex.value - 1 + searchResults.value.length) % searchResults.value.length
      }
    } else if (e.key === 'Enter') {
      if (searchResults.value.length > 0 && searchResults.value[selectedIndex.value]) {
        const item = searchResults.value[selectedIndex.value]
        if (item.url) {
          window.location.href = item.url
        }
      }
    }
  }

  function handleClickOutside(e: MouseEvent) {
    const target = e.target as HTMLElement
    if (!target.closest('.search-container')) {
      isSearchOpen.value = false
    }
  }

  onMounted(() => {
    window.addEventListener('keydown', handleKeydown)
    window.addEventListener('click', handleClickOutside)
  })

  onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown)
    window.removeEventListener('click', handleClickOutside)
  })

  return {
    searchQuery,
    isSearchOpen,
    searchInputRef,
    selectedIndex,
    searchResults,
    resultsByCategory,
    isLoadingTameng,
    clearSearch
  }
}
