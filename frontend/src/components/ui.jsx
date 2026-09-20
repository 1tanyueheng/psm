/**
 * Presentational primitives shared across every screen.
 *
 * Kept in one file because they are all small, and because having them
 * together is what makes visual consistency cheap to maintain — a change to
 * the card radius or badge padding lands everywhere at once.
 */

import { initials } from '../lib/format'

// ---------------------------------------------------------------------
// Surfaces
// ---------------------------------------------------------------------

export function Card({ children, className = '', padded = true }) {
  return (
    <div
      className={`bg-white border border-slate-200 rounded-xl ${
        padded ? 'p-5' : ''
      } ${className}`}
    >
      {children}
    </div>
  )
}

export function CardHeader({ title, subtitle, action }) {
  return (
    <div className="flex items-start justify-between gap-4 mb-4">
      <div className="min-w-0">
        <h2 className="text-sm font-medium text-slate-900">{title}</h2>
        {subtitle && <p className="text-xs text-slate-500 mt-0.5">{subtitle}</p>}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  )
}

/** Section heading for pages that are not wrapped in a card. */
export function PageHeader({ title, subtitle, action, children }) {
  return (
    <header className="mb-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0">
          <h1 className="text-lg font-medium text-slate-900">{title}</h1>
          {subtitle && <p className="text-sm text-slate-500 mt-1">{subtitle}</p>}
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </div>
      {children && <div className="mt-4">{children}</div>}
    </header>
  )
}

// ---------------------------------------------------------------------
// Data display
// ---------------------------------------------------------------------

export function StatCard({ label, value, hint, tone = 'default' }) {
  // Legacy names (good/warn/bad) are kept alongside the Badge vocabulary so
  // existing callers keep working; new code should use success/warning/danger.
  const tones = {
    default: 'text-slate-900',
    neutral: 'text-slate-900',
    good: 'text-emerald-600',
    success: 'text-emerald-600',
    warn: 'text-amber-600',
    warning: 'text-amber-600',
    bad: 'text-rose-600',
    danger: 'text-rose-600',
    info: 'text-brand-600',
    brand: 'text-brand-600',
  }

  return (
    <div className="bg-slate-50 rounded-lg p-4">
      <p className="text-xs text-slate-500 mb-1">{label}</p>
      <p className={`text-2xl font-medium ${tones[tone] ?? tones.default}`}>{value}</p>
      {hint && <p className="text-xs text-slate-500 mt-1">{hint}</p>}
    </div>
  )
}

/**
 * Semantic tone names, mapped to Tailwind classes.
 *
 * `tone` props accept either a name from this table or a raw class string.
 * Both are supported because a caller sometimes needs a one-off colour (a role
 * badge, say) that would be noise in a shared table — but the named form is
 * what screens should reach for, since it keeps a "danger" badge looking the
 * same in every corner of the app.
 */
const BADGE_TONES = {
  neutral: 'bg-slate-100 text-slate-700 border-slate-200',
  success: 'bg-emerald-50 text-emerald-700 border-emerald-200',
  warning: 'bg-amber-50 text-amber-700 border-amber-200',
  danger: 'bg-rose-50 text-rose-700 border-rose-200',
  info: 'bg-sky-50 text-sky-700 border-sky-200',
  brand: 'bg-brand-50 text-brand-700 border-brand-200',
}

export function Badge({ children, tone = 'neutral' }) {
  const classes = BADGE_TONES[tone] ?? tone

  return (
    <span
      className={`inline-flex items-center px-2 py-0.5 rounded-md border text-xs font-medium whitespace-nowrap ${classes}`}
    >
      {children}
    </span>
  )
}

/** Bar fill colours, named to match BADGE_TONES so `tone` reads the same. */
const BAR_TONES = {
  brand: 'bg-brand-500',
  success: 'bg-emerald-500',
  warning: 'bg-amber-500',
  danger: 'bg-rose-500',
  info: 'bg-sky-500',
  neutral: 'bg-slate-400',
}

/** A weighted milestone/progress bar. `value` is 0–100. */
export function ProgressBar({ value = 0, tone = 'brand', showLabel = false }) {
  const clamped = Math.max(0, Math.min(100, Number(value) || 0))
  const fill = BAR_TONES[tone] ?? tone

  return (
    <div className="flex items-center gap-2">
      <div className="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
        <div
          className={`h-full rounded-full transition-all ${fill}`}
          style={{ width: `${clamped}%` }}
        />
      </div>
      {showLabel && (
        <span className="text-xs text-slate-500 tabular-nums w-10 text-right">
          {Math.round(clamped)}%
        </span>
      )}
    </div>
  )
}

export function Avatar({ name, size = 'md' }) {
  const sizes = {
    sm: 'w-7 h-7 text-xs',
    md: 'w-9 h-9 text-sm',
    lg: 'w-11 h-11 text-base',
  }

  return (
    <div
      className={`${sizes[size]} rounded-full bg-brand-50 text-brand-700 flex items-center justify-center font-medium shrink-0`}
      aria-hidden="true"
    >
      {initials(name)}
    </div>
  )
}

// ---------------------------------------------------------------------
// States
// ---------------------------------------------------------------------

