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
 * Module 4 — one student's mark for one project in one PSM part, from the
 * moment a coordinator opens it to the moment it is locked.
 *
 * This is the record the flow the user described needs. The pieces already
 * existed — an `evaluations` row per assessor and a `final_grades` row per
 * student — but nothing joined them into a unit with a lifecycle, so "the mark
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

    public function finalGrade(): BelongsTo
    {
        return $this->belongsTo(FinalGrade::class);
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
        // A supervision links a student to a supervisor — there is no
        // project_id on the table. `forPart` matches this submission's part or
        // a BOTH-part supervision.
        //
        // Guard the part: this method is public and reachable from eager-load
        // closures, where the model may be a blank instance. Returning no
        // assessors fails closed — the readiness gate then reports outstanding
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
     * `evaluations.assessor_id` is a *user* id and `student_profile_id` is not —
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
                // The mark in the form's own units, plus its own maximum —
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
     * can change after opening — an examiner can be stood down, or excused for
     * a conflict — and a stored 'ready' flag would keep demanding a form from
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

        // Exactly one supervisor form is expected. Zero means the project has no
        // active supervision, which `open()` refuses; more than one would mean
        // a co-supervisor, and only one of them can attest for the batch.
        if (count($supervisorForms) !== 1) {
            $outstanding[] = [
                'role'     => 'supervisor',
                'reason'   => $supervisorForms === []
                    ? 'No supervisor form was allocated.'
                    : 'More than one supervisor form was allocated for this project.',
            ];
        } elseif ($supervisorForms[0]['status'] !== 'submitted'
            && $supervisorForms[0]['status'] !== 'released') {
            $outstanding[] = [
                'role'        => 'supervisor',
                'assessor'    => $supervisorForms[0]['assessor_name'],
                'form_code'   => $supervisorForms[0]['form_code'],
                'reason'      => 'Supervisor form is not submitted yet.',
                'status'      => $supervisorForms[0]['status'],
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