<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Module 1 — System roles.
 *
 * Order matters: it defines the privilege hierarchy used by EnsureUserHasRole
 * (a role inherits the permissions of every role listed before it only when
 * the middleware is called with the ":inherit" modifier — see the middleware).
 *
 * **There is no `examiner` role.** Being an examiner is a *seating*, not a job:
 * a member of academic staff supervises their own students and may additionally
 * be appointed to the panel of someone else's. Both are expressed by
 * `examiner_assignments`, which already carries the student, the project, the
 * panel role and the pair. A separate role duplicated that and then had to be
 * reconciled with it — the panel pool had to accept `examiner` *or*
 * `supervisor`, and a supervisor appointed to a panel could not read the project
 * they were appointed to examine. One staff role, `supervisor`, now covers both.
 */
enum Role: string
{
    case Student     = 'student';
    case Supervisor  = 'supervisor';
    case Lecturer    = 'lecturer';
    case Coordinator = 'coordinator';
    case Admin       = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Student     => 'Student',
            self::Supervisor  => 'Supervisor',
            self::Lecturer    => 'Lecturer',
            self::Coordinator => 'Coordinator',
            self::Admin       => 'System Administrator',
        };
    }

    /**
     * Landing route for this role's dashboard in the SPA.
     *
     * These must match the route table in `frontend/src/App.jsx` **exactly**.
     * They were `/dashboard/{role}` for every role, which only the lecturer's
     * route ever used — the other four are mounted at `/{role}/dashboard`. The
     * paths were wrong for four roles and nobody noticed, because the login
     * response sent this value under a key the client never read (`home` rather
     * than `home_route`), so the SPA silently used its own map instead. Fixing
     * the key exposed the drift; keeping the two in step is why the client map
     * is now only a fallback.
     */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Student     => '/student/dashboard',
            self::Supervisor  => '/supervisor/dashboard',
            self::Lecturer    => '/dashboard/lecturer',
            self::Coordinator => '/coordinator/dashboard',
            self::Admin       => '/admin/dashboard',
        };
    }

    /**
     * Roles that may assess work (Module 4). Drives which users are
     * selectable as an assessor on an evaluation form.
     *
     * Supervisors hold both assessment forms: the supervisor's own (Lampiran
     * E / G / H) for their students, and the examiner's (Lampiran I / J) for the
     * students whose panel they sit on. Which one applies is decided by the
     * assessor type on the evaluation, not by the role.
     *
     * **A lecturer is deliberately absent.** A lecturer marks coursework —
     * quizzes and assignments — for one section of a *service* course that runs
     * alongside PSM. That is not a Lampiran, carries no rubric, and is keyed
     * directly into `lecturer_marks`; it reaches the PSM 1 aggregate as a single
     * weighted component, not as an evaluation row. Letting a lecturer assess
     * would put them in every "pick an assessor" list in the system for a form
     * they cannot meaningfully fill in.
     */
    public function canAssess(): bool
    {
        return in_array($this, [self::Supervisor, self::Coordinator], true);
    }

    /**
     * Does this role work on PSM itself?
     *
     * A lecturer does not. They share a page with PSM students — the coursework
     * component appears on a student's result — but they have no project,
     * milestones, supervision, panel or submission of their own, and every PSM
     * surface should treat them as an outsider rather than as staff who happen
     * to have nothing assigned yet. Screens that would otherwise offer "your
     * students" or "your projects" use this to omit the section entirely.
     */
    public function worksOnPsm(): bool
    {
        return $this !== self::Lecturer;
    }

    /** Roles that see cohort-wide analytics (Module 5). */
    public function canViewCohortAnalytics(): bool
    {
        return in_array($this, [self::Coordinator, self::Admin], true);
    }

    /** Roles with unrestricted archive + audit access (Module 7). */
    public function canAccessArchive(): bool
    {
        return in_array($this, [self::Student, self::Supervisor, self::Coordinator, self::Admin], true);
    }

    /** Roles permitted to manage accounts and assignments (Module 2). */
    public function canManageUsers(): bool
    {
        return in_array($this, [self::Admin, self::Coordinator], true);
    }

    /** Roles that may open/lock/unlock mark submissions (Module 4). */
    public function canManageMarkSubmissions(): bool
    {
        return in_array($this, [self::Admin, self::Coordinator], true);
    }

    /** All roles, as raw string values. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Human-readable [value => label] map for dropdowns and validation rules. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $r) => [$r->value => $r->label()])
            ->all();
    }

    public static function fromName(string $name): ?self
    {
        return self::tryFrom(Str::lower(trim($name)));
    }
}
