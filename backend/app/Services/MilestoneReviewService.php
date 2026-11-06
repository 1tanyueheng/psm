<?php

namespace App\Services;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\MilestoneReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 3 — Lampiran R1 review.
 *
 * The supervisor responds to a chapter submission on the official R1 form. That
 * response is *the* review: its two checkboxes are the endorsement, and its
 * scores and signature are the evidence that the review happened. There is no
 * second, parallel state machine — an approved round calls
 * `MilestoneService::approve()`, so the chain advances exactly as it did before,
 * with an R1 row recorded alongside each move.
 *
 * **One form per chapter, both boxes ticked.** The faculty files R1 once; the
 * supervisor endorses by ticking both declarations. The form therefore has
 * exactly one outcome — the milestone settles — and the improvement branch below
 * is reachable only from the separate review route, never from R1.
 *
 * Rules this service enforces:
 *
 *   - **Both acknowledgement boxes must be ticked.** Half an R1 is an
 *     endorsement nobody signed: the screen shows a red prompt, and this service
 *     refuses the record with the same wording.
 *   - **At most one round.** A second filing for the same milestone is refused
 *     rather than appended, because the form is submitted once.
 *   - No date lock. Chapter milestones are always open — the due date is
 *     advisory and `acceptsSubmission()` is consulted for *submitting*, not for
 *     reviewing. A supervisor reviewing late is not refused here.
 *   - The scores are evidential only. Nothing in this service touches a grade;
 *     see `MilestoneReview::isEvidenceOnly()`.
 */
class MilestoneReviewService
{
    /**
     * The review items Lampiran R1 prints, in order, with the section each
     * belongs to. Kept here rather than in the controller so the score keys the
     * backend accepts are the same ones the screen renders and the same ones the
     * printed sheet shows.
     */
    public const REVIEW_ITEMS = [
        // "KUALITI ITEM — ... SEMAKAN BAB DALAM LAPORAN ATAU PROSIDING SAHAJA"
        'report_content'      => ['label' => 'Kandungan (Content)',              'section' => 'report'],
        'report_format'       => ['label' => 'Format Laporan (Writing Format)',  'section' => 'report'],

        // "KUALITI ITEM — ... SEMAKAN PROTOTAIP SISTEM ATAU KERANGKA ..."
        'prototype_analysis'  => ['label' => 'Analisis & Spesifikasi / Alat, Data, Kajian Kes', 'section' => 'prototype'],
        'prototype_ui'        => ['label' => 'Antara Muka Pengguna & Kefungsian / Teknik, Metrik Prestasi', 'section' => 'prototype'],
    ];

    public function __construct(private readonly MilestoneService $milestones) {}

