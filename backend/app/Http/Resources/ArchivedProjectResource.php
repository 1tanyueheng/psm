<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Module 7 — Archived project record.
 *
 * @mixin \App\Models\ArchivedProject
 */
class ArchivedProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'code' => $this->code,

            'title'    => $this->title,
            'abstract' => $this->abstract,

            'category'       => $this->category,
            'category_label' => $this->category === 'system' ? 'System Development' : 'Research',
            'psm_part'       => $this->psm_part,

            'academic_session' => $this->academic_session,
            'batch'            => $this->batch,
            'program'          => $this->program,

            // Frozen rosters
            'students'    => $this->students,
            'supervisors' => $this->supervisors,
            'examiners'   => $this->examiners,

            'student_list'    => $this->studentList(),
            'supervisor_list' => $this->supervisorList(),

            // Outcome
            'final_mark'   => $this->final_mark !== null ? (float) $this->final_mark : null,
            'grade_letter' => $this->grade_letter,
            'grade_point'  => $this->grade_point !== null ? (float) $this->grade_point : null,

            'milestone_summary' => $this->milestone_summary,
            'grade_breakdown'   => $this->when(
                $request->boolean('with_breakdown'),
                fn () => $this->grade_breakdown
            ),

            'documents'      => $this->documents,
            'document_count' => $this->documentCount(),
            'document_bytes' => $this->documentBytes(),

            'keywords' => $this->keywords,
            'is_public'=> (bool) $this->is_public,

            'archive_note' => $this->archive_note,
            'archived_at'  => $this->archived_at?->toIso8601String(),
        ];
    }
}
