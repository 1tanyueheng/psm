<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectCategory;
use App\Models\LampiranBForm;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Changing the title at the PSM 2 boundary.
 *
 * A student who **passed PSM 1** may choose a different title for PSM 2. The
 * faculty permits it, and the cost is theirs: the PSM 1 documentation was written
 * for the old title, so it has to be done again before Chapters 5–7 can be
 * assessed against the new one.
 *
 * The rule (spec §3.3): optional, chosen at the start of the chain before Chapter
 * 5 opens, decided by a **full defence** — a different title is a different
 * project — and the refiled Chapters 1–4 carry **no weight**, because the PSM 1
 * mark was already awarded and cannot be re-awarded. They are required evidence
 * for the panel, not assessable work.
 *
 * The project is never restarted: same code, same supervisor, same enrolment.
 * What changes is the chain in front of it and the title it agrees to.
 */
class Psm2TitleChangeService
{
    public function __construct(
        protected AuditLogger $audit,
        protected MilestoneService $milestones,
    ) {
    }

    /**
     * Is this project eligible to change title now?
     *
     * All four conditions are load-bearing:
     *
     *  - it is a PSM 2 project, because PSM 1 has a defence already;
     *  - the change has not already happened — one change per project, since a
     *    second would mean a third set of Chapters 1–4 in one semester;
     *  - Chapter 5 has **not opened**, because from that point the student is
     *    writing for the current title and a change would waste their own work;
     *  - the chain's first step has not been submitted, which is the practical
     *    proof that work under the old title has not started.
     */
    public function blockedReason(Project $project): ?string
    {
        if ($project->psm_part !== 'PSM2') {
            return 'Only a PSM 2 project can change its title — PSM 1 has its own defence.';
        }

        if (($project->metadata['title_changed_at_psm2'] ?? false) === true) {
            return 'This project has already changed its title once. A second change is not permitted.';
        }

        $chapter5 = $project->milestones()->where('code', 'chapter_5')->first();

        if ($chapter5 !== null && ! in_array($chapter5->status, [
            MilestoneStatus::Pending,
        ], true)) {
            return 'Chapter 5 has already opened, so the title can no longer be changed. '
                .'The change is only available before work under the current title begins.';
        }

        $submitted = $project->milestones()
            ->whereIn('status', [
                MilestoneStatus::Submitted->value,
                MilestoneStatus::Reviewed->value,
                MilestoneStatus::Approved->value,
                MilestoneStatus::Conditional->value,
                MilestoneStatus::Rejected->value,
            ])
            ->whereIn('code', ['chapter_5', 'chapter_6', 'chapter_7', 'final_report', 'form_k'])
            ->exists();

        if ($submitted) {
            return 'Work under the current title has already been submitted, so the title can no longer change.';
        }

        return null;
    }

    /**
     * What the student answered at the boundary, if they have answered.
     *
     * Stored in `projects.metadata.title_decision`. Two values matter:
     *
     *  - `keep` — they were offered the change and declined. The prompt must not
     *    come back, or it asks the same question every time the page opens;
     *  - `change` — they took it. Set when the request is open, so the prompt
     *    stops offering a second decision while the first is in flight.
     */
    public function decision(Project $project): ?string
    {
        if (($project->metadata['title_changed_at_psm2'] ?? false) === true) {
            return 'change';
        }

        return $project->metadata['title_decision'] ?? null;
    }

    /**
     * Keep the inherited title.
     *
     * Recorded rather than assumed: without it the project page cannot tell
     * "not asked yet" from "asked and declined", and would either nag for ever
     * or never ask at all. The four guards are deliberately **not** checked —
     * declining is always allowed, even after Chapter 5 opens, because the
     * question has simply been answered.
     */
    public function keep(Project $project, User $actor): Project
    {
        $project->forceFill([
            'metadata' => array_merge($project->metadata ?? [], [
                'title_decision'    => 'keep',
                'title_decided_at'  => now()->toIso8601String(),
                'title_decided_by'  => $actor->id,
            ]),
        ])->save();

        $this->audit->log(
            action: AuditAction::ProjectUpdated,
            description: "{$project->code}: student kept the inherited title at the PSM 2 boundary",
            subject: $project,
            actor: $actor,
        );

        return $project;
    }

