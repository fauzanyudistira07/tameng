// TAMENG API Client Service with Automatic Session & CSRF Authentication
function getCookie(name: string): string | null {
  if (typeof document === 'undefined') return null
  const match = document.cookie.match(new RegExp('(^|;\\s*)(' + name + ')=([^;]*)'))
  return match ? decodeURIComponent(match[3]) : null
}

let authPromise: Promise<boolean> | null = null
let isAuthenticated = false

export function getIsAuthenticated(): boolean {
  return isAuthenticated
}

export async function checkAuth(): Promise<boolean> {
  if (authPromise) return authPromise

  authPromise = (async () => {
    try {
      const res = await fetch('/api/user', {
        headers: { Accept: 'application/json' },
        credentials: 'include'
      })
      isAuthenticated = res.ok
      return res.ok
    } catch {
      isAuthenticated = false
      return false
    } finally {
      authPromise = null
    }
  })()

  return authPromise
}

export async function loginUser(email: string, password: string, remember: boolean = false): Promise<boolean> {
  // 1. Fetch CSRF cookie
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
  const xsrf = getCookie('XSRF-TOKEN')

  // 2. Authenticate credentials
  const res = await fetch('/api/login', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {})
    },
    credentials: 'include',
    body: JSON.stringify({ email, password, remember })
  })

  if (!res.ok) {
    let errData: any = null
    try {
      errData = await res.json()
    } catch {}
    throw new Error(errData?.message || 'Email atau kata sandi tidak valid.')
  }

  isAuthenticated = true
  if (remember) {
    localStorage.setItem('tameng_remember_email', email)
    localStorage.setItem('tameng_remember_me', 'true')
  } else {
    localStorage.removeItem('tameng_remember_email')
    localStorage.removeItem('tameng_remember_me')
  }
  return true
}

export async function requestPasswordReset(email: string): Promise<{ message: string; email: string; reset_code?: string }> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
  const xsrf = getCookie('XSRF-TOKEN')

  const res = await fetch('/api/forgot-password', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {})
    },
    credentials: 'include',
    body: JSON.stringify({ email })
  })

  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    throw new Error(data?.message || data?.errors?.email?.[0] || 'Gagal membuat permintaan reset password.')
  }
  return data
}

export async function resetPasswordWithToken(payload: { email: string; token: string; password: string; password_confirmation: string }): Promise<{ message: string }> {
  await fetch('/sanctum/csrf-cookie', { credentials: 'include' })
  const xsrf = getCookie('XSRF-TOKEN')

  const res = await fetch('/api/reset-password', {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {})
    },
    credentials: 'include',
    body: JSON.stringify(payload)
  })

  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const errorMsg = data?.message || data?.errors?.token?.[0] || data?.errors?.password?.[0] || data?.errors?.email?.[0] || 'Gagal memperbarui password.'
    throw new Error(errorMsg)
  }
  return data
}

export async function logoutUser(): Promise<void> {
  const xsrf = getCookie('XSRF-TOKEN')
  try {
    await fetch('/api/logout', {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        ...(xsrf ? { 'X-XSRF-TOKEN': xsrf } : {})
      },
      credentials: 'include'
    })
  } catch (err) {
    console.warn('[TAMENG API] Logout error:', err)
  } finally {
    isAuthenticated = false
    authPromise = null
  }
}

export async function ensureAuthenticated(): Promise<boolean> {
  if (isAuthenticated) return true
  return checkAuth()
}

export interface TamengRequestInit extends RequestInit {
  _isRetry?: boolean
}

export async function apiFetch<T = any>(endpoint: string, options: TamengRequestInit = {}): Promise<T> {
  const { _isRetry, ...requestOptions } = options
  await ensureAuthenticated()

  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...(requestOptions.headers as Record<string, string> || {})
  }

  const xsrf = getCookie('XSRF-TOKEN')
  if (xsrf && ['POST', 'PUT', 'DELETE', 'PATCH'].includes((requestOptions.method || 'GET').toUpperCase())) {
    headers['X-XSRF-TOKEN'] = xsrf
    if (typeof FormData === 'undefined' || !(requestOptions.body instanceof FormData)) {
      headers['Content-Type'] = headers['Content-Type'] || 'application/json'
    }
  }

  let res = await fetch(endpoint, {
    ...requestOptions,
    headers,
    credentials: 'include'
  })

  // If 401, session has expired or user is unauthenticated
  if (res.status === 401) {
    isAuthenticated = false
    if (typeof window !== 'undefined' && window.location.pathname !== '/login') {
      window.location.href = '/login'
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
