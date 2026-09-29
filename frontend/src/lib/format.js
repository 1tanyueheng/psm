/**
 * Presentation helpers shared across screens.
 *
 * Small, dependency-free, and deliberately boring — these run on nearly every
 * render, so they stay cheap and predictable.
 */

// ---------------------------------------------------------------------
// Dates
// ---------------------------------------------------------------------

/** '12 Mar 2026'. Returns '—' for null rather than 'Invalid Date'. */
export function formatDate(value) {
  if (!value) return '—'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return '—'

  return date.toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

/** '12 Mar 2026, 14:30' — for timestamps where the time matters. */
export function formatDateTime(value) {
  if (!value) return '—'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return '—'

  return date.toLocaleString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

/**
 * Human relative time: 'in 3 days', '2 days ago', 'today'.
 * Used for deadlines, where a relative phrase is more useful than a date.
 */
export function relativeDays(value) {
  if (!value) return '—'

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) return '—'

  const startOfToday = new Date()
  startOfToday.setHours(0, 0, 0, 0)

  const target = new Date(date)
  target.setHours(0, 0, 0, 0)

  const days = Math.round((target - startOfToday) / 86_400_000)

  if (days === 0) return 'today'
  if (days === 1) return 'tomorrow'
  if (days === -1) return 'yesterday'
  if (days > 0) return `in ${days} days`
  return `${Math.abs(days)} days ago`
}

/** True when a deadline has passed and work is still outstanding. */
export function isOverdue(dueAt, status) {
  if (!dueAt) return false
  if (['approved', 'submitted', 'reviewed'].includes(status)) return false

  return new Date(dueAt).getTime() < Date.now()
}

// ---------------------------------------------------------------------
// Numbers
// ---------------------------------------------------------------------

/** '86.40' — marks are always two decimal places. */
export function formatMark(value) {
  if (value === null || value === undefined || value === '') return '—'

  const number = Number(value)

  return Number.isNaN(number) ? '—' : number.toFixed(2)
}

/** '86.4%' for percentages shown in progress bars and summaries. */
export function formatPercent(value, digits = 1) {
  if (value === null || value === undefined || value === '') return '—'

  const number = Number(value)

  return Number.isNaN(number) ? '—' : `${number.toFixed(digits)}%`
}

/** '1.2 MB' — used in the submission file list. */
export function formatBytes(bytes) {
  if (!bytes && bytes !== 0) return '—'

  const units = ['B', 'KB', 'MB', 'GB']
  let size = Number(bytes)
  let unit = 0

  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024
    unit += 1
  }

  return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
}

/** 'Aisyah binti Rahman' → 'AR'. Handles single names and extra spaces. */
export function initials(name) {
  if (!name) return '?'

  const parts = String(name).trim().split(/\s+/)

  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()

  // Skip 'binti' / 'bin' — they are connectors, not initials
  const meaningful = parts.filter(
    (p) => !['binti', 'bin', 'a/l', 'a/p'].includes(p.toLowerCase()),
  )

  if (meaningful.length >= 2) {
    return (meaningful[0][0] + meaningful[meaningful.length - 1][0]).toUpperCase()
  }

  return parts[0].slice(0, 2).toUpperCase()
}

// ---------------------------------------------------------------------
// Domain vocabulary (mirrors the backend enums)
// ---------------------------------------------------------------------

export const MILESTONE_STATUS = {
  pending: { label: 'Not started', tone: 'bg-slate-100 text-slate-700 border-slate-200' },
  open: { label: 'Open', tone: 'bg-blue-50 text-blue-700 border-blue-200' },
  submitted: { label: 'Submitted', tone: 'bg-amber-50 text-amber-700 border-amber-200' },
  reviewed: { label: 'Reviewed', tone: 'bg-violet-50 text-violet-700 border-violet-200' },
  approved: { label: 'Approved', tone: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  rejected: { label: 'Revision required', tone: 'bg-rose-50 text-rose-700 border-rose-200' },
  overdue: { label: 'Overdue', tone: 'bg-red-50 text-red-700 border-red-200' },
}

export const PROJECT_STATUS = {
  draft: { label: 'Draft', tone: 'bg-slate-100 text-slate-700 border-slate-200' },
  submitted: { label: 'Pending approval', tone: 'bg-amber-50 text-amber-700 border-amber-200' },
  approved: { label: 'Approved', tone: 'bg-blue-50 text-blue-700 border-blue-200' },
  rejected: { label: 'Rejected', tone: 'bg-rose-50 text-rose-700 border-rose-200' },
  in_progress: { label: 'In progress', tone: 'bg-sky-50 text-sky-700 border-sky-200' },
  completed: { label: 'Completed', tone: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  archived: { label: 'Archived', tone: 'bg-slate-100 text-slate-600 border-slate-200' },
}

export const EVALUATION_STATUS = {
  draft: { label: 'Draft', tone: 'bg-slate-100 text-slate-700 border-slate-200' },
  submitted: { label: 'Submitted', tone: 'bg-amber-50 text-amber-700 border-amber-200' },
  moderated: { label: 'Moderated', tone: 'bg-violet-50 text-violet-700 border-violet-200' },
  released: { label: 'Released', tone: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  recused: { label: 'Recused', tone: 'bg-slate-100 text-slate-600 border-slate-200' },
}

export const CATEGORY_LABELS = {
  system: 'System Development',
  research: 'Research',
}

/** Grade band → tone, matching psm.grade_bands on the server. */
export function gradeTone(letter) {
  if (!letter) return 'bg-slate-100 text-slate-700 border-slate-200'
  if (letter.startsWith('A')) return 'bg-emerald-50 text-emerald-700 border-emerald-200'
  if (letter.startsWith('B')) return 'bg-sky-50 text-sky-700 border-sky-200'
  if (letter.startsWith('C')) return 'bg-amber-50 text-amber-700 border-amber-200'

  return 'bg-rose-50 text-rose-700 border-rose-200'
}

export const EXAMINER_PANEL_ROLES = {
  chair: 'Panel Chair',
  member: 'Panel Member',
  reserve: 'Reserve',
}

/** 'gold' | 'silver' | 'bronze' | null → medal display for the podium. */
export function medalFor(rank) {
  return { 1: 'gold', 2: 'silver', 3: 'bronze' }[rank] ?? null
}

export function statusMeta(map, key) {
  return map[key] ?? { label: key ?? '—', tone: 'bg-slate-100 text-slate-700 border-slate-200' }
}