export function EmptyState({ title, description, action }) {
  return (
    <div className="text-center py-12 px-6">
      <p className="text-sm font-medium text-slate-700">{title}</p>
      {description && (
        <p className="text-sm text-slate-500 mt-1 max-w-md mx-auto">{description}</p>
      )}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}

export function Spinner({ label = 'Loading…' }) {
  return (
    <div className="flex items-center justify-center gap-3 py-12" role="status">
      <svg className="w-4 h-4 animate-spin text-brand-500" viewBox="0 0 24 24" fill="none">
        <circle
          className="opacity-25"
          cx="12"
          cy="12"
          r="10"
          stroke="currentColor"
          strokeWidth="4"
        />
        <path
          className="opacity-75"
          fill="currentColor"
          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
        />
      </svg>
      <span className="text-sm text-slate-500">{label}</span>
    </div>
  )
}

export function ErrorState({ message, onRetry }) {
  return (
    <div className="rounded-lg border border-rose-200 bg-rose-50 p-4">
      <p className="text-sm text-rose-800">{message || 'Something went wrong.'}</p>
      {onRetry && (
        <button
          type="button"
          onClick={onRetry}
          className="mt-3 text-xs font-medium text-rose-700 underline hover:no-underline"
        >
          Try again
        </button>
      )}
    </div>
  )
}

/** Inline field-level validation errors, from the API's 422 `errors`. */
export function FieldErrors({ errors, field }) {
  const messages = errors?.[field]

  if (!messages) return null

  return (
    <ul className="mt-1 space-y-0.5">
      {(Array.isArray(messages) ? messages : [messages]).map((message) => (
        <li key={message} className="text-xs text-rose-600">
          {message}
        </li>
      ))}
    </ul>
  )
}

// ---------------------------------------------------------------------
// Forms
// ---------------------------------------------------------------------

export function Field({ label, hint, required, errors, field, children }) {
  return (
    <div>
      <label className="block text-xs font-medium text-slate-700 mb-1">
        {label}
        {required && <span className="text-rose-500 ml-0.5">*</span>}
      </label>
      {children}
      {hint && !errors?.[field] && <p className="text-xs text-slate-400 mt-1">{hint}</p>}
      <FieldErrors errors={errors} field={field} />
    </div>
  )
}

const INPUT_CLASS =
  'w-full rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-900 ' +
  'placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-brand-500/20 ' +
  'focus:border-brand-400 disabled:bg-slate-50 disabled:text-slate-500'

export function Input({ className = '', ...props }) {
  return <input className={`${INPUT_CLASS} ${className}`} {...props} />
}

export function Textarea({ className = '', rows = 4, ...props }) {
  return <textarea rows={rows} className={`${INPUT_CLASS} ${className}`} {...props} />
}

export function Select({ children, className = '', ...props }) {
  return (
    <select className={`${INPUT_CLASS} ${className}`} {...props}>
      {children}
    </select>
  )
}

export function Button({
  children,
  variant = 'primary',
  size = 'md',
  loading = false,
  className = '',
  ...props
}) {
  const variants = {
    primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-300',
    secondary:
      'bg-white text-slate-700 border border-slate-200 hover:bg-slate-50 disabled:text-slate-400',
    danger: 'bg-rose-600 text-white hover:bg-rose-700 disabled:bg-rose-300',
    success: 'bg-emerald-600 text-white hover:bg-emerald-700 disabled:bg-emerald-300',
    ghost: 'text-slate-600 hover:bg-slate-100 disabled:text-slate-400',
  }

  const sizes = {
    sm: 'px-2.5 py-1.5 text-xs',
    md: 'px-3.5 py-2 text-sm',
    lg: 'px-5 py-2.5 text-sm',
  }

  return (
    <button
      className={`inline-flex items-center justify-center gap-2 rounded-lg font-medium transition-colors
        focus:outline-none focus:ring-2 focus:ring-brand-500/30 disabled:cursor-not-allowed
        ${variants[variant]} ${sizes[size]} ${className}`}
      disabled={loading || props.disabled}
      {...props}
    >
      {loading && (
        <svg className="w-3.5 h-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle
            className="opacity-25"
            cx="12"
            cy="12"
            r="10"
            stroke="currentColor"
            strokeWidth="4"
          />
          <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
        </svg>
      )}
      {children}
    </button>
  )
}

// ---------------------------------------------------------------------
// Tables
// ---------------------------------------------------------------------

/**
 * Thin table wrapper. `columns` is [{ key, label, align?, width? }] and
 * `render` is (row) => cells, so screens keep control of cell content.
 */
export function DataTable({ columns, rows, render, empty, keyField = 'id' }) {
  if (!rows?.length) {
    return empty ?? <EmptyState title="Nothing to show yet" />
  }

  return (
    <div className="overflow-x-auto -mx-5">
      <table className="w-full text-sm min-w-[640px]">
        <thead>
          <tr className="border-b border-slate-200">
            {columns.map((column) => (
              <th
                key={column.key}
                className={`px-5 py-2.5 text-xs font-medium text-slate-500 ${
                  column.align === 'right' ? 'text-right' : 'text-left'
                }`}
                style={column.width ? { width: column.width } : undefined}
              >
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={row[keyField]}
              className="border-b border-slate-100 last:border-0 hover:bg-slate-50/70"
            >
              {render(row)}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

export function Td({ children, align = 'left', className = '' }) {
  return (
    <td
      className={`px-5 py-3 align-middle ${
        align === 'right' ? 'text-right' : 'text-left'
      } ${className}`}
    >
      {children}
    </td>
  )
}
