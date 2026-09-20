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
use App\Enums\NotificationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Module 3 — Milestone browsing, submission, review and deadline changes.
 */
class MilestoneController extends ApiController
{
    public function __construct(
        protected MilestoneService $milestones,
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
            ->with(['currentFiles.uploader', 'reviewer'])
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
            ->with(['currentFiles.uploader', 'reviewer', 'project'])
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
            'project.students.user',
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

        $maxMb = (int) config('psm.submission.max_mb', 25);
        $allowed = config('psm.submission.allowed_extensions', []);

        $validated = $request->validate([
            'files'   => ['required', 'array', 'min:1', 'max:'.max(1, $milestone->max_files)],
            'files.*' => [
                'required', 'file',
                'max:'.($maxMb * 1024),
                'mimes:'.implode(',', $allowed),
            ],
            'note'    => ['nullable', 'string', 'max:2000'],
        ], [
            'files.max'      => "You may upload at most {$milestone->max_files} file(s) for this milestone.",
            'files.*.max'    => "Each file must be smaller than {$maxMb} MB.",
            'files.*.mimes'  => 'Allowed file types: '.implode(', ', $allowed).'.',
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

        $files = DB::transaction(function () use ($milestone, $validated, $request) {
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

            $revisionNo = $milestone->currentFiles()->max('revision_no') + 1 ?: 1;

            $created = [];

            foreach ($request->file('files') as $uploaded) {
                // Randomised storage name; extension preserved for readability
                $path = $uploaded->storeAs(
                    'submissions/'.$milestone->project_id.'/'.$milestone->id,
                    Str::uuid()->toString().'.'.strtolower($uploaded->getClientOriginalExtension()),
                    'local',
                );

                $created[] = SubmissionFile::create([
                    'milestone_id'   => $milestone->id,
                    'uploaded_by'    => $request->user()->id,
                    'disk'           => 'local',
                    'path'           => $path,
                    'original_name'  => $uploaded->getClientOriginalName(),
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

        $updated = $this->milestones->approve(
            $milestone,
            $request->user(),
            $validated['comment'] ?? null
        );

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

        $updated = $this->milestones->requestRevision(
            $milestone,
            $request->user(),
            $validated['comment']
        );

        return $this->ok(
            new MilestoneResource($updated->load('currentFiles')),
            'Revision requested. The student has been notified.'
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
     * Withdraws a file. Allowed only while the milestone is still open, and it
     * marks the row superseded rather than destroying it (Module 7).
     */
    public function destroyFile(Request $request, SubmissionFile $file): JsonResponse
    {
        $milestone = $file->milestone;

        $this->authorize('submit', $milestone);

        $file->update([
            'is_current'    => false,
            'superseded_at' => now(),
            'superseded_by' => $request->user()->id,
        ]);

        // If nothing current remains, reopen the milestone for a fresh upload
        if ($milestone->currentFiles()->count() === 0
            && $milestone->status === MilestoneStatus::Submitted) {
            $this->milestones->transitionTo($milestone, MilestoneStatus::Rejected, $request->user(), 'Submission withdrawn by the student.');
        }

        return $this->ok(null, 'File withdrawn. It remains in the audit trail.');
    }
}
