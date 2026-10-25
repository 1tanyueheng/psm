<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Http\Controllers\ApiController;
use App\Http\Resources\MilestoneResource;
use App\Models\Milestone;
use App\Models\SubmissionFile;
use App\Models\SubmissionEvent;
use App\Services\AuditLogger;
use App\Services\MilestoneService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Module 3 — the OSHE form's second signature.
 *
 * The OSHE (occupational safety, health and environment) form is the one
 * milestone the *supervisor* completes: the student fills it in and uploads it,
 * the supervisor signs it and uploads the signed copy back. Without a signed
 * copy the student cannot be let into a lab, so "submitted" is not enough — the
 * milestone only settles when the signed copy arrives.
 *
 * Modelled as a **second file revision by the supervisor**, not a second
 * milestone and not a separate table:
 *
 *   - the milestone already owns a revision history (`submission_files`), and
 *     "the student's copy, then the signed copy" is exactly that;
 *   - `is_current` then answers "which one is in force" with no new concept;
 *   - the student's original stays on the record, so the pair (filled, signed)
 *     is always both retrievable — an appeal can see what was signed and what it
 *     was signed *over*.
 *
 * The role check is `MilestonePolicy::review()`: the student's own supervisor, a
 * coordinator, or an administrator.
 */
class OsheController extends ApiController
{
    public function __construct(
        private readonly MilestoneService $milestones,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /api/milestones/{milestone}/oshe/sign
     *
     * Upload the signed OSHE form. Refused unless this is the OSHE milestone and
     * the student has already filed their copy — a signature on a form that was
     * never filled in attests to nothing.
     */
    public function sign(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('review', $milestone);

        if ($milestone->code !== Milestone::CODE_OSHE) {
            return $this->fail('Only the OSHE milestone is signed this way.', 422);
        }

        $studentCopy = $milestone->currentFiles()->get();

        if ($studentCopy->isEmpty()) {
            return $this->fail(
                'The student has not submitted the OSHE form yet, so there is nothing to sign.',
                422
            );
        }

        $disk = config('filesystems.default', 'local');

        $validated = $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:3'],
            'files.*' => ['required', 'file', 'max:'.($milestone->maxFileMegabytes() * 1024), 'extensions:pdf'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $written = [];

        try {
            DB::transaction(function () use ($milestone, $validated, $disk, $studentCopy, &$written) {
                // The signed copy is the next revision of the same milestone.
                // Read across every revision, not just current ones, or a second
                // signing would be numbered 1 again.
                $revisionNo = ((int) $milestone->files()->max('revision_no')) + 1;

                // The student's copy stops being current: from now on the signed
                // form is what is in force. The row and the bytes stay.
                foreach ($studentCopy as $old) {
                    $old->update([
                        'is_current'    => false,
                        'superseded_at' => now(),
                        'superseded_by' => $request->user()->id,
                    ]);
                }

                foreach ($validated['files'] as $file) {
                    $path = $file->store("oshe/{$milestone->project_id}", $disk);
                    $written[] = $path;

                    SubmissionFile::create([
                        'milestone_id'     => $milestone->id,
                        'uploaded_by'      => $request->user()->id,
                        'disk'             => $disk,
                        'path'             => $path,
                        'original_name'    => $file->getClientOriginalName(),
                        'mime_type'        => $file->getClientMimeType(),
                        'size_bytes'       => $file->getSize(),
                        'checksum_sha256'  => hash_file('sha256', $file->getRealPath()),
                        'revision_no'      => $revisionNo,
                        'is_current'       => true,
                    ]);
                }

                // Settle the milestone. `note` distinguishes the supervisor's
                // signature from a normal approval in the timeline, so a reader
                // can tell "approved" from "signed and returned".
                $this->milestones->transitionTo(
                    $milestone->fresh(),
                    \App\Enums\MilestoneStatus::Approved,
                    $request->user(),
                    $validated['comment'] ?? 'OSHE form signed by the supervisor and returned.',
                    [],
                    SubmissionEvent::EVENT_APPROVED,
                );
            });
        } catch (InvalidArgumentException $e) {
            foreach ($written as $path) {
                Storage::disk($disk)->delete($path);
            }

            return $this->fail($e->getMessage(), 422);
        }

        $this->audit->log(
            action: AuditAction::FileUploaded,
            description: "OSHE form signed and returned by {$request->user()->name}",
            subject: $milestone,
        );

        return $this->ok(
            new MilestoneResource($milestone->fresh()->load('currentFiles', 'project')),
            'Signed OSHE form uploaded — the milestone is complete.'
        );
    }
}
