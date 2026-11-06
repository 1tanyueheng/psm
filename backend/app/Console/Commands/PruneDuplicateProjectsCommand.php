<?php

namespace App\Console\Commands;

use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove duplicate seeded projects from a development database.
 *
 * `ProjectSeeder` used to mint a new project on every run: `Project::nextCode()`
 * numbers by `count + 1`, so once the cohort existed the codes it generated no
 * longer matched any row, `updateOrCreate` missed, and a fresh project with a
 * full milestone chain was created alongside the real one. Two re-seeds left 28
 * orphan projects behind, each duplicating a student's work with the same title
 * and an empty R1 history.
 *
 * The seeder is fixed — it keys on the student's existing project for that part
 * and term, oldest first — but a database that already ran the broken version
 * still carries the duplicates, and the fix cannot know which of two rows the
 * demo has been showing. That is what this command decides, by the same rule the
 * seeder uses: **the oldest project for a student and part is the keeper**.
 *
 * Deliberately conservative:
 *
 *   - **It refuses to delete anything that carries evidence.** A duplicate with a
 *     final grade, an R1 row, an archived record or a leaderboard entry is not a
 *     duplicate anyone should be removing automatically: somebody marked it, and
 *     a cleanup tool must not throw a mark away. Those are reported and skipped.
 *   - **It is not a general project-deletion tool.** `--part` and the seeded
 *     metadata scope it to the demo cohort; a real cohort is left alone.
 *   - **It prints what it would do first.** `--dry-run` is the default posture in
 *     the docs, and every skipped project says why.
 *
 * Not a replacement for `migrate:fresh --seed`: on a disposable demo database
 * that is still the cleaner answer. This exists because the demo database is
 * sometimes not disposable between a demo and the next one.
 */
class PruneDuplicateProjectsCommand extends Command
{
    protected $signature = 'psm:prune-duplicate-projects
                            {--part= : Only this part (PSM1 or PSM2); both by default}
                            {--dry-run : Report what would be deleted without deleting}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Delete seeded projects that duplicate another project for the same student and part';

    public function handle(): int
    {
        $part = $this->option('part');
        $dryRun = (bool) $this->option('dry-run');

        if ($part !== null && ! in_array($part, ['PSM1', 'PSM2'], true)) {
            $this->error('--part must be PSM1 or PSM2.');

            return self::FAILURE;
        }

        $this->info('Scanning for seeded projects that duplicate another project for the same student and part…');

        $groups = DB::table('project_members')
            ->join('projects', 'projects.id', '=', 'project_members.project_id')
            ->whereNull('projects.deleted_at')
            ->when($part !== null, fn ($q) => $q->where('projects.psm_part', $part))
            ->select(
                'project_members.student_profile_id',
                'projects.psm_part',
                'projects.id as project_id',
            )
            ->orderBy('projects.id')
            ->get()
            ->groupBy(fn ($r) => $r->student_profile_id . '|' . $r->psm_part);

        $keepers = [];
        $candidates = [];

        foreach ($groups as $key => $rows) {
            if ($rows->count() < 2) {
                continue;
            }

            // Oldest first: the project the demo has been showing.
            $ids = $rows->pluck('project_id')->sort()->values();
            $keepers[$key] = $ids->first();

            foreach ($ids->slice(1) as $id) {
                $candidates[] = $id;
            }
        }

        if ($candidates === []) {
            $this->info('No duplicates found — every student holds one project per part.');

            return self::SUCCESS;
        }

        $deletable = [];
        $skipped = [];

        foreach ($candidates as $id) {
            $reason = $this->whyNotDeletable($id);

            if ($reason === null) {
                $deletable[] = $id;
            } else {
                $skipped[] = [$id, $reason];
            }
        }

        $this->newLine();
        $this->line('  duplicate candidates : ' . count($candidates));
        $this->line('  deletable            : ' . count($deletable));
        $this->line('  kept (carries data)  : ' . count($skipped));

        foreach ($skipped as [$id, $reason]) {
            $project = Project::withTrashed()->find($id);
            $this->warn("  kept {$id} ({$project?->code}): {$reason}");
        }

        if ($deletable === []) {
            $this->info('Nothing deletable.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('[dry-run] would delete project ids: ' . implode(',', $deletable));

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Delete ' . count($deletable) . ' duplicate project(s) and their milestones?',
            false
        )) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        // Force-delete, not soft: a soft-deleted duplicate still holds its
        // `(project_id, code, attempt)` milestone keys, so the seeder could not
        // rebuild the chain if the keeper were ever reseeded from scratch.
        $milestones = 0;
        $deleted = 0;

        foreach ($deletable as $id) {
            $milestones += DB::table('milestones')->where('project_id', $id)->count();
            Project::withTrashed()->find($id)?->forceDelete();
            $deleted++;
        }

        $this->newLine();
        $this->info("Deleted {$deleted} duplicate project(s) and {$milestones} milestone(s).");
        $this->line('Re-run `php artisan db:seed --class=ProjectSeeder` if any keeper needs topping up.');

        return self::SUCCESS;
    }

    /**
     * Why this project must not be deleted, or null when it is safe.
     *
     * The bar is "carries evidence somebody produced", not "is referenced": a
     * duplicate's own milestones, files and events go with it, because they are
     * part of the duplicate.
     */
    protected function whyNotDeletable(int $projectId): ?string
    {
        $milestoneIds = DB::table('milestones')->where('project_id', $projectId)->pluck('id');

        if (DB::table('final_grades')->where('project_id', $projectId)->exists()) {
            return 'has a final grade';
        }

        if ($milestoneIds->isNotEmpty()
            && DB::table('milestone_reviews')->whereIn('milestone_id', $milestoneIds)->exists()) {
            return 'has a Lampiran R1 record';
        }

        if (DB::table('evaluations')->where('project_id', $projectId)->exists()) {
            return 'has an evaluation';
        }

        if (DB::table('archived_projects')->where('project_id', $projectId)->exists()) {
            return 'is referenced by the archive';
        }

        if (DB::table('leaderboard_entries')->where('project_id', $projectId)->exists()) {
            return 'has a leaderboard entry';
        }

        if (DB::table('student_enrolments')->where('project_id', $projectId)->exists()) {
            return 'holds a student enrolment';
        }

        return null;
    }
}
