import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '../services/api'

export interface NotificationItem {
  id: string
  title: string
  description: string
  time: string
  timestamp: number
  category: 'Celah Keamanan' | 'Pekerjaan Scan' | 'Tata Kelola' | 'Mesin'
  statusDot: string
  badge: string
  badgeClass: string
  isRead: boolean
  isStarred: boolean
  url?: string
}

const STORAGE_READ_KEY = 'tameng_notif_read'
const STORAGE_STARRED_KEY = 'tameng_notif_starred'
const STORAGE_DISMISSED_KEY = 'tameng_notif_dismissed'

function getStoredSet(key: string): Set<string> {
  try {
    const raw = localStorage.getItem(key)
    return raw ? new Set(JSON.parse(raw)) : new Set()
  } catch {
    return new Set()
  }
}

function saveStoredSet(key: string, set: Set<string>) {
  try {
    localStorage.setItem(key, JSON.stringify(Array.from(set)))
  } catch {
    // Ignore quota errors
  }
}

function formatRelativeTime(dateStr?: string): string {
  if (!dateStr) return 'Baru saja'
  try {
    const date = new Date(dateStr)
    const now = new Date()
    const diffMs = now.getTime() - date.getTime()
    const diffMins = Math.floor(diffMs / 60000)
    const diffHours = Math.floor(diffMins / 60)
    const diffDays = Math.floor(diffHours / 24)

    if (diffMins < 1) return 'Baru saja'
    if (diffMins < 60) return `${diffMins} menit yang lalu`
    if (diffHours < 24) return `${diffHours} jam yang lalu`
    if (diffDays === 1) return 'Kemarin'
    if (diffDays < 30) return `${diffDays} hari yang lalu`
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' })
  } catch {
    return dateStr
  }
}

