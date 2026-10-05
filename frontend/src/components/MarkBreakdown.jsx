import { Badge, ProgressBar } from './ui'
import { formatMark } from '../lib/format'

/**
 * A student's mark, broken down by Lampiran and then by component.
 *
 * Shared by the student dashboard and the project page so the two can never
 * disagree about what a mark is.
 *
 * Every total here is the Lampiran's own mark — never rescaled onto a 0-100
 * scale. The system scores only part of the assessment (PSM 1: E 35 + I 30;
 * PSM 2: G 50 + H 5 + J 40), so a percentage of its own share would misstate
 * the student's result.
 *
 * A Lampiran with no returned form still appears, with its headings and an
 * "awaiting assessment" badge, so the student can see what is still to come.
 */
export default function MarkBreakdown({ breakdown, emptyMessage }) {
  const forms = breakdown?.forms ?? []

  if (forms.length === 0) {
    return <p className="text-sm text-slate-500">{emptyMessage}</p>
  }

  return (
    <div className="space-y-5">
      {forms.map((form) => (
        <div key={form.form_code}>
          <div className="flex items-baseline justify-between gap-3">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-sm font-medium text-slate-700">Lampiran {form.form_code}</p>
              {form.status === 'pending' && <Badge tone="warning">awaiting assessment</Badge>}
            </div>
            <p className="shrink-0 text-sm tabular-nums text-slate-600">
              {form.marks != null ? formatMark(form.marks) : '—'}
              <span className="text-xs text-slate-400"> / {formatMark(form.max)}</span>
            </p>
          </div>

          <ul className="mt-2 space-y-3 border-t border-slate-100 pt-3">
            {form.components.map((component) => (
              <li key={component.code}>
                <div className="flex items-baseline justify-between gap-3">
                  <span className="min-w-0 truncate text-sm text-slate-600">
                    {component.title}
                  </span>
                  <span className="shrink-0 text-sm tabular-nums text-slate-800">
                    {component.marks != null ? formatMark(component.marks) : '—'}
                    <span className="text-xs text-slate-400">
                      {' '}/ {formatMark(component.max)}
                    </span>
                  </span>
                </div>
                <div className="mt-1">
                  <ProgressBar
                    value={
                      component.marks != null && component.max > 0
                        ? (component.marks / component.max) * 100
                        : 0
                    }
                    tone="brand"
                  />
                </div>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  )
}

/** The combined mark across every returned Lampiran, in the Lampiran's units. */
export function MarkTotal({ breakdown }) {
  if (breakdown?.total_marks == null) return null

  return (
    <span className="text-xl font-semibold tabular-nums text-slate-900">
      {formatMark(breakdown.total_marks)}
      <span className="text-sm font-normal text-slate-400">
        {' '}/ {formatMark(breakdown.total_max)}
      </span>
    </span>
  )
}
