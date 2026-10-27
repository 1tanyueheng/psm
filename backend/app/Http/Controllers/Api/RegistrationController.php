<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Http\Resources\SupervisorAgreementResource;
use App\Models\SupervisorAgreement;
use App\Models\SupervisorProfile;
use App\Rules\IsTitle;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Module 2/3 — Registration flow (Lampiran A and Lampiran B).
 *
 * Lampiran A: student → supervisor acknowledgement, which registers the pairing
 * and fixes the agreed title. Lampiran B: the agreed title registered as a
 * project record.
 *
 * **The title is not decided here.** The panel rules on it at the project's
 * proposal milestone, which is what gates the rest of the milestone chain — see
 * `MilestoneController::titleDecision()`. This controller used to carry the
 * panel's review, and a coordinator approval before that; both are gone, because
 * neither the coordinator nor the agreement is where the title decision belongs.
 */
class RegistrationController extends ApiController
{
    public function __construct(
        protected RegistrationService $registrations,
    ) {
    }

    // -----------------------------------------------------------------
    // Lampiran A
    // -----------------------------------------------------------------

    /** List agreements visible to the signed-in user. */
    public function indexAgreements(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupervisorAgreement::class);

        $user = $request->user();

        $query = SupervisorAgreement::query()
            ->with(['studentProfile.user', 'supervisorProfile.user', 'project']);

