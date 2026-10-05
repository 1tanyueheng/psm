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
    public function fileLampiranC(User $actor, Milestone $milestone): bool
    {
        return $milestone->isProposal() && $this->ownsMilestone($actor, $milestone);
    }

    /** Change the project title after the panel refused it. The student's act. */
    public function changeTitle(User $actor, Milestone $milestone): bool
    {
        return $milestone->isProposal() && $this->ownsMilestone($actor, $milestone);
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
