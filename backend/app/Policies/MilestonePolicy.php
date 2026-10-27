<?php

namespace App\Policies;

use App\Enums\MilestoneStatus;
use App\Models\ExaminerAssignment;
use App\Models\Milestone;
use App\Models\User;

/**
 * Module 3 — Milestone visibility, submission and review rights.
 */
class MilestonePolicy
{
    public function view(User $actor, Milestone $milestone): bool
    {
        return app(ProjectPolicy::class)->view($actor, $milestone->project);
    }

    /**
     * Upload: the owning student only, and only while the milestone actually
     * accepts a submission (see Milestone::acceptsSubmission()).
     */
    public function submit(User $actor, Milestone $milestone): bool
    {
        return $this->ownsMilestone($actor, $milestone)
            && $milestone->acceptsSubmission();
    }

    /**
     * Withdrawing an attached file.
     *
     * Deliberately wider than `submit`. It covers the same window (the
     * milestone still accepts work) *and* the `submitted` window — handed in,
     * but not yet acted on by a reviewer.
     *
     * That second window is the common case: attaching the wrong file and
     * noticing immediately. Gating withdrawal behind `submit` alone meant a
     * student who had just submitted could not take the file back, and the
     * only remedy was to ask a supervisor to request a revision — a
     * conversation to fix a slip the student can already see. The
     * `Submitted → Rejected` transition in `destroyFile()` was written for
     * exactly this case, but was unreachable while the check was `submit`.
     *
     * Once the work is `reviewed` or `approved` the record is closed and a
     * revision request is the correct route.
     */
    public function withdraw(User $actor, Milestone $milestone): bool
    {
        if (! $this->ownsMilestone($actor, $milestone)) {
            return false;
        }

        return $milestone->acceptsSubmission()
            || $milestone->status === MilestoneStatus::Submitted;
    }

    /**
     * Is this actor the student whose project owns the milestone?
     *
     * Shared by `submit` and `withdraw` so the two can never disagree about
     * who owns the work.
     */
    protected function ownsMilestone(User $actor, Milestone $milestone): bool
    {
        if (! $actor->isStudent()) {
            return false;
        }

        $studentId = $actor->studentProfile?->id;

        if ($studentId === null) {
            return false;
        }

        return $milestone->project->members()
            ->where('student_profile_id', $studentId)
            ->exists();
    }

    /**
     * Review (approve / request revision): the student's own supervisor, or a
     * coordinator. An examiner does not approve milestones — they assess the
     * finished work through Module 4.
     *
     * The **proposal milestone** is the exception, and it is not reviewed here:
     * its verdict is the panel's and it settles the title, so it goes through
     * `decideTitle` instead. `MilestoneService::approve()` refuses it outright so
     * the generic path cannot quietly bypass the title decision.
     */
    public function review(User $actor, Milestone $milestone): bool
    {
        if ($actor->hasRole('admin')) {
            return true;
        }

        if ($actor->isCoordinator()) {
            return app(ProjectPolicy::class)->view($actor, $milestone->project);
        }

        $supervisorId = $actor->supervisorProfile?->id;

        if ($supervisorId === null) {
            return false;
        }

        return $milestone->project->members()
            ->whereHas('studentProfile.activeSupervisions', function ($q) use ($supervisorId) {
                $q->where('supervisor_profile_id', $supervisorId);
            })
            ->exists();
    }

    /**
     * Record a Lampiran R1 review round against a submission.
     *
     * The audience is the same as `review()` — the student's own supervisor, a
     * coordinator, or an administrator — because filling in the R1 *is* the
     * review: its checkboxes decide approve versus request-revision. Delegating
     * rather than repeating the supervisor lookup means the two can never drift
     * apart and let somebody review on one route but not the other.
     *
     * Two extra conditions beyond `review()`:
     *
     *   - the proposal milestone is excluded, because its verdict is the panel's
     *     (see `decideTitle`);
     *   - there must be a current submission to have reviewed. A verdict on a
     *     document that was never filed is not evidence of anything.
     */
    public function reviewR1(User $actor, Milestone $milestone): bool
    {
        if ($milestone->isProposal()) {
            return false;
        }

        // Lampiran R1 is the PSM 1 chapter-review form: Chapters 1-4, plus the
        // system demo. A PSM 2 chapter, the OSHE form, Lampiran C and the final
        // report are handled by other routes, so offering R1 here would record a
        // review on a form the faculty does not use for that milestone.
        if (! $milestone->usesR1()) {
            return false;
        }

        if (! $this->review($actor, $milestone)) {
            return false;
        }

        /**
         * The demo has nothing to submit.
         *
         * Every other R1 milestone reviews a document the student uploaded, so
         * "there is a current file" is the condition that stops a supervisor
         * reviewing an empty milestone. The system demo is a face-to-face
         * session — the student demonstrates, the supervisor fills the sheet —
         * so that condition would make the form permanently unavailable.
         */
        if ($milestone->code === Milestone::CODE_DEMO) {
            return true;
        }

        return $milestone->currentFiles()->count() > 0;
    }

