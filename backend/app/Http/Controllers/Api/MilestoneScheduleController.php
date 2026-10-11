<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProjectCategory;
use App\Http\Controllers\ApiController;
use App\Models\AcademicSemester;
use App\Models\MilestoneSchedule;
use App\Models\Project;
use App\Services\MilestoneScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Module 3 — the faculty's milestone calendar.
 *
 * The coordinator sets each milestone's deadline once for a whole batch of a
 * term, and every student in it follows. This replaces the per-project
 * derivation where each project added the template's offsets to its own
 * registration date, which let two students in the same batch be held to
 * different deadlines for the same chapter.
 */
class MilestoneScheduleController extends ApiController
{
    public function __construct(
        protected MilestoneScheduleService $schedules,
    ) {
    }

    /**
     * GET /api/milestone-schedule
     *
     * The calendar for one batch of one term, with each milestone's date, how
     * many projects it reaches, and how many are already settled.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MilestoneSchedule::class);

        $validated = $request->validate([
            'academic_semester_id' => ['required', 'integer', 'exists:academic_semesters,id'],
            'psm_part'             => ['required', Rule::in(['PSM1', 'PSM2'])],
        ]);

        $semesterId = (int) $validated['academic_semester_id'];
        $psmPart = $validated['psm_part'];

        return $this->ok([
            'academic_semester_id' => $semesterId,
            'psm_part'             => $psmPart,
            'milestones'           => $this->schedules->calendarFor($semesterId, $psmPart),
            // The term's own span, so the screen can say whether the calendar
            // falls inside it — a deadline past the end of term is almost always
            // a typo, and it is worth surfacing rather than discovering later.
            'term'                 => $this->termSummary($semesterId),
            // How many students the calendar governs, so the confirmation can
            // state the reach of a change rather than just "saved".
            'project_count'        => Project::query()
                ->where('academic_semester_id', $semesterId)
                ->where('psm_part', $psmPart)
                ->whereNull('archived_at')
                ->count(),
        ]);
    }

    /**
     * PUT /api/milestone-schedule
     *
     * Replace the calendar for a batch and push it onto every project.
     *
     * Sent as a whole set rather than one date at a time: the coordinator is
     * editing a calendar, and a partial save would leave the batch half on the
     * old dates and half on the new with no way to tell which.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorize('update', MilestoneSchedule::class);

        $validated = $request->validate([
            'academic_semester_id' => ['required', 'integer', 'exists:academic_semesters,id'],
            'psm_part'             => ['required', Rule::in(['PSM1', 'PSM2'])],
            'milestones'           => ['required', 'array', 'min:1'],
            'milestones.*.code'    => ['required', 'string', 'max:32'],
            'milestones.*.due_at'  => ['required', 'date'],
            'milestones.*.opens_at'=> ['nullable', 'date'],
        ]);

        try {
            $result = $this->schedules->save(
                (int) $validated['academic_semester_id'],
                $validated['psm_part'],
                $validated['milestones'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            $result,
            $this->summaryMessage($result),
        );
    }

    /**
     * POST /api/milestone-schedule/reset
     *
     * Re-seed the calendar from the templates' own offsets, relative to a start
     * date. The escape hatch for a term whose dates were set wrongly, and the
     * way a new term gets a starting calendar.
     */
    public function reset(Request $request): JsonResponse
    {
        $this->authorize('update', MilestoneSchedule::class);

        $validated = $request->validate([
            'academic_semester_id' => ['required', 'integer', 'exists:academic_semesters,id'],
            'psm_part'             => ['required', Rule::in(['PSM1', 'PSM2'])],
            'start_at'             => ['required', 'date'],
        ]);

        $created = $this->schedules->seedDefaultsFor(
            (int) $validated['academic_semester_id'],
            $validated['psm_part'],
            \Illuminate\Support\Carbon::parse($validated['start_at']),
            $request->user(),
        );

        $result = $this->schedules->apply(
            (int) $validated['academic_semester_id'],
            $validated['psm_part'],
            $request->user(),
        );

        return $this->ok(
            ['seeded' => $created, ...$result],
            $created === 0
                ? 'Every milestone already had a date, so nothing was re-seeded.'
                : "Calendar re-seeded from the template ({$created} date(s)) and applied to {$result['applied']} milestone(s).",
        );
    }

    /** @return array<string, mixed>|null */
    protected function termSummary(int $semesterId): ?array
    {
        $semester = AcademicSemester::find($semesterId);

        if ($semester === null) {
            return null;
        }

        return [
            'id'         => $semester->id,
            'name'       => $semester->name,
            'starts_at'  => $semester->starts_at?->toDateString(),
            'ends_at'    => $semester->ends_at?->toDateString(),
            'is_active'  => (bool) $semester->is_active,
        ];
    }

    /** @param array{applied:int, skipped:int, locked:int} $result */
    protected function summaryMessage(array $result): string
    {
        $message = "Calendar saved. {$result['applied']} milestone(s) re-dated";

        if ($result['locked'] > 0) {
            // Say so explicitly. A coordinator who expects every student to move
            // and sees only some of them change needs to know why, and that the
            // rest can still be changed one at a time with a reason.
            $message .= ", {$result['locked']} left alone because the work is already submitted";
        }

        if ($result['skipped'] > 0) {
            $message .= ", {$result['skipped']} skipped";
        }

        return $message.'.';
    }
}
