<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Module 1 — the single authentication principal for all five roles.
 *
 * Role-specific data hangs off `studentProfile` / `supervisorProfile`; the
 * convenience accessors below (isStudent(), canAssess(), ...) exist so that
 * policies and the SPA never have to compare raw strings.
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
    public function isCoordinator(): bool { return $this->role === Role::Coordinator; }
    public function isExaminer(): bool    { return $this->role === Role::Examiner; }
    public function isAdmin(): bool       { return $this->role === Role::Admin; }

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
     */
    public function isAvailableSupervisor(): bool
    {
        if (! $this->isSupervisor() || ! $this->isActive()) {
            return false;
        }

        $profile = $this->supervisorProfile;

        return $profile !== null
            && $profile->is_accepting_students
            && $profile->hasCapacity();
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
