<?php

namespace App\Models;

use App\Enums\ProjectCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 8 — One frozen ranking row.
 *
 * Every column here is a *copy*, never a join. The public endpoint therefore
 * cannot leak a student's email or phone, and withdrawing a project (or a
 * grade correction) never mutates a published board.
 */
class LeaderboardEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'leaderboard_id',
        'project_id',
        'final_grade_id',
        'rank',
        'is_winner',
        'is_top_n',
        'project_title',
        'project_abstract',
        'project_code',
        'category',
        'program',
        'students',
        'supervisors',
        'display_score',
        'score_label',
        'assessor_count',
        'award_title',
        'citation',
        'poster_path',
        'is_hidden',
    ];

    protected function casts(): array
    {
        return [
            'category'     => ProjectCategory::class,
            'students'     => 'array',
            'supervisors'  => 'array',
            'is_winner'    => 'boolean',
            'is_top_n'     => 'boolean',
            'is_hidden'    => 'boolean',
            'display_score'=> 'decimal:2',
            'rank'         => 'integer',
            'assessor_count' => 'integer',
        ];
    }

    public function leaderboard(): BelongsTo
    {
        return $this->belongsTo(Leaderboard::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function finalGrade(): BelongsTo
    {
        return $this->belongsTo(FinalGrade::class);
    }

    // -----------------------------------------------------------------
    // Public rendering helpers — used by the no-login endpoint
    // -----------------------------------------------------------------

    /** Only ever expose names and student IDs, never contact details. */
    public function publicStudents(): array
    {
        if (! $this->leaderboard?->show_student_names) {
            return [];
        }

        return collect($this->students ?? [])
            ->map(fn (array $s) => [
                'name'       => $s['name'] ?? null,
                'student_id' => $s['student_id'] ?? null,
            ])
            ->filter(fn (array $s) => $s['name'] !== null)
            ->values()
            ->all();
    }

    public function publicSupervisors(): array
    {
        return collect($this->supervisors ?? [])
            ->pluck('name')
            ->filter()
            ->values()
            ->all();
    }

    public function studentNames(): string
    {
        return collect($this->students ?? [])->pluck('name')->filter()->implode(', ');
    }

    /** Ordinal medal label for the podium display. */
    public function medal(): ?string
    {
        return match ($this->rank) {
            1 => 'gold',
            2 => 'silver',
            3 => 'bronze',
            default => null,
        };
    }

    public function rankSuffix(): string
    {
        return match (true) {
            $this->rank % 100 >= 11 && $this->rank % 100 <= 13 => 'th',
            $this->rank % 10 === 1 => 'st',
            $this->rank % 10 === 2 => 'nd',
            $this->rank % 10 === 3 => 'rd',
            default => 'th',
        };
    }

    /** Truncated abstract for the card layout, full text on the detail view. */
    public function shortAbstract(int $limit = 180): string
    {
        $abstract = trim((string) $this->project_abstract);

        return \Illuminate\Support\Str::limit($abstract, $limit, '…');
    }
}
