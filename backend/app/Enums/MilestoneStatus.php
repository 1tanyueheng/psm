<?php

namespace App\Enums;

/**
 * Module 3 / 7 — Milestone lifecycle.
 *
 * pending ──► open ──► submitted ──► reviewed ──► approved
 *                          │             │
 *                          │             ├──► rejected ──► open (resubmit)
 *                          │             └──► conditional_approve ──► approved
 *                          └──► overdue (deadline passed with no submission)
 *
 * `conditional_approve` is the proposal milestone's third outcome: the title is
 * accepted *subject to corrections*, and the student owes the Lampiran C form
 * before the milestone is approved and the rest of the chain opens. It is
 * distinct from `rejected` on purpose — a rejection means the title itself is
 * wrong and the student changes it, whereas a conditional approval means the
 * title stands and the work around it needs fixing.
 */
enum MilestoneStatus: string
{
    case Pending     = 'pending';
    case Open        = 'open';
    case Submitted   = 'submitted';
    case Reviewed    = 'reviewed';
    case Approved    = 'approved';
    case Conditional = 'conditional_approve';
    case Rejected    = 'rejected';
    case Overdue     = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Pending     => 'Not started',
            self::Open        => 'Open for submission',
            self::Submitted   => 'Submitted',
            self::Reviewed    => 'Reviewed',
            self::Approved    => 'Approved',
            self::Conditional => 'Conditional approval',
            self::Rejected    => 'Revision required',
            self::Overdue     => 'Overdue',
        };
    }

    /** Tailwind-friendly colour token consumed by the React badge component. */
    public function tone(): string
    {
        return match ($this) {
            self::Pending     => 'slate',
            self::Open        => 'blue',
            self::Submitted   => 'amber',
            self::Reviewed    => 'violet',
            self::Approved    => 'emerald',
            self::Conditional => 'amber',
            self::Rejected    => 'rose',
            self::Overdue     => 'red',
        };
    }

    /**
     * Legal transitions. Any status change outside this map is rejected by
     * MilestoneService::transitionTo(), which keeps the audit log meaningful.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending     => [self::Open, self::Overdue],
            self::Open        => [self::Submitted, self::Overdue],
            self::Overdue     => [self::Submitted, self::Open],
            self::Submitted   => [self::Reviewed, self::Approved, self::Conditional, self::Rejected],
            self::Reviewed    => [self::Approved, self::Conditional, self::Rejected],
            // The conditional branch: Lampiran C clears it to Approved, or the
            // panel refuses the corrections and it falls back to Rejected.
            self::Conditional => [self::Approved, self::Rejected],
            self::Rejected    => [self::Open, self::Submitted],
            self::Approved    => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Statuses that let a student upload or replace a file. */
    public function isSubmittable(): bool
    {
        return in_array($this, [self::Open, self::Rejected, self::Overdue], true);
    }

    /**
     * Statuses counted as "work delivered" in Module 5 progress rollups.
     *
     * Deliberately excludes `Conditional`: this is also the gate
     * `MilestoneController::approve()` uses to answer "is there a submission to
     * approve?", and a conditionally-approved milestone has already been decided.
     */
    public function isProgressed(): bool
    {
        return in_array($this, [self::Submitted, self::Reviewed, self::Approved], true);
    }

    /**
     * Is this milestone no longer waiting on the student?
     *
     * Approved and conditionally-approved both mean the reviewer has acted, so
     * the deadline no longer applies and the milestone must not be flagged
     * overdue while the Lampiran C form is outstanding.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Approved, self::Conditional], true);
    }

    /**
     * How much of a milestone counts as complete, as a fraction of 1.
     *
     * This is the single definition of "progress" in the system. Both the
     * per-chapter figure a supervisor sees
     * (Milestone::completionPercent()) and the overall project figure
     * (Project::milestoneProgressPercent()) are derived from it, so the two
     * can never disagree — a chapter showing 75% must add exactly its
     * weighted 75% to the project total.
     */
    public function progressFactor(): float
    {
        return match ($this) {
            self::Approved  => 1.0,
            // Partial credit: submitted/reviewed work is underway but not yet
            // accepted, so it cannot be worth the full weight. A conditional
            // approval is accepted-in-principle but still owes Lampiran C, so it
            // sits alongside `reviewed` rather than at full credit.
            self::Reviewed,
            self::Conditional => 0.75,
            self::Submitted => 0.5,
            default          => 0.0,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Approved;
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s) => [$s->value => $s->label()])
            ->all();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
