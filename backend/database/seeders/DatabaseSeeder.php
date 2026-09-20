<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds a complete, demonstrable cohort across all eight modules.
 *
 * Order matters: each seeder depends on rows created by the previous one.
 * Re-running is safe — every seeder is idempotent (updateOrCreate / firstOrCreate).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding PSM Management System...');
        $this->command->newLine();

        $this->call([
            // Module 1 & 2 — accounts, profiles, expertise
            UserSeeder::class,

            // Module 3 & 4 — templates that projects and rubrics depend on
            MilestoneTemplateSeeder::class,
            RubricTemplateSeeder::class,

            // Module 3 — projects, memberships, instantiated milestones
            ProjectSeeder::class,

            // Module 4 — evaluations and computed grades
            EvaluationSeeder::class,

            // Module 8 — settings and a published leaderboard
            LeaderboardSeeder::class,
        ]);

        $this->command->newLine();
        $this->command->info('Seed complete.');
        $this->command->newLine();
        $this->command->table(
            ['Role', 'Email', 'Password'],
            [
                ['Admin',       'admin@psm.test',       'password'],
                ['Coordinator', 'coordinator@psm.test', 'password'],
                ['Supervisor',  'supervisor@psm.test',  'password'],
                ['Examiner',    'examiner@psm.test',    'password'],
                ['Student',     'student@psm.test',     'password'],
            ]
        );
        $this->command->newLine();
        $this->command->comment('Public leaderboard: /leaderboard  (no login required)');
    }
}
