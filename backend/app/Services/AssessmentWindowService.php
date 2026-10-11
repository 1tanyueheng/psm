<?php

namespace App\Services;

use App\Enums\EvaluationStatus;
use App\Models\AssessmentWindow;
use App\Models\Evaluation;
use App\Models\MarkSubmission;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 4 — the coordinator's assessment window.
 *
 * Opening a window is one action that does two things: it allocates every form
 * the batch needs, and it starts the clock. That is deliberate. The alternative
 * — unlock now, allocate later — leaves a window that says "open" while
 * assessors have nothing to mark, which is exactly the confusion this replaces.
 *
 * Allocation is delegated to MarkSubmissionService::open(), per student. That
 * method already resolves the supervisor and the panel, enforces the
 * conflict-of-interest rule, and creates forms idempotently, so a second open
 * cannot duplicate anything. Re-implementing it here would be a second answer
 * to "who marks this student".
 */
class AssessmentWindowService
{
    public function __construct(
        protected MarkSubmissionService $submissions,
    ) {
    }

    /**
     * Open the window: allocate the forms it governs, then start accepting
     * marks.
     *
     * A student who cannot be allocated — no supervisor, no panel — is reported
     * rather than aborting the run. One un-assignable student must not hold up
     * the whole batch; the coordinator fixes it and re-opens, which is safe
     * because allocation is idempotent.
     *
     * A form-scoped window allocates only its own Lampiran, so opening
     * "PSM 1 — Lampiran E" leaves Lampiran I exactly as it was. That is the
     * point of the finer scope: the two are marked at different times by
     * different people.
     *
     * @return array{allocated:int, failed:array<int, array{project:string, student:?string, reason:string}>}
     */
    public function open(AssessmentWindow $window, User $actor): array
    {
        $allocated = 0;
        $failed = [];

        DB::transaction(function () use ($window, $actor, &$allocated, &$failed) {
            $projects = $this->projects($window);

            /**
             * Resolve the batch's supervisors up front, in one query.
             *
             * The loop below asks for each student's supervisor, and doing that
             * lazily cost three round trips per student — a lookup for the
             * supervision, then the supervisor's profile, then their user. On a
             * hosted database at ~60ms per query that is most of a second per
             * student before any work happens, multiplied by the cohort.
             */
            $this->submissions->warmSupervisors($projects);

            foreach ($projects as $project) {
                foreach ($project->students as $student) {
                    try {
                        $this->submissions->open(
                            $project,
                            $student->id,
                            $actor,
                            $window->form_code,
                        );
                        $allocated++;
                    } catch (InvalidArgumentException $e) {
                        $failed[] = [
                            'project' => $project->code,
                            'student' => $student->student_id,
                            'reason'  => $e->getMessage(),
                        ];
                    }
                }
            }

            $window->update([
                'status'    => AssessmentWindow::STATUS_OPEN,
                'opened_at' => now(),
                'opened_by' => $actor->id,
                'closed_at' => null,
                'closed_by' => null,
            ]);
        });

        return ['allocated' => $allocated, 'failed' => $failed];
    }

    /**
     * Close the window. Marks already filed stay; nothing new may be filed.
     *
     * Deliberately does NOT lock or release: those stay on the per-student mark
     * submission, which is where the "all forms are in" attestation belongs.
     */
    public function close(AssessmentWindow $window, User $actor): AssessmentWindow
    {
        if ($window->isClosed()) {
            return $window;
        }

        $window->update([
            'status'    => AssessmentWindow::STATUS_CLOSED,
            'closed_at' => now(),
            'closed_by' => $actor->id,
        ]);

        return $window->fresh();
    }

    /**
     * How far along the batch is: forms expected, forms filed.
     *
     * Counted from the evaluations actually allocated, so it answers "can I
     * close this yet" rather than "how many students exist".
     *
     * A form-scoped window counts only its own Lampiran. Counting the whole
     * batch would report "2 of 4 filed" on a window that only ever asked for
     * one form, which reads as an unfinished job rather than a complete one.
     *
     * @return array{students:int, forms:int, filed:int, outstanding:int, percent:float}
     */
    public function progress(AssessmentWindow $window): array
    {
        $forms = $this->formsFor($window);

        $filed = $forms->filter(fn (Evaluation $e) => in_array(
            $e->status?->value,
            [EvaluationStatus::Submitted->value, EvaluationStatus::Released->value],
            true
        ))->count();

        $total = $forms->count();

        return [
            'students'    => $this->projects($window)->sum(fn (Project $p) => $p->students->count()),
            'forms'       => $total,
            'filed'       => $filed,
            'outstanding' => max(0, $total - $filed),
            'percent'     => $total > 0 ? round(($filed / $total) * 100, 1) : 0.0,
        ];
    }

    /**
     * Per-student rows for the coordinator: who has been marked, who has not.
     *
     * @return array<int, array{project_id:int, code:string, student:?string, name:?string, forms:array, filed:int, total:int}>
     */
    public function roster(AssessmentWindow $window): array
    {
        $rows = [];

        foreach ($this->projects($window) as $project) {
            $forms = $this->formsFor($window, $project->id);

            $student = $project->students->first();

            $rows[] = [
                'project_id' => $project->id,
                'code'       => $project->code,
                'student'    => $student?->student_id,
                'name'       => $student?->user?->name,
                'filed'      => $forms->filter(fn (Evaluation $e) => in_array(
                    $e->status?->value,
                    [EvaluationStatus::Submitted->value, EvaluationStatus::Released->value],
                    true
                ))->count(),
                'total'      => $forms->count(),
                'forms'      => $forms->map(fn (Evaluation $e) => [
                    'form_code' => $e->rubricTemplate?->form_code,
                    'status'    => $e->status?->value,
                ])->all(),
            ];
        }

        return $rows;
    }

    /**
     * The evaluations this window is responsible for.
     *
     * Always narrowed to the window's own Lampiran, because every window governs
     * exactly one. Counting the whole batch would report "2 of 4 filed" on a
     * window that only ever asked for one form, which reads as an unfinished job
     * rather than a complete one.
     *
     * The form lives on the rubric template rather than on the evaluation, so
     * the filter is a `whereHas` — the same join `MarkSubmission::forms()` reads
     * through.
     *
     * @return Collection<int, Evaluation>
     */
    protected function formsFor(AssessmentWindow $window, ?int $projectId = null): Collection
    {
        return Evaluation::query()
            ->whereIn('project_id', $projectId !== null
                ? [$projectId]
                : $this->projects($window)->pluck('id'))
            ->where('psm_part', $window->psm_part)
            ->whereHas('rubricTemplate', fn ($q) => $q->where('form_code', $window->form_code))
            ->with('rubricTemplate')
            ->get();
    }

    /**
     * The live students this window governs.
     *
     * Archived projects are excluded: a completed project is not being assessed
     * again, and allocating forms to one would create marks nothing reads.
     *
     * @return Collection<int, Project>
     */
    protected function projects(AssessmentWindow $window): Collection
    {
        return Project::query()
            ->where('psm_part', $window->psm_part)
            ->where('academic_semester_id', $window->academic_semester_id)
            ->whereNull('archived_at')
            ->with(['students.user'])
            ->orderBy('code')
            ->get();
    }
}
