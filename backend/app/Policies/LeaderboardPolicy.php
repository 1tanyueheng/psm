<?php

namespace App\Policies;

use App\Models\Leaderboard;
use App\Models\User;

/**
 * Module 8 — Management of public recognition boards.
 *
 * Reading a published board needs no policy: the public route is
 * unauthenticated by design. What is guarded here is *authoring* and
 * *publishing*, because those expose student work to the open internet.
 */
class LeaderboardPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Previewing a draft board. */
    public function view(User $actor, Leaderboard $leaderboard): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Editing a draft. A published board must be unpublished first. */
    public function update(User $actor, Leaderboard $leaderboard): bool
    {
        if (! $actor->hasRole('admin', 'coordinator')) {
            return false;
        }

        return ! $leaderboard->isPublished();
    }

    /** Building/rebuilding the ranking snapshot. */
    public function build(User $actor, Leaderboard $leaderboard): bool
    {
        return $this->update($actor, $leaderboard);
    }

    /**
     * Publishing makes student names and titles publicly visible, so it is
     * restricted and irreversible without an explicit unpublish.
     */
    public function publish(User $actor, Leaderboard $leaderboard): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function unpublish(User $actor, Leaderboard $leaderboard): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    /** Hiding or revealing a single entry on a live board. */
    public function manageEntries(User $actor, Leaderboard $leaderboard): bool
    {
        return $actor->hasRole('admin', 'coordinator');
    }

    public function delete(User $actor, Leaderboard $leaderboard): bool
    {
        return $actor->hasRole('admin') && ! $leaderboard->isPublished();
    }

    /** Module 8 — global module settings. */
    public function manageSettings(User $actor): bool
    {
        return $actor->hasRole('admin');
    }
}