    /**
     * Open the change: a new Lampiran B for the new title.
     *
     * Nothing is created here except the form. The student proposes the new
     * title, the supervisor signs it, and only then does the reinstated chain
     * open — the same gate a refused title goes through, because it is the same
     * question: does the faculty agree to supervise work under this title?
     */
    public function request(Project $project, User $actor): LampiranBForm
    {
        if ($reason = $this->blockedReason($project)) {
            throw new InvalidArgumentException($reason);
        }

        return DB::transaction(function () use ($project, $actor) {
            $previous = LampiranBForm::forProject($project->id)
                ->orderByDesc('revision_no')
                ->first();

            $form = LampiranBForm::create([
                'project_id'  => $project->id,
                'revision_no' => LampiranBForm::nextRevisionFor($project->id),
                // The current title is a starting point, not a proposal — the
                // student must write a different one before submitting.
                'title'       => $project->title,
                'project_type' => $previous?->project_type
                    ?? ($project->category?->value === 'research' ? 'research' : 'system'),
                'field_study' => $previous?->field_study,
                'req_software' => $previous?->req_software,
                'req_hardware' => $previous?->req_hardware,
                'req_technology' => $previous?->req_technology,
                'origin'      => $previous?->origin,
                'supervisor_profile_id' => $previous?->supervisor_profile_id
                    ?? $project->leader()?->activeSupervisions()->value('supervisor_profile_id'),
                'status'      => LampiranBForm::STATUS_DRAFT,
                'supersedes_id' => $previous?->id,
            ]);

            // Deliberately **not** superseding the previous form: its title was
            // accepted and still stands until the new one is agreed. Linking it
            // as superseded would make the project look untitled in between.

            // Record the answer, so the boundary prompt stops offering a choice
            // that is already in flight.
            $project->forceFill([
                'metadata' => array_merge($project->metadata ?? [], [
                    'title_decision'   => 'change',
                    'title_decided_at' => now()->toIso8601String(),
                    'title_decided_by' => $actor->id,
                ]),
            ])->save();

            $this->audit->log(
                action: AuditAction::ProjectUpdated,
                description: "Title change requested at the PSM 2 boundary for {$project->code}",
                subject: $project,
                actor: $actor,
            );

            return $form;
        });
    }

    /**
     * Install the chain for a changed title, once the new form is signed.
     *
     * Called after the supervisor signs the new Lampiran B. Prepends the PSM 1
     * steps to the PSM 2 chain — a new proposal and Chapters 1–4 — so the student
     * redoes the documentation for the new title before continuing.
     *
     * **The refiled chapters carry no weight.** The PSM 1 mark was awarded on the
     * old title and cannot be re-awarded; these exist so the panel can judge the
     * new proposal against the new literature and design. Weight 0 also keeps the
     * PSM 2 total at 100, which a non-zero weight would silently break.
     */
    public function install(Project $project, LampiranBForm $form, User $actor): array
    {
        return DB::transaction(function () use ($project, $form, $actor) {
            /**
             * The new title becomes the project's title here.
             *
             * This is the one place a title changes without a panel accepting a
             * proposal, and it is correct: the panel will sit on the proposal
             * created below, and the title is what that defence is *about*. The
             * student cannot write a proposal for a title the project does not
             * hold.
             */
            $project->forceFill([
                'title'    => $form->title,
                'category' => $form->project_type === 'research'
                    ? ProjectCategory::Research->value
                    : ProjectCategory::System->value,
                'metadata' => array_merge($project->metadata ?? [], [
                    'title_changed_at_psm2' => true,
                    'title_changed_from'    => $project->title,
                    'title_changed_at'      => now()->toIso8601String(),
                    'title_changed_by'      => $actor->id,
                ]),
            ])->save();

            // Make room in front of the existing chain.
            $existing = $project->milestones()->orderBy('sequence')->get();
            $shift = 5;   // proposal + chapters 1-4
            $project->milestones()->increment('sequence', $shift);

            $created = [];
            $sequence = 1;

            $created[] = $this->add($project, [
                'code'  => Milestone::CODE_PROPOSAL,
                'attempt' => 1,
                'title' => 'Proposal (new title)',
                'description' => 'The title changed at the start of PSM 2, so the proposal is '
                    .'defended again for the new title.',
                'sequence' => $sequence++,
                'weight_percent' => 0,
                'status' => MilestoneStatus::Open,
                'opens_at' => now(),
                'requires_supervisor_approval' => true,
            ], $form);

            for ($n = 1; $n <= 4; $n++) {
                $created[] = $this->add($project, [
                    'code'  => "chapter_{$n}",
                    'attempt' => 1,
                    'title' => "Chapter {$n} (new title)",
                    'description' => 'Refiled for the new title. These chapters carry no weight in '
                        .'PSM 2 — the PSM 1 mark was already awarded — but the panel needs them to '
                        .'judge the proposal.',
                    'sequence' => $sequence++,
                    // No weight: the PSM 1 mark covers this work already.
                    'weight_percent' => 0,
                    'status' => MilestoneStatus::Pending,
                ], $form);
            }

            $this->audit->log(
                action: AuditAction::MilestoneOpened,
                description: "PSM 2 chain reopened for a new title on {$project->code} — "
                    ."\"{$form->title}\"",
                subject: $project,
                actor: $actor,
            );

            return $created;
        });
    }

    /** Insert one milestone in the reinstated chain. */
    protected function add(Project $project, array $attributes, LampiranBForm $form): Milestone
    {
        $milestone = Milestone::create([
            'project_id' => $project->id,
            'allow_late_submission' => true,
            'allowed_file_types' => ['pdf', 'doc', 'docx'],
            'max_files' => 3,
            'opens_at' => $attributes['opens_at'] ?? null,
            ...$attributes,
        ]);

        // The proposal milestone is the form's record, so signing it opened the
        // right step and the card can find it.
        if ($milestone->code === Milestone::CODE_PROPOSAL) {
            $form->forceFill(['milestone_id' => $milestone->id])->save();
        }

        return $milestone;
    }
}