    /**
     * Record the R1 review and move the milestone to approved.
     *
     * @param  array{ack_received?:bool,ack_reviewed?:bool,scores?:array<string,int|float|null>,
     *               comments?:?string,declaration?:?array,reviewer_name?:?string}  $data
     */
    public function record(Milestone $milestone, User $actor, array $data): MilestoneReview
    {
        $ackReceived = (bool) ($data['ack_received'] ?? false);
        $ackReviewed = (bool) ($data['ack_reviewed'] ?? false);

        $verdict = MilestoneReview::verdictFor($ackReceived, $ackReviewed);

        if ($verdict === null) {
            throw new InvalidArgumentException(
                'Tick both acknowledgements: "received ... for review" and '
                .'"reviewed ... for improvement". Lampiran R1 is filed once, and '
                .'both boxes together are the endorsement.'
            );
        }

        $comments = trim((string) ($data['comments'] ?? ''));

        // The proposal milestone is the panel's to decide; its verdict is the
        // title decision and must go through ProposalReviewService. Refused here
        // for the same reason MilestoneService::approve() refuses it.
        if ($milestone->isProposal()) {
            throw new InvalidArgumentException(
                'The proposal milestone is decided by the seated panel, not by an R1 review.'
            );
        }

        // Lampiran R1 covers PSM 1's Chapters 1-4 only — see Milestone::R1_CODES.
        // Enforced here as well as in the policy because the service is the last
        // line: a future route that forgot the policy check must still not record
        // an R1 against a milestone the form does not cover.
        if (! $milestone->usesR1()) {
            throw new InvalidArgumentException(
                "Lampiran R1 applies to Chapters 1-4 of PSM 1 only, not to '{$milestone->code}'."
            );
        }

        // One form per chapter. The faculty files R1 once and endorses once, so
        // a second filing is a correction to a record that is already settled,
        // not a new round — and `round_no` would otherwise manufacture a history
        // the faculty does not keep.
        if ($milestone->reviews()->exists()) {
            throw new InvalidArgumentException(
                'Lampiran R1 has already been filed for this milestone — the form is submitted once.'
            );
        }

        // A review answers a submission. Without one there is nothing to have
        // reviewed, and the R1 would be a verdict on a document that does not
        // exist — except for the system demo, which is a face-to-face session
        // with nothing to upload. See Milestone::CODE_DEMO.
        if ($milestone->code !== Milestone::CODE_DEMO
            && $milestone->currentFiles()->count() === 0) {
            throw new InvalidArgumentException(
                'There is nothing to review yet — the student has not submitted this milestone.'
            );
        }

        $scores = $this->normaliseScores($data['scores'] ?? []);
        [$total, $max] = $this->totals($scores);

        return DB::transaction(function () use (
            $milestone, $actor, $verdict, $ackReceived, $ackReviewed,
            $scores, $total, $max, $comments, $data
        ) {
            $review = MilestoneReview::create([
                'milestone_id'    => $milestone->id,
                'round_no'        => $milestone->nextReviewRound(),
                'revision_no'     => (int) ($milestone->currentFiles()->max('revision_no') ?: 1),
                'reviewer_id'     => $actor->id,
                'reviewer_name'   => $data['reviewer_name'] ?? $actor->name,
                'reviewer_role'   => $actor->role?->value,
                'ack_received'    => $ackReceived,
                'ack_reviewed'    => $ackReviewed,
                'verdict'         => $verdict,
                // The student's Bahagian A, frozen into the review so the record
                // says what was reviewed rather than what the student says today.
                'declaration'     => $data['declaration'] ?? null,
                'scores'          => $scores ?: null,
                'total_score'     => $total,
                'max_score'       => $max,
                'comments'        => $comments !== '' ? $comments : null,
            ]);

            // Drive the existing state machine rather than a parallel one.
            if ($verdict === MilestoneReview::VERDICT_APPROVED) {
                /**
                 * The demo has no submission, so it never reaches `submitted` on
                 * its own.
                 *
                 * The demonstration *is* the delivery, so the service walks the
                 * same path a submitted chapter would: `pending -> open ->
                 * submitted`. That keeps the transition table honest (widening it
                 * would let any pending milestone be approved without ever
                 * opening) while leaving the demo with the trail a reader
                 * expects — it opened, it was delivered, it was endorsed.
                 */
                if ($milestone->status === MilestoneStatus::Pending) {
                    $this->milestones->transitionTo(
                        $milestone,
                        MilestoneStatus::Open,
                        $actor,
                        'Demo session held — the milestone opened for its review.',
                    );
                    $milestone = $milestone->fresh();
                }

                if ($milestone->status === MilestoneStatus::Open) {
                    $this->milestones->transitionTo(
                        $milestone,
                        MilestoneStatus::Submitted,
                        $actor,
                        'System demonstrated in person — nothing to upload.',
                    );
                    $milestone = $milestone->fresh();
                }

                /**
                 * **`Submitted` has no direct path to `Approved`.**
                 *
                 * `MilestoneStatus::allowedTransitions()` is explicit about it:
                 * `Reviewed` is the endorsement, and it is the panel's gate — a
                 * panel must not rule on work its supervisor never read. Filling
                 * in Lampiran R1 *is* that endorsement, so the service walks the
                 * chapter through `Reviewed` and then approves it.
                 *
                 * Calling `approve()` on a `submitted` milestone threw "Cannot
                 * move milestone [chapter_1] from 'submitted' to 'approved'" and
                 * rolled the whole transaction back, so **no R1 could be filed
                 * for any submitted chapter** — the form only ever worked for the
                 * demo, which arrives here from `open`. The seeder hid it by
                 * skipping illegal hops quietly.
                 */
                if ($milestone->status === MilestoneStatus::Submitted) {
                    $this->milestones->transitionTo(
                        $milestone,
                        MilestoneStatus::Reviewed,
                        $actor,
                        'Endorsed on Lampiran R1.',
                    );
                    $milestone = $milestone->fresh();
                }

                $this->milestones->approve($milestone, $actor, $comments !== '' ? $comments : null);
            } elseif ($comments !== '') {
                // Only reachable from the separate review route: the R1 form's
                // both-ticked endorsement always approves, so this branch is dead
                // for R1. Kept so a send-back recorded through that route still
                // moves the milestone rather than silently doing nothing.
                $this->milestones->requestRevision($milestone, $actor, $comments);
            }

            return $review->fresh();
        });
    }

