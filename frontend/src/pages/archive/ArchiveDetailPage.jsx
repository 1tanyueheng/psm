import { useEffect, useState } from 'react'
import { useParams } from 'react-router-dom'
import { archiveApi } from '../../api/endpoints'
import {
  Card, CardHeader, PageHeader, Badge, Spinner, ErrorState,
  Button, ProgressBar,
} from '../../components/ui'
import { formatDate, formatMark, CATEGORY_LABELS } from '../../lib/format'

/**
 * Archived project detail — a frozen record of a completed PSM project.
 *
 * Every value on this page is a snapshot taken at archive time, never a live
 * join. That is what makes the archive trustworthy years later: the supervisor
 * list, the milestone summary and the grade breakdown all reflect the state of
 * the project when it was closed, not whatever the database says today.
 */
export default function ArchiveDetailPage() {
  const { id } = useParams()
  const [record, setRecord] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        // archiveApi.get() already unwraps; see ProfilePage for the same note.
        const data = await archiveApi.get(id)
        if (!cancelled) setRecord(data)
      } catch (err) {
        if (!cancelled) setError(err)
      } finally {
        if (!cancelled) setLoading(false)
      }
    }

    load()
    return () => {
      cancelled = true
    }
  }, [id])

  if (loading) return <Spinner label="Loading archived record" />
  if (error) return <ErrorState error={error} />
  if (!record) return <ErrorState error={{ message: 'Archived record not found.' }} />

  const summary = record.milestone_summary ?? {}
  const breakdown = record.grade_breakdown ?? {}

  return (
    <div className="space-y-6">
      <PageHeader
        title={record.title}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            {record.code && <span className="font-mono text-xs">{record.code}</span>}
            {record.psm_part && (
              <Badge tone={record.psm_part === 'PSM2' ? 'brand' : 'neutral'}>{record.psm_part}</Badge>
            )}
            {record.category && (
              <Badge tone="neutral">{CATEGORY_LABELS[record.category] ?? record.category}</Badge>
            )}
            {record.academic_session && <Badge tone="neutral">{record.academic_session}</Badge>}
            {record.is_public && <Badge tone="success">public record</Badge>}
          </span>
        }
        back={{ to: '/archive', label: 'Back to archive' }}
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader title="Abstract" subtitle="As submitted at the time of archiving" />
          {record.abstract ? (
            <p className="whitespace-pre-line text-sm leading-relaxed text-slate-700">
              {record.abstract}
            </p>
          ) : (
            <p className="text-sm text-slate-500">No abstract was recorded.</p>
          )}

          {record.keywords?.length > 0 && (
            <div className="mt-4 flex flex-wrap gap-1.5 border-t border-slate-100 pt-4">
              {record.keywords.map((kw) => (
                <span
                  key={kw}
                  className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600"
                >
                  {kw}
                </span>
              ))}
            </div>
          )}
        </Card>

        {/* The outcome block — why most visitors opened this page. */}
        <Card>
          <CardHeader title="Final outcome" />
          {record.final_mark != null ? (
            <div className="space-y-5">
              <div className="flex items-end gap-5">
                <div>
                  <p className="text-xs uppercase tracking-wide text-slate-400">Mark</p>
                  <p className="text-3xl font-semibold tabular-nums text-slate-900">
                    {formatMark(record.final_mark)}
                  </p>
                </div>
                <div>
                  <p className="text-xs uppercase tracking-wide text-slate-400">Grade</p>
                  <p className="text-3xl font-semibold text-slate-900">
                    {record.grade_letter ?? '—'}
                  </p>
                </div>
                {record.grade_point != null && (
                  <div>
                    <p className="text-xs uppercase tracking-wide text-slate-400">Point</p>
                    <p className="text-3xl font-semibold tabular-nums text-slate-900">
                      {record.grade_point.toFixed(2)}
                    </p>
                  </div>
                )}
              </div>

              {Object.keys(breakdown).length > 0 && (
                <div className="border-t border-slate-100 pt-4">
                  <p className="mb-3 text-sm font-medium text-slate-700">Assessment breakdown</p>
                  <div className="space-y-2.5">
                    {Object.entries(breakdown).map(([key, row]) => (
                      <div key={key}>
                        <div className="mb-1 flex items-center justify-between text-xs">
                          <span className="capitalize text-slate-600">
                            {key.replace(/_/g, ' ')}
                          </span>
                          <span className="tabular-nums text-slate-500">
                            {formatMark(row.mark ?? row.score)}
                            {row.weight != null && ` · ${row.weight}%`}
                          </span>
                        </div>
                        <ProgressBar value={row.mark ?? row.score ?? 0} max={100} tone="brand" />
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          ) : (
            <p className="text-sm text-slate-500">
              No final mark was recorded for this project.
            </p>
          )}
        </Card>
      </div>

      <div className="grid gap-6 lg:grid-cols-3">
        <Card>
          <CardHeader title="Students" />
          <ul className="space-y-3">
            {(record.students ?? []).length === 0 ? (
              <li className="text-sm text-slate-500">No student roster recorded.</li>
            ) : (
              record.students.map((student) => (
                <li key={student.student_id ?? student.name}>
                  <p className="text-sm font-medium text-slate-800">{student.name}</p>
                  <p className="text-xs text-slate-500">
                    {student.student_id}
                    {student.program && ` · ${student.program}`}
                    {student.batch && ` · batch ${student.batch}`}
                  </p>
                </li>
              ))
            )}
          </ul>
        </Card>

        <Card>
          <CardHeader title="Supervisors & examiners" />
          <div className="space-y-4">
            <div>
              <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                Supervisors
              </p>
              <ul className="space-y-1">
                {(record.supervisor_names ?? record.supervisors ?? []).length === 0 ? (
                  <li className="text-sm text-slate-500">None recorded</li>
                ) : (
                  (record.supervisor_names ?? record.supervisors ?? []).map((s) => {
                    const name = typeof s === 'string' ? s : s.name
                    return (
                      <li key={name} className="text-sm text-slate-700">
                        {name}
                        {typeof s !== 'string' && s.role && s.role !== 'primary' && (
                          <span className="ml-1.5 text-xs text-slate-400">{s.role}</span>
                        )}
                      </li>
                    )
                  })
                )}
              </ul>
            </div>
            <div className="border-t border-slate-100 pt-3">
              <p className="mb-2 text-xs font-medium uppercase tracking-wide text-slate-400">
                Examiners
              </p>
              <ul className="space-y-1">
                {(record.examiners ?? []).length === 0 ? (
                  <li className="text-sm text-slate-500">None recorded</li>
                ) : (
                  record.examiners.map((e) => {
                    const name = typeof e === 'string' ? e : e.name
                    return (
                      <li key={name} className="text-sm text-slate-700">
                        {name}
                        {typeof e !== 'string' && e.panel_role && (
                          <span className="ml-1.5 text-xs text-slate-400">{e.panel_role}</span>
                        )}
                      </li>
                    )
                  })
                )}
              </ul>
            </div>
          </div>
        </Card>

        <Card>
          <CardHeader title="Milestone record" />
          {Object.keys(summary).length === 0 ? (
            <p className="text-sm text-slate-500">No milestone summary recorded.</p>
          ) : (
            <div className="space-y-3">
              {summary.total != null && (
                <Row label="Total milestones" value={summary.total} />
              )}
              {summary.approved != null && (
                <Row label="Approved" value={summary.approved} tone="text-emerald-700" />
              )}
              {summary.revisions != null && (
                <Row label="Revisions requested" value={summary.revisions} />
              )}
              {summary.late != null && (
                <Row label="Submitted late" value={summary.late} tone="text-rose-600" />
              )}
              {summary.completion_percent != null && (
                <div className="border-t border-slate-100 pt-3">
                  <div className="mb-1 flex items-center justify-between text-xs text-slate-500">
                    <span>Completion</span>
                    <span className="tabular-nums">{summary.completion_percent}%</span>
                  </div>
                  <ProgressBar value={summary.completion_percent} tone="success" />
                </div>
              )}
            </div>
          )}

          <div className="mt-5 border-t border-slate-100 pt-4 text-xs text-slate-500">
            <p>Archived {formatDate(record.archived_at, { fallback: 'unknown date' })}</p>
            {record.archive_note && (
              <p className="mt-1 italic text-slate-600">{record.archive_note}</p>
            )}
          </div>
        </Card>
      </div>

      {(record.documents ?? []).length > 0 && (
        <Card>
          <CardHeader title="Documents" subtitle="Files preserved with this record" />
          <ul className="divide-y divide-slate-100">
            {record.documents.map((doc) => (
              <li key={doc.id ?? doc.name} className="flex items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm text-slate-700">{doc.name ?? doc.original_name}</p>
                  <p className="text-xs text-slate-400">
                    {doc.milestone ?? doc.milestone_code ?? 'general'}
                  </p>
                </div>
                <Button
                  size="sm"
                  variant="ghost"
                  onClick={() => window.open(archiveApi.downloadUrl(doc.id), '_blank')}
                >
                  Download
                </Button>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

function Row({ label, value, tone = 'text-slate-800' }) {
  return (
    <div className="flex items-center justify-between">
      <span className="text-sm text-slate-600">{label}</span>
      <span className={`text-sm font-semibold tabular-nums ${tone}`}>{value}</span>
    </div>
  )
}
