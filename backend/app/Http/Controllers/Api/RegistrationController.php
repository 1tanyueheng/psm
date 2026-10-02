<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\ApiController;
use App\Models\SupervisorAgreement;
use App\Services\RegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Module 2/3 — Registration flow (Lampiran A & B).
 *
 * Lampiran A: student → supervisor acknowledgement → JKPSM approval, at which
 * point the supervisor<->student pairing is registered.
 * Lampiran B: the title proposal that turns an approved agreement into a
 * project record.
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
        $user = $request->user();

        $query = SupervisorAgreement::query()
            ->with(['studentProfile.user', 'supervisorProfile.user']);

        if ($user->isStudent()) {
            $query->where('student_profile_id', $user->studentProfile?->id);
        } elseif ($user->isSupervisor()) {
            $query->where('supervisor_profile_id', $user->supervisorProfile?->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        return $this->ok($query->latest()->get());
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
            'session'               => ['required', 'string', 'max:32'],
            'psm_part'              => ['nullable', 'in:PSM1,PSM2,BOTH'],
            'proposed_title_1'      => ['required', 'string', 'max:255'],
            'proposed_title_2'      => ['nullable', 'string', 'max:255'],
            'proposed_title_3'      => ['nullable', 'string', 'max:255'],
            'english_report'        => ['boolean'],
        ]);

        $agreement = $this->registrations->submitAgreement($student, $data, $request->user());

        return $this->created($agreement, 'Lampiran A submitted.');
    }

    public function showAgreement(SupervisorAgreement $agreement): JsonResponse
    {
        return $this->ok($agreement->load([
            'studentProfile.user',
            'supervisorProfile.user',
            'supervisionAssignment',
        ]));
    }

    /** Part C — supervisor acknowledges and picks the agreed title. */
    public function acknowledge(Request $request, SupervisorAgreement $agreement): JsonResponse
    {
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

        return $this->ok($agreement, 'Lampiran A acknowledged.');
    }

    /** Part D — JKPSM/coordinator approves; the pairing is registered here. */
    public function approve(Request $request, SupervisorAgreement $agreement): JsonResponse
    {
        try {
            $agreement = $this->registrations->approve($agreement, $request->user());
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->ok($agreement, 'Approved — the supervisor pairing has been registered.');
    }

    public function reject(Request $request, SupervisorAgreement $agreement): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $agreement = $this->registrations->reject($agreement, $request->user(), $data['reason']);

        return $this->ok($agreement, 'Lampiran A rejected.');
    }

    // -----------------------------------------------------------------
    // Lampiran B
    // -----------------------------------------------------------------

    /** Student submits Lampiran B against an approved agreement. */
    public function storeTitleProposal(Request $request, SupervisorAgreement $agreement): JsonResponse
    {
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

        return $this->created($project, 'Lampiran B submitted.');
    }
}
