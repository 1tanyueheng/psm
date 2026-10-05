/**
 * PSM batch vocabulary (Module 3).
 *
 * The faculty runs PSM 1 and PSM 2 *concurrently in the same semester*, which
 * makes `psm_part` a scoping column rather than a label. It decides which
 * milestone chain applies, which marking forms count, which examiner pairs may
 * be seated, and how many students a supervisor may carry.
 *
 * This mirrors `App\Enums\PsmPart`. It exists so filter bars and segmented
 * controls have one source of truth for the two deliverable parts — without it
 * every screen invents its own `'PSM1' | 'PSM2'` literals and its own idea of
 * what the labels read as.
 *
 * Not a security boundary: the API validates `psm_part` independently.
 */

/** The two parts a *project* may belong to. Mirrors `PsmPart::deliverables()`. */
export const PSM_PARTS = ['PSM1', 'PSM2']

/** Every value the enum accepts, including the `BOTH` pairing marker. */
export const ALL_PSM_PARTS = ['PSM1', 'PSM2', 'BOTH']

export const PSM_PART_LABELS = {
  PSM1: 'PSM 1',
  PSM2: 'PSM 2',
  BOTH: 'PSM 1 & 2',
}

/** Compact labels for tight spots — pills, table headers, tab strips. */
export const PSM_PART_SHORT_LABELS = {
  PSM1: 'PSM1',
  PSM2: 'PSM2',
  BOTH: 'Both',
}

export const PSM_PART_DESCRIPTIONS = {
  PSM1: 'Chapters 1–4, with the title proposed and reviewed before registration.',
  PSM2: 'Chapters 5–7 and the final report, assessed at the end of the semester.',
  BOTH: 'A supervision covering both parts of one student’s project.',
}

/** Tailwind text colour per part, so a batch is recognisable at a glance. */
export const PSM_PART_TONES = {
  PSM1: 'text-sky-700',
  PSM2: 'text-violet-700',
  BOTH: 'text-slate-600',
}

/** Tailwind pill classes per part. */
export const PSM_PART_BADGE_TONES = {
  PSM1: 'bg-sky-50 text-sky-700 border-sky-200',
  PSM2: 'bg-violet-50 text-violet-700 border-violet-200',
  BOTH: 'bg-slate-50 text-slate-700 border-slate-200',
}

/** Tailwind fill per part, for capacity bars and distribution charts. */
export const PSM_PART_FILL = {
  PSM1: 'bg-sky-500',
  PSM2: 'bg-violet-500',
  BOTH: 'bg-slate-400',
}

/**
 * Normalise a loose value to a canonical part, or null.
 *
 * Tolerates the shapes that actually turn up: raw enum values from the API,
 * lowercase from a hand-typed query string, an object carrying `.value`, and
 * legacy `'BOTH'` rows. One bad row must not blank a whole screen, so this
 * returns null rather than throwing and lets the caller decide.
 */
export function normalisePart(value) {
  if (value == null) return null
  if (typeof value === 'object') value = value.value
  if (typeof value !== 'string') return null

  const upper = value.trim().toUpperCase()
  return ALL_PSM_PARTS.includes(upper) ? upper : null
}

/** The other part — used when promoting a student from PSM 1 to PSM 2. */
export function counterpartPart(value) {
  const part = normalisePart(value)
  return part === 'PSM1' ? 'PSM2' : 'PSM1'
}

export function partLabel(value, { short = false } = {}) {
  const part = normalisePart(value)
  if (!part) return '—'
  return short ? PSM_PART_SHORT_LABELS[part] : PSM_PART_LABELS[part]
}

export function partTone(value) {
  return PSM_PART_TONES[normalisePart(value)] ?? 'text-slate-600'
}

export function partBadgeTone(value) {
  return PSM_PART_BADGE_TONES[normalisePart(value)] ?? PSM_PART_BADGE_TONES.BOTH
}

export function partFill(value) {
  return PSM_PART_FILL[normalisePart(value)] ?? PSM_PART_FILL.BOTH
}

/** Options for a `<Select>`, always including an "all parts" row. */
export function partFilterOptions({ allLabel = 'All batches', includeAll = true } = {}) {
  const options = PSM_PARTS.map((part) => ({
    value: part,
    label: PSM_PART_LABELS[part],
  }))

  return includeAll ? [{ value: '', label: allLabel }, ...options] : options
}

/**
 * Drop empty filter keys before they reach the API.
 *
 * An empty `psm_part` is meaningful — the backend reads a missing filter as
 * *both* parts, which is exactly what "All batches" should mean. Sending
 * `psm_part=` as an empty string instead risks being read as an invalid value,
 * so the key is removed rather than blanked.
 */
export function cleanPartFilters(params = {}) {
  const { psm_part: part, ...rest } = params
  const cleaned = { ...rest }

  if (part) cleaned.psm_part = normalisePart(part)

  return cleaned
}