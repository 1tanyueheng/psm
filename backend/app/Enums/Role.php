<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Module 1 — System roles.
 *
 * Order matters: it defines the privilege hierarchy used by EnsureUserHasRole
 * (a role inherits the permissions of every role listed before it only when
 * the middleware is called with the ":inherit" modifier — see the middleware).
 */
enum Role: string
{
    case Student     = 'student';
    case Supervisor  = 'supervisor';
    case Coordinator = 'coordinator';
    case Examiner    = 'examiner';
    case Admin       = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Student     => 'Student',
            self::Supervisor  => 'Supervisor',
            self::Coordinator => 'Coordinator',
            self::Examiner    => 'Examiner',
            self::Admin       => 'System Administrator',
        };
    }

    /** Landing route for this role's dashboard in the SPA. */
    public function homeRoute(): string
    {
        return match ($this) {
            self::Student     => '/dashboard/student',
            self::Supervisor  => '/dashboard/supervisor',
            self::Coordinator => '/dashboard/coordinator',
            self::Examiner    => '/dashboard/examiner',
            self::Admin       => '/dashboard/admin',
        };
    }

    /**
     * Roles that may assess work (Module 4). Drives which users are
     * selectable as an assessor on an evaluation form.
     */
    public function canAssess(): bool
    {
        return in_array($this, [self::Supervisor, self::Examiner, self::Coordinator], true);
    }

    /** Roles that see cohort-wide analytics (Module 5). */
    public function canViewCohortAnalytics(): bool
    {
        return in_array($this, [self::Coordinator, self::Admin], true);
    }

    /** Roles with unrestricted archive + audit access (Module 7). */
    public function canAccessArchive(): bool
    {
        return in_array($this, [self::Admin, self::Coordinator], true);
    }

    /** Roles permitted to manage accounts and assignments (Module 2). */
    public function canManageUsers(): bool
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
