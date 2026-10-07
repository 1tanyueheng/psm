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

/**
 * How long to wait for a reply, in milliseconds.
 *
 * 30s is right for an ordinary API call — a login or a list that has not
 * answered in half a minute is not going to. It is badly wrong for an upload:
 * a multi-file submission of a few MB over a slow link legitimately takes
 * longer, and axios aborting at 30s produced a **"Network Error"** while the
 * server carried on and finished the upload successfully. The student then saw
 * an error, retried, and found the file already there — or worse, submitted it
 * twice.
 *
 * Uploads therefore get their own, much longer budget. It is deliberately
 * generous: the cost of waiting too long is a slow screen, while the cost of
 * giving up too early is a submission the student believes failed but did not.
 */
const REQUEST_TIMEOUT_MS = 30_000
export const UPLOAD_TIMEOUT_MS = 10 * 60_000

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  headers: { Accept: 'application/json' },
  timeout: REQUEST_TIMEOUT_MS,
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
      // axios reports a timeout as ECONNABORTED with no response. It is worth
      // naming separately: "the server took too long" and "the network is
      // down" call for different advice, and a timeout on an upload may well
      // mean the work *did* complete on the server.
      isTimeout: error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT',
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

/**
 * Saves a binary response as a file download.
 *
 * Exports are fetched through the axios instance rather than opened with
 * `window.open`. The export routes sit behind Sanctum, and a plain navigation
 * carries no Authorization header, so the browser would be handed a 401 JSON
 * envelope instead of the CSV.
 */
export function saveDownload(response, fallbackName) {
  const contentType = response.headers?.['content-type'] ?? ''

  // With responseType: 'blob' an error envelope also arrives as a Blob, so a
  // failed export would otherwise be saved as a file full of JSON.
  if (contentType.includes('application/json')) {
    return response.data.text().then((text) => {
      let message = 'The export could not be generated.'
      try {
        message = JSON.parse(text).message ?? message
      } catch {
        // Not JSON after all; the fallback message stands.
      }
      throw { message }
    })
  }

  // Content-Disposition is CORS-exposed, so the server's filename survives.
  const disposition = response.headers?.['content-disposition'] ?? ''
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)
  const filename = match ? decodeURIComponent(match[1]) : fallbackName

  const url = URL.createObjectURL(response.data)
  const link = document.createElement('a')

  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
