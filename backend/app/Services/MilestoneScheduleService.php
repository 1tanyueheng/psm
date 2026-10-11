<?php

namespace App\Services;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\MilestoneSchedule;
use App\Models\MilestoneTemplateItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Module 3 — the faculty's milestone calendar for a term.
 *
 * The coordinator sets each milestone's date once for the batch, and every
 * student in it is held to that date. This replaces the per-project derivation
 * where each project added the template's offsets to its own registration date,
 * which meant two students in the same batch could be told different deadlines
 * for the same chapter.
 *
 * The calendar is the source; `milestones.due_at` is what the system enforces.
 * `apply()` is what keeps the second in step with the first, and it is written
 * to be safe to run at any time:
 *
 *   - it never touches a milestone a supervisor has already acted on, because
 *     re-dating work that has been submitted or approved rewrites history;
 *   - it never resurrects a milestone from an older template that the current
 *     chain no longer contains;
 *   - it is idempotent, so applying the same calendar twice changes nothing.
 */
class MilestoneScheduleService
{
    public function __construct(
        protected AuditLogger $audit,
    ) {
    }

    /**
     * The calendar for one batch of one term, in chain order.
     *
     * Built from the template's chain rather than from the rows already stored,
     * so a term whose calendar has never been set still returns every milestone
     * with its default dates. The coordinator edits a complete list rather than
     * filling in a blank one, which is the difference between "set the dates"
     * and "notice which ones are missing".
     *
     * @return array<int, array{
     *     code:string, title:string, sequence:int,
     *     opens_at:?string, due_at:string,
     *     is_custom:bool, affected:int, locked:int
     * }>
     */
    public function calendarFor(int $semesterId, string $psmPart): array
    {
        $items = $this->chainFor($semesterId, $psmPart);

        if ($items === []) {
            return [];
        }

        $stored = MilestoneSchedule::query()
            ->forSemesterPart($semesterId, $psmPart)
            ->get()
            ->keyBy('code');

        // One grouped query for the whole batch rather than two per milestone.
        // On a hosted database the per-milestone version cost a dozen round
        // trips to render a six-row screen, which is exactly the kind of
        // per-row query that made the slow screens slow.
        $impact = $this->impactByCode($semesterId, $psmPart);

        $rows = [];

        foreach ($items as $item) {
            $row = $stored->get($item->code);
            [$affected, $locked] = $impact[$item->code] ?? [0, 0];

            $rows[] = [
                'code'      => $item->code,
                'title'     => $item->title,
                'sequence'  => (int) $item->sequence,
                'opens_at'  => $row?->opens_at?->toDateString(),
                // Falling back to the template's own window keeps the form
                // complete for a term that has not been scheduled yet.
                'due_at'    => $row?->due_at?->toDateString()
                    ?? $this->defaultDueFor($semesterId, $item),
                'is_custom' => $row !== null,
                'affected'  => $affected,
                'locked'    => $locked,
            ];
        }

        return $rows;
    }

    /**
     * Set the calendar for a batch and push it onto every project in the term.
     *
     * @param  array<int, array{code:string, opens_at?:?string, due_at:string}>  $entries
     * @return array{saved:int, applied:int, skipped:int, locked:int}
     */
    public function save(int $semesterId, string $psmPart, array $entries, User $actor): array
    {
        $validCodes = $this->chainFor($semesterId, $psmPart)->pluck('code')->all();

        if ($validCodes === []) {
            throw new InvalidArgumentException(
                "No milestone template is active for {$psmPart}, so there is no calendar to set."
            );
        }

        $saved = 0;

        return DB::transaction(function () use ($semesterId, $psmPart, $entries, $actor, $validCodes, &$saved) {
            foreach ($entries as $entry) {
                $code = $entry['code'] ?? null;

                /**
                 * A code the chain does not contain is refused rather than
                 * ignored. Silently dropping it would let a coordinator believe
                 * they had scheduled a milestone that no student will ever see,
                 * which is worse than an error naming the code.
                 */
                if (! in_array($code, $validCodes, true)) {
                    throw new InvalidArgumentException(
                        "Milestone [{$code}] is not part of the {$psmPart} chain for this term."
                    );
                }

                $dueAt = Carbon::parse($entry['due_at'])->startOfDay();
                $opensAt = isset($entry['opens_at']) && $entry['opens_at'] !== null
                    ? Carbon::parse($entry['opens_at'])->startOfDay()
                    : null;

                if ($opensAt !== null && $opensAt->isAfter($dueAt)) {
                    throw new InvalidArgumentException(
                        "Milestone [{$code}] cannot open after its own deadline."
                    );
                }

                MilestoneSchedule::updateOrCreate(
                    [
                        'academic_semester_id' => $semesterId,
                        'psm_part'             => $psmPart,
                        'code'                 => $code,
                    ],
                    [
                        'opens_at'   => $opensAt,
                        'due_at'     => $dueAt,
                        'updated_by' => $actor->id,
                    ],
                );

                $saved++;
            }

            $result = $this->apply($semesterId, $psmPart, $actor);

            $this->audit->log(
                action: \App\Enums\AuditAction::DeadlineChanged,
                description: sprintf(
                    '%s milestone calendar updated for term %d: %d date(s) set, %d milestone(s) re-dated',
                    $psmPart,
                    $semesterId,
                    $saved,
                    $result['applied'],
                ),
                actor: $actor,
            );

            return ['saved' => $saved, ...$result];
        });
    }

