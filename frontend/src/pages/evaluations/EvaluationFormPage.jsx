import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { evaluationApi } from '../../api/endpoints'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Field, Textarea, FieldErrors, Avatar, ProgressBar,
} from '../../components/ui'
import { formatDate, formatDateTime, formatMark, relativeDays, EVALUATION_STATUS, statusMeta } from '../../lib/format'

/**
 * Rubric marking form — Module 4's core screen.
 *
 * The rubric is rendered from the frozen `rubric_snapshot` that was taken when
 * the form was created, so an assessor always marks against the same criteria
 * the form was issued with, even if the template later changes.
 *
 * Marks are held in local state and saved in two ways: explicitly ("Save
 * draft"), and implicitly via a debounced autosave so a long marking session
 * is never lost to a browser crash. Every mark recomputes the weighted total
 * live, because assessors calibrate against the running figure.
 */
export default function EvaluationFormPage() {
  const { id } = useParams()

  const [evaluation, setEvaluation] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [marks, setMarks] = useState({})
  const [comments, setComments] = useState({})
  const [saving, setSaving] = useState(false)
  const [savedAt, setSavedAt] = useState(null)
  const [saveError, setSaveError] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [errors, setErrors] = useState(null)

  const dirtyRef = useRef(false)
  const timerRef = useRef(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      // evaluationApi.get() already unwraps the envelope.
      const data = await evaluationApi.get(id)
      setEvaluation(data)

      // Seed local state from whatever was previously saved.
      const initialMarks = {}
      const initialComments = {}
      for (const score of data.scores ?? []) {
        initialMarks[score.criterion_code] = score.mark ?? ''
        if (score.comment) initialComments[score.criterion_code] = score.comment
      }
      setMarks(initialMarks)
      setComments(initialComments)
      dirtyRef.current = false
    } catch (err) {
      setError(err)
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    load()
  }, [load])

  const readOnly = evaluation ? !['draft', 'in_progress'].includes(evaluation.status) : true

  const payloadFor = useCallback(
    () => ({
      marks: Object.entries(marks)
        .filter(([, value]) => value !== '' && value != null && !Number.isNaN(Number(value)))
        .map(([criterion_code, mark]) => ({
          criterion_code,
          mark: Number(mark),
          comment: comments[criterion_code]?.trim() || undefined,
        })),
    }),
    [marks, comments]
  )

  const save = useCallback(
    async (silent = false) => {
      const body = payloadFor()
      if (body.marks.length === 0) return

      if (!silent) setSaving(true)
      setSaveError(null)
      try {
        await evaluationApi.saveMarks(id, body)
        dirtyRef.current = false
        setSavedAt(new Date())
      } catch (err) {
        setSaveError(err?.message ?? 'Could not save your marks.')
      } finally {
        if (!silent) setSaving(false)
      }
    },
    [id, payloadFor]
  )

  // Debounced autosave — 1.5s after the assessor stops typing.
  useEffect(() => {
    if (readOnly) return undefined
    if (!dirtyRef.current) return undefined

    clearTimeout(timerRef.current)
    timerRef.current = setTimeout(() => save(true), 1500)
    return () => clearTimeout(timerRef.current)
  }, [marks, comments, readOnly, save])

  function setMark(code, value) {
    dirtyRef.current = true
    setMarks((prev) => ({ ...prev, [code]: value }))
  }

  function setComment(code, value) {
    dirtyRef.current = true
    setComments((prev) => ({ ...prev, [code]: value }))
  }

  const totals = useMemo(() => computeTotals(evaluation, marks), [evaluation, marks])

  async function handleSubmit() {
    setSubmitting(true)
    setErrors(null)
    try {
      await save(false)
      await evaluationApi.submit(id)
      await load()
    } catch (err) {
      if (err?.errors) setErrors(err.errors)
      else setSaveError(err?.message ?? 'Could not submit the form.')
    } finally {
      setSubmitting(false)
    }
  }

  if (loading) return <Spinner label="Loading the marking form" />
  if (error) return <ErrorState error={error} />
  if (!evaluation) return <ErrorState error={{ message: 'Evaluation not found.' }} />

  const meta = statusMeta(EVALUATION_STATUS, evaluation.status)
  const components = evaluation.rubric_snapshot?.components ?? []
  const student = evaluation.project?.students?.[0]

  if (components.length === 0) {
    return (
      <div className="mx-auto max-w-2xl">
        <Card>
          <EmptyState
            title="No rubric attached"
            message="This form has no rubric snapshot, so there is nothing to mark against. Ask your coordinator to re-issue it."
          />
        </Card>
      </div>
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={evaluation.project?.title ?? 'Assessment'}
        subtitle={
          <span className="flex flex-wrap items-center gap-2">
            <Badge tone={meta?.tone ?? 'neutral'}>{meta?.label ?? evaluation.status}</Badge>
            <Badge tone="neutral">{evaluation.assessor_type}</Badge>
            {evaluation.panel_role && <Badge tone="neutral">{evaluation.panel_role}</Badge>}
            {evaluation.due_at && <span className="text-xs">due {formatDate(evaluation.due_at)}</span>}
          </span>
        }
        back={{ to: '/evaluations', label: 'All assessments' }}
      />

      {readOnly && (
        <Card className="border-brand-200 bg-brand-50/50">
          <p className="text-sm text-brand-900">
            This form has been {evaluation.status} and is now read-only.
            {evaluation.submitted_at && ` Filed ${formatDateTime(evaluation.submitted_at)}.`}
          </p>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-4">
        <div className="space-y-6 lg:col-span-3">
          {saveError && <ErrorState error={{ message: saveError }} />}
          <FieldErrors errors={errors} />

          {components.map((component) => (
            <Card key={component.code}>
              <CardHeader
                title={component.name}
                subtitle={
                  component.weight != null
                    ? `Weighted ${component.weight}% of the final mark`
                    : undefined
                }
                action={
                  <ComponentScore
                    component={component}
                    marks={marks}
                  />
                }
              />

              <ul className="divide-y divide-slate-100">
                {(component.criteria ?? []).map((criterion) => (
                  <CriterionRow
                    key={criterion.code}
                    criterion={criterion}
                    component={component}
                    mark={marks[criterion.code] ?? ''}
                    comment={comments[criterion.code] ?? ''}
                    readOnly={readOnly}
                    onMark={(value) => setMark(criterion.code, value)}
                    onComment={(value) => setComment(criterion.code, value)}
                  />
                ))}
              </ul>
            </Card>
          ))}

          {/* Overall comment — a single narrative summary alongside the rubric. */}
          {evaluation.overall_comment != null || !readOnly ? (
            <Card>
              <CardHeader title="Overall comment" subtitle="Visible with your marks once released" />
              <Field label="Summary" htmlFor="overall" hint="Optional but strongly encouraged">
                <Textarea
                  id="overall"
                  rows={5}
                  disabled={readOnly}
                  defaultValue={evaluation.overall_comment ?? ''}
                  onBlur={(e) => {
                    if (readOnly) return
                    const value = e.target.value
                    if (value !== (evaluation.overall_comment ?? '')) {
                      evaluationApi
                        .saveMarks(id, { ...payloadFor(), overall_comment: value })
                        .then(() => setSavedAt(new Date()))
                        .catch((err) => setSaveError(err?.message ?? 'Could not save the comment.'))
                    }
                  }}
                  placeholder="A short narrative assessment of the project…"
                />
              </Field>
            </Card>
          ) : null}
        </div>

        {/* Sticky summary rail — the assessor watches this figure while marking. */}
        <div className="lg:col-span-1">
          <div className="sticky top-6 space-y-4">
            <Card>
              <CardHeader title="Running total" />
              <div className="space-y-4">
                <div>
                  <p className="text-3xl font-semibold tabular-nums text-slate-900">
                    {formatMark(totals.total)}
                  </p>
                  <p className="text-xs text-slate-500">
                    out of {evaluation.rubric_snapshot?.total_marks ?? 100}
                  </p>
                </div>

                <ProgressBar
                  value={totals.percent}
                  tone={
                    totals.percent >= (evaluation.rubric_snapshot?.pass_mark ?? 50) ? 'success' : 'danger'
                  }
                />
                <p className="text-xs text-slate-500">
                  {totals.markedCount} of {totals.criterionCount} criteria marked
                  {totals.percent < (evaluation.rubric_snapshot?.pass_mark ?? 50) &&
                    totals.markedCount > 0 && (
                      <span className="ml-1 text-rose-600">
                        · below the {evaluation.rubric_snapshot?.pass_mark ?? 50} mark
                      </span>
                    )}
                </p>

                <ul className="space-y-2 border-t border-slate-100 pt-3">
                  {totals.byComponent.map((row) => (
                    <li key={row.code} className="flex items-center justify-between text-sm">
                      <span className="truncate text-slate-600">{row.name}</span>
                      <span className="shrink-0 tabular-nums text-slate-800">
                        {formatMark(row.score)}
                        <span className="ml-1 text-xs text-slate-400">/{row.max}</span>
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            </Card>

            {!readOnly && (
              <Card>
                <div className="space-y-3">
                  <div className="flex items-center justify-between text-xs text-slate-500">
                    <span>
                      {saving ? 'Saving…' : savedAt ? `Saved ${relativeDays(savedAt)}` : 'Not saved yet'}
                    </span>
                    {dirtyRef.current && <span className="text-amber-600">Unsaved changes</span>}
                  </div>
                  <Button
                    variant="secondary"
                    className="w-full"
                    disabled={saving}
                    onClick={() => save(false)}
                  >
                    Save draft
                  </Button>
                  <Button className="w-full" disabled={submitting} onClick={handleSubmit}>
                    {submitting ? 'Submitting…' : 'Submit final marks'}
                  </Button>
                  <p className="text-xs text-slate-500">
                    Submitting locks the form. Ask your coordinator if you need it reopened.
                  </p>
                </div>
              </Card>
            )}

            <Card>
              <CardHeader title="Project" />
              <div className="space-y-3">
                <div className="flex items-center gap-3">
                  <Avatar name={student?.name ?? 'Student'} size="sm" />
                  <div className="min-w-0">
                    <p className="truncate text-sm text-slate-700">{student?.name ?? '—'}</p>
                    {student?.student_id && (
                      <p className="font-mono text-xs text-slate-400">{student.student_id}</p>
                    )}
                  </div>
                </div>
                {evaluation.project?.id && (
                  <Link to={`/projects/${evaluation.project.id}`}>
                    <Button size="sm" variant="secondary" className="w-full">
                      Open project
                    </Button>
                  </Link>
                )}
              </div>
            </Card>
          </div>
        </div>
      </div>
    </div>
  )
}

/**
 * One rubric criterion: a mark input, a band guide, and a comment box.
 *
 * A number input is used rather than a slider because marks are frequently
 * entered from a paper marking sheet, so exact typed values matter more than
 * physical manipulation.
 */
function CriterionRow({ criterion, component, mark, comment, readOnly, onMark, onComment }) {
  const max = criterion.max_marks ?? 100
  const numeric = mark === '' ? null : Number(mark)
  const invalid = numeric != null && (Number.isNaN(numeric) || numeric < 0 || numeric > max)

  return (
    <li className="py-4">
      <div className="flex flex-wrap items-start gap-4">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <p className="font-medium text-slate-800">{criterion.name}</p>
            <span className="text-xs text-slate-400">
              {component.weight != null && `${component.weight}% · `}
              {max} marks
            </span>
          </div>
          {criterion.description && (
            <p className="mt-1 text-sm text-slate-600">{criterion.description}</p>
          )}
          {criterion.grading_guide && (
            <details className="mt-2">
              <summary className="cursor-pointer text-xs font-medium text-brand-700 hover:underline">
                Show band descriptors
              </summary>
              <div className="mt-2 whitespace-pre-line rounded-md bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-600">
                {criterion.grading_guide}
              </div>
            </details>
          )}
        </div>

        <div className="w-28 shrink-0">
          <label className="sr-only" htmlFor={`mark-${criterion.code}`}>
            Mark for {criterion.name}
          </label>
          <div className="relative">
            <input
              id={`mark-${criterion.code}`}
              type="number"
              inputMode="decimal"
              min={0}
              max={max}
              step={0.5}
              value={mark}
              disabled={readOnly}
              onChange={(e) => onMark(e.target.value)}
              aria-invalid={invalid ? 'true' : undefined}
              className={`w-full rounded-md border px-2.5 py-2 pr-12 text-right tabular-nums shadow-sm focus:outline-none focus:ring-2 ${
                invalid
                  ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-200'
                  : 'border-slate-300 focus:border-brand-500 focus:ring-brand-200'
              } disabled:bg-slate-50 disabled:text-slate-500`}
            />
            <span className="pointer-events-none absolute inset-y-0 right-2.5 flex items-center text-xs text-slate-400">
              / {max}
            </span>
          </div>
          {invalid && (
            <p className="mt-1 text-xs text-rose-600">0–{max} only</p>
          )}
        </div>
      </div>

      {/* Only prompt for a comment on a weak mark — this is when feedback matters. */}
      {!readOnly && (numeric != null && numeric < max * 0.7) && (
        <Textarea
          rows={2}
          value={comment}
          onChange={(e) => onComment(e.target.value)}
          placeholder="Brief feedback for this criterion…"
          className="mt-3"
        />
      )}
      {readOnly && comment && (
        <p className="mt-3 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">{comment}</p>
      )}
    </li>
  )
}

function ComponentScore({ component, marks }) {
  const { score, max } = scoreComponent(component, marks)
  return (
    <span className="text-sm tabular-nums text-slate-700">
      {formatMark(score)}
      <span className="ml-1 text-xs text-slate-400">/ {formatMark(max)}</span>
    </span>
  )
}

/**
 * Sum one component's criteria.
 *
 * Each criterion's earned marks are scaled by the criterion's share of the
 * component and the component's share of the whole, matching the server's
 * algorithm in `EvaluationService` so the on-screen figure and the stored
 * figure never disagree.
 */
function scoreComponent(component, marks) {
  const criteria = component.criteria ?? []
  if (criteria.length === 0) return { score: 0, max: 0 }

  // Criteria weights inside a component sum to 100.
  const criterionWeightTotal = criteria.reduce((sum, c) => sum + (c.weight ?? 0), 0) || 100
  const componentMax = criteria.reduce((sum, c) => sum + (c.max_marks ?? 0), 0)

  let earned = 0
  for (const criterion of criteria) {
    const raw = marks[criterion.code]
    if (raw === '' || raw == null || raw === '') continue
    const value = Number(raw)
    if (Number.isNaN(value)) continue

    const max = criterion.max_marks ?? 100
    const criterionShare = (criterion.weight ?? 0) / criterionWeightTotal
    earned += (value / max) * criterionShare * componentMax
  }

  return { score: round2(earned), max: round2(componentMax) }
}

function computeTotals(evaluation, marks) {
  const components = evaluation?.rubric_snapshot?.components ?? []
  const totalMarks = evaluation?.rubric_snapshot?.total_marks ?? 100

  const weightTotal = components.reduce((sum, c) => sum + (c.weight ?? 0), 0)
  const byComponent = components.map((component) => {
    const { score, max } = scoreComponent(component, marks)
    // If the rubric's weights do not sum to 100 the server rescales; match it.
    const scale = weightTotal > 0 ? 100 / weightTotal : 1
    const weighted = (score / (max || 1)) * (component.weight ?? 0) * scale

    return {
      code: component.code,
      name: component.name,
      score: round2((score / (max || 1)) * (component.max_marks ?? max) || score),
      max: round2(max),
      weighted,
    }
  })

  const total = byComponent.reduce((sum, row) => sum + row.weighted, 0)
  const criterionCount = components.reduce((sum, c) => sum + (c.criteria?.length ?? 0), 0)
  const markedCount = components.reduce(
    (sum, c) =>
      sum +
      (c.criteria ?? []).filter((criterion) => {
        const raw = marks[criterion.code]
        return raw !== '' && raw != null && !Number.isNaN(Number(raw))
      }).length,
    0
  )

  return {
    total: round2((total / 100) * totalMarks),
    percent: round2(total),
    byComponent,
    criterionCount,
    markedCount,
  }
}

function round2(value) {
  return Math.round((value + Number.EPSILON) * 100) / 100
}
