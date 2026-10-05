import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { evaluationApi, milestoneApi } from '../../api/endpoints'
import { unwrapPaged } from '../../api/client'
import { useAuth } from '../../context/AuthContext'
import {
  Card, CardHeader, PageHeader, StatCard, Badge, Avatar,
  EmptyState, Spinner, ErrorState, ProgressBar, SegmentedControl, Button,
} from '../../components/ui'
import {
  formatDate, formatMark, EVALUATION_STATUS, EXAMINER_PANEL_ROLES,
  statusMeta
} from '../../lib/format'
import { PSM_PART_BADGE_TONES, partLabel, PSM_PARTS } from '../../lib/psmPart'

/** Badge tone per proposal outcome, so the list reads at a glance. */
const PROPOSAL_TONE = {
  approved: 'success',
  conditional_approve: 'warning',
  rejected: 'danger',
  submitted: 'info',
  reviewed: 'info',
  open: 'neutral',
  pending: 'neutral',
  overdue: 'danger',
}

/**
 * Examiner workspace.
 *
 * An examiner does not own a project — they are invited to mark one. So the
 * page is built around "what have I been asked to examine, and what is still
 * outstanding" rather than a roster of long-term relationships.
 */
export default function PanelWorkPage() {
  const { user } = useAuth()
  const [forms, setForms] = useState([])
  // The proposals this panel has to rule on — the title decision, which is the
  // panel's other job and the one that gates the rest of a student's chain.
  const [proposals, setProposals] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [selectedPart, setSelectedPart] = useState('all')

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setError(null)
      try {
        // Both jobs at once: the forms to mark, and the proposals to decide.
        // A panel is appointed to do two things, and the second one gates the
        // student's whole milestone chain — it should not be the harder to find.
        const [formsRes, proposalsRes] = await Promise.all([
          evaluationApi.list({ mine: true, as: 'examiner', per_page: 100 }),
          milestoneApi.panelProposals().catch(() => []),
        ])

        if (!cancelled) {
          setForms(unwrapPaged(formsRes).items)
          setProposals(Array.isArray(proposalsRes) ? proposalsRes : [])
        }
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
  }, [])

  // Filter forms by selected part
  const filteredForms = forms.filter(f =>
    selectedPart === 'all' || f.psm_part === selectedPart
  )

  const { drafts, submitted, stats } = useMemo(() => {
    const isDraft = (f) => f.status === 'draft'
    const draftRows = filteredForms.filter(isDraft)
    const submittedRows = filteredForms.filter((f) => !isDraft(f))

    return {
      drafts: draftRows,
      submitted: submittedRows,
      stats: {
        total: filteredForms.length,
        outstanding: draftRows.length,
        done: submittedRows.length,
        conflicts: filteredForms.filter((f) => f.has_conflict).length,
      },
    }
  }, [filteredForms])

  if (loading) return <Spinner label="Loading your examination assignments" />
  if (error) return <ErrorState error={error} />

  return (
    <div className="space-y-6">
      <PageHeader
        title={`Welcome, ${user?.name?.split(' ')[0] ?? 'Supervisor'}`}
        subtitle="Projects whose panel you sit on"
        action={
          <SegmentedControl
            options={[
              { value: 'all', label: 'All' },
              ...PSM_PARTS.map(p => ({ value: p, label: partLabel(p) }))
            ]}
            value={selectedPart}
            onChange={setSelectedPart}
          />
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Assigned" value={stats.total} hint="Evaluation forms" />
        <StatCard
          label="Outstanding"
          value={stats.outstanding}
          hint="Draft, not submitted"
          tone={stats.outstanding > 0 ? 'warning' : 'default'}
        />
        <StatCard label="Submitted" value={stats.done} hint="Locked and counted" tone="success" />
        <StatCard
          label="Conflicts declared"
          value={stats.conflicts}
          hint="Excluded from assessment"
          tone={stats.conflicts > 0 ? 'danger' : 'default'}
        />
      </div>

      {proposals.length > 0 && (
        <Card>
          <CardHeader
            title="Proposals to decide"
            subtitle="The panel rules on the title at the project's proposal milestone — it gates the rest of that student's chain"
            action={
              proposals.some((p) => p.awaiting_decision) ? (
                <Badge tone="warning">
                  {proposals.filter((p) => p.awaiting_decision).length} awaiting
                </Badge>
              ) : (
                <Badge tone="success">All decided</Badge>
              )
            }
          />

          <ul className="divide-y divide-slate-100">
            {proposals.map((p) => (
              <li key={p.id} className="flex flex-wrap items-center gap-3 py-3">
                <div className="min-w-0 flex-1">
                  <p className="truncate text-sm font-medium text-slate-800">
                    {p.student?.name ?? 'Unknown student'}
                    {p.student?.student_id && (
                      <span className="ml-1.5 text-xs font-normal text-slate-400">
                        {p.student.student_id}
                      </span>
                    )}
                  </p>
                  <p className="truncate text-xs text-slate-500">
                    {p.project?.code} — {p.project?.title}
                  </p>
                </div>

                <Badge tone={PROPOSAL_TONE[p.status] ?? 'neutral'}>{p.status_label}</Badge>
                {p.panel_role && <Badge tone="neutral">{p.panel_role}</Badge>}

                <Link to={`/milestones/${p.id}`}>
                  <Button size="sm" variant={p.awaiting_decision ? 'primary' : 'ghost'}>
                    {p.awaiting_decision ? 'Decide' : 'View'}
                  </Button>
                </Link>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {stats.outstanding > 0 && (
        <Card className="border-amber-200 bg-amber-50/50">
          <div className="flex flex-wrap items-center gap-3 p-1">
            <div className="flex-1">
              <p className="font-medium text-amber-900">
                {stats.outstanding} form{stats.outstanding === 1 ? '' : 's'} still in draft
              </p>
              <p className="text-sm text-amber-800">
                Marks are not visible to anyone until you submit. Submit before the panel deadline.
              </p>
            </div>
          </div>
        </Card>
      )}

      {PSM_PARTS.map(part => {
        const partDrafts = drafts.filter(f => f.psm_part === part)
        const partSubmitted = submitted.filter(f => f.psm_part === part)
        if (partDrafts.length === 0 && partSubmitted.length === 0 && selectedPart !== 'all') {
          return null
        }
        return (
          <Card key={part}>
            <CardHeader
              title={`Pending ${partLabel(part)} assessments`}
              subtitle={partDrafts.length === 0 ? 'All caught up' : `${partDrafts.length} form${partDrafts.length === 1 ? '' : 's'} to complete`}
            />
            {partDrafts.length === 0 ? (
              <EmptyState title="Nothing outstanding" message="Every form assigned to you has been submitted. Thank you." />
            ) : (
              <ul className="divide-y divide-slate-100">
                {partDrafts.map((form) => (
                  <FormRow key={form.id} form={form} />
                ))}
              </ul>
            )}
          </Card>
        )
      })}

      {PSM_PARTS.map(part => {
        const partSubmitted = submitted.filter(f => f.psm_part === part)
        if (partSubmitted.length === 0 && selectedPart !== 'all') {
          return null
        }
        return (
          <Card key={`submitted-${part}`}>
            <CardHeader title={`Submitted ${partLabel(part)} assessments`} subtitle="A read-only record of what you filed" />
            {partSubmitted.length === 0 ? (
              <EmptyState title="No submissions yet" message="Submitted forms are archived here." />
            ) : (
              <ul className="divide-y divide-slate-100">
                {partSubmitted.map((form) => (
                  <FormRow key={form.id} form={form} readOnly />
                ))}
              </ul>
            )}
          </Card>
        )
      })}
    </div>
  )
}

function FormRow({ form, readOnly = false }) {
  const meta = statusMeta(EVALUATION_STATUS, form.status)
  const project = form.project ?? {}
  const lead = project.students?.[0]

  return (
    <li className="py-4">
      <div className="flex flex-wrap items-start gap-4">
        <Avatar name={lead?.name ?? project.title ?? 'Project'} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <Link
              to={`/evaluations/${form.id}`}
              className="font-medium text-slate-900 hover:text-brand-700"
            >
              {project.title ?? `Evaluation #${form.id}`}
            </Link>
            <Badge tone={meta?.tone ?? 'neutral'}>{meta?.label ?? form.status}</Badge>
            <Badge tone={PSM_PART_BADGE_TONES[form.psm_part] ?? 'neutral'}>{partLabel(form.psm_part)}</Badge>
            {form.panel_role && (
              <Badge tone="neutral">{EXAMINER_PANEL_ROLES[form.panel_role] ?? form.panel_role}</Badge>
            )}
            {form.has_conflict && <Badge tone="danger">Conflict declared</Badge>}
          </div>

          <p className="mt-1 text-sm text-slate-600">
            {lead?.name}
            {lead?.student_id && (
              <span className="ml-2 font-mono text-xs text-slate-400">{lead.student_id}</span>
            )}
          </p>

          {/* Only show progress on a draft — a submitted form's marks are frozen. */}
          {!readOnly && form.rubric_completion_percent != null && (
            <div className="mt-3 flex items-center gap-3">
              <div className="w-full max-w-xs">
                <ProgressBar
                  value={form.rubric_completion_percent}
                  tone={form.rubric_completion_percent >= 80 ? 'success' : 'brand'}
                />
              </div>
              <span className="w-10 shrink-0 text-xs tabular-nums text-slate-500">
                {form.rubric_completion_percent}%
              </span>
            </div>
          )}

          {readOnly && form.aggregate_mark != null && (
            <p className="mt-2 text-sm text-slate-700">
              Your mark: <span className="font-semibold tabular-nums">{formatMark(form.aggregate_mark)}</span>
            </p>
          )}
        </div>

        <div className="text-right text-sm">
          {form.submitted_at ? (
            <>
              <p className="text-slate-500">Submitted</p>
              <p className="font-mono text-slate-700">{formatDate(form.submitted_at)}</p>
            </>
          ) : (
            <p className="text-amber-600 font-medium">Not submitted</p>
          )}
        </div>
      </div>
    </li>
  )
}
