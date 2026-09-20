<?php

namespace Database\Seeders;

use App\Models\ArchivedProject;
use App\Models\FinalGrade;
use App\Models\Leaderboard;
use App\Models\LeaderboardSetting;
use App\Models\Project;
use App\Models\User;
use App\Services\ArchiveService;
use App\Services\LeaderboardService;
use Illuminate\Database\Seeder;

/**
 * Modules 7 & 8 — Recognition and the archive.
 *
 * Both services are two-phase, and the seeder respects that:
 *
 *   Module 8   build()   ranks eligible grades into a DRAFT snapshot
 *              publish() flips it live
 *   Module 7   archive() freezes a self-contained record of a finished project
 *
 * Seeding both gives the demo three things worth showing: a public pixel-it
 * page with a podium, an archive that survives its source rows, and a
 * coordinator view where the next board is still a previewable draft.
 */
class LeaderboardSeeder extends Seeder
{
    public function __construct(
        protected LeaderboardService $leaderboards,
        protected ArchiveService $archive,
    ) {
    }

    public function run(): void
    {
        $admin       = User::where('email', 'admin@psm.test')->firstOrFail();
        $coordinator = User::where('email', 'coordinator@psm.test')->firstOrFail();

        $this->seedSettings();

        // --- Module 8: the live public board ---------------------------
        $live = $this->buildBoard(
            title: 'PSM 2025/2026 — Pixel-It Awards',
            slug: 'psm-2026-pixel-it',
            subtitle: 'Top Final Year Projects',
            description: 'Ranked by final mark across the 2025/2026 PSM2 cohort. '
                .'Scores are the combined assessment of the supervisor and an independent examiner.',
            coordinator: $coordinator,
            topN: 3,
            awardTitles: ['Pixel-It Gold', 'Pixel-It Silver', 'Pixel-It Bronze'],
        );

        $published = false;

        try {
            $this->leaderboards->publish($live, $coordinator);
            $published = true;
        } catch (\Throwable $e) {
            // Publishing legitimately fails when the cohort has not produced
            // enough released grades (e.g. a partially graded database). Leave
            // the board as a draft rather than forcing an invalid state.
            $this->command?->warn("  Leaderboard left as draft: {$e->getMessage()}");
        }

        // --- Module 8: next semester, still in draft -------------------
        $draft = Leaderboard::updateOrCreate(
            ['slug' => 'psm-2026-draft-review'],
            [
                'title'            => 'PSM 2025/2026 — Moderation Preview',
                'subtitle'         => 'Draft — not visible to the public',
                'description'      => 'Internal preview used to sanity-check the ranking before release.',
                'psm_part'         => 'PSM2',
                'batch'            => '2026',
                'academic_session' => '2025/2026',
                'top_n'            => 5,
                'min_assessors'    => 2,
                'ranking_basis'    => 'final_mark',
                'tie_breaker'      => 'assessor_count',
                'status'           => 'draft',
                'show_abstract'    => true,
                'show_scores'      => false,
                'show_student_names' => false,
                'show_program'     => true,
                'created_by'       => $coordinator->id,
            ]
        );

        try {
            $this->leaderboards->build($draft);
        } catch (\Throwable $e) {
            $this->command?->warn("  Draft board not built: {$e->getMessage()}");
        }

        // --- Module 7: archive the finished cohort ---------------------
        $archived = $this->seedArchive($admin);

        $this->command?->info(sprintf(
            '  Leaderboards: %d (%s), entries: %d. Archived projects: %d.',
            Leaderboard::count(),
            $published ? 'live board published' : 'draft only',
            \App\Models\LeaderboardEntry::count(),
            $archived
        ));

        if ($published) {
            $this->command?->line('  Public page: /leaderboard/'.$live->slug.'  (no login required)');
        }
    }

    // -----------------------------------------------------------------
    // Module 8
    // -----------------------------------------------------------------

    /**
     * Module-level settings. Only created once — on a re-seed the existing row
     * is left alone so a faculty demo configuration is not overwritten.
     */
    protected function seedSettings(): void
    {
        LeaderboardSetting::updateOrCreate(
            ['id' => 1],
            [
                'default_top_n'        => 3,
                'default_min_assessors'=> 2,
                'default_ranking_basis'=> 'final_mark',
                'module_enabled'       => true,
                // Publishing is a coordinator action, so approval is required
                'require_approval'     => true,
                // Honour a student's opt-out from public display
                'honour_opt_out'       => true,
            ]
        );
    }

    protected function buildBoard(
        string $title,
        string $slug,
        string $subtitle,
        string $description,
        User $coordinator,
        int $topN,
        array $awardTitles,
    ): Leaderboard {
        return Leaderboard::updateOrCreate(
            ['slug' => $slug],
            [
                'title'            => $title,
                'subtitle'         => $subtitle,
                'description'      => $description,
                'psm_part'         => 'PSM2',
                'batch'            => '2026',
                'academic_session' => '2025/2026',
                'top_n'            => $topN,
                'min_assessors'    => 2,
                'ranking_basis'    => 'final_mark',
                'tie_breaker'      => 'assessor_count',
                'status'           => 'draft',
                'auto_publish_at'  => null,
                // The public page may show names and abstracts, but not raw scores
                'show_abstract'    => true,
                'show_scores'      => false,
                'show_student_names' => true,
                'show_program'     => true,
                'theme'            => 'academic',
                'created_by'       => $coordinator->id,
            ]
        );
    }

    // -----------------------------------------------------------------
    // Module 7
    // -----------------------------------------------------------------

    /**
     * Freeze completed projects into the archive.
     *
     * Only projects that are genuinely finished and already released can be
     * archived, because the archived record copies the final grade — a
     * provisional mark would become permanently wrong the moment it changed.
     */
    protected function seedArchive(User $admin): int
    {
        $releasedProjectIds = FinalGrade::query()
            ->where('status', 'released')
            ->pluck('project_id')
            ->unique();

        if ($releasedProjectIds->isEmpty()) {
            return 0;
        }

        $archived = 0;

        Project::query()
            ->whereIn('id', $releasedProjectIds)
            ->where('status', 'completed')
            ->whereNull('archived_at')
            ->whereDoesntHave('archivedRecord')
            ->orderBy('code')
            ->get()
            ->each(function (Project $project) use (&$archived, $admin) {
                try {
                    $this->archive->archive(
                        $project,
                        $admin,
                        'Archived at the close of the 2025/2026 academic session.',
                        // Publicly searchable: this is the "past projects" library
                        public: true,
                    );
                    $archived++;
                } catch (\Throwable $e) {
                    $this->command?->warn("  Could not archive {$project->code}: {$e->getMessage()}");
                }
            });

        return $archived;
    }
}
