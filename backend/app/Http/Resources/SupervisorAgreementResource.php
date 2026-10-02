<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 2/3 — Lampiran A payload.
 *
 * The agreement endpoints used to return raw Eloquent models, which serialised
 * the relations under their method names (`studentProfile`) while the SPA read
 * `student_profile`, so every name on the registration screens rendered as an
 * em dash. It also leaked `decided_by`, `metadata` and `deleted_at` to every
 * caller. This resource is the single shape both the list and the detail page
 * read from.
 *
 * @mixin \App\Models\SupervisorAgreement
 */
class SupervisorAgreementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        $student = $this->whenLoaded('studentProfile', fn () => $this->studentProfile);
        $supervisor = $this->whenLoaded('supervisorProfile', fn () => $this->supervisorProfile);

        return [
            'id'             => $this->id,
            'session'        => $this->session,
            'psm_part'       => $this->psm_part,
            'status'         => $this->status,
            'status_label'   => $this->statusLabel(),
            'english_report' => (bool) $this->english_report,

            'proposed_title_1' => $this->proposed_title_1,
            'proposed_title_2' => $this->proposed_title_2,
            'proposed_title_3' => $this->proposed_title_3,
            'agreed_title'     => $this->agreed_title,
            'titles'           => array_values(array_filter($this->proposedTitles())),

            'student' => $student ? [
                'profile_id' => $student->id,
                'user_id'    => $student->user_id,
                'name'       => $student->user?->name,
                'student_id' => $student->student_id,
                'program'    => $student->program_code,
                'batch'      => $student->batch,
                'email'      => $student->user?->email,
            ] : null,

            'supervisor' => $supervisor ? [
                'profile_id' => $supervisor->id,
                'user_id'    => $supervisor->user_id,
                'name'       => $supervisor->label(),
                'staff_no'   => $supervisor->staff_no,
            ] : null,

            'signed_at'          => $this->student_signed_at?->toIso8601String(),
            'acknowledged_at'    => $this->supervisor_acknowledged_at?->toIso8601String(),
            'jkpsm_received_at'  => $this->jkpsm_received_at?->toIso8601String(),
            'decided_at'         => $this->decided_at?->toIso8601String(),
            'decided_by'         => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'rejection_reason'   => $this->rejection_reason,

            'supervision_assignment_id' => $this->supervision_assignment_id,

            // The project this agreement produced, so the registration list can
            // link straight to it instead of making the student go and find it.
            'project' => $this->whenLoaded('projects', fn () => $this->projects->first())
                ? [
                    'id'    => $this->projects->first()->id,
                    'code'  => $this->projects->first()->code,
                    'title' => $this->projects->first()->title,
                    'status'=> $this->projects->first()->status,
                ]
                : null,

            'can_acknowledge' => $this->status === \App\Models\SupervisorAgreement::STATUS_PENDING_SUPERVISOR,
            'can_decide'      => $this->status === \App\Models\SupervisorAgreement::STATUS_PENDING_JKPSM,
            'can_submit_b'    => $this->isApproved(),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** Wording the SPA shows instead of a raw status string. */
    protected function statusLabel(): string
    {
        return match ($this->status) {
            \App\Models\SupervisorAgreement::STATUS_PENDING_SUPERVISOR => 'Awaiting supervisor acknowledgement',
            \App\Models\SupervisorAgreement::STATUS_PENDING_JKPSM      => 'Awaiting JKPSM approval',
            \App\Models\SupervisorAgreement::STATUS_APPROVED           => 'Approved',
            \App\Models\SupervisorAgreement::STATUS_REJECTED           => 'Rejected',
            \App\Models\SupervisorAgreement::STATUS_CANCELLED          => 'Cancelled',
            default                                                  => $this->status,
        };
    }
}
