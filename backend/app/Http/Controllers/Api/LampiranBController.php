<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\LampiranBFormResource;
use App\Models\LampiranBForm;
use App\Models\Milestone;
use App\Models\Project;
use App\Services\AuditLogger;
use App\Services\MilestoneService;
use App\Services\Psm2TitleChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Lampiran B â€” filing, signing, and the gate it opens.
 *
 * A re-presented title runs through here: the student revises the pre-filled
 * form, the supervisor signs it, and only then does the re-presented proposal
 * milestone open for the next defence.
 *
 *   POST /api/lampiran-b/{form}/file    student submits the revised form
 *   POST /api/lampiran-b/{form}/sign    supervisor signs, or sends it back
 *   GET  /api/projects/{project}/lampiran-b   every filing, newest first
 *
 * The first filing is not made here â€” it is created by `RegistrationService`
 * when the project is registered, and its record is written by the migration's
 * backfill for projects that predate this table. This controller is for the
 * rounds after a refusal.
 */
class LampiranBController extends ApiController
{
    public function __construct(
        protected MilestoneService $milestones,
        protected AuditLogger $audit,
        protected Psm2TitleChangeService $titleChanges,
    ) {
    }

    /**
     * POST /api/projects/{project}/title-change
     *
     * A student who passed PSM 1 chooses a different title for PSM 2.
     *
     * Only opens the form. The change itself happens when the supervisor signs
     * it — the same gate a refused title goes through, because it is the same
     * question: does the faculty agree to supervise work under this title?
     */
    public function requestTitleChange(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        if ($project->leader()?->user_id !== $request->user()->id) {
            return $this->fail('Only the student on this project can change its title.', 403);
        }

        try {
            $form = $this->titleChanges->request($project, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created(
            (new LampiranBFormResource($form->load('supervisorProfile.user')))->resolve($request),
            'Propose the new title, then submit it for your supervisor\'s signature.',
        );
    }

    /** Every filing for a project, newest first, for the milestone page. */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $forms = LampiranBForm::forProject($project->id)
            ->with(['supervisorProfile.user', 'rejectedBy'])
            ->orderByDesc('revision_no')
            ->get();

        return $this->ok([
            'project_id' => $project->id,
            'code'       => $project->code,
            '//'         => '`title` on the project is the last ACCEPTED title; each form carries what was proposed.',
            'accepted_title' => $project->title,
            'forms'      => LampiranBFormResource::collection($forms)->resolve($request),
        ]);
    }

    /**
     * The student fills in the revised form.
     *
     * Only the fields the student owns. The supervisor is not among them â€” the
     * pairing was fixed by Lampiran A, and a re-file is about the title, not
     * about who supervises.
     */
    public function file(Request $request, LampiranBForm $form): JsonResponse
    {
        $this->authorize('file', $form);

        if ($form->status === LampiranBForm::STATUS_ACCEPTED) {
            return $this->fail('This filing has already been accepted.', 422);
        }

        $validated = $request->validate([
            'title'        => ['required', 'string', 'min:10', 'max:255', new \App\Rules\IsTitle()],
            'project_type' => ['required', Rule::in(['system', 'research'])],
            'field_study'  => ['nullable', 'string', 'max:255'],
            'req_software' => ['nullable', 'string', 'max:255'],
            'req_hardware' => ['nullable', 'string', 'max:255'],
            'req_technology' => ['nullable', 'string', 'max:255'],
            'origin'       => ['nullable', 'string', 'max:64'],
        ]);

        /**
         * A re-file must actually differ from the title that was refused.
         *
         * Submitting the same title again wastes a panel sitting and tells the
         * student nothing â€” the panel already said no to it. Compared
         * case-insensitively with whitespace collapsed, so re-typing it with
         * different spacing or capitalisation is still the same title.
         */
        $previous = $form->supersedes;

        if ($previous !== null) {
            $normalise = static fn ($t): string => mb_strtolower(
                preg_replace('/\s+/u', ' ', trim((string) $t)) ?? ''
            );

            if ($normalise($validated['title']) === $normalise($previous->title)) {
                return $this->fail(
                    'That is the title the panel refused â€” it needs to be revised, not resubmitted. '
                    .($previous->rejected_reason ? "The panel said: {$previous->rejected_reason}" : ''),
                    422,
                    ['title' => ['This title was already refused.']],
                );
            }
        }

        $form->update([
            ...$validated,
            'status' => LampiranBForm::STATUS_SUBMITTED,
            // A re-submission is a new signature: the old one covered the old title.
            'supervisor_signature_path' => null,
            'supervisor_signed_at'      => null,
            'signature_type'            => null,
        ]);

        $this->audit->log(
            action: AuditAction::ProjectUpdated,
            description: "Lampiran B #{$form->revision_no} filed for {$form->project?->code} â€” "
                ."\"{$form->title}\" awaiting the supervisor's signature",
            subject: $form,
            actor: $request->user(),
        );

        return $this->ok(
            (new LampiranBFormResource($form->fresh()->load('supervisorProfile.user')))->resolve($request),
            'Lampiran B submitted â€” awaiting your supervisor\'s signature.',
        );
    }

    /**
     * The supervisor signs the new title, or sends it back.
     *
     * Signing is what **opens the proposal milestone**. That is the gate the
     * faculty's sequence turns on: the supervisor reads the new title before any
     * panel sees it, and a proposal cannot be uploaded for a title nobody has
     * agreed to supervise.
     */
    public function sign(Request $request, LampiranBForm $form): JsonResponse
    {
        $this->authorize('sign', $form);

        if ($form->status === LampiranBForm::STATUS_ACCEPTED) {
            return $this->fail('This filing has already been accepted.', 422);
        }

        if ($form->status === LampiranBForm::STATUS_DRAFT) {
            return $this->fail('The student has not submitted this filing yet.', 422);
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
                    'Say what needs changing â€” the student cannot act on a refusal with no reason.',
                    422,
                );
            }

            // Back to the student to edit; the form stays theirs.
            $form->update(['status' => LampiranBForm::STATUS_DRAFT]);

            $this->audit->log(
                action: AuditAction::ProjectRejected,
                description: "Lampiran B #{$form->revision_no} returned: {$reason}",
                subject: $form,
                actor: $request->user(),
            );

            return $this->ok(
                (new LampiranBFormResource($form->fresh()))->resolve($request),
                'Returned to the student.',
            );
        }

