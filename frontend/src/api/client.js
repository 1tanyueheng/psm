import axios from 'axios'

/**
 * Single axios instance for the whole app.
 *
 * Two responsibilities beyond plain HTTP: attaching the bearer token, and
 * translating the API's error envelope into something a component can render.
 */

const TOKEN_KEY = 'psm.token'

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: { Accept: 'application/json' },
  timeout: 30000,
})

// --- Request -----------------------------------------------------------
api.interceptors.request.use((config) => {
  const token = tokenStore.get()

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  // Let the browser set the multipart boundary itself
  if (config.data instanceof FormData) {
    delete config.headers['Content-Type']
  }

  return config
})

// --- Response ----------------------------------------------------------
api.interceptors.response.use(
  (response) => response,

  (error) => {
    const status = error.response?.status
    const payload = error.response?.data

    // A 401 means the token is gone or has been revoked. Clearing it here
    // stops the app from retrying with a dead token on every navigation.
    if (status === 401) {
      tokenStore.clear()

      // Only redirect if we are not already heading to login, otherwise a
      // failed login attempt would bounce the user off the page they are on.
      if (!window.location.pathname.startsWith('/login')) {
        const next = encodeURIComponent(window.location.pathname + window.location.search)
        window.location.assign(`/login?next=${next}`)
      }
    }

    return Promise.reject({
      status,
      message: payload?.message || error.message || 'Something went wrong.',
      // Validation errors arrive as { field: [messages] }
      errors: payload?.errors || null,
      isNetworkError: !error.response,
      isRateLimited: status === 429,
    })
  },
)

export default api

/**
 * Is this a raw axios response, or a payload the API layer already unwrapped?
 *
 * Axios always attaches `config` and `headers` to a response. An unwrapped
 * payload from `endpoints.js` is a plain model object and has neither. This is
 * what makes the two helpers below safe to call twice.
 */
function isAxiosResponse(value) {
  return (
    value != null &&
    typeof value === 'object' &&
    'config' in value &&
    'headers' in value
  )
}

/**
 * Normalise a paginated response into { items, meta }.
 * The API puts `meta` at the top level, not inside `data`.
 *
 * Idempotent on purpose. `endpoints.js` applies this internally in most
 * methods, but pages also call it defensively — and because the two disagreed,
 * the second call used to read `.data.data` off an already-normalised
 * `{items, meta}` object, produce `[]`, and silently render an empty list with
 * no error. That failure mode is invisible, which is worse than a crash.
 *
 * Calling it twice now returns the same result as calling it once, so neither
 * layer has to know what the other did.
 */
export function unwrapPaged(response) {
  if (response != null && Array.isArray(response.items)) {
    return response
  }

  return {
    items: response?.data?.data ?? [],
    meta: response?.data?.meta ?? null,
  }
}

/**
 * Normalise a single-resource response into just the payload.
 *
 * Idempotent for the same reason as `unwrapPaged`: the API layer usually
 * unwraps first, so a second call must not turn a valid model into `null`.
 */
export function unwrap(response) {
  if (!isAxiosResponse(response)) {
    return response ?? null
  }

  return response.data?.data ?? null
}
