import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { evaluationApi } from '../../api/endpoints'
import {
  Card, CardHeader, PageHeader, Badge, EmptyState, Spinner, ErrorState,
  Button, Field, Textarea, FieldErrors, Avatar, ProgressBar, Select,
} from '../../components/ui'
import { formatDate, formatDateTime, formatMark, formatPercent, formLabel, relativeDays, EVALUATION_STATUS, statusMeta } from '../../lib/format'

/**
 * The top of the official Lampiran scale.
 *
 * Every marking form (E, G, H, I, J) prints the same six-point scale — 0 "Tiada
 * Data", 1 "Sangat Kurang Memuaskan" through 5 "Sangat Baik" — and weights each
 * item with `(score / 5) x weight`. It must match
 * `EvaluationService::MAX_ITEM_SCORE` on the server, which performs the same
 * conversion when the marks are saved.
 */
const ITEM_SCORE_MAX = 5

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
      //
      // The input is the official 0-5 item score, so seed from `item_score` —
      // NOT `marks_awarded`, which is that score already weighted (a criterion
      // worth 1.45 of Lampiran J's 40 would put "1.45" in a 0-5 box). Rows
      // entered before the 0-5 screen have no `item_score`; they are left blank
      // rather than guessed at, because a stored 1.00 could be any of several
      // item scores on a 1.45 scale.
      //
      // Per-criterion comments are read straight from the response where they
      // are displayed (see CriterionRow), not held in editable state: the form
      // takes one overall comment, so there is no criterion comment to edit.
      const initialMarks = {}
      for (const score of data.scores ?? []) {
        initialMarks[score.criterion_code] = score.item_score ?? ''
      }
      setMarks(initialMarks)
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

  // The status enum is the authority on whether a form can still be marked;
  // `is_editable` comes from it rather than being re-derived here.
  const readOnly = evaluation ? !evaluation.is_editable : true

  const payloadFor = useCallback(
    (overrides = {}) => ({
      // `marks` is the name the endpoint validates against.
      //
      // Only the empty placeholder is dropped. A selected `"0"` must be sent:
      // on this scale 0 means "Tiada Data", which is a judgement the marker
      // made, not an unanswered question — `value !== ''` is what keeps those
      // two apart, since `0` is falsy.
      //
      // No per-criterion `comment` is sent: the form carries one overall
      // comment (saved separately as `comment`), and sending none leaves any
      // comment already on a criterion untouched rather than clearing it.
      marks: Object.entries(marks)
        .filter(([, value]) => value !== '' && value != null && !Number.isNaN(Number(value)))
        .map(([criterion_code, mark]) => ({
          criterion_code,
          marks: Number(mark),
        })),
      ...overrides,
    }),
    [marks]
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

  // Debounced autosave — 1.5s after the assessor stops changing a score.
  useEffect(() => {
    if (readOnly) return undefined
    if (!dirtyRef.current) return undefined

    clearTimeout(timerRef.current)
    timerRef.current = setTimeout(() => save(true), 1500)
    return () => clearTimeout(timerRef.current)
  }, [marks, readOnly, save])

  function setMark(code, value) {
    dirtyRef.current = true
    setMarks((prev) => ({ ...prev, [code]: value }))
  }

  const totals = useMemo(() => computeTotals(evaluation, marks), [evaluation, marks])

  /**
   * Chapter status keyed by milestone code, so a chapter-scoped component can
   * show how that chapter is progressing while it is being marked.
   */
  const milestonesByCode = useMemo(() => {
    const map = {}
    for (const m of evaluation?.milestones ?? []) map[m.code] = m
    return map
  }, [evaluation])

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

  // Checked *before* the generic error state: the server refuses to hand over a
  // draft whose batch is not open for marking, and "marking has not been opened
  // yet" is a state to explain, not a failure to report. This only fires on a
  // stale tab or a bookmark, since the list hides these forms too.
  if (error?.status === 403) {
    return (
      <div className="mx-auto max-w-2xl">
        <Card className="border-amber-200 bg-amber-50/60">
          <EmptyState
            title="Marking has not been opened yet"
            message="Your coordinator opens marking for each batch. This form becomes available once they do."
          />
        </Card>
      </div>
    )
  }

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
            {formLabel(evaluation) && <Badge tone="brand">{formLabel(evaluation)}</Badge>}
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
                title={component.title}
                subtitle={
                  component.weight_percent != null
                    ? `Weighted ${formatPercent(component.weight_percent, 0)} of the final mark`
                    : undefined
                }
                action={
                  <ComponentScore
                    component={component}
                    marks={marks}
                  />
                }
              />

              {component.description && (
                <p className="mb-3 text-sm text-slate-600">{component.description}</p>
              )}

              {/* Chapter-scoped components carry the milestone they score, so
                  the supervisor can see its submission state while marking. */}
              {component.milestone_code && milestonesByCode[component.milestone_code] && (
                <ChapterStatus milestone={milestonesByCode[component.milestone_code]} />
              )}

              <ul className="divide-y divide-slate-100">
                {(component.criteria ?? []).map((criterion) => (
                  <CriterionRow
                    key={criterion.code}
                    criterion={criterion}
                    component={component}
                    mark={marks[criterion.code] ?? ''}
                    comment={(evaluation.scores ?? []).find((s) => s.criterion_code === criterion.code)?.comment ?? ''}
                    readOnly={readOnly}
                    onMark={(value) => setMark(criterion.code, value)}
                  />
                ))}
              </ul>
            </Card>
          ))}

          {/* Overall comment — a single narrative summary alongside the rubric. */}
          {evaluation.comment != null || !readOnly ? (
            <Card>
              <CardHeader title="Overall comment" subtitle="Visible with your marks once released" />
              <Field label="Summary" htmlFor="overall" hint="Optional but strongly encouraged">
                <Textarea
                  id="overall"
                  rows={5}
                  disabled={readOnly}
                  defaultValue={evaluation.comment ?? ''}
                  onBlur={(e) => {
                    if (readOnly) return
                    const value = e.target.value
                    if (value !== (evaluation.comment ?? '')) {
                      evaluationApi
                        .saveMarks(id, payloadFor({ comment: value }))
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

                {/* No pass/fail colouring: the system records a mark, and the
                    form's own total is not the whole assessment. */}
                <ProgressBar value={totals.percent} tone="brand" />
                <p className="text-xs text-slate-500">
                  {totals.markedCount} of {totals.criterionCount} criteria marked
                </p>

                <ul className="space-y-2 border-t border-slate-100 pt-3">
                  {totals.byComponent.map((row) => (
                    <li key={row.code} className="flex items-center justify-between text-sm">
                      <span className="truncate text-slate-600">{row.title}</span>
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
 * The official Lampiran scale, in the words the paper form prints.
 *
 * A dropdown of bare digits makes the marker translate "3" into a judgement
 * themselves; the printed form gives every stop a name, and so does this. The
 * wording is the form's own (0 Tiada Data … 5 Sangat Baik), so what the marker
 * picks is what the sheet would say.
 */
const ITEM_SCORE_OPTIONS = [
  { value: 0, label: '0 — Tiada Data (No Data)' },
  { value: 1, label: '1 — Sangat Kurang Memuaskan (Very Unsatisfactory)' },
  { value: 2, label: '2 — Kurang Memuaskan (Unsatisfactory)' },
  { value: 3, label: '3 — Sederhana (Moderate)' },
  { value: 4, label: '4 — Baik (Good)' },
  { value: 5, label: '5 — Sangat Baik (Very Good)' },
]

/**
 * One rubric criterion: the official 0-5 item score, its converted weight, and
 * a comment box.
 *
 * The score is chosen from a **dropdown of the form's own six stops** (0 Tiada
 * Data … 5 Sangat Baik), which is exactly what the printed Lampiran offers — a
 * free-text box invited half marks and out-of-range values that the paper form
 * cannot express. `max_marks` is not a score the marker picks: it is the
 * criterion's share of the form's total (Lampiran J's A1 is 1.45 of 40), and the
 * weighted value is `(score / 5) x max_marks`. The converted figure is shown
 * beside the dropdown so the marker can see what their judgement is worth.
 */
function CriterionRow({ criterion, component, mark, comment, readOnly, onMark }) {
  const ITEM_MAX = ITEM_SCORE_MAX
  const weight = criterion.max_marks ?? 0
  const numeric = mark === '' || mark == null ? null : Number(mark)

  // What this judgement is worth on the form's own scale: (score / 5) x weight.
  const converted =
    numeric != null && !Number.isNaN(numeric) ? round2((numeric / ITEM_MAX) * weight) : null

  return (
    <li className="py-4">
      <div className="flex flex-wrap items-start gap-4">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <p className="font-medium text-slate-800">{criterion.title}</p>
            <span className="text-xs text-slate-400">
              {component.weight_percent != null &&
                `${formatPercent(component.weight_percent, 0)} · `}
              {round2(weight)} marks
            </span>
          </div>
          {criterion.description && (
            <p className="mt-1 text-sm text-slate-600">{criterion.description}</p>
          )}
          {criterion.guidance && (
            <details className="mt-2">
              <summary className="cursor-pointer text-xs font-medium text-brand-700 hover:underline">
                What excellent looks like
              </summary>
              <div className="mt-2 whitespace-pre-line rounded-md bg-slate-50 px-3 py-2 text-xs leading-relaxed text-slate-600">
                {criterion.guidance}
              </div>
            </details>
          )}
        </div>

        <div className="w-64 shrink-0">
          <label className="sr-only" htmlFor={`mark-${criterion.code}`}>
            Score for {criterion.title}
          </label>
          <div className="flex items-center gap-2">
            <Select
              id={`mark-${criterion.code}`}
              value={mark === '' || mark == null ? '' : String(mark)}
              disabled={readOnly}
              onChange={(e) => onMark(e.target.value)}
              className="w-full py-2 text-sm"
            >
              <option value="">— Select / Pilih —</option>
              {ITEM_SCORE_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
            <div className="min-w-14 shrink-0 text-xs text-slate-500">
              {converted != null ? (
                <span className="tabular-nums">
                  = <span className="font-medium text-slate-700">{converted.toFixed(2)}</span>
                </span>
              ) : (
                <span className="text-slate-300">= 0.00</span>
              )}
            </div>
          </div>
        </div>
      </div>

      {/* No per-criterion comment box: the Lampiran forms carry one overall
          comment for the whole submission, and a box on every criterion turned
          a 25-item form into a wall of textareas. Any comment already recorded
          against this criterion is still shown, because it is part of the
          assessment — it is simply no longer editable here. */}
      {comment && (
        <p className="mt-3 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-700">{comment}</p>
      )}
    </li>
  )
}

/**
 * The chapter's submission state, shown above the criteria it is marked on.
 * Answers "is this chapter actually submitted yet?" at the moment of marking.
 */
function ChapterStatus({ milestone }) {
  return (
    <div className="mb-3 flex flex-wrap items-center gap-3 rounded-md bg-slate-50 px-3 py-2">
      <Badge tone={milestone.status_tone ?? 'neutral'}>
        {milestone.status_label ?? milestone.status}
      </Badge>
      <div className="min-w-32 flex-1">
        <ProgressBar value={milestone.completion_percent ?? 0} tone={milestone.status_tone ?? 'brand'} />
      </div>
      <span className="text-xs text-slate-500">
        chapter {formatPercent(milestone.completion_percent ?? 0)} complete
      </span>
      <span className="text-xs text-slate-400">
        due {formatDate(milestone.effective_due_at ?? milestone.due_at)}
      </span>
    </div>
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
 * Sum one component's criteria from the entered 0-5 item scores.
 *
 * Each criterion's item score is converted the way the Lampiran forms do it —
 * `(score / 5) x max_marks` — and the component's earned total is the sum of
 * those converted values. The maximum is the sum of the criteria's `max_marks`,
 * which is the component's share of the form's total (Lampiran J's A_paper is
 * worth 10 of 40, B 25 of 40, C 5 of 40).
 *
 * This mirrors `EvaluationService::saveMarks()`, so the figure on screen and the
 * figure stored after saving cannot disagree. It previously divided by
 * `criterion.max_marks`, which was only correct while the input itself was on
 * the weighted scale — the very thing the 0-5 entry screen replaces.
 */
function scoreComponent(component, marks) {
  const criteria = component.criteria ?? []
  if (criteria.length === 0) return { score: 0, max: 0 }

  const componentMax = criteria.reduce((sum, c) => sum + (c.max_marks ?? 0), 0)

  let earned = 0
  for (const criterion of criteria) {
    const raw = marks[criterion.code]
    if (raw === '' || raw == null) continue
    const value = Number(raw)
    if (Number.isNaN(value)) continue

    earned += (value / ITEM_SCORE_MAX) * (criterion.max_marks ?? 0)
  }

  return { score: round2(earned), max: round2(componentMax) }
}

function computeTotals(evaluation, marks) {
  const components = evaluation?.rubric_snapshot?.components ?? []
  const totalMarks = evaluation?.rubric_snapshot?.total_marks ?? 100

  const weightTotal = components.reduce((sum, c) => sum + (c.weight_percent ?? 0), 0)
  const byComponent = components.map((component) => {
    const { score, max } = scoreComponent(component, marks)
    // If the rubric's weights do not sum to 100 the server rescales; match it.
    const scale = weightTotal > 0 ? 100 / weightTotal : 1
    const weighted = (score / (max || 1)) * (component.weight_percent ?? 0) * scale

    return {
      code: component.code,
      title: component.title,
      score: round2(score),
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
