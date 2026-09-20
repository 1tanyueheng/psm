<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 7 — Self-contained archive record of a completed PSM project.
 *
 * Fully denormalised on purpose: after archiving, the record must be readable
 * for years even if users are deleted, rubrics are retired, or grades are
 * recomputed. Nothing here may reference a live row as its only source.
 */
class ArchivedProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'code',
        'title',
        'abstract',
        'category',
        'psm_part',
        'academic_session',
        'batch',
        'program',
        'students',
        'supervisors',
        'examiners',
        'final_mark',
        'grade_letter',
        'grade_point',
        'milestone_summary',
        'grade_breakdown',
        'documents',
        'keywords',
        'supervisor_names',
        'is_public',
        'archived_at',
        'archived_by',
        'archive_note',
    ];

    protected function casts(): array
    {
        return [
            'students'          => 'array',
            'supervisors'       => 'array',
            'examiners'         => 'array',
            'milestone_summary' => 'array',
            'grade_breakdown'   => 'array',
            'documents'         => 'array',
            'final_mark'        => 'decimal:2',
            'grade_point'       => 'decimal:2',
            'is_public'         => 'boolean',
            'archived_at'       => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    // -----------------------------------------------------------------
    // Presentation — the archive is a reading surface, not a data source
    // -----------------------------------------------------------------

    /** "Aisyah binti Rahman (S12345), Tan Wei Ming (S12346)" */
    public function studentList(): string
    {
        return collect($this->students ?? [])
            ->map(fn (array $s) => ($s['name'] ?? 'Unknown').(! empty($s['student_id']) ? " ({$s['student_id']})" : ''))
            ->implode(', ');
    }

    public function supervisorList(): string
    {
        return collect($this->supervisors ?? [])
            ->pluck('name')
            ->filter()
            ->implode(', ');
    }

    public function documentCount(): int
    {
        return count($this->documents ?? []);
    }

    /** Total bytes of retained documents, for the archive storage report. */
    public function documentBytes(): int
    {
        return (int) collect($this->documents ?? [])->sum(fn ($d) => (int) ($d['size_bytes'] ?? 0));
    }

    // -----------------------------------------------------------------
    // Search
    // -----------------------------------------------------------------

    /**
     * Multi-column search for the archive retrieval screen. Matches title,
     * abstract, code, student names and the flattened supervisor list.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('title', 'like', $like)
              ->orWhere('abstract', 'like', $like)
              ->orWhere('code', 'like', $like)
              ->orWhere('keywords', 'like', $like)
              ->orWhere('supervisor_names', 'like', $like)
              ->orWhere('students', 'like', $like);
        });
    }

    public function scopeForSession(Builder $query, string $session): Builder
    {
        return $query->where('academic_session', $session);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeForBatch(Builder $query, string $batch): Builder
    {
        return $query->where('batch', $batch);
    }
}