    /**
     * Read the R1 history of a milestone.
     *
     * Wider than `reviewR1`: the people who *wrote* it can obviously read it,
     * and so can everybody who can already see the milestone — the student whose
     * chapter it is, in particular, who needs to read the reason they were sent
     * back. Reading the reviews is not authoring them.
     */
    public function viewReviews(User $actor, Milestone $milestone): bool
    {
        return $this->view($actor, $milestone);
    }

    /**
     * Record the panel's verdict on the proposal milestone — the title decision.
     *
     * The two examiners seated on the project's student, or a coordinator/admin
     * recording it on the panel's behalf. The supervisor is deliberately absent:
     * the title is the panel's call, which is the whole point of moving the
     * decision off the Lampiran A agreement and onto the proposal.
     */
    public function decideTitle(User $actor, Milestone $milestone): bool
    {
        if (! $milestone->isProposal()) {
            return false;
        }

        if ($actor->hasRole('admin', 'coordinator')) {
            return app(ProjectPolicy::class)->view($actor, $milestone->project);
        }

        return $this->isSeatedPanel($actor, $milestone);
    }

    /**
     * File Lampiran C — the corrections a conditional title approval required.
     * The owning student's own act.
     */
    /**
     * File Lampiran C.
     *
     * Two shapes, because the corrections step now has two homes:
     *
     *   - the **proposal** milestone, where a conditional approval used to be
     *     cleared by filing C against the proposal itself (the original path,
     *     kept so existing projects continue to work);
     *   - the **lampiran_c** milestone, created when a panel conditionally
     *     approves, which is where the digital form is filed now.
     *
     * In both cases it is the student who owns the project.
     */
    public function fileLampiranC(User $actor, Milestone $milestone): bool
    {
        if (! $milestone->isProposal() && ! $milestone->isLampiranC()) {
            return false;
        }

        return $this->ownsMilestone($actor, $milestone);
    }


    /**
     * Is this user one of the examiners seated on the project's student?
     *
     * Read from `examiner_assignments`, which is anchored on the student — the
     * panel is seated from Lampiran A onward, and Lampiran B stamps the project
     * onto those allocations.
     *
     * There is no role check because there is no examiner role: sitting on a
     * panel is a seating, so *any* academic-staff account can hold one. What
     * matters is the allocation, not who the person is.
     */
    protected function isSeatedPanel(User $actor, Milestone $milestone): bool
    {
        $studentId = $milestone->project?->leader()?->id;

        if ($studentId === null) {
            return false;
        }

        return ExaminerAssignment::query()
            ->forStudent($studentId)
            ->where('examiner_id', $actor->id)
            ->where('is_active', true)
            ->exists();
    }

    /** Downloading an attached file follows the same rule as viewing. */
    public function download(User $actor, Milestone $milestone): bool
    {
        return $this->view($actor, $milestone);
    }

    /**
     * Changing a deadline is the most contested administrative action, so it
     * is restricted to coordinators/admins even though supervisors can review.
     */
    public function changeDeadline(User $actor, Milestone $milestone): bool
    {
        if (! $actor->hasRole('admin', 'coordinator')) {
            return false;
        }

        return $actor->hasRole('admin')
            || app(ProjectPolicy::class)->view($actor, $milestone->project);
    }

    /** Adding an ad-hoc milestone to a project. */
    public function create(User $actor, Milestone $milestone): bool
    {
        return $this->changeDeadline($actor, $milestone);
    }

    public function delete(User $actor, Milestone $milestone): bool
    {
        return $actor->hasRole('admin', 'coordinator')
            && ! $milestone->submitted_at;
    }
}
