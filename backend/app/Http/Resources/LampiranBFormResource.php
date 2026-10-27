<?php

namespace App\Http\Resources;

use App\Models\LampiranBForm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One filing of Lampiran B.
 *
 * Shaped for the milestone page, which is the one place a refused title and the
 * title replacing it are read side by side (agreed: the comparison lives on the
 * milestone, not on a screen of its own).
 *
 * @mixin LampiranBForm
 */
class LampiranBFormResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'project_id'  => $this->project_id,
            'revision_no' => $this->revision_no,
            'label'       => $this->label(),

            'title'        => $this->title,
            'project_type' => $this->project_type,
            'field_study'  => $this->field_study,
            'requirements' => [
                'software'   => $this->req_software,
                'hardware'   => $this->req_hardware,
                'technology' => $this->req_technology,
            ],
            'origin' => $this->origin,

            'status'       => $this->status,
            'is_current'   => $this->superseded_by_id === null,
            'is_accepted'  => $this->isAccepted(),
            'is_rejected'  => $this->isRejected(),

            // The panel's objection, which the student must be able to read while
            // revising — the whole point of keeping the refused form.
            'rejected_reason' => $this->rejected_reason,
            'rejected_at'     => $this->rejected_at?->toIso8601String(),
            'rejected_by'     => $this->rejectedBy?->name,

            'supervisor' => $this->supervisorProfile ? [
                'profile_id' => $this->supervisorProfile->id,
                'name'       => $this->supervisorProfile->label(),
            ] : null,

            'signed_at' => $this->supervisor_signed_at?->toIso8601String(),
            'is_signed' => $this->isSigned(),

            /**
             * Whether the signature on this form is a demo artefact.
             *
             * The seeded images carry a small DEMO mark, and this says the same
             * in the data. A screen printing a signature can state plainly that
             * it is not a real approval, rather than leaving it to a reader to
             * notice a watermark.
             */
            'signature_is_demo' => $this->isSeededSignature(),
            'has_signature_image' => $this->supervisor_signature_path !== null,

            'supersedes_id'   => $this->supersedes_id,
            'superseded_by_id' => $this->superseded_by_id,
            'milestone_id'    => $this->milestone_id,

            // What this reader may do with this filing.
            'can_file' => $request->user()?->can('file', $this->resource) ?? false,
            'can_sign' => $request->user()?->can('sign', $this->resource) ?? false,

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
