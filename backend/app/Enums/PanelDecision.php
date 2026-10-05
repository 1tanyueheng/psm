<?php

namespace App\Enums;

/**
 * The panel's verdict on a proposal.
 *
 * The panel reviews the candidate titles the student filed in Lampiran A and
 * records **one** decision, not one per examiner: the panel reads the proposal
 * together and the verdict is what the student is held to. This was the title
 * defence's decision sheet, moved onto the proposal itself — the examiners judged
 * the same titles either way, and doing it on the proposal removes the separate
 * event, the sitting to schedule and the roster to build.
 *
 *   Approved            the proposal stands; the student may file Lampiran B
 *   ConditionalApprove  accepted subject to corrections — Lampiran C has to be
 *                       filed and accepted before Lampiran B
 *   Rejected            the title is refused; the student takes another candidate
 *                       title from their Lampiran A to a re-review
 */
enum PanelDecision: string
{
    case Approved           = 'approved';
    case ConditionalApprove = 'conditional_approve';
    case Rejected           = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved           => 'Approved',
            self::ConditionalApprove => 'Conditional Approve',
            self::Rejected           => 'Rejected',
        };
    }

    /** Tailwind-friendly token consumed by the badge component. */
    public function tone(): string
    {
        return match ($this) {
            self::Approved           => 'emerald',
            self::ConditionalApprove => 'amber',
            self::Rejected           => 'rose',
        };
    }

    /**
     * Does this outcome require the panel to explain itself?
     *
     * A refusal or a condition with no stated reason leaves the student with
     * nothing to act on — the same reasoning as a revision request on a
     * milestone.
     */
    public function requiresReason(): bool
    {
        return $this !== self::Approved;
    }

    /** Does the student still owe a step before Lampiran B may be filed? */
    public function blocksApproval(): bool
    {
        return $this !== self::Approved;
    }

    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $decision) => [$decision->value => $decision->label()])
            ->all();
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
