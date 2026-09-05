import axios from 'axios'

const cache = new Map()
const CACHE_TTL_MS = 60 * 1000 // 60 seconds default cache TTL

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

function getCacheKey(config) {
  const url = config.url || ''
  const params = config.params ? JSON.stringify(config.params) : ''
  return `${url}?${params}`
}

// Request interceptor: return cached response if valid & not skipped
api.interceptors.request.use((config) => {
  const method = (config.method || 'get').toLowerCase()

  // Invalidate cache on mutations (POST, PUT, DELETE, PATCH)
  if (['post', 'put', 'delete', 'patch'].includes(method)) {
    if (!config.skipCacheInvalidate) {
      cache.clear()
    }
    return config
  }

  if (method === 'get' && !config.skipCache) {
    const key = getCacheKey(config)
    const cached = cache.get(key)
    const ttl = config.cacheTTL || CACHE_TTL_MS
    if (cached && Date.now() - cached.timestamp < ttl) {
      // Use adapter to return cached data immediately with 0ms network latency
      config.adapter = () => {
        return Promise.resolve({
          data: JSON.parse(JSON.stringify(cached.data)),
          status: 200,
          statusText: 'OK (cached)',
          headers: { ...cached.headers },
          config,
          request: {},
        })
      }
    }
  }

  return config
})

// Response interceptor: save successful GET response to cache
api.interceptors.response.use(
  (response) => {
    const method = (response.config.method || 'get').toLowerCase()
    if (method === 'get' && !response.config.skipCache && response.status >= 200 && response.status < 300) {
      const key = getCacheKey(response.config)
      cache.set(key, {
        data: response.data,
        headers: response.headers,
        timestamp: Date.now(),
      })
    }
    return response
  },
  (error) => {
    return Promise.reject(error)
  }
)

api.clearCache = () => {
  cache.clear()
}

api.invalidate = (urlPattern) => {
  if (!urlPattern) {
    cache.clear()
    return
  }
  for (const key of cache.keys()) {
    if (key.includes(urlPattern)) {
      cache.delete(key)
    }
  }
}

api.prefetch = (url, config = {}) => {
  return api.get(url, { ...config }).catch(() => {})
}

export { api }
export default api