    /**
     * Push the stored calendar onto every project's milestones.
     *
     * @return array{applied:int, skipped:int, locked:int}
     */
    public function apply(int $semesterId, string $psmPart, ?User $actor = null): array
    {
        $schedules = MilestoneSchedule::query()
            ->forSemesterPart($semesterId, $psmPart)
            ->get()
            ->keyBy('code');

        if ($schedules->isEmpty()) {
            return ['applied' => 0, 'skipped' => 0, 'locked' => 0];
        }

        $applied = 0;
        $skipped = 0;
        $locked = 0;

        /**
         * Chunked over the milestones themselves, not over projects.
         *
         * Iterating projects and querying each one's milestones was a query per
         * project — 15 round trips for one batch, on a screen that already talks
         * to a hosted database. `whereHas` keeps the scope identical (live
         * projects in this term and batch) while reading the rows in one pass.
         */
        Milestone::query()
            ->whereHas('project', fn ($q) => $q
                ->where('academic_semester_id', $semesterId)
                ->where('psm_part', $psmPart)
                ->whereNull('archived_at'))
            ->chunkById(200, function ($milestones) use ($schedules, &$applied, &$skipped, &$locked) {
                foreach ($milestones as $milestone) {
                    $schedule = $schedules->get($milestone->code);

                    if ($schedule === null) {
                        // A milestone from a template the term's calendar no
                        // longer describes. Left alone rather than guessed at:
                        // it is real work a student may still owe.
                        $skipped++;

                        continue;
                    }

                    $dueAt = $schedule->due_at;

                    /**
                     * `opens_at` is only applied to a milestone that has not
                     * opened yet.
                     *
                     * The two columns mean different things once a milestone is
                     * under way. On the calendar, `opens_at` is a *plan* — when
                     * the chapter should become available. On the milestone,
                     * `activateNext()` overwrites it with the moment it
                     * *actually* opened, because that is the fact a student's
                     * lateness is measured against. Copying the plan over the
                     * fact would both rewrite history and make this method
                     * permanently non-idempotent, since the two would never
                     * agree again after the first open.
                     *
                     * So a pending milestone takes the planned date; anything
                     * already opened keeps its own.
                     */
                    $opensAt = $milestone->status === MilestoneStatus::Pending
                        ? ($schedule->opens_at ?? $milestone->opens_at)
                        : $milestone->opens_at;

                    $sameDue = $milestone->due_at?->toDateString() === $dueAt->toDateString();
                    $sameOpens = $milestone->opens_at?->toDateString() === $opensAt?->toDateString();

                    // Nothing to do. Checked *before* the settled guard below so
                    // a milestone that is not being changed is not reported as
                    // having been "left alone" — it was never in question.
                    if ($sameDue && $sameOpens && $milestone->deadline_is_fixed) {
                        continue;
                    }

                    /**
                     * Never re-date work already in flight.
                     *
                     * A submitted or approved milestone has a recorded date the
                     * student was held to. Moving it afterwards would
                     * retroactively change whether they were late, which is
                     * exactly the kind of quiet rewrite the audit trail exists
                     * to prevent. The coordinator can still change one of these
                     * deliberately, per milestone, with a reason.
                     *
                     * Counted only when this milestone's date genuinely differs
                     * from the new calendar: otherwise "49 left alone" would be
                     * reported for a change that touched one date, which reads
                     * as if 49 students had been denied something.
                     */
                    if ($this->isSettled($milestone)) {
                        $locked++;

                        continue;
                    }

                    // A coordinator's own per-milestone override outranks the
                    // term calendar; that is what makes it an override.
                    if ($milestone->deadline_overridden_by !== null) {
                        $skipped++;

                        continue;
                    }

                    Milestone::query()
                        ->whereKey($milestone->id)
                        ->update([
                            'due_at'            => $dueAt->toDateString(),
                            'opens_at'          => $opensAt?->toDateString(),
                            'deadline_is_fixed' => true,
                            // Written through the query builder so a bulk
                            // re-date does not look like the student or
                            // supervisor edited every milestone.
                            'updated_at'        => now(),
                        ]);

                    $applied++;
                }
            });

        return ['applied' => $applied, 'skipped' => $skipped, 'locked' => $locked];
    }

