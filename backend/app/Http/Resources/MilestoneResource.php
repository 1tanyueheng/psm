<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 3 / 6 — Milestone payload.
 *
 * Includes the deadline arithmetic (days remaining, overdue flag, whether a
 * submission is currently accepted) so the SPA never reimplements date logic —
 * the rules for late windows and extensions live on the model.
 *
 * @mixin \App\Models\Milestone
 */
class MilestoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'project_id' => $this->project_id,
            'code'       => $this->code,
            'title'      => $this->title,
            'description'=> $this->description,

            'sequence'       => $this->sequence,
            'weight_percent' => (float) $this->weight_percent,

            /**
             * How far this chapter has got, and what it is worth.
             *
             * `completion_percent` is the chapter's own progress; the
             * contribution is how many points of the project-wide percentage
             * this chapter is currently adding. Resolved here rather than in
             * the SPA so the chapter breakdown a supervisor reads always sums
             * to the project's headline progress figure.
             */
            'completion_percent'    => $this->completionPercent(),
            'progress_contribution' => $this->progressContribution(),

            'status'       => $this->status->value,
            'status_label' => $this->status->label(),
            'status_tone'  => $this->status->tone(),

            // -----------------------------------------------------------------
            // Deadline arithmetic, resolved server-side
            // -----------------------------------------------------------------
            'opens_at'              => $this->opens_at?->toDateString(),
            'due_at'                => $this->due_at?->toDateString(),
            'effective_due_at'      => $this->effectiveDueAt()?->toIso8601String(),
            'extended_until'        => $this->extended_until?->toIso8601String(),
            'days_until_due'        => $this->daysUntilDue(),
            'is_overdue'            => $this->isOverdue(),
            'is_within_late_window' => $this->isWithinLateWindow(),
            'accepts_submission'    => $this->acceptsSubmission(),

            'allow_late_submission'        => (bool) $this->allow_late_submission,
            'late_window_days'             => $this->late_window_days,
            'requires_supervisor_approval' => (bool) $this->requires_supervisor_approval,

            // -----------------------------------------------------------------
            // Review outcome
            // -----------------------------------------------------------------
            'submitted_at'   => $this->submitted_at?->toIso8601String(),
            'reviewed_at'    => $this->reviewed_at?->toIso8601String(),
            'approved_at'    => $this->approved_at?->toIso8601String(),
            'review_comment' => $this->review_comment,
            'revision_count' => $this->revision_count,

            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer ? [
                'id'   => $this->reviewer->id,
                'name' => $this->reviewer->name,
            ] : null),

            // -----------------------------------------------------------------
            // The proposal milestone — the title decision
            // -----------------------------------------------------------------
            // The proposal is the one milestone whose verdict is the panel's and
            // which settles the project title. Its `status` carries the decision:
            // approved / conditional_approve / rejected. Everything below is
            // resolved only for it, so the other milestones pay nothing.
            'is_proposal' => $this->isProposal(),

            'can_decide_title' => $this->when(
                $this->isProposal(),
                fn () => (bool) $request->user()?->can('decideTitle', $this->resource),
            ),

            /**
             * Whether the signed-in user may move this deadline.
             *
             * The same `changeDeadline` ability the endpoint authorises, so the
             * button cannot offer an action the API will refuse. Resolved here
             * rather than re-derived from the role in the SPA, because the
             * policy also requires visibility of the owning project — a
             * coordinator cannot move a deadline on a project they cannot see.
             */
            'can_change_deadline' => (bool) $request->user()?->can('changeDeadline', $this->resource),

            /**
             * Lampiran R1 — can the signed-in user respond to the submission?
             *
             * The same ability the endpoint authorises, so the "Respond (R1)"
             * button cannot offer a review the API will refuse. False for the
             * proposal, whose verdict is the panel's, and false while nothing
             * has been submitted — there is nothing to respond to.
             */
            'can_review_r1' => (bool) $request->user()?->can('reviewR1', $this->resource),

            /**
             * Does Lampiran R1 govern this milestone?
             *
             * Chapters 1-4 of PSM 1 only, decided by the server so the SPA never
             * re-implements the rule. Sent separately from `can_review_r1`
             * because they answer different questions: "does this form apply
             * here" versus "may *this user* fill it in right now". A coordinator
             * looking at an already-approved chapter gets `uses_r1: true` and
             * `can_review_r1: false`, which is the truthful pair.
             */
            'uses_r1' => $this->usesR1(),

            /**
             * Is this one of the two registration documents (Lampiran A / B)?
             *
             * The page uses it to render the read-only document card instead of a
             * review or submission card — these milestones are records, not tasks.
             */
            'is_registration_document' => $this->isRegistrationDocument(),

            /**
             * The registration documents, rendered read-only.
             *
             * Lampiran A and B were filed before the project existed, so their
             * milestones are records rather than tasks. The data lives on the
             * agreement and the project — this block is what the digital form
             * needs to draw the document that was actually submitted.
             *
             * Sent only on those two milestones: a chapter carrying the whole
             * agreement would bloat every worklist row.
             */
            'registration_document' => $this->isRegistrationDocument() ? [
                'kind' => $this->code,

                /**
                 * True when the project actually came through registration.
                 *
                 * Only four of the seeded projects have an agreement; the rest
                 * were written straight into the database for demonstration. The
                 * card uses this to say so plainly rather than render an empty
                 * form and let a reader think the document was lost.
                 */
                'has_agreement' => $this->whenLoaded('project', fn () => $this->project?->agreement_id !== null),

                'agreement' => $this->whenLoaded('project', function () {
                    $a = $this->project?->agreement;
                    $student = $this->project?->students?->first();

                    // No agreement: fall back to what the project itself holds,
                    // so the card still shows the student and the title that the
                    // milestone stands for.
                    if ($a === null) {
                        return [
                            'id'               => null,
                            'session'          => $this->project?->academic_session,
                            'psm_part'         => $this->project?->psm_part,
                            'proposed_titles'  => [],
                            'agreed_title'     => $this->project?->title,
                            'english_report'   => false,
                            'status'           => null,
                            'student_signed_at'=> null,
                            'acknowledged_at'  => null,
                            'student_name'     => $student?->user?->name,
                            'student_matric'   => $student?->student_id,
                            'programme'        => $student?->program_code,
                            'supervisor_name'  => $student?->activeSupervisions?->first()?->supervisorProfile?->user?->name,

                            'project' => [
                                'title'      => $this->project?->title,
                                'abstract'   => $this->project?->abstract,
                                'objectives' => $this->project?->objectives,
                                'scope'      => $this->project?->scope,
                                'category'   => $this->project?->category?->value,
                                'batch'      => $this->project?->batch,
                                'program'    => $this->project?->program,
                            ],
                        ];
                    }

                    return [
                        'id'               => $a?->id,
                        'session'          => $a?->session,
                        'psm_part'         => $a?->psm_part,
                        'proposed_titles'  => array_values(array_filter([
                            $a?->proposed_title_1,
                            $a?->proposed_title_2,
                            $a?->proposed_title_3,
                        ])),
                        'agreed_title'     => $a?->agreed_title,
                        'english_report'   => (bool) $a?->english_report,
                        'status'           => $a?->status,
                        'student_signed_at'=> $a?->student_signed_at?->toIso8601String(),
                        'acknowledged_at'  => $a?->supervisor_acknowledged_at?->toIso8601String(),
                        'student_name'     => $student?->user?->name,
                        'student_matric'   => $student?->student_id,
                        'programme'        => $student?->program_code,
                        'supervisor_name'  => $a?->supervisorProfile?->user?->name,

                        // Bahagian B of Lampiran B — the project details.
                        'project' => [
                            'title'      => $this->project?->title,
                            'abstract'   => $this->project?->abstract,
                            'objectives' => $this->project?->objectives,
                            'scope'      => $this->project?->scope,
                            'category'   => $this->project?->category?->value,
                            'batch'      => $this->project?->batch,
                            'program'    => $this->project?->program,
                        ],
                    ];
                }),
            ] : null,

            /**
             * The R1 rounds recorded so far, newest first.
             *
             * Loaded only when the caller asked for them, because the worklist
             * shows many milestones per request and each one's history would be
             * a query per row.
             */
            'reviews' => $this->whenLoaded(
                'reviews',
                fn () => \App\Http\Resources\MilestoneReviewResource::collection($this->reviews),
            ),

            /**
             * Bahagian A of the R1 sheet, for the review form to prefill.
             *
             * Derived from the project and its student rather than stored on the
             * milestone: the census fields (name, matric, programme, title) live
             * on the project and student profile, and a copy on every milestone
             * would go stale. The review itself still freezes a snapshot into
             * `milestone_reviews.declaration`, so what a supervisor is recorded
             * as having reviewed never changes afterwards.
             */
            'declaration' => $this->whenLoaded('project', function () {
                $student = $this->project?->students?->first();

                return [
                    'session'       => $this->project?->academicSemester?->name,
                    'academic_session' => $this->project?->academicSemester?->academic_session,
                    'student_name'  => $student?->user?->name,
                    'matric'        => $student?->student_id,
                    'programme'     => $student?->program_code ?? $student?->program,
                    'year_study'    => $student?->current_semester,
                    'project_title' => $this->project?->title,
                    'batch'         => $this->project?->batch,
                ];
            }),

            // The two examiners seated on the student — read from the
            // allocations, so the screen names the people the system appointed.
            'panel' => $this->when(
                $this->isProposal() && $this->relationLoaded('project'),
                fn () => app(\App\Services\ProposalReviewService::class)->panel($this->project),
            ),

            // Lampiran C — the corrections a conditional approval required.
            'lampiran_c_title'   => $this->lampiran_c_title,
            'lampiran_c_actions' => $this->lampiran_c_actions,
            'lampiran_c_at'      => $this->lampiran_c_at?->toIso8601String(),

            /**
             * Lampiran C as its own milestone.
             *
             * `requires_lampiran_c` sits on the *proposal* and says what the panel
             * asked for; the rest are read on the `lampiran_c` milestone itself.
             * Both are sent unconditionally because the screen has to decide
             * whether to show the step at all: a project whose proposal was
             * approved outright has no C milestone and must not be offered one.
             */
            'requires_lampiran_c' => $this->requires_lampiran_c,
            'lampiran_c' => [
                'previous_title'   => $this->lampiran_c_previous_title,
                'new_title'        => $this->lampiran_c_new_title,
                'ic_number'        => $this->lampiran_c_ic_number,
                'passport_number'  => $this->lampiran_c_passport_number,
                'mobile_number'    => $this->lampiran_c_mobile_number,
                'defense_date'     => $this->lampiran_c_defense_date?->toDateString(),
                'actions'          => $this->lampiran_c_actions ?? [],
                'filed_at'         => $this->lampiran_c_filed_at?->toIso8601String(),
                'filed_by'         => $this->lampiran_c_filed_by,
                'is_filed'         => $this->lampiran_c_filed_at !== null,
                'is_signed'        => $this->hasSupervisorSignature(),
                'signed_at'        => $this->supervisor_signed_at?->toIso8601String(),
                'signed_by'        => $this->supervisor_signed_by,
                'has_student_signature'    => $this->hasStudentSignature(),
                'student_signed_at'        => $this->student_signed_at?->toIso8601String(),
                'has_supervisor_signature' => $this->hasSupervisorSignature(),
            ],

            /**
             * The owning project.
             *
             * The cross-project milestone worklist renders the project on every
             * row, and that screen cannot issue a second request per row to
             * resolve it.
             */
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id'          => $this->project->id,
                'code'        => $this->project->code,
                'title'       => $this->project->title,
                'psm_part'    => $this->project->psm_part,
                'batch'       => $this->project->batch,
                'category'    => $this->project->category->value,
                'category_label' => $this->project->category->label(),

                'students' => $this->project->relationLoaded('students')
                    ? $this->project->students->map(fn ($student) => [
                        'id'         => $student->id,
                        'student_id' => $student->student_id,
                        'name'       => $student->user?->name,
                        // Needed by the UI to decide whether the signed-in
                        // student owns this milestone.
                        'user_id'    => $student->user_id,
                    ])->values()->all()
                    : null,

                /**
                 * Everyone who supervises this project, derived from its
                 * students' active supervision assignments. The detail screen
                 * needs this to show who may act on the submission.
                 */
                'supervisors' => $this->project->relationLoaded('students')
                    ? $this->project->students
                        ->flatMap(fn ($student) => $student->activeSupervisions)
                        ->map(fn ($assignment) => $assignment->supervisorProfile?->user)
                        ->filter()
                        ->unique('id')
                        ->map(fn ($user) => [
                            'id'   => $user->id,
                            'name' => $user->name,
                        ])->values()->all()
                    : null,
            ] : null),

            /**
             * What a good submission looks like, copied from the template item
             * at instantiation. Lives on the template rather than the milestone
             * row so the wording can be corrected for future projects without
             * rewriting history for ones already under way.
             */
            'deliverable_expectation' => $this->whenLoaded(
                'templateItem',
                fn () => $this->templateItem?->deliverable_expectation,
            ),

            // Deadline override audit (Module 7)
            'deadline_override_reason' => $this->deadline_override_reason,

            // -----------------------------------------------------------------
            // Files and history
            // -----------------------------------------------------------------
            'files' => SubmissionFileResource::collection($this->whenLoaded('currentFiles')),

            /**
             * The same list under the name the review cards read.
             *
             * The SPA's R1 card and OSHE card were written against
             * `current_files` while the resource has always published `files`,
             * so both silently saw "no submission" on a milestone that had one.
             * Both names are sent rather than one being renamed: `files` is used
             * by the existing submission card, and renaming it would break that
             * instead.
             */
            'current_files' => SubmissionFileResource::collection($this->whenLoaded('currentFiles')),

            'file_count'   => $this->whenLoaded('currentFiles', fn () => $this->currentFiles->count()),

            /**
             * Upload constraints, resolved server-side.
             *
             * The SPA used to hardcode a 32 MB ceiling and build its `accept`
             * list from the milestone's own types alone. The server enforced
             * 25 MB (psm.submission.max_mb) and a different, platform-wide
             * allowlist — so a file the form was happy to stage could be
             * rejected on submit, and a type the form offered could be refused.
             * Publishing the effective values means the form can only offer
             * what the API will actually take.
             */
            'max_files'          => $this->effectiveMaxFiles(),
            'max_file_mb'        => $this->maxFileMegabytes(),
            'allowed_file_types' => $this->allowed_file_types,
            'allowed_extensions' => $this->effectiveAllowedExtensions(),

            /**
             * Whether the signed-in user may withdraw a file from this
             * milestone — the same `withdraw` ability the DELETE endpoint
             * authorises, so the button cannot offer an action the API refuses.
             *
             * Gated on `currentFiles` being loaded so the cross-project
             * worklist, which does not load it, does not pay a policy check
             * (and a members query) for every row.
             */
            'can_withdraw' => $this->whenLoaded(
                'currentFiles',
                fn () => (bool) $request->user()?->can('withdraw', $this->resource),
            ),

            'events' => SubmissionEventResource::collection($this->whenLoaded('events')),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
