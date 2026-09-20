<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\ArchivedProject;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 7 — Archive and retrieval.
 *
 * Archiving produces a self-contained record. Once written, the archive must
 * remain readable even if the source project, its users, or its rubric are
 * later removed — so every field is denormalised at archive time.
 */
class ArchiveService
{
    public function __construct(
        protected AuditLogger $audit,
    ) {
    }

    /**
     * Archive a completed project into the long-term repository.
     */
    public function archive(Project $project, User $actor, ?string $note = null, bool $public = false): ArchivedProject
    {
        if ($project->archivedRecord()->exists()) {
            throw new InvalidArgumentException(
                "Project {$project->code} has already been archived."
            );
        }

        return DB::transaction(function () use ($project, $actor, $note, $public) {
            $project->load([
                'students.user',
                'members',
                'milestones.files',
                'evaluations.assessor',
                'finalGrades',
            ]);

            $grade = $project->primaryGrade();

            $record = ArchivedProject::create([
                'project_id'       => $project->id,
                'code'             => $project->code,
                'title'            => $project->title,
                'abstract'         => $project->abstract,
                'category'         => $project->category->value,
                'psm_part'         => $project->psm_part,
                'academic_session' => $project->academic_session,
                'batch'            => $project->batch,
                'program'          => $project->program,

                'students'    => $this->studentRoster($project),
                'supervisors' => $this->supervisorRoster($project),
                'examiners'   => $this->examinerRoster($project),

                'final_mark'   => $grade?->final_mark,
                'grade_letter' => $grade?->grade_letter,
                'grade_point'  => $grade?->grade_point,

                'milestone_summary' => $this->milestoneSummary($project),
                'grade_breakdown'   => $grade?->computation_breakdown,

                'documents' => $this->documentManifest($project),

                'keywords'         => $this->extractKeywords($project),
                'supervisor_names' => $this->supervisorNames($project),

                'is_public'   => $public,
                'archived_at' => now(),
                'archived_by' => $actor->id,
                'archive_note'=> $note,
            ]);

            // Retire the live project
            $project->update([
                'status'      => 'archived',
                'archived_at' => now(),
            ]);

            $this->audit->log(
                action: AuditAction::ProjectArchived,
                description: "Archived {$project->code} ({$project->title})",
                subject: $record,
                actor: $actor,
            );

            return $record;
        });
    }

    /**
     * Archive every completed-but-unarchived project in a batch.
     *
     * @return array{archived:int, skipped:int, errors:array<int,string>}
     */
    public function archiveBatch(string $batch, User $actor, string $psmPart = 'PSM2'): array
    {
        $archived = 0;
        $skipped  = 0;
        $errors   = [];

        Project::query()
            ->where('batch', $batch)
            ->where('psm_part', $psmPart)
            ->where('status', 'completed')
            ->whereNull('archived_at')
            ->with('archivedRecord')
            ->chunkById(50, function ($projects) use (&$archived, &$skipped, &$errors, $actor) {
                foreach ($projects as $project) {
                    try {
                        $this->archive($project, $actor, 'Batch archival');
                        $archived++;
                    } catch (\Throwable $e) {
                        $skipped++;
                        $errors[] = "{$project->code}: {$e->getMessage()}";
                    }
                }
            });

        return compact('archived', 'skipped', 'errors');
    }

    /**
     * Search the archive. This is the "look up a past PSM project" feature.
     */
    public function search(
        ?string $term = null,
        ?string $session = null,
        ?string $batch = null,
        ?string $category = null,
        int $perPage = 20,
    ) {
        return ArchivedProject::query()
            ->when($term, fn ($q) => $q->search($term))
            ->when($session, fn ($q) => $q->forSession($session))
            ->when($batch, fn ($q) => $q->forBatch($batch))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->orderByDesc('archived_at')
            ->paginate($perPage);
    }

    /** Distinct academic sessions present in the archive, for the filter UI. */
    public function availableSessions(): Collection
    {
        return ArchivedProject::query()
            ->select('academic_session')
            ->distinct()
            ->orderByDesc('academic_session')
            ->pluck('academic_session');
    }

