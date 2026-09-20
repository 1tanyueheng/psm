<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 3 — Submission file metadata.
 *
 * Deliberately omits the storage path: the client receives an id and fetches
 * the bytes through an authorised, audited download endpoint.
 *
 * @mixin \App\Models\SubmissionFile
 */
class SubmissionFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'original_name' => $this->original_name,
            'extension'     => $this->extension(),
            'mime_type'     => $this->mime_type,
            'size_bytes'    => $this->size_bytes,
            'human_size'    => $this->humanSize(),
            'is_pdf'        => $this->isPdf(),

            'revision_no' => $this->revision_no,
            'is_current'  => (bool) $this->is_current,

            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader ? [
                'id'   => $this->uploader->id,
                'name' => $this->uploader->name,
            ] : null),

            // Flags a suspiciously small PDF, which a reviewer may want to check
            'is_suspiciously_small' => $this->looksSuspiciouslySmall(),
            'exists_on_disk'        => $this->exists(),

            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'uploaded_at'   => $this->created_at?->toIso8601String(),

            // Download is a separate, audited request
            'download_url' => "/api/submissions/{$this->id}/download",
        ];
    }
}
