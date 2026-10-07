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

            /**
             * Whether the bytes are actually there — **opt-in**, and off by
             * default.
             *
             * This is a real request to the storage backend. On a local disk
             * that is a cheap `stat`, but on object storage it is a network
             * round trip, and this resource is serialised once per file inside
             * every milestone in a list. A cohort screen would fire hundreds of
             * them and stall on latency the user cannot see the cause of.
             *
             * Nothing in the SPA reads the field: the archive screen already
             * treats a missing file by catching the download failure and saying
             * so, which is the honest place to discover it. Callers that really
             * want to verify a specific file can ask with `?with_exists=1`.
             */
            'exists_on_disk' => $this->when(
                $request->boolean('with_exists'),
                fn () => $this->exists()
            ),

            'superseded_at' => $this->superseded_at?->toIso8601String(),
            'uploaded_at'   => $this->created_at?->toIso8601String(),

            // Download is a separate, audited request
            'download_url' => "/api/submissions/{$this->id}/download",
        ];
    }
}
