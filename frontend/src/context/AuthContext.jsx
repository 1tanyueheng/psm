import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { authApi } from '../api/auth'
import { tokenStore } from '../api/client'
import { can, homeRouteFor, isAtLeast } from '../lib/permissions'

const AuthContext = createContext(null)

/**
 * Authentication and authorisation state for the whole SPA (Module 1).
 *
 * The token lives in localStorage; the user object is fetched from /me on
 * boot so a role change or a deactivation takes effect on the next load
 * rather than lingering in a stale client-side copy.
 */
export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(Boolean(tokenStore.get()))
  const [error, setError] = useState(null)

  // --- Restore the session on boot -------------------------------------
  useEffect(() => {
    const token = tokenStore.get()

    if (!token) {
      setLoading(false)
      return
    }

    let cancelled = false

    authApi
      .me()
      .then((payload) => {
        if (!cancelled) setUser(payload?.data ?? payload?.user ?? payload)
      })
      .catch(() => {
        // The token is stale or revoked; the interceptor already cleared it
        if (!cancelled) {
          tokenStore.clear()
          setUser(null)
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [])

  const login = useCallback(async (email, password) => {
    setError(null)

    try {
      const payload = await authApi.login(email, password)
      const token = payload?.token ?? payload?.data?.token
      const nextUser = payload?.user ?? payload?.data?.user

      if (token) tokenStore.set(token)
      if (nextUser) setUser(nextUser)

      return { ok: true, user: nextUser, homeRoute: payload?.home_route }
    } catch (err) {
      setError(err.message)
      return { ok: false, message: err.message }
    }
  }, [])

  const logout = useCallback(async () => {
    try {
      await authApi.logout()
    } catch {
      // A failed logout must still clear local state, or the user is stuck
      // in a half-authenticated shell with a dead token.
    } finally {
      tokenStore.clear()
      setUser(null)
      window.location.assign('/login')
    }
  }, [])

  /** Re-fetch /me — used after a profile edit so the header updates. */
  const refresh = useCallback(async () => {
    try {
      const payload = await authApi.me()
      setUser(payload?.data ?? payload?.user ?? payload)
    } catch {
      // Silent: a failed refresh should not clear a working session
    }
  }, [])

  const value = useMemo(
    () => ({
      user,
      loading,
      error,
      login,
      logout,
      refresh,
      isAuthenticated: Boolean(user),

      role: user?.role ?? null,
      /** Route this user should land on after login. */
      homeRoute: homeRouteFor(user?.role),

      /** Does the current user hold one of these roles? */
      hasRole: (...roles) => (user ? roles.flat().includes(user.role) : false),
      /** Seniority check — e.g. isAtLeast('coordinator'). */
      isAtLeast: (role) => isAtLeast(user?.role, role),
      /** Capability check, e.g. can('assess'). */
      can: (capability) => can(user?.role, capability),
    }),
    [user, loading, error, login, logout, refresh],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)

  if (context === null) {
    throw new Error('useAuth must be used inside an <AuthProvider>')
  }

  return context
}
