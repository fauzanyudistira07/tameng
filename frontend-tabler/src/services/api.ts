// TAMENG API Client Service with Automatic Session & CSRF Authentication
function getCookie(name: string): string | null {
  if (typeof document === 'undefined') return null
  const match = document.cookie.match(new RegExp('(^|;\\s*)(' + name + ')=([^;]*)'))
  return match ? decodeURIComponent(match[3]) : null
}

let authPromise: Promise<boolean> | null = null

export async function ensureAuthenticated(force = false): Promise<boolean> {
  if (force) {
    authPromise = null
  }
  if (authPromise) return authPromise

  authPromise = (async () => {
    try {
      // 1. If not forcing, check if already authenticated with active session
      if (!force) {
        const userRes = await fetch('/api/user', {
          headers: { Accept: 'application/json' },
          credentials: 'include'
        })
        if (userRes.ok) return true
      }

      // 2. Fetch CSRF cookie
      await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
      const xsrf = getCookie('XSRF-TOKEN')

      // 3. Login with official TAMENG local credentials
      const loginRes = await fetch('/api/login', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {})
        },
        credentials: 'include',
        body: JSON.stringify({
          email: 'admin@secsys.local',
          password: 'password'
        })
      })

      return loginRes.ok
    } catch (err) {
      console.warn('[TAMENG API] Authentication check error:', err)
      return false
    } finally {
      authPromise = null
    }
  })()

  return authPromise
}

export async function apiFetch<T = any>(endpoint: string, options: RequestInit = {}): Promise<T> {
  await ensureAuthenticated()

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(options.headers as Record<string, string> || {})
  }

  const xsrf = getCookie('XSRF-TOKEN')
  if (xsrf && ['POST', 'PUT', 'DELETE', 'PATCH'].includes((options.method || 'GET').toUpperCase())) {
    headers['X-XSRF-TOKEN'] = xsrf
    headers['Content-Type'] = headers['Content-Type'] || 'application/json'
  }

  let res = await fetch(endpoint, {
    ...options,
    headers,
    credentials: 'include'
  })

  // If 401, re-authenticate with force=true and retry once
  if (res.status === 401) {
    const ok = await ensureAuthenticated(true)
    if (ok) {
      const newXsrf = getCookie('XSRF-TOKEN')
      if (newXsrf && headers['X-XSRF-TOKEN']) {
        headers['X-XSRF-TOKEN'] = newXsrf
      }
      res = await fetch(endpoint, {
        ...options,
        headers,
        credentials: 'include'
      })
    }
  }

  if (!res.ok) {
    let errorData: any = null
    try {
      errorData = await res.json()
    } catch {}
    const err: any = new Error(errorData?.message || `[TAMENG API] HTTP ${res.status}: ${res.statusText}`)
    err.status = res.status
    err.data = errorData
    throw err
  }

  return res.json()
}