export function useNotifications() {
  const notifications = ref<NotificationItem[]>([])
  const activeFilter = ref<'all' | 'unread' | 'starred'>('all')
  const isLoading = ref(false)

  const readSet = getStoredSet(STORAGE_READ_KEY)
  const starredSet = getStoredSet(STORAGE_STARRED_KEY)
  const dismissedSet = getStoredSet(STORAGE_DISMISSED_KEY)

  async function loadRealTamengNotifications() {
    isLoading.value = true
    try {
      const items: NotificationItem[] = []

      // 1. Fetch Real High & Critical Findings from TAMENG
      try {
        const findingsData = await apiFetch('/api/findings')
        const findingsList = findingsData?.findings || []
        // Filter high and critical findings, sorted by ID descending
        const criticalFindings = findingsList
          .filter((f: any) => ['critical', 'high'].includes(f.severity?.toLowerCase()))
          .slice(0, 10)

        criticalFindings.forEach((f: any) => {
          const id = `notif-fnd-${f.id}`
          if (dismissedSet.has(id)) return

          const isCrit = f.severity?.toLowerCase() === 'critical'
          const timestamp = f.created_at ? new Date(f.created_at).getTime() : Date.now()

          items.push({
            id,
            title: `[${f.severity?.toUpperCase()}] ${f.rule_id || 'Kerentanan Terdeteksi'}`,
            description: `${f.title} (${f.code} • ${f.project?.name || 'Aset'})`,
            time: formatRelativeTime(f.created_at),
            timestamp,
            category: 'Celah Keamanan',
            statusDot: isCrit ? 'status-dot-animated bg-red' : 'bg-orange',
            badge: f.severity?.toUpperCase() || 'HIGH',
            badgeClass: isCrit ? 'bg-red-lt text-red' : 'bg-orange-lt text-orange',
            isRead: readSet.has(id),
            isStarred: starredSet.has(id),
            url: '/findings'
          })
        })
      } catch (err) {
        console.warn('[Notifications] Findings fetch error:', err)
      }

      // 2. Fetch Real Scan Jobs from TAMENG
      try {
        const jobsData = await apiFetch('/api/scan-jobs')
        const jobsList = jobsData?.scan_jobs || []
        const recentJobs = jobsList.slice(0, 10)

        recentJobs.forEach((j: any) => {
          const id = `notif-job-${j.id}`
          if (dismissedSet.has(id)) return

          const status = (j.status || 'unknown').toLowerCase()
          const timestamp = j.created_at ? new Date(j.created_at).getTime() : Date.now()
          const targetDesc = j.repository?.name || j.target?.name || j.project?.name || 'Aset'

          let dotClass = 'bg-secondary'
          let badgeClass = 'bg-secondary-lt text-secondary'
          if (status === 'completed') {
            dotClass = 'bg-green'
            badgeClass = 'bg-green-lt text-green'
          } else if (status === 'running') {
            dotClass = 'status-dot-animated bg-blue'
            badgeClass = 'bg-blue-lt text-blue'
          } else if (['failed', 'denied'].includes(status)) {
            dotClass = 'bg-red'
            badgeClass = 'bg-red-lt text-red'
          }

          items.push({
            id,
            title: `Pekerjaan Scan: ${j.code}`,
            description: `Status: ${status.toUpperCase()} (${j.progress || 0}%) • Target: ${targetDesc}`,
            time: formatRelativeTime(j.created_at),
            timestamp,
            category: 'Pekerjaan Scan',
            statusDot: dotClass,
            badge: status.toUpperCase(),
            badgeClass,
            isRead: readSet.has(id),
            isStarred: starredSet.has(id),
            url: '/scan-jobs'
          })
        })
      } catch (err) {
        console.warn('[Notifications] Scan jobs fetch error:', err)
      }

      // 3. Fetch Real Audit Logs from TAMENG
      try {
        const auditData = await apiFetch('/api/audit-logs')
        const auditList = Array.isArray(auditData) ? auditData : auditData?.audit_logs || []
        const recentLogs = auditList.slice(0, 10)

        recentLogs.forEach((l: any) => {
          const id = `notif-audit-${l.id}`
          if (dismissedSet.has(id)) return

          const timestamp = l.created_at ? new Date(l.created_at).getTime() : Date.now()
          const isSuccess = (l.result || 'success').toLowerCase() === 'success'

          items.push({
            id,
            title: `Log Audit: ${l.action}`,
            description: `User: ${l.user?.name || 'Sistem'} • Hasil: ${(l.result || 'OK').toUpperCase()}`,
            time: formatRelativeTime(l.created_at),
            timestamp,
            category: 'Tata Kelola',
            statusDot: isSuccess ? 'bg-cyan' : 'bg-orange',
            badge: l.target_type ? l.target_type.toUpperCase() : 'AUDIT',
            badgeClass: 'bg-cyan-lt text-cyan',
            isRead: readSet.has(id),
            isStarred: starredSet.has(id),
            url: '/audit-logs'
          })
        })
      } catch (err) {
        console.warn('[Notifications] Audit logs fetch error:', err)
      }

      // Sort all notifications by timestamp descending (newest first)
      items.sort((a, b) => b.timestamp - a.timestamp)
      notifications.value = items
    } catch (err) {
      console.error('[Notifications] Global load error:', err)
    } finally {
      isLoading.value = false
    }
  }

  onMounted(() => {
    loadRealTamengNotifications()
  })

  const unreadCount = computed(() => {
    return notifications.value.filter(n => !n.isRead).length
  })

  const starredCount = computed(() => {
    return notifications.value.filter(n => n.isStarred).length
  })

  const filteredNotifications = computed(() => {
    if (activeFilter.value === 'unread') {
      return notifications.value.filter(n => !n.isRead)
    }
    if (activeFilter.value === 'starred') {
      return notifications.value.filter(n => n.isStarred)
    }
    return notifications.value
  })

  function markAsRead(id: string) {
    const item = notifications.value.find(n => n.id === id)
    if (item) {
      item.isRead = true
      readSet.add(id)
      saveStoredSet(STORAGE_READ_KEY, readSet)
    }
  }

  function markAllAsRead() {
    notifications.value.forEach(n => {
      n.isRead = true
      readSet.add(n.id)
    })
    saveStoredSet(STORAGE_READ_KEY, readSet)
  }

  function toggleStar(id: string) {
    const item = notifications.value.find(n => n.id === id)
    if (item) {
      item.isStarred = !item.isStarred
      if (item.isStarred) {
        starredSet.add(id)
      } else {
        starredSet.delete(id)
      }
      saveStoredSet(STORAGE_STARRED_KEY, starredSet)
    }
  }

  function removeNotification(id: string) {
    const index = notifications.value.findIndex(n => n.id === id)
    if (index !== -1) {
      dismissedSet.add(id)
      saveStoredSet(STORAGE_DISMISSED_KEY, dismissedSet)
      notifications.value.splice(index, 1)
    }
  }

  function handleNotificationClick(notif: NotificationItem) {
    markAsRead(notif.id)
    if (notif.url) {
      window.location.href = notif.url
    }
  }

  return {
    notifications,
    activeFilter,
    unreadCount,
    starredCount,
    filteredNotifications,
    isLoading,
    refreshNotifications: loadRealTamengNotifications,
    markAsRead,
    markAllAsRead,
    toggleStar,
    removeNotification,
    handleNotificationClick
  }
}
