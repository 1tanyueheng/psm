<?php

namespace App\Services;

use App\Enums\MarkSubmissionStatus;
use App\Models\AcademicSemester;
use App\Models\ExaminerAssignment;
use App\Models\MarkSubmission;
use App\Models\Project;
use App\Models\StudentEnrolment;
use App\Models\SupervisionAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closing a semester: the one place a project gets its outcome.
 *
 * The faculty's rule is arithmetic, not committee. A project runs for its term,
 * and when that term closes its outcome is the mark: `final_mark >= 40` passed,
 * below that failed. There is no verdict to give, because the mark already is
 * the verdict Ã¢â‚¬â€ see the design spec at `thesis/PSM-LIFECYCLE-SPEC.md` Ã‚Â§1.
 *
 * This class therefore does three things together, and they must happen
 * together or the system ends up with a student who is `failed` but still
 * occupying a supervisor's slot:
 *
 *   1. **derive** every project's outcome from its mark;
 *   2. **release** the people attached to it Ã¢â‚¬â€ the supervision and the panel;
 *   3. **refuse** the close when the term is not ready, naming what is missing.
 *
 * The refusal is the important half. "All marks submitted" is a rule the
 * coordinator relies on, and a rule that silently does nothing when violated is
 * worse than no rule: the term closes, the unmarked students are never assessed,
 * and nobody finds out until results are queried.
 */
class TermCloseService
{
    /**
     * Why the term cannot close, or null when it can.
     *
     * Returns a single sentence naming what is outstanding, because a
     * coordinator reading it should know what to do next without opening
     * another screen.
     */
    public function closeBlockedReason(AcademicSemester $term): ?string
    {
        /**
         * Students enrolled with no project.
         *
         * A mark keys off a project, so a student with none can never be marked
         * Ã¢â‚¬â€ and the term would block forever with no explanation. The faculty's
         * position is that every student eventually gets an approved title, so
         * this list is normally empty; when it is not, the coordinator chases
         * the student or withdraws the enrolment.
         */
        $withoutProject = StudentEnrolment::query()
            ->forSemester($term->id)
            ->live()
            ->whereNull('project_id')
            ->with('studentProfile')
            ->get();

        if ($withoutProject->isNotEmpty()) {
            $names = $withoutProject
                ->map(fn (StudentEnrolment $e) => $e->studentProfile?->student_id)
                ->filter()
                ->take(5)
                ->implode(', ');

            return sprintf(
                '%d student(s) are enrolled for %s but have no registered project, so they cannot '
                .'be marked: %s%s. Withdraw their enrolment or wait for their registration.',
                $withoutProject->count(),
                $term->name,
                $names,
                $withoutProject->count() > 5 ? ', Ã¢â‚¬Â¦' : '',
            );
        }

        /**
         * Projects with no submitted mark.
         *
         * `submitted` and `locked` both count Ã¢â‚¬â€ the assessor has filed, whether
         * or not the coordinator has since locked the form.
         */
        $projects = Project::query()
            ->forSemester($term->id)
            ->active()
            ->pluck('id');

        $marked = MarkSubmission::query()
            ->whereIn('project_id', $projects)
            ->whereIn('status', [
                MarkSubmissionStatus::Locked->value,
                MarkSubmissionStatus::Released->value,
            ])
            ->pluck('project_id')
            ->unique();

        $unmarked = $projects->diff($marked);

        if ($unmarked->isNotEmpty()) {
            $codes = Project::query()
                ->whereIn('id', $unmarked->take(5))
                ->pluck('code')
                ->implode(', ');

            return sprintf(
                '%d project(s) in %s have no submitted mark: %s%s. Every assessor must file '
                .'before the term can close.',
                $unmarked->count(),
                $term->name,
                $codes,
                $unmarked->count() > 5 ? ', Ã¢â‚¬Â¦' : '',
            );
        }

        return null;
    }

