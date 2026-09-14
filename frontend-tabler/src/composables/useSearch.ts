import { ref, computed, onMounted, onUnmounted } from 'vue'
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

// Global cache for live TAMENG data
const tamengLiveItems = ref<SearchItem[]>([])
const isLoadingTameng = ref(false)
let isLoaded = false

function getSeverityBadgeClass(sev: string): string {
  switch (sev?.toLowerCase()) {
    case 'critical':
      return 'bg-red text-red-fg'
    case 'high':
      return 'bg-orange text-orange-fg'
    case 'medium':
      return 'bg-yellow text-yellow-fg'
    case 'low':
      return 'bg-blue-lt text-blue'
    default:
      return 'bg-secondary-lt text-secondary'
  }
}

async function fetchTamengData() {
  if (isLoaded || isLoadingTameng.value) return
  isLoadingTameng.value = true

  try {
    const items: SearchItem[] = []

    // 1. Fetch Real Findings from TAMENG API
    try {
      const findingsData = await apiFetch('/api/findings')
      const findingsList = findingsData?.findings || []
      findingsList.forEach((f: any) => {
        const severity = f.severity ? f.severity.toUpperCase() : 'MEDIUM'
        items.push({
          id: `finding-${f.id}`,
          title: f.title || f.rule_id || 'Kerentanan Terdeteksi',
          subtitle: `${f.code} • Engine: ${f.engine_key || 'scanner'} • Proyek: ${f.project?.name || 'Aset'}`,
          category: 'Celah Keamanan',
          url: '/findings',
          badge: severity,
          badgeClass: getSeverityBadgeClass(f.severity),
          iconType: 'bug'
        })
      })
    } catch (e) {
      console.warn('[Search] Failed loading findings:', e)
    }

    // 2. Fetch Real Scan Jobs from TAMENG API
    try {
      const jobsData = await apiFetch('/api/scan-jobs')
      const jobsList = jobsData?.scan_jobs || []
      jobsList.forEach((j: any) => {
        const status = (j.status || 'unknown').toUpperCase()
        const targetDesc = j.repository?.name || j.target?.name || j.project?.name || 'Aset'
        items.push({
          id: `job-${j.id}`,
          title: `${j.code}`,
          subtitle: `Status: ${status} (${j.progress ?? 0}%) • Target: ${targetDesc}`,
          category: 'Pekerjaan Scan',
          url: '/scan-jobs',
          badge: status,
          badgeClass: j.status === 'completed' ? 'bg-success text-success-fg' : j.status === 'running' ? 'bg-blue text-blue-fg' : 'bg-secondary-lt text-secondary',
          iconType: 'scan'
        })
      })
    } catch (e) {
      console.warn('[Search] Failed loading scan jobs:', e)
    }

    // 3. Fetch Real Projects from TAMENG API
    try {
      const projectsData = await apiFetch('/api/projects')
      const projectList = Array.isArray(projectsData) ? projectsData : projectsData?.projects || []
      projectList.forEach((p: any) => {
        items.push({
          id: `project-${p.id}`,
          title: p.name,
          subtitle: `Kode: ${p.code || '-'} • ${p.description || 'Grup proyek aplikasi'}`,
          category: 'Aset & Repositori',
          url: '/projects',
          badge: 'Proyek',
          badgeClass: 'bg-purple-lt',
          iconType: 'folder'
        })
      })
    } catch (e) {
      console.warn('[Search] Failed loading projects:', e)
    }

    // 4. Fetch Real Repositories from TAMENG API
    try {
      const reposData = await apiFetch('/api/repositories')
      const repoList = Array.isArray(reposData) ? reposData : reposData?.repositories || []
      repoList.forEach((r: any) => {
        items.push({
          id: `repo-${r.id}`,
          title: r.name,
          subtitle: `${r.clone_url || r.name} (${r.default_branch || 'main'})`,
          category: 'Aset & Repositori',
          url: '/repositories',
          badge: 'SAST Git',
          badgeClass: 'bg-dark-lt',
          iconType: 'folder'
        })
      })
    } catch (e) {
      console.warn('[Search] Failed loading repositories:', e)
    }

    // 5. Fetch Real Targets from TAMENG API
    try {
      const targetsData = await apiFetch('/api/targets')
      const targetList = Array.isArray(targetsData) ? targetsData : targetsData?.targets || []
      targetList.forEach((t: any) => {
        items.push({
          id: `target-${t.id}`,
          title: t.name || t.target_url || 'Target',
          subtitle: `${t.target_url || t.ip_address || '-'} (Tipe: ${t.type || 'web'})`,
          category: 'Aset & Repositori',
          url: '/targets',
          badge: 'Target DAST',
          badgeClass: 'bg-green-lt',
          iconType: 'target'
        })
      })
    } catch (e) {
      console.warn('[Search] Failed loading targets:', e)
    }

    tamengLiveItems.value = items
    isLoaded = true
  } catch (err) {
    console.error('[Search] Error fetching TAMENG live data:', err)
  } finally {
    isLoadingTameng.value = false
  }
}

export function useSearch() {
  const searchQuery = ref('')
  const isSearchOpen = ref(false)
  const searchInputRef = ref<HTMLInputElement | null>(null)
  const selectedIndex = ref(0)

  // Trigger real data fetch
  onMounted(() => {
    fetchTamengData()
  })

  const searchResults = computed(() => {
    const q = searchQuery.value.trim().toLowerCase()
    if (!q) return []

    // Search across static navigation modules + real live TAMENG data
    const allPool = [...MODULE_PAGES, ...tamengLiveItems.value]

    const matches = allPool.filter(item => {
      const titleMatch = item.title.toLowerCase().includes(q)
      const subMatch = item.subtitle.toLowerCase().includes(q)
      const catMatch = item.category.toLowerCase().includes(q)
      const badgeMatch = item.badge?.toLowerCase().includes(q)
      return titleMatch || subMatch || catMatch || badgeMatch
    })

    // Limit to top 20 most relevant results for smooth UX
    return matches.slice(0, 20)
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
    refreshTamengData: fetchTamengData,
    clearSearch
  }
}
