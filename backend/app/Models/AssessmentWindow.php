<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 4 — the assessment window.
 *
 * The coordinator's open/close control over marking. While a window is open,
 * the supervisor and the panel may file Lampiran E and I (PSM 1) or G, H and J
 * (PSM 2) for any student in the batch; once it closes, they may read what they
 * filed but not change it.
 *
 * Two-part gate — the coordinator's `status` plus the published time window — so
 * the coordinator controls it deliberately and the published dates still bind.
 *
 * Scoped by (term, psm_part), and optionally narrowed to one `form_code`.
 *
 * The form is what makes "open Lampiran E without opening Lampiran I" possible.
 * Those are two different events in the real process — the supervisor's
 * evaluation and the seminar examiner's are marked at different times by
 * different people — so the coordinator needs to start them independently.
 *
 * `form_code` null keeps the original meaning: one window for the whole batch,
 * governing every form in it. Both kinds coexist, so a coordinator can keep the
 * coarse control and add finer windows alongside it rather than choosing one
 * model for the term.
 */
class AssessmentWindow extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_OPEN      = 'open';
    public const STATUS_CLOSED    = 'closed';

    protected $fillable = [
        'name',
        'academic_session',
        'academic_semester_id',
        'psm_part',
        'form_code',
        'scheduled_start_at',
        'scheduled_end_at',
        'status',
        'opened_at',
        'closed_at',
        'opened_by',
        'closed_by',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at'   => 'datetime',
            'opened_at'          => 'datetime',
            'closed_at'          => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function academicSemester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class, 'academic_semester_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // -----------------------------------------------------------------
    // State
    // -----------------------------------------------------------------

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /**
     * May marks be filed right now?
     *
     * Open *and* inside the published window. The coordinator controls `status`;
     * the window is what the faculty published. A window left open past its end
     * stops accepting marks rather than silently running on, but this only gates
     * writes and never rewrites the status.
     */
    public function acceptsMarks(): bool
    {
        if (! $this->isOpen()) {
            return false;
        }

        if ($this->scheduled_end_at !== null && now()->isAfter($this->scheduled_end_at)) {
            return false;
        }

        return true;
    }

    /**
     * A short human description of where the window stands.
     *
     * Distinguishes "open but out of time" from "closed", because the two look
     * identical to a reader and mean different things to whoever has to fix it.
     */
    public function stateLabel(): string
    {
        if ($this->isClosed()) {
            return 'Closed';
        }

        if (! $this->isOpen()) {
            return 'Not started';
        }

        return $this->acceptsMarks() ? 'Open for marking' : 'Open — past its end time';
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    /** The window governing one batch of one term, if there is one. */
    public function scopeForSemesterPart(
        Builder $query,
        int|AcademicSemester|null $semester,
        ?string $psmPart
    ): Builder {
        $id = $semester instanceof AcademicSemester ? $semester->id : $semester;

        return $query
            ->when($id !== null, fn ($q) => $q->where('academic_semester_id', $id))
            ->when($psmPart !== null, fn ($q) => $q->where('psm_part', $psmPart));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Narrow to the windows that govern one form.
     *
     * A batch-wide window (`form_code` null) governs every form in its batch, so
     * it always qualifies; a form-scoped window qualifies only for its own form.
     * Pass null for `$formCode` to match batch-wide windows only.
     */
    public function scopeForForm(Builder $query, ?string $formCode): Builder
    {
        return $query->where(fn ($q) => $q
            ->whereNull('form_code')
            ->when($formCode !== null, fn ($sub) => $sub->orWhere('form_code', $formCode)));
    }

    /**
     * Windows that are accepting marks *right now* — the SQL twin of
     * `acceptsMarks()`.
     *
     * The two must agree exactly: this one decides which draft forms an
     * assessor is allowed to see in a list, and the method decides whether a
     * write is permitted. If they drifted, a form would be listed but not
     * saveable (or worse, saveable but invisible).
     */
    public function scopeAcceptingMarks(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_OPEN)
            ->where(fn ($q) => $q
                ->whereNull('scheduled_end_at')
                ->orWhere('scheduled_end_at', '>=', now()));
    }

    /**
     * Is marking open for this project's batch, right now?
     *
     * **Fail-closed.** A batch with no window has not been opened, so its marks
     * are not being taken. This is the deliberate inverse of the original
     * reading, where a missing window meant "ungated" — that let an assessor
     * mark a cohort whose marking the coordinator had never started, which is
     * exactly what the window exists to prevent. Windows are provisioned
     * automatically when a term is created, so a missing one means the term
     * predates that, not that marking should be allowed.
     *
     * Answers for the *whole batch*, ignoring form codes. Use
     * `markingOpenForForm()` when the form is known — which is the normal case
     * for an evaluation, since a form-scoped window should not be able to
     * unblock a different form in the same batch.
     */
    public static function markingOpenFor(?Project $project): bool
    {
        if ($project?->academic_semester_id === null || $project->psm_part === null) {
            return false;
        }

        return static::query()
            ->forSemesterPart($project->academic_semester_id, $project->psm_part)
            ->acceptingMarks()
            ->exists();
    }

    /**
     * Is marking open for one specific form of this project's batch?
     *
     * A batch-wide window (`form_code` null) opens every form in its batch, so
     * it satisfies any form. A form-scoped window opens only its own form.
     * That ordering is what lets a coordinator keep the coarse control while
     * adding finer windows alongside it, rather than having to choose one model.
     *
     * A window scoped to a *different* form does not open this one, which is
     * the whole point: opening Lampiran E must not let an examiner file
     * Lampiran I.
     */
    public static function markingOpenForForm(?Project $project, ?string $formCode): bool
    {
        if ($project?->academic_semester_id === null || $project->psm_part === null) {
            return false;
        }

        return static::query()
            ->forSemesterPart($project->academic_semester_id, $project->psm_part)
            ->acceptingMarks()
            ->forForm($formCode)
            ->exists();
    }

    /**
     * The stable key for a (term, part) pair.
     *
     * Nulls are spelled out rather than interpolated so an unset term cannot
     * collide with a real id.
     */
    public static function termPartKey(?int $semesterId, ?string $psmPart): string
    {
        return ($semesterId ?? 'null') . ':' . ($psmPart ?? 'null');
    }

    /**
     * The stable key for a (term, part, form) triple.
     *
     * Extends `termPartKey()`, so a batch-wide pair key is still a prefix of the
     * forms under it and the two families cannot collide.
     */
    public static function termPartFormKey(?int $semesterId, ?string $psmPart, ?string $formCode): string
    {
        return self::termPartKey($semesterId, $psmPart) . ':' . ($formCode ?? 'null');
    }

    /**
     * Every (term, part) pair and (term, part, form) triple accepting marks.
     *
     * One query for a whole list, so a resource can answer "is this form's
     * marking open?" without a query per row. Controllers put the result on the
     * request for the resource to read; see EvaluationController.
     *
     * Two sets rather than one, because the two kinds of window answer different
     * questions. `pairs` are the batch-wide windows, which open every form in
     * their batch; `forms` are the form-scoped ones, which open only their own.
     * A draft is visible when *either* set covers its (term, part, form) — which
     * is exactly `markingOpenForForm()`, evaluated in bulk.
     *
     * @return array{pairs: array<int, string>, forms: array<int, string>}
     */
    public static function openFormKeys(): array
    {
        $windows = static::query()
            ->acceptingMarks()
            ->get(['academic_semester_id', 'psm_part', 'form_code']);

        $pairs = [];
        $forms = [];

        foreach ($windows as $window) {
            if ($window->form_code === null) {
                $pairs[] = self::termPartKey($window->academic_semester_id, $window->psm_part);

                continue;
            }

            $forms[] = self::termPartFormKey(
                $window->academic_semester_id,
                $window->psm_part,
                $window->form_code
            );
        }

        return ['pairs' => array_values(array_unique($pairs)), 'forms' => array_values(array_unique($forms))];
    }

    /**
     * Every (term, part) pair currently accepting marks, batch-wide or not.
     *
     * The coarse answer: "is *anything* open for this batch?". Kept for callers
     * that only need to know whether the coordinator has started marking at all
     * — the per-form question is `openFormKeys()`.
     *
     * @return array<int, string>
     */
    public static function openTermPartKeys(): array
    {
        return static::query()
            ->acceptingMarks()
            ->get(['academic_semester_id', 'psm_part'])
            ->map(fn (self $w) => self::termPartKey($w->academic_semester_id, $w->psm_part))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The window that governs a project, or null when marking is ungated.
     *
     * Prefers a form-scoped window when one is given, since that is the finer
     * answer. `$formCode` null keeps the original batch-wide lookup.
     */
    public static function governing(Project $project, ?string $formCode = null): ?self
    {
        if ($project->academic_semester_id === null) {
            return null;
        }

        $query = static::query()
            ->forSemesterPart($project->academic_semester_id, $project->psm_part);

        if ($formCode !== null) {
            // The form's own window wins; a batch-wide one is the fallback.
            return (clone $query)->where('form_code', $formCode)->first()
                ?? $query->whereNull('form_code')->first();
        }

        return $query->whereNull('form_code')->first() ?? $query->first();
    }
}
