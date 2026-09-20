import api from './client'

/**
 * Auth endpoints (Module 1).
 *
 * Login responses carry the role and a suggested landing route, so the SPA
 * does not need a hardcoded role → route table of its own.
 */
export const authApi = {
  login: (email, password) =>
    api.post('/auth/login', { email, password }).then((r) => r.data),

  logout: () => api.post('/auth/logout'),

  me: () => api.get('/me').then((r) => r.data),

  changePassword: (payload) =>
    api.post('/auth/change-password', payload).then((r) => r.data),

  forgotPassword: (email) =>
    api.post('/auth/forgot-password', { email }).then((r) => r.data),

  resetPassword: (payload) =>
    api.post('/auth/reset-password', payload).then((r) => r.data),
}
