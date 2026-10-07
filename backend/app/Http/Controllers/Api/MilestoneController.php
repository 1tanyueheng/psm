<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Http\Controllers\ApiController;
use App\Http\Resources\MilestoneResource;
use App\Http\Resources\SubmissionFileResource;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\SubmissionEvent;
use App\Models\SubmissionFile;
use App\Services\AuditLogger;
use App\Services\MilestoneService;
use App\Services\NotificationDispatcher;
use App\Services\ProposalReviewService;
use App\Enums\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Module 3 — Milestone browsing, submission, review and deadline changes.
 *
 * The **proposal milestone** is special: its verdict is the panel's and it
 * settles the project title, so it is decided through the three endpoints at the
 * bottom of this class rather than through `approve` / `request-revision`.
 * MilestoneService refuses those two for the proposal, so the generic path
 * cannot bypass the title decision.
 */
class MilestoneController extends ApiController
{
    public function __construct(
        protected MilestoneService $milestones,
        protected ProposalReviewService $proposals,
        protected AuditLogger $audit,
        protected NotificationDispatcher $notifications,
    ) {
    }

    /**
     * GET /api/projects/{project}/milestones
     */
    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $milestones = $project->milestones()
            ->with(['currentFiles.uploader', 'reviewer', 'templateItem'])
            ->orderBy('sequence')
            ->get();

