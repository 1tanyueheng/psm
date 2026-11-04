<?php

namespace App\Models;

use App\Enums\Programme;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Module 2 — the programmes a coordinator is responsible for.
 *
 * **There are three states, and they are not the same thing:**
 *
 * | Rows | `is_primary` | The coordinator sees            |
 * |------|--------------|----------------------------------|
 * | any  | true         | **every programme** — they head it |
 * | one+ | false        | only the programmes on their rows  |
 * | none | n/a          | every programme (see the warning)  |
 *
 * `is_primary` used to be a boolean nothing read. It now marks the **head
 * coordinator**: the one person answerable for the whole cohort, who must be
 * able to see all of it. Everybody else is limited to their programme, which is
 * the real separation in this faculty — a BIP coordinator and a BIS coordinator
 * are different jobs; an intake year is a filter, not a job.
 *
 * **`batch` is gone** (2026-11-04). It used to restrict a coordinator to one
 * intake year, which turned a report filter into a permission: a coordinator
 * scoped to 2026 saw no other year on any screen, and no screen existed that
 * could change it. The report screen still narrows by batch with `?batch=2026`,
 * which is the right place for it — narrowing a view rather than limiting a
 * person.
 *
 * **The empty case still means "no restriction".** That is the original design
 * and it is deliberately not changed here: an administrator who forgets to scope
 * a coordinator grants too much rather than locking them out, and every
 * coordinator created through the user form — which has no scope field — would
 * otherwise lose all access. It is wrong in the direction of "too permissive",
 * which is the failure mode to fix with a scope editor rather than with a
 * migration.
 */
class CoordinatorScope extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'program',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The programme this scope covers, as the `Programme` enum.
     *
     * Null when the column is empty or holds a value the enum no longer knows —
     * a programme can be renamed (`BIK` → `BIP`) and an old row that survived
     * that rename should read as "no programme named", not throw.
     */
    public function programmeEnum(): ?Programme
    {
        return $this->program === null || $this->program === ''
            ? null
            : Programme::tryParse($this->program);
    }

    /** "BIP" or "All programmes". */
    public function describe(): string
    {
        if ($this->is_primary) {
            return 'All programmes (head)';
        }

        return $this->program ?: 'All programmes';
    }

    // -----------------------------------------------------------------
    // The rule, in one place
    // -----------------------------------------------------------------

    /**
     * The programmes this coordinator may see, or null for "no restriction".
     *
     * Null rather than an empty list, because the two mean opposite things to a
     * query: `null` is *add no filter*, `[]` is *match nothing*. Returning `[]`
     * here would turn a head coordinator into one who sees nothing at all.
     *
     * @param  iterable<int, CoordinatorScope>  $scopes
     * @return array<int, string>|null
     */
    public static function visibleProgrammes(iterable $scopes): ?array
    {
        $programmes = [];

        foreach ($scopes as $scope) {
            // Nobody limits the head coordinator — that is the point of the flag.
            if ($scope->is_primary) {
                return null;
            }

            // A row with no programme names no restriction either: it is what an
            // administrator produces when they mark somebody as a coordinator
            // without choosing a programme.
            if (blank($scope->program)) {
                return null;
            }

            $programmes[] = $scope->program;
        }

        return $programmes === [] ? null : array_values(array_unique($programmes));
    }
}