    /**
     * Rebuild an archived project back into the live tables.
     * Intentionally conservative: it restores the project row and its
     * milestone skeleton, not historical grades (which stay frozen in the
     * archive as the authoritative record).
     */
    public function restore(ArchivedProject $record, User $actor): Project
    {
        if ($record->project_id && Project::withTrashed()->find($record->project_id)) {
            throw new InvalidArgumentException(
                'The live project still exists; un-archive it instead of restoring it.'
            );
        }

        return DB::transaction(function () use ($record, $actor) {
            $project = Project::create([
                'code'             => $record->code,
                'title'            => $record->title,
                'abstract'         => $record->abstract,
                'category'         => $record->category,
                'psm_part'         => $record->psm_part,
                'academic_session' => $record->academic_session,
                'batch'            => $record->batch,
                'program'          => $record->program,
                'status'           => 'completed',
                'created_by'       => $actor->id,
                'approved_by'      => $actor->id,
                'approved_at'      => now(),
            ]);

            $record->update(['project_id' => $project->id]);

            $this->audit->log(
                action: AuditAction::ArchiveRestored,
                description: "Restored {$record->code} from archive",
                subject: $project,
                actor: $actor,
            );

            return $project;
        });
    }

    // -----------------------------------------------------------------
    // Snapshot builders
    // -----------------------------------------------------------------

    protected function studentRoster(Project $project): array
    {
        return $project->students->map(fn ($student) => [
            // user_id is kept so a student can still retrieve their own
            // archived project after the live account is gone.
            'user_id'    => $student->user_id,
            'name'       => $student->user?->name,
            'student_id' => $student->student_id,
            'program'    => $student->program,
            'is_leader'  => (bool) $student->pivot->is_leader,
        ])->all();
    }

    protected function supervisorRoster(Project $project): array
    {
        $ids = $project->students
            ->flatMap(fn ($s) => $s->activeSupervisions->pluck('supervisor_profile_id'))
            ->unique();

        return \App\Models\SupervisorProfile::query()
            ->whereIn('id', $ids)
            ->with('user')
            ->get()
            ->map(fn ($s) => [
                'name'     => $s->user?->name,
                'staff_no' => $s->staff_no,
            ])
            ->all();
    }

    protected function examinerRoster(Project $project): array
    {
        return $project->examinerAssignments()
            ->with('examiner')
            ->get()
            ->map(fn ($a) => [
                'name'       => $a->examiner?->name,
                'examiner_id'=> $a->examiner_id,
                'panel_role' => $a->panel_role,
                'psm_part'   => $a->psm_part,
            ])
            ->all();
    }

    protected function milestoneSummary(Project $project): array
    {
        return $project->milestones->map(fn ($m) => [
            'code'          => $m->code,
            'title'         => $m->title,
            'weight_percent'=> (float) $m->weight_percent,
            'status'        => $m->status->value,
            'status_label'  => $m->status->label(),
            'due_at'        => $m->due_at?->toDateString(),
            'submitted_at'  => $m->submitted_at?->toIso8601String(),
            'approved_at'   => $m->approved_at?->toIso8601String(),
            'revision_count'=> $m->revision_count,
            'file_count'    => $m->files->count(),
        ])->all();
    }

    /** Where the retained files live, so an archive can be restored or migrated. */
    protected function documentManifest(Project $project): array
    {
        return $project->milestones
            ->flatMap(fn ($m) => $m->files->map(fn ($f) => [
                'milestone'     => $m->code,
                'revision_no'   => $f->revision_no,
                'original_name' => $f->original_name,
                'disk'          => $f->disk,
                'path'          => $f->path,
                'mime_type'     => $f->mime_type,
                'size_bytes'    => $f->size_bytes,
            ]))
            ->all();
    }

    /** Cheap keyword bag from the title, objectives and abstract. */
    protected function extractKeywords(Project $project): string
    {
        $text = implode(' ', array_filter([
            $project->title,
            $project->objectives,
            $project->abstract,
        ]));

        $words = preg_split('/[^a-z0-9+#]+/i', strtolower($text)) ?: [];

        $stop = ['the', 'and', 'for', 'with', 'a', 'an', 'of', 'to', 'in', 'on',
                 'using', 'based', 'system', 'development', 'study', 'research'];

        $keywords = collect($words)
            ->filter(fn ($w) => strlen($w) > 3 && ! in_array($w, $stop, true))
            ->countBy()
            ->sortDesc()
            ->take(15)
            ->keys();

        return $keywords->implode(', ');
    }

    protected function supervisorNames(Project $project): string
    {
        return collect($this->supervisorRoster($project))
            ->pluck('name')
            ->filter()
            ->implode(', ');
    }
}