        return $this->ok(MilestoneResource::collection($milestones));
    }

    /**
     * GET /api/milestones
     *
     * The cross-project worklist behind the Milestones screen. A student sees
     * their own chain, a supervisor the milestones they are expected to
     * review, coordinators and admins the whole cohort.
     *
     * Visibility is delegated to Project::scopeVisibleTo() rather than
     * reimplemented, so this list can never disagree with /api/projects about
     * who may see what.
     */
    public function all(Request $request): JsonResponse
    {
        $user = $request->user();

        $paginator = Milestone::query()
            ->whereHas('project', fn ($q) => $q->visibleTo($user))
            // `templateItem` carries the deliverable expectation, which the
            // worklist shows per row alongside the chapter's progress.
            ->with(['currentFiles.uploader', 'reviewer', 'project', 'templateItem'])
            ->when(
                $request->filled('status'),
                fn ($q) => $q->where('status', $request->input('status'))
            )
            ->when(
                $request->filled('project_id'),
                fn ($q) => $q->where('project_id', $request->integer('project_id'))
            )
            ->when(
                $request->filled('psm_part'),
                fn ($q) => $q->whereHas(
                    'project',
                    fn ($p) => $p->forPart($request->input('psm_part'))
                )
            )
            // The screen groups by urgency itself, so the query only needs a
            // stable order: project, then sequence within it.
            ->orderBy('project_id')
            ->orderBy('sequence')
            ->paginate($request->integer('per_page', 50));

        return $this->paginated(
            $paginator,
            fn (Milestone $m) => (new MilestoneResource($m))->resolve($request)
        );
    }

    /**
     * GET /api/milestones/{milestone}
     */
    public function show(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('view', $milestone);

        $milestone->load([
            'currentFiles.uploader',
            'files.uploader',
            'events.actor',
            'reviewer',
            'templateItem',
            // The detail screen shows who owns the work and who may review it,
            // so both the students and their supervisors are needed up front.
            'project.students.user',
            'project.students.activeSupervisions.supervisorProfile.user',
        ]);

        return $this->ok(new MilestoneResource($milestone));
    }

    /**
     * POST /api/milestones/{milestone}/submit
     *
     * Uploads one or more files and moves the milestone to `submitted`.
     *
     * Files are stored on a private disk with a randomised name; the original
     * filename is preserved only in the database, so a crafted filename cannot
     * influence the storage path.
     */
    public function submit(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('submit', $milestone);

        $maxMb      = $milestone->maxFileMegabytes();
        $maxFiles   = $milestone->effectiveMaxFiles();
        $extensions = $milestone->effectiveAllowedExtensions();

        $fileRules = ['required', 'file', 'max:'.($maxMb * 1024)];

        /**
         * `extensions` rather than `mimes`.
         *
         * Both check the upload, but they check different things: `mimes`
         * validates the extension Symfony *guesses* from the file's MIME type,
         * which is unreliable for the source files this platform accepts
         * (.py, .java, .sql, .md frequently sniff to `text/plain` and are then
         * rejected even though they are on the allowlist). `extensions` checks
         * the extension the client sent — the same value the upload form
         * advertises in its `accept` attribute, so the form and the validator
         * can never disagree. Files are stored privately under a random name
         * and are never executed, so extension matching is the appropriate
         * control here.
         */
        if ($extensions !== []) {
            $fileRules[] = 'extensions:'.implode(',', $extensions);
        }

        $validated = $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'files.*' => $fileRules,
            'note'    => ['nullable', 'string', 'max:2000'],
        ], [
            'files.max'          => "You may upload at most {$maxFiles} file(s) for this milestone.",
            'files.*.max'        => "Each file must be smaller than {$maxMb} MB.",
            'files.*.extensions' => 'This milestone accepts: '.implode(', ', $extensions).'.',
        ]);

        if (! $milestone->acceptsSubmission()) {
            return $this->fail(
                match (true) {
                    $milestone->status === MilestoneStatus::Approved
                        => 'This milestone is already approved and no longer accepts submissions.',
                    $milestone->isOverdue()
                        => 'The deadline for this milestone has passed and late submission is not permitted. '
                           .'Contact your coordinator to request an extension.',
                    default => 'This milestone is not currently open for submission.',
                },
                422
            );
        }

        /**
         * Where the bytes go, resolved once.
         *
         * Read from config rather than hardcoded so the same code writes to a
         * local disk in development and object storage in production, and the
         * resolved name is recorded per row — which is what lets existing files
         * be migrated to a new disk without a flag day.
         */
        $disk = config('filesystems.default', 'local');

        /**
         * Paths actually written, so a failure can undo them.
         *
         * The database rolls back on its own, but a file already written to the
         * disk does not — so a failure on the third of five files would leave
         * two orphans behind with no row pointing at them. Tracked outside the
         * transaction because the cleanup has to run after it has rolled back.
         */
        $written = [];

        try {
            $files = DB::transaction(function () use ($milestone, $validated, $request, $disk, &$written) {
                /**
                 * The revision number has to be read across *every* attempt, before
                 * the previous one is superseded.
                 *
                 * `currentFiles()` is scoped to `is_current`, so once the old rows
                 * are flipped below it returns nothing and every resubmission would
                 * be numbered 1 — erasing the chapter's revision history.
                 */
                $revisionNo = ((int) $milestone->files()->max('revision_no')) + 1;

                $isResubmission = $milestone->submitted_at !== null
                    || $milestone->revision_count > 0;

                // Supersede the previous attempt rather than deleting it
                if ($isResubmission) {
                    $milestone->currentFiles()->update([
                        'is_current'    => false,
                        'superseded_at' => now(),
                        'superseded_by' => $request->user()->id,
                    ]);
                }

                $created = [];

                foreach ($request->file('files') as $uploaded) {
                    $originalName = $uploaded->getClientOriginalName();

                    /**
                     * A failed write must not become a database row.
                     *
                     * Laravel reports a storage failure in one of two ways
                     * depending on the disk's `throw` flag, so both are handled:
                     *
                     *  - `throw => false`: `FilesystemAdapter::put()` catches
                     *    `UnableToWriteFile` and *returns* `false`. Assigning
                     *    that into `path` created a row pointing at nothing —
                     *    the student saw a successful submission, the reviewer
                     *    got a 404 on download, and nothing was logged.
                     *  - `throw => true` (the `s3` disk): the exception
                     *    propagates, which would surface as an opaque 500.
                     *
                     * Note that some failures escape the flag entirely —
                     * `UnableToCreateDirectory` is not caught by `put()`, so a
                     * local disk raises it either way. Catching here means the
                     * student gets the same actionable message in every case.
                     *
                     * On a local disk this is nearly impossible; on object
                     * storage a dropped connection or a bad credential makes it
                     * routine, so the check matters most exactly where it would
                     * otherwise be hardest to notice.
                     */
                    try {
                        // Randomised storage name; extension preserved for readability
                        $path = $uploaded->storeAs(
                            'submissions/'.$milestone->project_id.'/'.$milestone->id,
                            Str::uuid()->toString().'.'.strtolower($uploaded->getClientOriginalExtension()),
                            $disk
                        );
                    } catch (\Throwable $storageFailure) {
                        report($storageFailure);

                        $path = false;
                    }

                    if (! is_string($path) || $path === '') {
                        throw new InvalidArgumentException(
                            "\"{$originalName}\" could not be saved to storage. "
                            .'Nothing was submitted — please try again, and contact your '
                            .'coordinator if it keeps failing.'
                        );
                    }

                    $written[] = $path;

                    $created[] = SubmissionFile::create([
                        'milestone_id'   => $milestone->id,
                        'uploaded_by'    => $request->user()->id,
                        'disk'           => $disk,
                        'path'           => $path,
                        'original_name'  => $originalName,
                        'mime_type'      => $uploaded->getClientMimeType(),
                        'size_bytes'     => $uploaded->getSize(),
                        'checksum_sha256'=> hash_file('sha256', $uploaded->getRealPath()) ?: null,
                        'revision_no'    => $revisionNo,
                        'is_current'     => true,
                    ]);
                }

                // Drive the state machine from the model's own rules
                if ($milestone->status === MilestoneStatus::Pending) {
                    $milestone->update(['status' => MilestoneStatus::Open]);
                }

                $this->milestones->transitionTo(
                    $milestone,
                    MilestoneStatus::Submitted,
                    $request->user(),
                    $validated['note'] ?? null,
                );

                return $created;
            });
        } catch (\Throwable $e) {
            /**
             * The transaction has rolled back, so no row survives — but the
             * bytes do. Without this, a failure part-way through a multi-file
             * upload leaves objects on the disk that nothing points at: invisible
             * to every screen, still billed for, and impossible to tell apart
             * from a real submission later.
             *
             * Rethrown rather than converted, because the response envelope is
             * already decided in one place — `bootstrap/app.php` maps
             * InvalidArgumentException (a failed write, a rejected transition)
             * to 422 with its message, and anything else to 500.
             */
            $this->discardWrittenFiles($disk, $written);

            throw $e;
        }

        $this->audit->log(
            action: AuditAction::FileUploaded,
            description: count($files).' file(s) uploaded to '.$milestone->title,
            subject: $milestone,
        );

        // Notify the supervising team that there is something to review
        $supervisorUsers = $milestone->project->students
            ->flatMap(fn ($s) => $s->activeSupervisions)
            ->map(fn ($a) => $a->supervisorProfile?->user)
            ->filter()
            ->unique('id');

        $this->notifications->notify(
            $supervisorUsers,
            NotificationType::MilestoneSubmitted,
            [
                'title'      => 'New submission to review',
                'body'       => "{$milestone->project->code} submitted '{$milestone->title}'.",
                'action_url' => "/projects/{$milestone->project_id}/milestones/{$milestone->id}",
                'meta'       => ['file_count' => count($files)],
            ],
            $milestone,
        );

        $milestone->refresh()->load(['currentFiles.uploader', 'events.actor']);

        return $this->created([
            'milestone' => new MilestoneResource($milestone),
            'files'     => SubmissionFileResource::collection($files),
        ], 'Submission received.');
    }

    /**
     * Best-effort removal of objects written during a submission that failed.
     *
     * Best-effort on purpose: this runs while an exception is already
     * propagating, so a failure here must not replace the real error with a
     * confusing one. A leftover object is untidy; a masked exception is a bug
     * nobody can diagnose. Each path is attempted independently for the same
     * reason — one unwritable key must not strand the rest.
     *
     * @param  array<int, string>  $paths
     */
    protected function discardWrittenFiles(string $disk, array $paths): void
    {
        if ($paths === []) {
            return;
        }

        foreach ($paths as $path) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $cleanupFailure) {
                report($cleanupFailure);
            }
        }
    }

    /**
     * POST /api/milestones/{milestone}/approve
     */
    public function approve(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('review', $milestone);

        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $milestone->status->isProgressed()) {
            return $this->fail('There is nothing to approve yet — no submission has been made.', 422);
        }

        try {
            $updated = $this->milestones->approve(
                $milestone,
                $request->user(),
                $validated['comment'] ?? null
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        $this->notifications->notify(
            $updated->project->students->pluck('user')->filter(),
            NotificationType::MilestoneApproved,
            [
                'title'      => 'Milestone approved',
                'body'       => "{$updated->title} has been approved.",
                'action_url' => "/projects/{$updated->project_id}/milestones/{$updated->id}",
            ],
            $updated,
        );

        return $this->ok(new MilestoneResource($updated->load('currentFiles')), 'Milestone approved.');
    }

    /**
     * POST /api/milestones/{milestone}/request-revision
     */
    public function requestRevision(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('review', $milestone);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $updated = $this->milestones->requestRevision(
                $milestone,
                $request->user(),
                $validated['comment']
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            new MilestoneResource($updated->load('currentFiles')),
            'Revision requested. The student has been notified.'
        );
    }

    // -----------------------------------------------------------------
    // The proposal milestone — the title decision
    // -----------------------------------------------------------------

    /**
     * GET /api/panel/proposals
     *
     * The proposals this panel member has to rule on.
     *
     * The panel decides the title at the project's proposal milestone, so a
     * seated examiner needs to see which proposals are waiting for them. The
     * milestone screen carries the form, but nothing pointed them at it — a
     * panel member had no way to find the students they were appointed to
     * examine except by searching the project list.
     *
     * Settled proposals are returned too, not just the outstanding ones: the
     * list doubles as the record of what this person decided, and a list that
     * empties itself the moment a decision is recorded is impossible to check.
     */
    public function panelProposals(Request $request): JsonResponse
    {
        $actor = $request->user();

        $milestones = Milestone::query()
            ->where('code', Milestone::CODE_PROPOSAL)
            ->whereHas('project.examinerAssignments', fn ($q) => $q
                ->where('examiner_id', $actor->id)
                ->where('is_active', true))
            ->with(['project.students.user', 'project.examinerAssignments.examiner'])
            ->orderBy('id')
            ->get();

        return $this->ok($milestones->map(function (Milestone $milestone) use ($actor) {
            $project = $milestone->project;
            $student = $project?->leader();

            return [
                'id'           => $milestone->id,
                'status'       => $milestone->status->value,
                'status_label' => $milestone->status->label(),
                // The panel has something to do only while it is filed and undecided.
                'awaiting_decision' => in_array(
                    $milestone->status,
                    [MilestoneStatus::Submitted, MilestoneStatus::Reviewed],
                    true
                ),
                'project' => $project ? [
                    'id'       => $project->id,
                    'code'     => $project->code,
                    'title'    => $project->title,
                    'psm_part' => $project->psm_part,
                ] : null,
                'student' => $student ? [
                    'profile_id' => $student->id,
                    'student_id' => $student->student_id,
                    'name'       => $student->user?->name,
                ] : null,
                'decided_at' => $milestone->reviewed_at?->toIso8601String(),
                'panel_role' => $project?->examinerAssignments
                    ?->firstWhere('examiner_id', $actor->id)?->panel_role,
                'panel' => ($project?->examinerAssignments ?? collect())
                    ->where('is_active', true)
                    ->map(fn ($a) => [
                        'examiner_id' => $a->examiner_id,
                        'name'        => $a->examiner?->displayName(),
                        'panel_role'  => $a->panel_role,
                    ])
                    ->values(),
            ];
        })->values());
    }

    /**
     * POST /api/milestones/{milestone}/title-decision
     *
     * The panel's verdict on the proposal, which settles the title and gates the
     * rest of the chain. One decision for the whole panel.
     *
     *   approved            the milestone is approved; the remaining chapters open
     *   conditional_approve the title stands subject to corrections (Lampiran C)
     *   rejected            the title is refused; the student changes it
     */
    public function titleDecision(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('decideTitle', $milestone);

        $validated = $request->validate([
            'decision'     => ['required', Rule::in(\App\Enums\PanelDecision::values())],
            'panel_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = $this->proposals->recordDecision($milestone, $validated, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            new MilestoneResource($updated->load('currentFiles')),
            "The panel's decision has been recorded.",
        );
    }

    /**
     * POST /api/milestones/{milestone}/lampiran-c
     *
     * Lampiran C — the corrections a conditional approval required. Filed by the
     * student; accepting it approves the milestone and fixes the corrected title.
     */
    public function fileLampiranC(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('fileLampiranC', $milestone);

        $validated = $request->validate([
            'corrections_title'             => ['required', 'string', 'max:255'],
            'corrections_actions'           => ['nullable', 'array'],
            'corrections_actions.*.comment' => ['nullable', 'string', 'max:1000'],
            'corrections_actions.*.action'  => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->proposals->fileLampiranC($milestone, $validated, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            new MilestoneResource($updated->load('currentFiles')),
            'Lampiran C filed — the title is confirmed and the next milestone is open.',
        );
    }

    /**
     * POST /api/milestones/{milestone}/change-title
     *
     * Change the title after the panel refused it. The new title is written to
     * the project, and the milestone reopens so the proposal can be refiled and
     * decided again.
     */
    public function changeTitle(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('changeTitle', $milestone);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        try {
            $updated = $this->proposals->changeTitle($milestone, $validated['title'], $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            new MilestoneResource($updated->load('currentFiles')),
            'Title updated — resubmit the proposal for a fresh decision.',
        );
    }

    /**
     * POST /api/milestones/{milestone}/deadline
     *
     * Coordinator/admin only. The reason is mandatory because this is the
     * action most likely to be challenged later.
     */
    public function changeDeadline(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('changeDeadline', $milestone);

        $validated = $request->validate([
            'due_at' => ['required', 'date', 'after:today'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $updated = $this->milestones->overrideDeadline(
            $milestone,
            \Illuminate\Support\Carbon::parse($validated['due_at']),
            $request->user(),
            $validated['reason'],
        );

        return $this->ok(
            new MilestoneResource($updated->load('currentFiles')),
            'Deadline updated and the student has been notified.'
        );
    }

    /**
     * POST /api/milestones/{milestone}/comment
     *
     * Free-text feedback that does not change status — keeps the timeline rich
     * without forcing a formal decision.
     */
    public function comment(Request $request, Milestone $milestone): JsonResponse
    {
        $this->authorize('view', $milestone);

        $validated = $request->validate([
            'comment' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        SubmissionEvent::create([
            'milestone_id' => $milestone->id,
            'actor_id'     => $request->user()->id,
            'event'        => SubmissionEvent::EVENT_COMMENTED,
            'comment'      => $validated['comment'],
            'to_status'    => $milestone->status->value,
        ]);

        return $this->ok(null, 'Comment added.');
    }

    /**
     * GET /api/submissions/{file}/download
     *
     * Streams a submission file. Kept behind the view policy and audited, so
     * there is a record of who read a student's work.
     */
    public function download(Request $request, SubmissionFile $file)
    {
        $milestone = $file->milestone;

        $this->authorize('download', $milestone);

        if (! $file->exists()) {
            return $this->fail('The stored file could not be found. It may have been moved during archiving.', 404);
        }

        $this->audit->log(
            action: AuditAction::FileDownloaded,
            description: "Downloaded {$file->original_name}",
            subject: $file,
        );

        return Storage::disk($file->disk)->download($file->path, $file->original_name);
    }

    /**
     * DELETE /api/submissions/{file}
     *
     * Withdraws a file: allowed while the milestone still accepts submissions,
     * and also while the submission is merely `submitted` (see
     * MilestonePolicy::withdraw()). The row is marked superseded rather than
     * destroyed (Module 7).
     *
     * "Withdraw" rather than "delete" is the accurate verb: the bytes stay on
     * the private disk and the row stays in the table, so an appeal can still
     * be answered with what was actually submitted. What changes is that the
     * file is no longer current, so it leaves the reviewer's view.
     */
    public function destroyFile(Request $request, SubmissionFile $file): JsonResponse
    {
        $milestone = $file->milestone;

        $this->authorize('withdraw', $milestone);

        /**
         * Only the live file can be withdrawn.
         *
         * Without this guard a replayed request against an already-superseded
         * row would quietly succeed, restamp `superseded_at`, and — because
         * the count of current files is already zero — drive the milestone
         * through a second `rejected` transition, bumping `revision_count`
         * again. The UI only offers the button on current files, so the
         * realistic path never reaches this; a retried request would.
         */
        if (! $file->is_current) {
            return $this->fail('That file has already been withdrawn.', 422);
        }

        $milestone = DB::transaction(function () use ($file, $request, $milestone) {
            $file->update([
                'is_current'    => false,
                'superseded_at' => now(),
                'superseded_by' => $request->user()->id,
            ]);

            $comment = "Withdrew {$file->original_name}.";

            /**
             * Any withdrawal from a `submitted` milestone returns it to
             * `rejected`, even if other files remain.
             *
             * The reviewer is about to assess a specific set of files. Once
             * that set changes, the thing awaiting review is no longer the
             * thing that was submitted, so the submission is void and has to be
             * made again — otherwise a supervisor could approve a milestone
             * whose contents shifted underneath them between opening it and
             * acting on it. `rejected` still accepts uploads, so the student
             * can drop in a replacement and resubmit in one flow.
             *
             * The narrative event is named explicitly: the default for a
             * `rejected` transition is "requested a revision", which would
             * attribute the action to the wrong party and duplicate the
             * withdrawal. One entry, stating what actually happened.
             */
            if ($milestone->status === MilestoneStatus::Submitted) {
                return $this->milestones->transitionTo(
                    $milestone,
                    MilestoneStatus::Rejected,
                    $request->user(),
                    $comment,
                    narrativeEvent: SubmissionEvent::EVENT_WITHDRAWN,
                );
            }

            /**
             * Otherwise the milestone keeps its status — a revision was already
             * requested, or it is still open — so the withdrawal is recorded on
             * its own.
             *
             * It belongs in the timeline, not only in the audit log:
             * `submission_events` is the layer the student and supervisor read
             * on this page, while `audit_logs` answers "what did this user do"
             * for an auditor. A file silently vanishing from the submission
             * list with nothing explaining why is exactly the kind of
             * unexplained change the record is meant to prevent.
             */
            SubmissionEvent::create([
                'milestone_id' => $milestone->id,
                'actor_id'     => $request->user()->id,
                'event'        => SubmissionEvent::EVENT_WITHDRAWN,
                'from_status'  => $milestone->status->value,
                'to_status'    => $milestone->status->value,
                'comment'      => $comment,
                'payload'      => [
                    'file_id'       => $file->id,
                    'original_name' => $file->original_name,
                    'files_left'    => $milestone->currentFiles()->count(),
                ],
            ]);

            return $milestone->refresh();
        });

        $this->audit->log(
            action: AuditAction::FileWithdrawn,
            description: "Withdrew {$file->original_name} from {$milestone->title}",
            subject: $file,
        );

        $milestone->load(['currentFiles.uploader', 'files.uploader', 'events.actor', 'reviewer']);

        return $this->ok(
            new MilestoneResource($milestone),
            'File withdrawn. It remains in the audit trail.'
        );
    }
}
