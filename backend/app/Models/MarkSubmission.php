<?php

namespace App\Models;

use App\Enums\MarkSubmissionStatus;
use App\Models\ExaminerAssignment;
use App\Models\SupervisionAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Module 4 â€” one student's mark for one project in one PSM part, from the
 * moment a coordinator opens it to the moment it is locked.
 *
 * This is the record the flow the user described needs. The pieces already
 * existed â€” an `evaluations` row per assessor and a `final_grades` row per
 * student â€” but nothing joined them into a unit with a lifecycle, so "the mark
 * is final" was never a moment anyone could reach.
 *
 * A submission does not own the marks. It owns the *promise* that a known set
 * of forms will come back, and the attestation that they did. Every score is
 * read from the evaluation rows, so the aggregate can never disagree with the
 * forms it claims to summarise.
 *
 * @property MarkSubmissionStatus $status
 */
class MarkSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'student_profile_id',
        'psm_part',
        'academic_semester_id',
        'status',
        'expected_panel_size',
        'opened_by',
        'opened_at',
        'locked_by',
        'locked_at',
        'unlock_reason',
        'final_grade_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'    => MarkSubmissionStatus::class,
            'opened_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(AcademicSemester::class, 'academic_semester_id');
    }

    /** The coordinator who opened the submission. */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** The coordinator who attested the mark. */
    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * The computed grade for this submission's project and student.
     *
     * **Not a `belongsTo` on a foreign key.** There is no
     * `final_grades.mark_submission_id` column: `FinalGrade` is keyed on
     * `(project_id, student_profile_id, psm_part)`, which is the pair the mark
     * actually belongs to.
     *
     * The previous version â€” `belongsTo(FinalGrade::class)` with no key â€” made
     * Eloquent look for `final_grades.mark_submission_id`, a column that has
     * never existed. It appeared to work for exactly one row because
     * `mark_submissions.id` happened to equal `project_id` there and the default
     * key resolved by luck; every other row threw
     * `Undefined column: mark_submission_id`. Found by the term-close test.
     *
     * `hasOne` on the real identity is what this always meant: one submission
     * per project per part, one grade per student per project per part.
     */
    /**
     * The computed grade for this submission's project and student.
     *
     * **Not an Eloquent relation.** There is no `final_grades.mark_submission_id`
     * column: `FinalGrade` is keyed on `(project_id, student_profile_id,
     * psm_part)`, and a relation cannot express a three-column identity that
     * also has to eager-load. Two attempts failed for that reason â€”
     * `belongsTo` looked for a column that does not exist, and `hasOne` with
     * `whereColumn` breaks under `with('finalGrade')` because the parent table
     * is not in the query at all.
     *
     * A cached lookup is honest about what this is: one row per project per
     * student per part, fetched on demand and remembered for the instance.
     * `with('finalGrade')` still behaves â€” it simply does nothing â€” so callers
     * that ask for it keep working; ask for `final_mark` instead.
     *
     * Found by the term-close test, which read every project's mark and got
     * null for all of them.
     */
    public function getFinalGradeAttribute(): ?FinalGrade
    {
        if ($this->relationLoaded('finalGrade')) {
            return $this->relations['finalGrade'];
        }

        $grade = FinalGrade::query()
            ->where('project_id', $this->project_id)
            ->where('student_profile_id', $this->student_profile_id)
            ->where('psm_part', $this->psm_part)
            ->first();

        $this->relations['finalGrade'] = $grade;

        return $grade;
    }

    /**
     * Eager-load the grade for a set of submissions in one query.
     *
     * `with('finalGrade')` cannot work (see above), so the term close selects
     * the grades directly with `whereIn` instead. This does the same and keys
     * them back onto the submissions, which is what a caller looping over a
     * cohort actually needs.
     */
    public static function preloadFinalGrades(iterable $submissions): void
    {
        $submissions = collect($submissions);

        if ($submissions->isEmpty()) {
            return;
        }

        $grades = FinalGrade::query()
            ->whereIn('project_id', $submissions->pluck('project_id')->unique())
            ->get();

        foreach ($submissions as $submission) {
            $match = $grades->first(fn (FinalGrade $g) => $g->project_id === $submission->project_id
                && $g->student_profile_id === $submission->student_profile_id
                && (string) $g->psm_part === (string) $submission->psm_part);

            $submission->relations['finalGrade'] = $match;
        }
    }

    /**
     * Every evaluation row allocated for this project and part.
     *
     * Deliberately *not* narrowed to this student: an evaluation is keyed by
     * (project, assessor) and there is no student column on it, so a filter
     * here would have to guess. The assessors that belong to this student are
     * the ones resolved by `expectedAssessors()`, and `forms()` applies that.
     */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'project_id', 'project_id')
            ->where('psm_part', $this->psm_part);
    }

    /**
     * The users whose forms this submission is waiting on.
     *
     * @return array{supervisor: int|null, examiners: array<int, int>}
     */
    public function expectedAssessors(): array
    {
        // A supervision links a student to a supervisor â€” there is no
        // project_id on the table. `forPart` matches this submission's part or
        // a BOTH-part supervision.
        //
        // Guard the part: this method is public and reachable from eager-load
        // closures, where the model may be a blank instance. Returning no
        // assessors fails closed â€” the readiness gate then reports outstanding
        // forms rather than silently passing.
        $supervision = $this->psm_part === null ? null : SupervisionAssignment::query()
            ->where('student_profile_id', $this->student_profile_id)
            ->forPart($this->psm_part)
            ->active()
            ->with('supervisorProfile')
            ->latest('id')
            ->first();

        $examiners = ExaminerAssignment::query()
            ->where('project_id', $this->project_id)
            // Scoped to this submission's part, matching resolvePanel() on the
            // service side. Without it the expectation is every examiner on the
            // project node rather than the panel for *this* batch: a project
            // carrying rows for both parts â€” which this data does â€” would
            // demand forms from the other batch's examiners, and readiness
            // would never clear.
            ->when($this->psm_part !== null, fn ($q) => $q->where('psm_part', $this->psm_part))
            ->active()
            ->pluck('examiner_id')
            ->all();

        return [
            'supervisor' => $supervision?->supervisorProfile?->user_id,
            'examiners'  => array_values(array_unique(array_map('intval', $examiners))),
        ];
    }

    /**
     * The forms actually attributable to this student.
     *
     * Filtered by assessor identity rather than by any student column, because
     * `evaluations.assessor_id` is a *user* id and `student_profile_id` is not â€”
     * comparing them directly would silently match the wrong person.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Evaluation>
     */
    public function forms()
    {
        $expected = $this->expectedAssessors();

        $assessorIds = array_values(array_filter(array_merge(
            [$expected['supervisor']],
            $expected['examiners']
        )));

        return $this->evaluations()
            ->whereIn('assessor_id', $assessorIds)
            ->with(['assessor', 'rubricTemplate', 'scores'])
            ->get();
    }

    /** The forms this submission is waiting on, keyed for the checklist. */
    private function formsWithDetail(): array
    {
        $forms = $this->forms()
            ->map(fn (Evaluation $e) => [
                'evaluation_id'  => $e->id,
                'assessor_id'    => $e->assessor_id,
                'assessor_name'  => $e->assessor?->name,
                'assessor_type'  => $e->assessor_type?->value,
                'assessor_label' => $e->assessor_type?->label(),
                'form_code'      => $e->rubricTemplate?->form_code,
                'status'         => $e->status?->value,
                'status_label'   => $e->status?->label(),
                'submitted_at'   => $e->submitted_at?->toIso8601String(),
                // The mark in the form's own units, plus its own maximum â€”
                // this is what the Lampiran prints. `score` is the percentage
                // and is kept for the readiness arithmetic, not for display.
                'raw_score'      => $e->raw_score !== null ? (float) $e->raw_score : null,
                'score'          => $e->score_percent !== null ? (float) $e->score_percent : null,
                'max_score'      => $e->max_score !== null ? (float) $e->max_score : null,
            ])
            ->sortBy([
                fn ($f) => $f['assessor_type'] === 'supervisor' ? 0 : 1,
                fn ($f) => $f['assessor_id'],
            ])
            ->values()
            ->all();

        return $forms;
    }

    // -----------------------------------------------------------------
    // Derived state
    // -----------------------------------------------------------------

    /**
     * Is every form this submission expects back?
     *
     * Read from the evaluation rows on every call rather than cached. The panel
     * can change after opening â€” an examiner can be stood down, or excused for
     * a conflict â€” and a stored 'ready' flag would keep demanding a form from
     * someone who is no longer on the panel.
     */
    public function isReadyToLock(): bool
    {
        return $this->readiness()['ready'];
    }

    /**
     * A per-form breakdown for the coordinator's checklist.
     *
     * `ready` is true only when the supervisor has submitted *and* the panel
     * has returned every expected form. `outstanding` names who is missing, so
     * the refusal can be specific instead of "cannot lock".
     *
     * @return array{
     *     ready: bool,
     *     expected_panel_size: int,
     *     returned_panel_size: int,
     *     outstanding: array<int, array<string, mixed>>,
     *     forms: array<int, array<string, mixed>>
     * }
     */
    public function readiness(): array
    {
        $forms = $this->formsWithDetail();

        $supervisorForms = array_values(array_filter(
            $forms,
            fn ($f) => $f['assessor_type'] === 'supervisor'
        ));

        $panelForms = array_values(array_filter(
            $forms,
            fn ($f) => $f['assessor_type'] === 'examiner'
        ));

        $outstanding = [];

        /**
         * Every supervisor form must come back, and there may be more than one.
         *
         * PSM 1 has a single supervisor form (E). PSM 2 has three â€” the main
         * evaluation (G) plus the two progress reports (H1, H2) â€” so a check
         * for "exactly one" would refuse every PSM 2 submission outright. What
         * actually matters is that each *allocated* form is returned; the
         * count is a consequence of that, not a rule in itself.
         *
         * A submission with no supervisor form at all is still a problem:
         * `open()` refuses to create one without an active supervision, so its
         * absence here means the rows were removed rather than never made.
         */
        if ($supervisorForms === []) {
            $outstanding[] = [
                'role'   => 'supervisor',
                'reason' => 'No supervisor form was allocated.',
            ];
        }

        foreach ($supervisorForms as $form) {
            if (in_array($form['status'], ['submitted', 'released'], true)) {
                continue;
            }

            $outstanding[] = [
                'role'      => 'supervisor',
                'assessor'  => $form['assessor_name'],
                'form_code' => $form['form_code'],
                'reason'    => $form['form_code'] === 'G'
                    ? 'Supervisor form is not submitted yet.'
                    : "Progress report {$form['form_code']} is not submitted yet.",
                'status'    => $form['status'],
            ];
        }

        $returnedPanel = 0;
        foreach ($panelForms as $form) {
            if (in_array($form['status'], ['submitted', 'released'], true)) {
                $returnedPanel++;

                continue;
            }

            $outstanding[] = [
                'role'      => 'examiner',
                'assessor'  => $form['assessor_name'],
                'form_code' => $form['form_code'],
                'reason'    => 'Panel form is not submitted yet.',
                'status'    => $form['status'],
            ];
        }

        // The panel must have come back whole, and must have been the size this
        // submission promised when it opened. A panel that shrank after opening
        // must be reopened by the coordinator, not quietly accepted.
        $expected = (int) $this->expected_panel_size;
        if ($returnedPanel < $expected) {
            $shortfall = $expected - $returnedPanel;
            $outstanding[] = [
                'role'   => 'examiner',
                'reason' => $shortfall === 1
                    ? 'One panel form is still outstanding.'
                    : "{$shortfall} panel forms are still outstanding.",
            ];
        }

        return [
            'ready'                => $outstanding === [],
            'expected_panel_size'  => $expected,
            'returned_panel_size'  => $returnedPanel,
            'outstanding'          => $outstanding,
            'forms'                => $forms,
        ];
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeLocked(Builder $query): Builder
    {
        return $query->where('status', MarkSubmissionStatus::Locked->value);
    }

    public function scopeAwaitingLock(Builder $query): Builder
    {
        return $query->where('status', MarkSubmissionStatus::Open->value);
    }

    public function scopeForSemesterPart(Builder $query, int|AcademicSemester|null $semester, string $psmPart): Builder
    {
        return $query->forSemester($semester)->where('psm_part', $psmPart);
    }

    public function scopeForSemester(Builder $query, int|AcademicSemester|null $semester): Builder
    {
        $id = $semester instanceof AcademicSemester
            ? $semester->id
            : AcademicSemester::resolveFilterId($semester);

        return $query->where('academic_semester_id', $id);
    }
}