        if ($user->isStudent()) {
            $query->where('student_profile_id', $user->studentProfile?->id);
        } elseif ($user->isSupervisor()) {
            $query->where('supervisor_profile_id', $user->supervisorProfile?->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return $this->ok(SupervisorAgreementResource::collection($query->latest()->get()));
    }

    /** Student submits Lampiran A. */
    public function storeAgreement(Request $request): JsonResponse
    {
        $student = $request->user()->studentProfile;

        if (! $student) {
            return $this->fail('Only a student may submit Lampiran A.', 403);
        }

        $data = $request->validate([
            'supervisor_profile_id' => ['required', 'integer', 'exists:supervisor_profiles,id'],

            /**
             * `session` is accepted but ignored: RegistrationService stamps the
             * active semester's session string onto the agreement, so a client
             * cannot file Lampiran A against a term that does not exist (AC#2).
             * Left optional rather than removed so the existing form keeps
             * working while it is migrated to reading the semester from context.
             */
            'session'               => ['nullable', 'string', 'max:32'],

            /**
             * Accepted and ignored.
             *
             * Lampiran A registers PSM 1 only — `submitAgreement()` hard-codes
             * the part. PSM 2 is inherited through progression, so it has no
             * registration step to file. The field is kept in the rules rather
             * than removed so a client still sending it is not rejected for a
             * field the server simply ignores.
             */
            'psm_part'              => ['nullable', 'in:PSM1,PSM2,BOTH'],

            /**
             * Lampiran A carries THREE candidate titles, not one.
             *
             * All three are mandatory, and they must differ. The candidate set is
             * what the panel considers at the proposal milestone, and it is the
             * only place a refused title can be replaced from — so an agreement
             * with one title (or three copies of it) leaves that path with
             * nothing to offer.
             *
             * `IsTitle` exists because "1", "2" and "3" are three distinct
             * strings. Without it, a student can satisfy the rule that the
             * titles differ while filing no title at all — which is not
             * hypothetical: agreement #5 in the demo data is exactly that, and it
             * sat un-acknowledged with a supervisor who had nothing to read.
             *
             * The length floor lives *inside* the rule rather than as a `min:`
             * alongside it. Both would fire, and Laravel reports every failing
             * rule, so one bad title produced two messages saying the same thing.
             */
            'proposed_title_1'      => ['required', 'string', 'max:255', new IsTitle()],
            'proposed_title_2'      => [
                'required', 'string', 'max:255', 'different:proposed_title_1', new IsTitle(),
            ],
            'proposed_title_3'      => [
                'required', 'string', 'max:255',
                'different:proposed_title_1', 'different:proposed_title_2', new IsTitle(),
            ],

            'english_report'        => ['boolean'],
        ], [
            'proposed_title_1.required'  => 'Lampiran A requires three candidate titles — Title 1 is missing.',
            'proposed_title_2.required'  => 'Lampiran A requires three candidate titles — Title 2 is missing.',
            'proposed_title_3.required'  => 'Lampiran A requires three candidate titles — Title 3 is missing.',
            'proposed_title_2.different' => 'Title 2 must differ from Title 1.',
            'proposed_title_3.different' => 'Title 3 must differ from the other candidate titles.',
        ]);

        /**
         * FSKTM pairs staff with students of their own programme.
         *
         * Enforced here as well as in the shortlist the form draws from, because
         * a filtered dropdown is a convenience, not a rule: the id can be posted
         * directly. The rule already existed for *coordinator* allocation
         * (`AssignmentService` -> `programmeRefusal()`); registration was the
         * one path that never asked, so a student could name a supervisor of
         * another programme and the agreement was created as if it were valid.
         *
         * `canSuperviseProgramme()` fails open when either programme is
         * unrecorded, so a legacy row is not locked out; where both are known,
         * the pairing must match.
         */
        $supervisor = SupervisorProfile::with('user')->find($data['supervisor_profile_id']);

        $programmeRefusal = $supervisor?->programmeRefusal(
            $student->program_code,
            $student->student_id,
        );

        if ($programmeRefusal !== null) {
            return $this->fail($programmeRefusal, 422, [
                'supervisor_profile_id' => [$programmeRefusal],
            ]);
        }

        $agreement = $this->registrations->submitAgreement($student, $data, $request->user());

        return $this->created(
            (new SupervisorAgreementResource($agreement->load('studentProfile.user', 'supervisorProfile.user')))
                ->resolve($request),
            'Lampiran A submitted.',
        );
    }

    public function showAgreement(Request $request, SupervisorAgreement $agreement): JsonResponse
    {
        $this->authorize('view', $agreement);

        $agreement->load([
            'studentProfile.user',
            'supervisorProfile.user',
            'supervisionAssignment',
            'project',
        ]);

        return $this->ok((new SupervisorAgreementResource($agreement))->resolve($request));
    }

    /** Part C — supervisor acknowledges and picks the agreed title. */
    public function acknowledge(Request $request, SupervisorAgreement $agreement): JsonResponse
    {
        $this->authorize('acknowledge', $agreement);

        $data = $request->validate([
            'agreed_title' => ['required', 'string', 'max:255'],
        ]);

        try {
            $agreement = $this->registrations->acknowledgeBySupervisor(
                $agreement,
                $data['agreed_title'],
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($agreement, 'Lampiran A acknowledged — Lampiran B may now be filed.');
    }

    /**
     * POST /api/registrations/agreements/{agreement}/withdraw
     *
     * The student takes back a Lampiran A they filed.
     *
     * Authorised by the `withdraw` ability, which is the student's own and is
     * limited to an agreement no project has been registered from — see
     * `SupervisorAgreement::canBeWithdrawn()`. A supervisor cannot retract a
     * student's registration, and a coordinator uses the same endpoint only when
     * the faculty has decided to release the pairing.
     *
     * The reason is optional: a student changing their mind owes no explanation,
     * but if they give one it is kept, because a coordinator looking at a
     * withdrawn form benefits from knowing whether it was a mistake or a change
     * of plan.
     */
    public function withdraw(Request $request, SupervisorAgreement $agreement): JsonResponse
    {
        $this->authorize('withdraw', $agreement);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $withdrawn = $this->registrations->withdrawAgreement(
                $agreement,
                $request->user(),
                $data['reason'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok(
            (new SupervisorAgreementResource(
                $withdrawn->load('studentProfile.user', 'supervisorProfile.user')
            ))->resolve($request),
            'Registration withdrawn. You may file a new Lampiran A.',
        );
    }

    // -----------------------------------------------------------------
    // Lampiran B
    // -----------------------------------------------------------------

    /** Student submits Lampiran B against an acknowledged agreement. */
    public function storeTitleProposal(Request $request, SupervisorAgreement $agreement): JsonResponse
    {
        $this->authorize('submitTitleProposal', $agreement);

        $data = $request->validate([
            'project_title'  => ['required', 'string', 'max:255'],
            'project_type'   => ['required', 'in:Pembangunan,Kajian'],
            'field_study'    => ['nullable', 'string', 'max:128'],
            'project_origin' => ['nullable', 'string', 'max:128'],
            'req_software'   => ['nullable', 'string'],
            'req_hardware'   => ['nullable', 'string'],
            'req_tech'       => ['nullable', 'string'],
        ]);

        try {
            $project = $this->registrations->submitTitleProposal($agreement, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->created($project, 'Lampiran B submitted — the proposal milestone is open.');
    }
}
