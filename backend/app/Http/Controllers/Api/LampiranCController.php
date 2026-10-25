<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\MilestoneResource;
use App\Models\Milestone;
use App\Services\AuditLogger;
use App\Services\MilestoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Module 3 — Lampiran C as a two-party digital form.
 *
 * The corrections form a **conditional approval** obliges, in the shape the
 * faculty's paper form takes:
 *
 *   student   records the previous title, the new title, and one row per
 *             examiner comment with the correction taken against it
 *   supervisor reviews it and **signs** it
 *
 * The two halves are separate actions on purpose. Filing the form is the
 * student's statement; signing it is the supervisor's judgement. A student
 * cannot sign their own corrections, and a supervisor cannot edit what the
 * student declared — the record has to show which of them said what.
 *
 * What the signature *means* is decided by the caller, not by this controller:
 * `approve=true` signs and settles the milestone, `approve=false` records a
 * refusal with a reason and sends the form back. A supervisor who rejects does
 * not sign — there is nothing to attest to — which is why the signature is only
 * stored on the approving path.
 */
class LampiranCController extends ApiController
{
    public function __construct(
        private readonly MilestoneService $milestones,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/milestones/{milestone}/lampiran-c/form
     *
     * The student files the digital form. Allowed while the milestone is open or
     * was sent back, and refused once the supervisor has signed it.
     */
    public function file(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('fileLampiranC', $milestone);

        if (! $milestone->isLampiranC()) {
            return $this->fail('This endpoint files the Lampiran C corrections milestone only.', 422);
        }

        if ($milestone->hasSupervisorSignature()) {
            return $this->fail(
                'Your supervisor has already signed this form, so it can no longer be edited.',
                422
            );
        }

        $validated = $request->validate([
            'previous_title'          => ['required', 'string', 'max:255'],
            'new_title'               => ['required', 'string', 'max:255'],
            'actions'                 => ['nullable', 'array', 'max:20'],
            'actions.*.comment'       => ['nullable', 'string', 'max:1000'],
            'actions.*.action'        => ['nullable', 'string', 'max:1000'],

            // Bahagian A — typed on the form, not held on the student profile.
            'ic_number'               => ['nullable', 'string', 'max:32'],
            'passport_number'         => ['nullable', 'string', 'max:32'],
            'mobile_number'           => ['nullable', 'string', 'max:32'],
            'defense_date'            => ['nullable', 'date'],

            // Bahagian C — the student's own signature.
            'signature'               => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'signature_type'          => ['nullable', 'in:drawn,uploaded'],
        ]);

        // A corrections form with no corrections recorded is not a correction.
        $actions = collect($validated['actions'] ?? [])
            ->filter(fn (array $row) => filled($row['comment'] ?? null) || filled($row['action'] ?? null))
            ->values()
            ->all();

        if ($actions === []) {
            return $this->fail(
                'Record at least one examiner comment and the correction you made against it.',
                422
            );
        }

        $disk = config('filesystems.default', 'local');
        $signaturePath = null;

        if ($request->hasFile('signature')) {
            $signaturePath = $request->file('signature')
                ->store("lampiran-c/{$milestone->project_id}/student", $disk);
        }

        try {
            $milestone = $this->milestones->transitionTo(
                $milestone,
                MilestoneStatus::Submitted,
                $request->user(),
                'Lampiran C filed — awaiting the supervisor\'s signature.',
            );

            $milestone->forceFill([
                'lampiran_c_previous_title'  => $validated['previous_title'],
                'lampiran_c_new_title'       => $validated['new_title'],
                'lampiran_c_actions'         => $actions,
                'lampiran_c_filed_at'        => now(),
                'lampiran_c_filed_by'        => $request->user()->id,
                'lampiran_c_ic_number'       => $validated['ic_number'] ?? null,
                'lampiran_c_passport_number' => $validated['passport_number'] ?? null,
                'lampiran_c_mobile_number'   => $validated['mobile_number'] ?? null,
                'lampiran_c_defense_date'    => $validated['defense_date'] ?? null,
                'student_signature_path'     => $signaturePath,
                'student_signature_disk'     => $signaturePath ? $disk : null,
                'student_signature_type'     => $signaturePath
                    ? ($validated['signature_type'] ?? 'uploaded')
                    : null,
                'student_signed_at'          => $signaturePath ? now() : null,
            ])->save();
        } catch (InvalidArgumentException $e) {
            if ($signaturePath) {
                Storage::disk($disk)->delete($signaturePath);
            }

            return $this->fail($e->getMessage(), 422);
        }

        $this->audit->log(
            action: AuditAction::MilestoneSubmitted,
            description: "Lampiran C filed — title corrected to \"{$validated['new_title']}\"",
            subject: $milestone,
        );

        return $this->ok(
            new MilestoneResource($milestone->fresh()->load('currentFiles', 'project')),
            'Lampiran C filed — your supervisor will review and sign it.'
        );
    }

    /**
     * POST /api/milestones/{milestone}/lampiran-c/sign
     *
     * The supervisor's verdict on the corrections.
     *
     *   approve=true   the signature image is stored and the milestone settles
     *   approve=false  a reason is required and the form goes back; no signature
     *                  is stored, because a refusal is not an attestation
     */
    public function sign(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('review', $milestone);

        if (! $milestone->isLampiranC()) {
            return $this->fail('This endpoint signs the Lampiran C corrections milestone only.', 422);
        }

        if ($milestone->lampiran_c_filed_at === null) {
            return $this->fail('The student has not filed Lampiran C yet.', 422);
        }

        if ($milestone->hasSupervisorSignature()) {
            return $this->fail('This Lampiran C has already been signed.', 422);
        }

        $validated = $request->validate([
            'approve'   => ['required', 'boolean'],
            'signature' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'comment'   => ['nullable', 'string', 'max:2000'],
        ]);

        $approve = (bool) $validated['approve'];

        if (! $approve) {
            $reason = trim((string) ($validated['comment'] ?? ''));

            if ($reason === '') {
                return $this->fail(
                    'Say what still needs correcting — the student cannot act on a refusal with no reason.',
                    422
                );
            }

            try {
                $milestone = $this->milestones->requestRevision($milestone, $request->user(), $reason);
            } catch (InvalidArgumentException $e) {
                return $this->fail($e->getMessage(), 422);
            }

            $this->audit->log(
                action: AuditAction::MilestoneRejected,
                description: "Lampiran C returned for correction: {$reason}",
                subject: $milestone,
            );

            return $this->ok(
                new MilestoneResource($milestone->fresh()->load('currentFiles', 'project')),
                'Lampiran C returned to the student.'
            );
        }

        // Approving: the signature is what makes the approval an attestation.
        $path = null;
        $disk = config('filesystems.default', 'local');

        if ($request->hasFile('signature')) {
            $path = $request->file('signature')->store("lampiran-c/{$milestone->project_id}", $disk);
        }

        try {
            $milestone = $this->milestones->approve(
                $milestone,
                $request->user(),
                $validated['comment'] ?? 'Lampiran C signed — the corrected title is confirmed.',
            );

            $milestone->forceFill([
                'supervisor_signature_path' => $path,
                'supervisor_signature_disk' => $path ? $disk : null,
                'supervisor_signature_type' => $path
                    ? ($request->input('signature_type') === 'drawn' ? 'drawn' : 'uploaded')
                    : null,
                'supervisor_signed_at'      => now(),
                'supervisor_signed_by'      => $request->user()->id,
                'lampiran_c_title'          => $milestone->lampiran_c_new_title,
                'lampiran_c_at'             => now(),
                'lampiran_c_by'             => $request->user()->id,
            ])->save();

            // The corrected title becomes the project's title: the panel approved
            // the corrections, so this is the title the project now carries.
            if (filled($milestone->lampiran_c_new_title)) {
                $milestone->project->forceFill(['title' => $milestone->lampiran_c_new_title])->save();
            }

            /**
             * Signing the corrections settles the **proposal** too.
             *
             * A conditional approval left the proposal in `conditional_approve`,
             * which is a waiting state: nothing downstream may proceed while a
             * correction is outstanding. The signature is what clears it, so the
             * proposal moves to `approved` here — otherwise the chain would stall
             * on a proposal whose corrections were already accepted.
             *
             * Guarded on the status because a project may reach its C milestone
             * through an older path where the proposal was already approved.
             */
            $proposal = $milestone->project->milestones()
                ->where('code', Milestone::CODE_PROPOSAL)
                ->first();

            if ($proposal && $proposal->status === MilestoneStatus::Conditional) {
                $this->milestones->transitionTo(
                    $proposal,
                    MilestoneStatus::Approved,
                    $request->user(),
                    'Lampiran C signed — the conditional approval is satisfied.',
                );
            }
        } catch (InvalidArgumentException $e) {
            if ($path) {
                Storage::disk($disk)->delete($path);
            }

            return $this->fail($e->getMessage(), 422);
        }

        $this->audit->log(
            action: AuditAction::MilestoneApproved,
            description: "Lampiran C signed — title confirmed as \"{$milestone->lampiran_c_new_title}\"",
            subject: $milestone,
        );

        return $this->ok(
            new MilestoneResource($milestone->fresh()->load('currentFiles', 'project')),
            'Lampiran C signed — the corrected title is now confirmed.'
        );
    }
}