    /**
     * Close the term and give every project its outcome.
     *
     * @throws \InvalidArgumentException when the term is not ready
     */
    public function close(AcademicSemester $term, User $actor): array
    {
        if ($reason = $this->closeBlockedReason($term)) {
            throw new \InvalidArgumentException($reason);
        }

        $passMark = (float) config('psm.pass_mark', 40);

        return DB::transaction(function () use ($term, $actor, $passMark) {
            $projects = Project::query()->forSemester($term->id)->active()->get();

            $passed = 0;
            $failed = 0;

            /**
             * Grades fetched once for the whole cohort.
             *
             * `finalGrade` is a computed accessor, not a relation â€” a grade is
             * keyed on (project, student, part) and carries no foreign key back
             * to the submission â€” so `with('finalGrade')` cannot eager-load it.
             * Reading it inside the loop would cost one query per project.
             */
            $submissions = MarkSubmission::query()
                ->whereIn('project_id', $projects->pluck('id'))
                ->whereIn('status', [
                    MarkSubmissionStatus::Locked->value,
                    MarkSubmissionStatus::Released->value,
                ])
                ->get();

            MarkSubmission::preloadFinalGrades($submissions);

            $byProject = $submissions->keyBy('project_id');

            foreach ($projects as $project) {
                $mark = $byProject->get($project->id)?->finalGrade?->final_mark;

                /**
                 * A project with a submitted form but no computed mark cannot be
                 * given an outcome. Refusing here would be wrong Ã¢â‚¬â€ the close has
                 * already been checked Ã¢â‚¬â€ so it is recorded as failed, which is
                 * what the faculty's "no mark means zero" rule says. The
                 * assessor was required to enter something; this is the
                 * consequence of not having done so.
                 */
                $finalMark = $mark === null ? 0.0 : (float) $mark;
                $outcome = $finalMark >= $passMark ? 'completed' : 'failed';

                $project->forceFill([
                    'status' => $outcome,
                    'metadata' => array_merge($project->metadata ?? [], [
                        'outcome'          => $outcome,
                        'outcome_mark'     => $finalMark,
                        'outcome_pass_mark'=> $passMark,
                        'resolved_at'      => now()->toIso8601String(),
                        'resolved_by'      => $actor->id,
                        'resolved_by_name' => $actor->name,
                        'resolved_by_term_close' => true,
                    ]),
                ])->save();

                $outcome === 'completed' ? $passed++ : $failed++;

                $this->closeSupervisions($project, $outcome);
                $this->releasePanel($project);
                $this->settleEnrolments($project, $outcome, $finalMark);
            }

            return [
                'projects'  => $projects->count(),
                'passed'    => $passed,
                'failed'    => $failed,
                'pass_mark' => $passMark,
            ];
        });
    }

    /**
     * End the supervision on the parts this project covers.
     *
     * A `BOTH` pairing spans PSM 1 and PSM 2, so closing one part must not
     * necessarily end it: a student who passed PSM 1 and continues to PSM 2 is
     * still supervised, and ending the pairing would leave the PSM 2 project
     * with nobody. The pairing is narrowed to whatever part is still running,
     * and ended only when none is.
     *
     * Reads `active()` Ã¢â‚¬â€ "not finished" Ã¢â‚¬â€ because at this point the project has
     * just been given an outcome, so it is no longer running.
     */
    protected function closeSupervisions(Project $project, string $outcome): void
    {
        $project->loadMissing('members');

        foreach ($project->members as $member) {
            $studentId = $member->student_profile_id;

            $stillRunning = Project::query()
                ->whereHas('members', fn ($q) => $q->where('student_profile_id', $studentId))
                ->active()
                ->whereKeyNot($project->id)
                ->pluck('psm_part')
                ->unique()
                ->all();

            $assignments = SupervisionAssignment::query()
                ->where('student_profile_id', $studentId)
                ->where('is_active', true)
                ->get();

            foreach ($assignments as $assignment) {
                $stillNeeded = collect($stillRunning)->contains(
                    fn (string $part) => $part === $assignment->psm_part || $assignment->psm_part === 'BOTH'
                );

                if ($stillNeeded) {
                    continue;
                }

                $assignment->forceFill([
                    'is_active'  => false,
                    'ended_at'   => now(),
                    'end_reason' => sprintf('%s closed as %s', $project->code, $outcome),
                ])->save();
            }
        }
    }

    /**
     * Release the examiners seated for this project's part.
     *
     * The panel exists to examine one part of one student's work. Once the term
     * closes there is nothing left to examine, and leaving the rows active would
     * keep examiners seated on a finished student for ever. Retired, never
     * deleted Ã¢â‚¬â€ an appeal months later asks who examined this.
     */
    protected function releasePanel(Project $project): void
    {
        ExaminerAssignment::query()
            ->where('project_id', $project->id)
            ->where('psm_part', $project->psm_part)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    /**
     * Settle the student's enrolment to match the project's outcome.
     *
     * The two records describe the same attempt from different angles, and
     * letting them disagree is how a finished student stays counted against
     * supervisor capacity. If there is no enrolment Ã¢â‚¬â€ a seeded or imported
     * project Ã¢â‚¬â€ there is nothing to settle.
     */
    protected function settleEnrolments(Project $project, string $outcome, float $finalMark): void
    {
        $enrolments = StudentEnrolment::where('project_id', $project->id)->get();

        foreach ($enrolments as $enrolment) {
            $enrolment->update([
                'status'     => $outcome === 'completed' ? 'completed' : 'failed',
                'settled_at' => now(),
                'note'       => sprintf('Term closed with a final mark of %.2f.', $finalMark),
            ]);
        }
    }
}
