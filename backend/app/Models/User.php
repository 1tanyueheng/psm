<?php

namespace App\Models;

use App\Enums\NotificationType;
use App\Enums\PsmPart;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Module 1 — the single authentication principal for all four roles.
 *
 * Role-specific data hangs off `studentProfile` / `supervisorProfile`; the
 * convenience accessors below (isStudent(), canAssess(), ...) exist so that
 * policies and the SPA never have to compare raw strings.
 *
 * Being an *examiner* is not a role: it is an `examiner_assignments` row. A
 * supervisor supervises their own students and may also sit on someone else's
 * panel — see the Role enum. Lecturing a section is not a role either: it is
 * `sections.lecturer_id`, one section per term, and the person holds it in
 * addition to whatever their role is.
 *
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property Role   $role
 * @property string $status
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'status',
        'phone',
        'avatar_path',
        'staff_id',
        'department',
        'must_change_password',
        'notification_preferences',
        'digest_only',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'        => 'datetime',
            'password'                 => 'hashed',
            'role'                     => Role::class,
            'must_change_password'     => 'boolean',
            'digest_only'              => 'boolean',
            'notification_preferences' => 'array',
            'last_login_at'            => 'datetime',
            'locked_until'             => 'datetime',
            'failed_login_attempts'    => 'integer',
        ];
    }

    // -----------------------------------------------------------------
    // Relations
    // -----------------------------------------------------------------

    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    public function supervisorProfile(): HasOne
    {
        return $this->hasOne(SupervisorProfile::class);
    }

    /** Projects this user created (a student's own registrations). */
    public function createdProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'created_by');
    }

    /** Module 4 — evaluation forms assigned to this user as an assessor. */
    public function evaluations(): HasMany
    {
        return $this->hasMany(Evaluation::class, 'assessor_id');
    }

    /** Module 3/4 — examiner allocations for this user. */
    public function examinerAssignments(): HasMany
    {
        return $this->hasMany(ExaminerAssignment::class, 'examiner_id');
    }

    /** Module 2 — coordinator cohort scopes. */
    public function coordinatorScopes(): HasMany
    {
        return $this->hasMany(CoordinatorScope::class);
    }

    /** Module 7 — entries this user authored. */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    public function receivedNotifications(): MorphMany
    {
        return $this->morphMany(DatabaseNotification::class, 'notifiable')
                    ->orderByDesc('created_at');
    }

    // -----------------------------------------------------------------
    // Role helpers — used by policies and the /me endpoint
    // -----------------------------------------------------------------

    public function hasRole(Role|string ...$roles): bool
    {
        $wanted = array_map(
            fn ($r) => $r instanceof Role ? $r->value : strtolower(trim($r)),
            $roles
        );

        return in_array($this->role->value, $wanted, true);
    }

    public function isStudent(): bool     { return $this->role === Role::Student; }
    public function isSupervisor(): bool  { return $this->role === Role::Supervisor; }
    public function isLecturer(): bool    { return $this->role === Role::Lecturer; }
    public function isCoordinator(): bool { return $this->role === Role::Coordinator; }
    public function isAdmin(): bool       { return $this->role === Role::Admin; }

    /**
     * The section this account lectures in a term, if appointed to one.
     *
     * **Entitlement comes from the appointment, not from the role.** This used
     * to return null unless the account's role was `lecturer`, which ruled out
     * the ordinary case: the people who teach a coursework section are usually
     * already supervisors, and `users.role` holds exactly one value. A
     * supervisor appointed as a section's lecturer now keeps every supervision
     * power *and* gains the coursework screen; a supervisor with no appointment
     * still gets null. The role is what someone is; lecturing a section is a
     * seating, the same distinction that removed the `examiner` role (see the
     * note at the top of `Role`).
     *
     * **The appointment is per term.** Sections are provisioned afresh each term
     * and carry the term themselves, so "which section does this person lecture"
     * has no answer without naming one: the same code `S1` exists once per term
     * and a person may teach `S1` this term and `S2` the next. Pass the term when
     * the caller knows which one it is writing into (`saveMarks()` does, from the
     * student's own placement); passing none means the term the system is
     * working in, which is what a screen asks when it loads without a filter.
     *
     * One section per teacher per term is the faculty's rule, so this returns a
     * single model rather than a collection. There is no unique index that says
     * so — `sections` only constrains the code within a term — because the rule
     * is about a *person*, so it is enforced where the appointment is made
     * (`LecturerMarkController::update()`), which is also the only place that can
     * explain the refusal.
     *
     * Memoised per term, including the null. `LecturerMarkPolicy` calls this once
     * per row while checking a roster, and without the cache that is a query per
     * student on a page that already loads every student. The `array_key_exists`
     * form matters: caching on `!== null` would re-query for every unappointed
     * account, which is the case the null exists to handle.
     */
    public function lecturerSection(?int $semesterId = null): ?Section
    {
        // A student never lectures a section, and the short circuit keeps a
        // student's roster check from costing a query per row.
        if ($this->isStudent()) {
            return null;
        }

        $semesterId ??= AcademicSemester::current()?->id;

        // The key carries the term because the answer does: without a term in
        // view the honest answer is null, not "whatever they last taught".
        $key = 'lecturerSection:'.($semesterId ?? 'none');

        if (! array_key_exists($key, $this->cachedRelations)) {
            // Prefer an eager-loaded relation when the caller asked for one: the
            // admin's lecturer picker loads every candidate's appointments at
            // once, and a query here would re-query once per row.
            $this->cachedRelations[$key] = $this->relationLoaded('sectionsLectured')
                ? $this->sectionsLectured->firstWhere('academic_semester_id', $semesterId)
                : $this->sectionsLectured()->where('academic_semester_id', $semesterId)->first();
        }

        return $this->cachedRelations[$key];
    }

    /**
     * Does this account lecture a section in *any* term?
     *
     * This is the question "may you use the coursework screen at all" — a
     * different question from "which section, this term", and deliberately a
     * broader one. A teacher who lectured last term and not this one still
     * reaches the screen, where the term picker then shows them last term's
     * roster; hiding the screen instead would take away the only way to read
     * marks they keyed. The screen itself not being term-scoped is why the
     * entitlement is not either (`canKeyCoursework()`).
     */
    public function lecturesAnySection(): bool
    {
        if ($this->isStudent()) {
            return false;
        }

        if (! array_key_exists('lecturesAnySection', $this->cachedRelations)) {
            $this->cachedRelations['lecturesAnySection'] = $this->relationLoaded('sectionsLectured')
                ? $this->sectionsLectured->isNotEmpty()
                : $this->sectionsLectured()->exists();
        }

        return (bool) $this->cachedRelations['lecturesAnySection'];
    }

    /**
     * The relation behind the two lookups above.
     *
     * A has-many, even though a person teaches at most one section per term, for
     * the same reason the row carries the term: across terms the appointments
     * are several. Exists as a named relation so callers can eager-load it
     * (`with('sectionsLectured')`) and so there is one place that says which
     * column makes a person a section's lecturer.
     */
    public function sectionsLectured(): HasMany
    {
        return $this->hasMany(Section::class, 'lecturer_id');
    }

    /** @var array<string, mixed> */
    protected array $cachedRelations = [];

    /** Module 4 — may this user submit marks at all? */
    public function canAssess(): bool
    {
        return $this->role->canAssess();
    }

    /** Module 2 — may this user create/edit accounts and pairings? */
    public function canManageUsers(): bool
    {
        return $this->role->canManageUsers();
    }

    /** Module 5/7 — cohort-wide analytics and archive access. */
    public function canViewCohortAnalytics(): bool
    {
        return $this->role->canViewCohortAnalytics();
    }

    /** Module 4 — open/lock/unlock mark submissions. */
    public function canManageMarkSubmissions(): bool
    {
        return $this->role->canManageMarkSubmissions();
    }

    /**
     * Module 1/4 — may this account key coursework marks?
     *
     * True for the roles that mark coursework by job (`Role::canKeyCoursework`)
     * and, in addition, for anyone who lectures a section. The second half is
     * what lets a supervisor who teaches a section reach the screen without
     * giving up the `supervisor` role.
     *
     * Note which question this is: *may they use the screen*, not *which section
     * may they write into now*. The screen is not per-term — a teacher reads
     * their past terms on it for ever — so this asks `lecturesAnySection()`, and
     * the term-scoped part is answered where a mark is actually written
     * (`lecturerSection($term)` inside `saveMarks()`).
     */
    public function canKeyCoursework(): bool
    {
        return $this->role->canKeyCoursework() || $this->lecturesAnySection();
    }

    /**
     * Capabilities this account holds that its *role* does not imply.
     *
     * The SPA maps roles to capabilities statically (`frontend/src/lib/permissions.js`),
     * which cannot express a rule that depends on an appointment, so the server
     * sends the difference and the client unions the two. Only the one
     * capability is dynamic today; the list will grow if another seating ever
     * grants access to a screen, and sending a list rather than a boolean means
     * the client does not need a new field each time.
     */
    public function extraCapabilities(): array
    {
        return $this->canKeyCoursework() && ! $this->role->canKeyCoursework()
            ? ['keyCoursework']
            : [];
    }

    public function canAccessArchive(): bool
    {
        return $this->role->canAccessArchive();
    }

    // -----------------------------------------------------------------
    // Account state
    // -----------------------------------------------------------------

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function markLoggedIn(?string $ip = null): void
    {
        $this->forceFill([
            'last_login_at'          => now(),
            'last_login_ip'          => $ip,
            'failed_login_attempts'  => 0,
            'locked_until'           => null,
        ])->save();
    }

    /**
     * Progressive lockout: after N failures the account is frozen for a
     * window that doubles each time. Returns true when the account just
     * became locked.
     */
    public function registerFailedLogin(int $maxAttempts = 5, int $lockMinutes = 15): bool
    {
        $attempts = $this->failed_login_attempts + 1;

        $this->forceFill(['failed_login_attempts' => $attempts]);

        if ($attempts >= $maxAttempts) {
            $over = intdiv($attempts, $maxAttempts) - 1;
            $this->locked_until = now()->addMinutes($lockMinutes * (2 ** max(0, $over)));
            $this->save();

            return true;
        }

        $this->save();

        return false;
    }

    public function unlock(): void
    {
        $this->forceFill([
            'failed_login_attempts' => 0,
            'locked_until'          => null,
        ])->save();
    }

    /**
     * Module 2 — should this supervisor appear in the coordinator's
     * assignment dropdown, i.e. are they available to take a new student?
     *
     * Pass `$psmPart` when the batch is known. Without it the check is "has room
     * in *some* batch", which under two concurrent batches is the honest default
     * — a supervisor who is full in PSM 1 is still a valid answer for a PSM 2
     * student, and dropping them from the list entirely would hide that.
     */
    public function isAvailableSupervisor(?string $psmPart = null): bool
    {
        if (! $this->isSupervisor() || ! $this->isActive()) {
            return false;
        }

        $profile = $this->supervisorProfile;

        if ($profile === null || ! $profile->is_accepting_students) {
            return false;
        }

        $part = PsmPart::tryParse($psmPart);

        return $part === null ? ! $profile->isFull() : $profile->hasCapacityFor($part);
    }

    // -----------------------------------------------------------------
    // Module 6 — notification preferences
    // -----------------------------------------------------------------

    /**
     * Whether the user wants this notification type.
     *
     * Resolution order: explicit per-type preference → urgent override →
     * default (on). Opting out of an urgent type is not honoured, because the
     * whole point of Module 6 is that deadlines stop being missed.
     */
    public function wantsNotification(NotificationType $type): bool
    {
        if ($type->isUrgent()) {
            return true;
        }

        $prefs = $this->notification_preferences ?? [];

        if (array_key_exists($type->value, $prefs)) {
            return (bool) $prefs[$type->value];
        }

        return true;
    }

    public function setNotificationPreference(NotificationType $type, bool $enabled): void
    {
        $prefs = $this->notification_preferences ?? [];
        $prefs[$type->value] = $enabled;

        $this->notification_preferences = $prefs;
        $this->save();
    }

    /** Channels for a given type, after applying the user's preferences. */
    public function channelsFor(NotificationType $type): array
    {
        if (! $this->wantsNotification($type)) {
            return [];
        }

        $channels = $type->defaultChannels();

        // A digest-only user still gets urgent mail immediately, but in-app
        // entries are suppressed so their notification centre stays quiet.
        if ($this->digest_only && ! $type->isUrgent()) {
            $channels = ['mail'];
        }

        return $channels;
    }

    // -----------------------------------------------------------------
    // Scopes
    // -----------------------------------------------------------------

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeWithRole($query, Role|string $role)
    {
        return $query->where('role', $role instanceof Role ? $role->value : $role);
    }

    /** Restrict to the batches/programs a coordinator is responsible for. */
    public function scopeInCoordinatorScope($query, self $coordinator)
    {
        $scopes = $coordinator->coordinatorScopes;

        if ($scopes->isEmpty()) {
            return $query;   // no explicit scope = whole faculty
        }

        return $query->where(function ($q) use ($scopes) {
            foreach ($scopes as $scope) {
                $q->orWhere(function ($sub) use ($scope) {
                    if ($scope->batch) {
                        $sub->where('batch', $scope->batch);
                    }
                    if ($scope->program) {
                        $sub->where('program', $scope->program);
                    }
                });
            }
        });
    }

    /** Display name including academic title, for rosters and the leaderboard. */
    public function displayName(): string
    {
        $title = $this->supervisorProfile?->academic_title;

        return $title ? trim("{$title} {$this->name}") : $this->name;
    }
}
