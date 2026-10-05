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

            // Module 3 — academic terms. Before anything else that carries a
            // semester_id, because the project seeder assigns the active term
            // and the semester-scoped screens resolve against it.
            AcademicSemesterSeeder::class,

            // Module 3 & 4 — templates that projects and rubrics depend on
            MilestoneTemplateSeeder::class,

            // The official marking forms (Lampiran E, G, H, I, J) are the only
            // rubrics in the system. MarkingFormSeeder is their sole owner.
            //
            // RubricTemplateSeeder used to sit here and invented a
            // chapter-shaped rubric (Proposal / Chapters 1-5 / Final Report /
            // Supervision) for each category x assessor type. Those rows matched
            // no Lampiran form, and because the seeder runs *after* the
            // 2026_10_03_000006_remove_legacy_rubric_templates migration it kept
            // resurrecting what that migration had just deleted. It has been
            // removed rather than fixed: deleting the module erased the
            // problem outright, and nothing in any caller missed it.
            MarkingFormSeeder::class,

            // Module 3 — projects, memberships, instantiated milestones
            ProjectSeeder::class,

            // Module 4 — evaluations and computed grades
            EvaluationSeeder::class,

            // Module 3 — a small cohort with Lampiran A agreements driven through
            // the supervisor's acknowledgement and the panel's review, so the
            // registration queue is populated. It creates its own students rather
            // than attaching agreements to the seeded projects, which were made
            // directly and never had a proposal.
            RegistrationDemoSeeder::class,

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
                // Same role as the line above: a panel is drawn from the people
                // who supervise, so this account demonstrates being *seated* on
                // a panel rather than a separate kind of staff.
                ['Supervisor (panel)', 'examiner@psm.test', 'password'],
                ['Student',     'student@psm.test',     'password'],
            ]
        );
        $this->command->newLine();
        $this->command->comment('Public leaderboard: /leaderboard  (no login required)');
    }
}
