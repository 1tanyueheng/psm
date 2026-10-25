<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\ApiController;
use App\Http\Resources\MilestoneReviewResource;
use App\Models\Milestone;
use App\Models\MilestoneReview;
use App\Services\AuditLogger;
use App\Services\MilestoneReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Module 3 — Lampiran R1 review rounds.
 *
 * Three endpoints:
 *
 *   GET  /api/milestones/{milestone}/reviews                 the round history
 *   POST /api/milestones/{milestone}/reviews                 record a review
 *   POST /api/milestones/{milestone}/reviews/{review}/signature   attach the image
 *   GET  /api/milestones/{milestone}/reviews/{review}/signature   read it back
 *
 * The history is readable by anyone who can see the milestone — the student
 * included, who needs the reason they were sent back. Recording and signing are
 * restricted to whoever may review the milestone, which is the supervisor who
 * owns it, a coordinator, or an administrator.
 */
class MilestoneReviewController extends ApiController
{
    public function __construct(
        private readonly MilestoneReviewService $reviews,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/milestones/{milestone}/reviews
     *
     * The whole history, newest first. A chapter returned twice then accepted
     * yields three rows.
     */
    public function index(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('viewReviews', $milestone);

        $rounds = $milestone->reviews()->with('reviewer:id,name')->get();

        return $this->ok(
            MilestoneReviewResource::collection($rounds),
            $rounds->isEmpty()
                ? 'No R1 review has been recorded for this milestone yet.'
                : "{$rounds->count()} R1 review round(s) recorded."
        );
    }

    /**
     * POST /api/milestones/{milestone}/reviews
     *
     * Record the supervisor's response. The two acknowledgement boxes are the
     * verdict:
     *
     *   ack_received only          -> the milestone is approved and settles
     *   ack_reviewed (and/or both) -> improvement, the student revises and the
     *                                 next round starts (no cap)
     *   neither                    -> refused
     *
     * `declaration` is the student's Bahagian A, echoed back so the review
     * records what was actually reviewed rather than what the student's profile
     * says today.
     */
    public function store(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('reviewR1', $milestone);

        $validated = $request->validate([
            'ack_received' => ['nullable', 'boolean'],
            'ack_reviewed' => ['nullable', 'boolean'],

            'scores'               => ['nullable', 'array'],
            'scores.*'             => ['nullable', 'numeric', 'min:0', 'max:5'],

            'comments'     => ['nullable', 'string', 'max:4000'],
            'declaration'  => ['nullable', 'array'],
            'reviewer_name' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $review = $this->reviews->record($milestone, $request->user(), $validated);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $this->audit->log(
            action: $review->isApproved()
                ? AuditAction::MilestoneApproved
                : AuditAction::MilestoneRejected,
            description: $review->isApproved()
                ? "R1 round {$review->round_no} — received, {$milestone->title} settled"
                : "R1 round {$review->round_no} — improvement requested on {$milestone->title}",
            subject: $milestone,
        );

        return $this->ok(
            new MilestoneReviewResource($review->load('reviewer:id,name')),
            $review->isApproved()
                ? 'Review recorded — the milestone is settled.'
                : 'Review recorded — the student has been asked to revise.',
            201
        );
    }

    /**
     * POST /api/milestones/{milestone}/reviews/{review}/signature
     *
     * Attach the supervisor's signature image, either drawn on a canvas (sent as
     * an image blob) or uploaded as a photo of a physical signature.
     *
     * Optional by design: the acknowledgement already recorded is the evidence.
     * A supervisor on a device without a pointer must not be blocked from
     * reviewing, so nothing here is required — but whatever arrives is kept
     * privately and attached to the round it belongs to.
     */
    public function storeSignature(Request $request, Milestone $milestone, MilestoneReview $review): JsonResponse
    {
        $this->authorize('reviewR1', $milestone);

        // The review must belong to the milestone in the URL, or a signed
        // supervisor could attach their signature to somebody else's round.
        abort_unless($review->milestone_id === $milestone->id, 404);

        $validated = $request->validate([
            'signature' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'type'      => ['nullable', 'in:drawn,uploaded'],
        ]);

        $file = $validated['signature'];
        $type = $validated['type'] ?? 'uploaded';

        // A dated folder per milestone keeps the private disk browsable when
        // somebody has to answer an appeal by hand.
        $path = $file->store("signatures/milestones/{$milestone->id}", 'local');

        $review = $this->reviews->attachSignature(
            $review,
            disk: 'local',
            path: $path,
            type: $type,
            mimeType: $file->getClientMimeType(),
            sizeBytes: $file->getSize(),
        );

        $this->audit->log(
            action: AuditAction::FileUploaded,
            description: "R1 signature attached to round {$review->round_no} ({$type})",
            subject: $milestone,
        );

        return $this->ok(
            new MilestoneReviewResource($review->load('reviewer:id,name')),
            'Signature attached.'
        );
    }

    /**
     * GET /api/milestones/{milestone}/reviews/{review}/signature
     *
     * Stream the signature back. Authorised through the milestone, never served
     * as a public URL — a signature is a person's mark.
     */
    public function showSignature(Milestone $milestone, MilestoneReview $review): StreamedResponse|JsonResponse
    {
        $this->authorize('viewReviews', $milestone);

        abort_unless($review->milestone_id === $milestone->id, 404);

        if (! $review->hasSignature()) {
            return $this->fail('No signature was attached to this review.', 404);
        }

        $disk = Storage::disk($review->signature_disk ?? 'local');

        if (! $disk->exists($review->signature_path)) {
            return $this->fail('The stored signature could not be found.', 404);
        }

        $extension = pathinfo($review->signature_path, PATHINFO_EXTENSION) ?: 'png';

        return $disk->response($review->signature_path, "r1-signature-round-{$review->round_no}.{$extension}");
    }
}