    /**
     * Seed a term's calendar from the templates' own offsets, relative to a
     * start date. Used when a term is created and by the seeder, so a new term
     * begins with a complete, editable calendar rather than an empty one.
     */
    public function seedDefaultsFor(int $semesterId, string $psmPart, Carbon $start, ?User $actor = null): int
    {
        $created = 0;

        foreach ($this->chainFor($semesterId, $psmPart) as $item) {
            $opensAt = $start->copy()->addDays((int) $item->offset_days);
            $dueAt = $opensAt->copy()->addDays((int) $item->duration_days);

            $schedule = MilestoneSchedule::firstOrCreate(
                [
                    'academic_semester_id' => $semesterId,
                    'psm_part'             => $psmPart,
                    'code'                 => $item->code,
                ],
                [
                    'opens_at'   => $opensAt,
                    'due_at'     => $dueAt,
                    'updated_by' => $actor?->id,
                ],
            );

            if ($schedule->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * The milestone chain a batch of a term follows, in order.
     *
     * Resolved through the template for each category the term contains, since
     * a term holds both a system-development and a research cohort. The chains
     * share their codes (a `chapter_1` is a `chapter_1` in both), so the union
     * is the right list — and a code is only scheduled once however many
     * categories use it.
     *
     * @return \Illuminate\Support\Collection<int, MilestoneTemplateItem>
     */
    protected function chainFor(int $semesterId, string $psmPart)
    {
        $categories = Project::query()
            ->where('academic_semester_id', $semesterId)
            ->where('psm_part', $psmPart)
            ->whereNull('archived_at')
            ->distinct()
            ->pluck('category');

        // A term with no projects yet still needs a calendar, so fall back to
        // every category the faculty runs rather than returning nothing.
        if ($categories->isEmpty()) {
            $categories = collect(\App\Enums\ProjectCategory::values());
        }

        $templateIds = \App\Models\MilestoneTemplate::query()
            ->whereIn('category', $categories->map(fn ($c) => $c instanceof \App\Enums\ProjectCategory ? $c->value : $c))
            ->whereIn('psm_part', [$psmPart, 'BOTH'])
            ->where('is_active', true)
            ->pluck('id');

        return MilestoneTemplateItem::query()
            ->whereIn('milestone_template_id', $templateIds)
            ->orderBy('sequence')
            ->get()
            // One row per code: the categories share a chain, and a duplicate
            // would put the same milestone on the calendar twice.
            ->unique('code')
            ->values();
    }

    /** A sensible default deadline for a milestone with no stored calendar. */
    protected function defaultDueFor(int $semesterId, MilestoneTemplateItem $item): string
    {
        // Prefer a project's own start so the suggestion is close to reality;
        // fall back to today, which is still editable.
        $start = Project::query()
            ->where('academic_semester_id', $semesterId)
            ->min('created_at');

        $base = $start !== null ? Carbon::parse($start) : now();

        return $base->copy()
            ->addDays((int) $item->offset_days + (int) $item->duration_days)
            ->toDateString();
    }

    /**
     * How many projects each milestone of a batch reaches, and how many of those
     * are already settled.
     *
     * One grouped query for the whole chain. Answering this per milestone cost
     * two round trips each, which on a hosted database is a dozen queries to
     * render a six-row screen.
     *
     * @return array<string, array{0:int, 1:int}>  code => [affected, locked]
     */
    protected function impactByCode(int $semesterId, string $psmPart): array
    {
        $settled = $this->settledStatuses();

        $rows = Milestone::query()
            ->whereHas('project', fn ($q) => $q
                ->where('academic_semester_id', $semesterId)
                ->where('psm_part', $psmPart)
                ->whereNull('archived_at'))
            ->selectRaw('code, count(*) as total')
            ->selectRaw(
                'count(*) filter (where status in ('.implode(',', array_fill(0, count($settled), '?')).')) as locked',
                $settled
            )
            ->groupBy('code')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[$row->code] = [(int) $row->total, (int) $row->locked];
        }

        return $out;
    }

    /** Has this milestone progressed far enough that its date is history? */
    protected function isSettled(Milestone $milestone): bool
    {
        return in_array($milestone->status?->value, $this->settledStatuses(), true);
    }

    /**
     * The statuses whose deadline is settled.
     *
     * From the point a student submits, the date they were held to is a fact
     * about their record. `rejected` is deliberately *not* here: a milestone
     * sent back for revision is open again, and the coordinator may well be
     * re-dating it as part of granting more time.
     *
     * @return array<int, string>
     */
    protected function settledStatuses(): array
    {
        return [
            MilestoneStatus::Submitted->value,
            MilestoneStatus::Reviewed->value,
            MilestoneStatus::Approved->value,
            MilestoneStatus::Conditional->value,
        ];
    }
}
