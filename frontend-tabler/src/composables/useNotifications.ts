import { ref, computed } from 'vue'
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

// Global state across components
const notifications = ref<NotificationItem[]>([])
const activeFilter = ref<'all' | 'unread' | 'starred'>('all')
const isLoading = ref(false)
const backendUnreadCount = ref(0)
const backendStarredCount = ref(0)
let lastFetchTime = 0
const CACHE_TTL_MS = 15000 // 15 seconds

export function useNotifications() {
  async function loadRealTamengNotifications(force = false) {
    if (!force && notifications.value.length > 0 && Date.now() - lastFetchTime < CACHE_TTL_MS) {
      return
    }
    if (isLoading.value) return
    isLoading.value = true

    try {
      const data = await apiFetch('/api/notifications')
      if (data?.notifications && Array.isArray(data.notifications)) {
        notifications.value = data.notifications
        backendUnreadCount.value = data.unread_count ?? 0
        backendStarredCount.value = data.starred_count ?? 0
        lastFetchTime = Date.now()
      }
    } catch (err) {
      console.warn('[Notifications] Failed loading from Go backend:', err)
    } finally {
      isLoading.value = false
    }
  }

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

  async function markAsRead(id: string) {
    const item = notifications.value.find(n => n.id === id)
    if (item && !item.isRead) {
      item.isRead = true
      try {
        await apiFetch(`/api/notifications/${id}/read`, { method: 'POST' })
      } catch (err) {
        console.warn('[Notifications] Failed to sync mark as read:', err)
      }
    }
  }

  async function markAllAsRead() {
    notifications.value.forEach(n => {
      n.isRead = true
    })
    try {
      await apiFetch('/api/notifications/read-all', { method: 'POST' })
    } catch (err) {
      console.warn('[Notifications] Failed to sync mark all read:', err)
    }
  }

  async function toggleStar(id: string) {
    const item = notifications.value.find(n => n.id === id)
    if (item) {
      item.isStarred = !item.isStarred
      try {
        await apiFetch(`/api/notifications/${id}/star`, { method: 'POST' })
      } catch (err) {
        console.warn('[Notifications] Failed to sync star:', err)
      }
    }
  }

  async function removeNotification(id: string) {
    const idx = notifications.value.findIndex(n => n.id === id)
    if (idx !== -1) {
      notifications.value.splice(idx, 1)
      try {
        await apiFetch(`/api/notifications/${id}`, { method: 'DELETE' })
      } catch (err) {
        console.warn('[Notifications] Failed to sync dismiss:', err)
      }
    }
  }

  function handleNotificationClick(item: NotificationItem) {
    markAsRead(item.id)
    if (item.url) {
      window.location.href = item.url
    }
  }

  function resetFilterOnOpen() {
    activeFilter.value = 'all'
    loadRealTamengNotifications(true)
  }

  return {
    notifications,
    activeFilter,
    isLoading,
    unreadCount,
    starredCount,
    filteredNotifications,
    loadRealTamengNotifications,
    markAsRead,
    markAllAsRead,
    toggleStar,
    removeNotification,
    handleNotificationClick,
    resetFilterOnOpen
  }
}
