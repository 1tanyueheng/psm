<?php

use App\Console\Commands\PruneAuditLogCommand;
use App\Console\Commands\SendDeadlineRemindersCommand;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console routes & schedule
|--------------------------------------------------------------------------
|
| Module 6 lives here. On Render, the scheduler is driven by a cron job that
| runs `php artisan schedule:run` every minute, or by a dedicated worker
| process running `php artisan schedule:work`.
|
*/

// ---------------------------------------------------------------------
// Module 6 — Deadline reminders
// ---------------------------------------------------------------------
// Runs hourly; the command itself decides which reminder wave (7/3/1 days)
// applies today, so changing REMINDER_DAYS_BEFORE takes effect immediately
// without touching this schedule.
Schedule::command(SendDeadlineRemindersCommand::class)
    ->hourly()
    ->between(
        sprintf('%02d:00', (int) config('psm.reminder_send_hour', 8)),
        sprintf('%02d:59', (int) config('psm.reminder_send_hour', 8)),
    )
    ->name('deadline-reminders')
    ->withoutOverlapping()
    ->onOneServer();

// ---------------------------------------------------------------------
// Module 3 — Flag milestones that missed their deadline
// ---------------------------------------------------------------------
Schedule::call(function () {
    app(\App\Services\MilestoneService::class)->flagOverdue();
})
    ->dailyAt('00:15')
    ->name('flag-overdue-milestones')
    ->withoutOverlapping()
    ->onOneServer();

// ---------------------------------------------------------------------
// Module 3 — Open milestones whose window has begun
// ---------------------------------------------------------------------
Schedule::call(function () {
    \App\Models\Milestone::query()
        ->where('status', \App\Enums\MilestoneStatus::Pending->value)
        ->whereNotNull('opens_at')
        ->whereDate('opens_at', '<=', now())
        // Only open a milestone if its predecessor is settled
        ->whereDoesntHave('project.milestones', function ($q) {
            $q->whereColumn('sequence', '<', 'milestones.sequence')
              ->whereNotIn('status', [
                  \App\Enums\MilestoneStatus::Approved->value,
              ]);
        })
        ->update(['status' => \App\Enums\MilestoneStatus::Open->value]);
})
    ->dailyAt('00:05')
    ->name('open-due-milestones')
    ->withoutOverlapping()
    ->onOneServer();

// ---------------------------------------------------------------------
// Module 6 — Deadline digest
// ---------------------------------------------------------------------
// A single daily summary for users who set digest_only, so their inbox is
// not flooded but they still cannot claim they were not told.
Schedule::command(SendDeadlineRemindersCommand::class, ['--digest'])
    ->dailyAt('07:30')
    ->name('deadline-digest')
    ->withoutOverlapping()
    ->onOneServer();

// ---------------------------------------------------------------------
// Module 7 — Audit retention
// ---------------------------------------------------------------------
Schedule::command(PruneAuditLogCommand::class)
    ->monthlyOn(1, '03:00')
    ->name('prune-audit-log')
    ->withoutOverlapping()
    ->onOneServer();

// ---------------------------------------------------------------------
// Housekeeping — clear stale queue and session noise
// ---------------------------------------------------------------------
Schedule::command('queue:prune-failed --hours=720')->weekly()->name('prune-failed-jobs');
Schedule::command('sanctum:prune-expired --hours=168')->daily()->name('prune-expired-tokens');
Schedule::command('model:prune')->daily()->name('prune-models');