        return DB::transaction(function () use ($request, $form) {
            $path = null;

            if ($request->hasFile('signature')) {
                $path = $request->file('signature')
                    ->store("signatures/lampiran-b/{$form->project_id}", 'local');
            }

            $form->update([
                'status'        => LampiranBForm::STATUS_ENDORSED,
                'supervisor_signature_path' => $path ?? $form->supervisor_signature_path,
                'supervisor_signature_disk' => $path !== null ? 'local' : $form->supervisor_signature_disk,
                'signature_type' => $path !== null ? 'uploaded' : $form->signature_type,
                'supervisor_signed_at' => now(),
                'supervisor_profile_id' => $form->supervisor_profile_id
                    ?? $request->user()->supervisorProfile?->id,
            ]);

            $opened = $this->openProposalFor($form);

            /**
             * A boundary title change installs its chain on signature.
             *
             * The form created by `Psm2TitleChangeService::request()` carries no
             * milestone â€” there is nothing to open, because the chain it needs
             * does not exist yet. Signing is the moment it comes into being: the
             * new title becomes the project's title, and the refiled proposal and
             * Chapters 1â€“4 are prepended to the PSM 2 chain.
             *
             * Distinguished from a re-filing by the project's flagged state and
             * by the form having no milestone, so the two paths cannot collide.
             */
            $installed = null;

            if ($opened === null && $form->milestone_id === null && $form->project !== null) {
                $blocked = $this->titleChanges->blockedReason($form->project);

                if ($blocked === null) {
                    $installed = $this->titleChanges->install($form->project, $form, $request->user());

                    $this->audit->log(
                        action: AuditAction::ProjectUpdated,
                        description: "PSM 2 title changed for {$form->project->code} â€” "
                            ."\"{$form->title}\"; PSM 1 documents reinstated",
                        subject: $form->project,
                        actor: $request->user(),
                    );
                }
            }

            $this->audit->log(
                action: AuditAction::ProjectApproved,
                description: "Lampiran B #{$form->revision_no} signed for {$form->project?->code} â€” "
                    ."\"{$form->title}\"",
                subject: $form,
                actor: $request->user(),
            );

            return $this->ok([
                ...(new LampiranBFormResource($form->fresh()->load('supervisorProfile.user')))->resolve($request),
                'proposal_opened' => $opened?->id,
                'chain_reinstated' => $installed !== null ? count($installed) : 0,
            ], match (true) {
                $installed !== null =>
                    'Signed. The project now carries the new title, and the PSM 1 documents '
                    .'are reinstated in the PSM 2 chain to be done again.',
                $opened !== null => 'Signed. The proposal for this title is now open.',
                default => 'Signed.',
            });
        });
    }

    /**
     * Open the proposal milestone this filing belongs to.
     *
     * The re-presented proposal is created as `pending` by the rejection and
     * carries the form's `milestone_id`. Signing activates it â€” that is the
     * whole gate. A form with no milestone (the first filing, made before the
     * project existed) opens nothing: its proposal is already part of the chain.
     */
    protected function openProposalFor(LampiranBForm $form): ?Milestone
    {
        if ($form->milestone_id === null) {
            return null;
        }

        $milestone = $form->milestone;

        if ($milestone === null) {
            return null;
        }

        if ($milestone->status !== MilestoneStatus::Pending) {
            return $milestone;
        }

        return $this->milestones->transitionTo(
            $milestone,
            MilestoneStatus::Open,
            null,
            null,
            [],
            // `submission_events.event` is varchar(64) and holds a short event
            // key, not a sentence â€” a full description here is rejected by
            // PostgreSQL rather than truncated. The reader-facing wording lives
            // in the response message and the audit entry.
            'Proposal opened after Lampiran B signature',
        );
    }
}
