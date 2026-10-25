<?php

namespace App\Http\Resources;

use App\Services\MilestoneReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 3 — one Lampiran R1 review round.
 *
 * Sent for every round so the screen can show the whole history: the student
 * needs to read why they were sent back a second time, and the supervisor needs
 * to see what they said last round before writing this one.
 *
 * The scores are returned as the form prints them — per item out of 5, plus the
 * totals in the same units — and are labelled as evidence, never as marks. The
 * `is_evidence_only` flag is here so the UI can say so out loud instead of
 * leaving a reader to assume an R1 score counts toward the grade.
 *
 * @mixin \App\Models\MilestoneReview
 */
class MilestoneReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'milestone_id' => $this->milestone_id,
            'round_no'     => $this->round_no,
            'revision_no'  => $this->revision_no,

            // ---- Bahagian B: what the supervisor declared ----------------
            'ack_received'    => (bool) $this->ack_received,
            'ack_reviewed'    => (bool) $this->ack_reviewed,
            'verdict'         => $this->verdict,
            'verdict_label'   => $this->verdictLabel(),
            'repeats'         => $this->repeats(),

            // ---- Who and when ------------------------------------------
            'reviewer' => [
                'id'   => $this->reviewer_id,
                'name' => $this->reviewer_name ?? $this->reviewer?->name,
                'role' => $this->reviewer_role,
            ],
            'reviewed_at' => $this->created_at?->toIso8601String(),

            // ---- Bahagian A, frozen at review time ---------------------
            'declaration' => $this->declaration,

            // ---- The 0-5 scores, per item ------------------------------
            /**
             * Keyed by the review item, each 0-5, exactly as printed. An item
             * that was left blank is absent rather than 0, because the form's
             * own scale uses 0 to mean "Tiada Data" — collapsing "not scored"
             * and "scored zero" would misreport the sheet.
             */
            'scores' => $this->scores ?? [],

            /** The item list with its printed labels, so the UI need not
             *  duplicate the faculty's wording. */
            'score_items' => collect(MilestoneReviewService::REVIEW_ITEMS)
                ->map(fn (array $item, string $key) => [
                    'key'     => $key,
                    'label'   => $item['label'],
                    'section' => $item['section'],
                    'score'   => $this->scores[$key] ?? null,
                ])
                ->values()
                ->all(),

            'total_score' => $this->total_score !== null ? (float) $this->total_score : null,
            'max_score'   => $this->max_score !== null ? (float) $this->max_score : null,

            'comments' => $this->comments,

            // ---- Signature ---------------------------------------------
            'signature' => $this->hasSignature() ? [
                'type'       => $this->signature_type,
                'mime_type'  => $this->signature_mime_type,
                'size_bytes' => $this->signature_size_bytes,
                'signed_at'  => $this->signed_at?->toIso8601String(),
                // The binary is fetched through an authorised route, never
                // exposed as a public URL: a signature is a person's mark and
                // must not be readable by anyone who guesses the path.
                'url' => $this->signature_path
                    ? route('milestones.reviews.signature', [
                        'milestone' => $this->milestone_id,
                        'review'    => $this->id,
                    ], false)
                    : null,
            ] : null,

            /**
             * Said explicitly so nobody later wires R1 scores into the grade.
             * The graded forms are E, G, H, I and J.
             */
            'is_evidence_only' => $this->isEvidenceOnly(),
        ];
    }
}