    /**
     * Attach a signature image to an existing round.
     *
     * Separate from `record()` because the signature is optional and arrives
     * either from a canvas or from an upload: recording the verdict must not
     * depend on the transport that carries the picture.
     */
    public function attachSignature(
        MilestoneReview $review,
        string $disk,
        string $path,
        string $type,
        ?string $mimeType = null,
        ?int $sizeBytes = null,
    ): MilestoneReview {
        if (! in_array($type, ['drawn', 'uploaded'], true)) {
            throw new InvalidArgumentException('Signature type must be "drawn" or "uploaded".');
        }

        $review->update([
            'signature_disk'       => $disk,
            'signature_path'       => $path,
            'signature_type'       => $type,
            'signature_mime_type'  => $mimeType,
            'signature_size_bytes' => $sizeBytes,
            'signed_at'            => now(),
        ]);

        return $review->fresh();
    }

    /**
     * Keep only the review items Lampiran R1 prints, and bound each to 0-5.
     *
     * Unknown keys are dropped rather than rejected: the form gains and loses
     * items as the faculty revises it, and a stale client sending an item that
     * no longer exists should not fail the whole review.
     */
    private function normaliseScores(array $raw): array
    {
        $clean = [];

        foreach (array_keys(self::REVIEW_ITEMS) as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }

            $value = $raw[$key];

            // A blank answer is not a zero. Leaving it out keeps "not scored"
            // distinguishable from "scored 0", which the printed scale needs:
            // 0 is "Tiada Data", not "very poor".
            if ($value === null || $value === '') {
                continue;
            }

            $number = (float) $value;

            if ($number < 0 || $number > 5) {
                throw new InvalidArgumentException(
                    "Score for [{$key}] must be between 0 and 5."
                );
            }

            $clean[$key] = $number;
        }

        return $clean;
    }

    /**
     * Totals in the form's own units.
     *
     * The R1 sheet scores each item out of 5 and prints no percentage, so the
     * totals are "sum of items / 5 x number of items scored". Null when nothing
     * was scored, so the record can say "no scores" rather than "0 out of 0".
     */
    private function totals(array $scores): array
    {
        if ($scores === []) {
            return [null, null];
        }

        return [
            round(array_sum($scores), 2),
            round(count($scores) * 5, 2),
        ];
    }

    // -----------------------------------------------------------------
    // Reading the history
    // -----------------------------------------------------------------

    /** Every round for a milestone, newest first. */
    public function history(Milestone $milestone): \Illuminate\Support\Collection
    {
        return $milestone->reviews()->with('reviewer:id,name')->get();
    }

    /**
     * How many times the milestone has been sent back.
     *
     * The count a report quotes — "chapter 2 was returned twice" — read from
     * stored rows rather than inferred from the event timeline. Always 0 for a
     * milestone reviewed on the R1 form, since both-ticked means approved.
     */
    public function improvementCount(Milestone $milestone): int
    {
        return $milestone->reviews()
            ->where('verdict', MilestoneReview::VERDICT_IMPROVEMENT)
            ->count();
    }

    /** Has Lampiran R1 already been filed for this milestone? */
    public function hasBeenFiled(Milestone $milestone): bool
    {
        return $milestone->reviews()->exists();
    }

    /** Can this milestone take an R1 review right now? */
    public function canReview(Milestone $milestone): bool
    {
        if ($milestone->isProposal() || ! $milestone->usesR1()) {
            return false;
        }

        // The form is filed once. Once a row exists the milestone is settled and
        // there is nothing left to endorse, so the screen offers the history
        // instead of the inputs.
        if ($this->hasBeenFiled($milestone)) {
            return false;
        }

        // The status the chain must be in for a review to mean anything: the
        // student has submitted and the supervisor has not yet settled it.
        //
        // The **demo** is reviewed from `pending` as well: it has no submission
        // to wait for, so nothing ever moves it to `open`, and requiring a
        // submitted state would mean the sheet could only be filled after the
        // session it is meant to record.
        $reviewable = [
            MilestoneStatus::Submitted,
            MilestoneStatus::Reviewed,
            MilestoneStatus::Rejected,
            MilestoneStatus::Overdue,
            MilestoneStatus::Open,
        ];

        if ($milestone->code === Milestone::CODE_DEMO) {
            $reviewable[] = MilestoneStatus::Pending;
        }

        if (! in_array($milestone->status, $reviewable, true)) {
            return false;
        }

        // The demo is reviewed with no submission — see Milestone::CODE_DEMO.
        if ($milestone->code === Milestone::CODE_DEMO) {
            return true;
        }

        return $milestone->currentFiles()->count() > 0;
    }
}
