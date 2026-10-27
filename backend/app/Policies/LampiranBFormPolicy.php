<?php

namespace App\Policies;

use App\Models\LampiranBForm;
use App\Models\User;

/**
 * Who may file and sign a Lampiran B.
 *
 * Filing is the student's: the title is theirs to revise. Signing is the
 * supervisor's: a signature on a title means the person who will supervise the
 * work has read it and agrees it may go to a panel.
 *
 * Neither may do the other's half, and a coordinator may act as the supervisor
 * only where the faculty requires it — a supervisor who has left, for instance.
 * That is expressed here as `admin`/`coordinator` being allowed to sign, with the
 * audit entry naming who actually did it.
 */
class LampiranBFormPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;   // scoped by the controller; never unrestricted in practice
    }

    public function view(User $actor, LampiranBForm $form): bool
    {
        return app(\App\Policies\ProjectPolicy::class)->view($actor, $form->project);
    }

    /**
     * Revise and submit the form.
     *
     * The student who owns the project, acting while the filing is still theirs
     * to change — `draft` or `submitted`. Once the supervisor has endorsed it the
     * title is settled for that round and a change means a new filing, which the
     * panel's refusal creates.
     */
    public function file(User $actor, LampiranBForm $form): bool
    {
        if (! $actor->isStudent()) {
            return false;
        }

        $isOwner = $form->project?->leader()?->user_id === $actor->id;

        if (! $isOwner) {
            return false;
        }

        return in_array($form->status, [
            LampiranBForm::STATUS_DRAFT,
            LampiranBForm::STATUS_SUBMITTED,
        ], true);
    }

    /**
     * Sign, or send back.
     *
     * The student's own supervisor. Read from `supervisor_profile_id` on the form
     * when it is set, because a re-filing carries the pairing forward from the
     * original; otherwise from the project's live supervision.
     */
    public function sign(User $actor, LampiranBForm $form): bool
    {
        if ($actor->hasRole('admin', 'coordinator')) {
            return true;
        }

        $supervisorId = $actor->supervisorProfile?->id;

        if ($supervisorId === null) {
            return false;
        }

        if ($form->supervisor_profile_id !== null) {
            return $form->supervisor_profile_id === $supervisorId;
        }

        return $form->project?->leader()?->activeSupervisions()
            ->where('supervisor_profile_id', $supervisorId)
            ->exists() ?? false;
    }
}
