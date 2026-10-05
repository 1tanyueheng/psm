<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 3 — Project payload.
 *
 * @mixin \App\Models\Project
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'title'          => $this->title,
            'abstract'       => $this->abstract,
            'objectives'     => $this->objectives,
            'scope'          => $this->scope,

            'category'       => $this->category->value,
            'category_label' => $this->category->label(),
            'psm_part'       => $this->psm_part,

            'academic_session' => $this->academic_session,
            'batch'            => $this->batch,
            'program'          => $this->program,

            /**
             * The term this project belongs to.
             *
             * `academic_session` alone cannot answer "which semester am I
             * looking at?" once PSM 1 and PSM 2 run concurrently — both sit in
             * the same session string. Only the relation distinguishes them.
             */
            'academic_semester' => $this->whenLoaded('academicSemester', fn () => $this->academicSemester ? [
                'id'         => $this->academicSemester->id,
                'name'       => $this->academicSemester->name,
                'short_name' => $this->academicSemester->shortName(),
                'is_active'  => (bool) $this->academicSemester->is_active,
            ] : null),
            'academic_semester_id' => $this->academic_semester_id,

            'status'           => $this->status,
            'submitted_at'     => $this->submitted_at?->toIso8601String(),
            'approved_at'      => $this->approved_at?->toIso8601String(),
            'rejection_reason' => $this->rejection_reason,
            'archived_at'      => $this->archived_at?->toIso8601String(),

            // Module 3 — progress figures the dashboard renders directly
            'milestone_progress' => $this->milestoneProgressPercent(),

            /**
             * The milestone the student is on, as a compact pair.
             *
             * The list used to read `next_milestone_name` / `next_milestone_due_at`,
             * which this resource has never emitted — so every row rendered a
             * blank milestone and a 0% bar. Emitted here as one object to keep
             * the name and its date from drifting apart.
             */
            'next_milestone' => $this->whenLoaded('milestones', function () {
                $next = $this->currentMilestone();

                return $next === null ? null : [
                    'id'      => $next->id,
                    // Milestones carry `title`, not `name` — matching the
                    // column rather than inventing a second label.
                    'title'   => $next->title,
                    'sequence'=> $next->sequence,
                    'due_at'  => $next->due_at?->toIso8601String(),
                    'status'  => $next->status,
                ];
            }),
            'current_stage'      => $this->when(
                $request->boolean('with_stage'),
                fn () => $this->currentStageLabel()
            ),

            // Module 8 consent
            'leaderboard_opt_out' => (bool) $this->leaderboard_opt_out,

            'students'    => $this->whenLoaded('students', fn () => $this->students->map(fn ($s) => [
                'id'         => $s->id,
                // The signed-in student matches themselves on this, so the
                // dashboard can ask for their own mark breakdown without
                // guessing from an email address.
                'user_id'    => $s->user_id,
                'name'       => $s->user?->name,
                'student_id' => $s->student_id,
                'program'    => $s->program,
                'batch'      => $s->batch,
                'email'      => $s->user?->email,
                'is_leader'  => (bool) $s->pivot->is_leader,
            ])),

            'supervisors' => $this->whenLoaded('students', function () {
                return $this->students
                    ->flatMap(fn ($s) => $s->activeSupervisions ?? collect())
                    ->unique('supervisor_profile_id')
                    ->map(fn ($a) => [
                        'id'       => $a->supervisorProfile?->id,
                        'name'     => $a->supervisorProfile?->label(),
                        'role'     => $a->role,
                        'psm_part' => $a->psm_part,
                    ])
                    ->values();
            }),

            /**
             * The current panel — active allocations only.
             *
             * `examiner_assignments` keeps retired rows on purpose: when a
             * project is re-paired, the old allocation is deactivated rather
             * than deleted so the audit trail survives. Emitting those here made
             * a project with two examiners display three, because a retired
             * allocation is not a panel member. Their marks still count towards
             * a released aggregate; that is shown on the mark breakdown, not as
             * a current examiner.
             */
            'examiners' => $this->whenLoaded('examinerAssignments', fn () => $this->examinerAssignments
                ->filter(fn ($a) => (bool) $a->is_active)
                ->values()
                ->map(fn ($a) => [
                    'id'         => $a->id,
                    'user_id'    => $a->examiner_id,
                    'name'       => $a->examiner?->displayName(),
                    'panel_role' => $a->panel_role,
                    'psm_part'   => $a->psm_part,
                ])),

            /**
             * How full the examiner panel is against the required size.
             *
             * The panel is fixed at two, so a project sitting on one examiner
             * cannot produce a valid examiner mark — the average needs both.
             * Exposing the count lets the allocation screen say so rather than
             * leaving a coordinator to notice a missing second name.
             */
            'examiner_panel' => $this->whenLoaded('examinerAssignments', function () {
                $active = $this->examinerAssignments->where('is_active', true)->count();
                $required = (int) config('psm.examiner_panel_size', 2);

                return [
                    'assigned' => $active,
                    'required' => $required,
                    'is_complete' => $active >= $required,
                ];
            }),

            'milestones' => MilestoneResource::collection($this->whenLoaded('milestones')),

            // Aggregate result. The number is withheld until the coordinator
            // releases the mark — otherwise a student could read their
            // provisional total off the project page before release. Staff see
            // it at any status; the `status` key is always present so the UI can
            // say "awaiting release".
            'final_grade' => $this->whenLoaded('finalGrades', function () {
                $grade = $this->finalGrades->sortByDesc('aggregate_percent')->first();

                if ($grade === null) {
                    return null;
                }

                $viewer = request()->user();
                $isStaff = $viewer !== null && $viewer->hasRole('admin', 'coordinator');
                $released = $grade->status === 'released';

                if (! $isStaff && ! $released) {
                    return null;
                }

                return [
                    // `(float) null` is 0.0 — a student whose panel has not
                    // returned a form yet has no mark, not a mark of zero.
                    'final_mark'        => $grade->final_mark !== null ? (float) $grade->final_mark : null,
                    'aggregate_percent' => $grade->aggregate_percent !== null ? (float) $grade->aggregate_percent : null,
                    'milestone_score'   => $grade->milestone_score !== null ? (float) $grade->milestone_score : null,
                    'status'            => $grade->status,
                    'assessor_count'    => $grade->assessor_count,
                    'released_at'       => $grade->released_at?->toIso8601String(),
                ];
            }),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
