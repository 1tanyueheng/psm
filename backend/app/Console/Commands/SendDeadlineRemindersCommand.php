<?php

namespace App\Console\Commands;

use App\Enums\MilestoneStatus;
use App\Enums\NotificationType;
use App\Models\Milestone;
use App\Models\User;
use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Module 6 — Deadline reminders.
 *
 * For each configured reminder wave (default 7, 3 and 1 days before a
 * deadline), find the milestones due on that date and notify the students
 * concerned.
 *
 * Safe to run repeatedly: NotificationDispatcher::sendReminder() records each
 * dispatch, so a retry or an overlapping run cannot double-send.
 */
class SendDeadlineRemindersCommand extends Command
{
    protected $signature = 'psm:send-deadline-reminders
                            {--days=* : Override the reminder waves, in days}
                            {--digest : Send one consolidated digest instead of individual alerts}
                            {--dry-run : Report what would be sent without sending}';

    protected $description = 'Send milestone deadline reminders to students (Module 6)';

    public function handle(NotificationDispatcher $notifications): int
    {
        $days = $this->option('days')
            ? array_map('intval', $this->option('days'))
            : config('psm.reminder_days_before', [7, 3, 1]);

        $dryRun   = (bool) $this->option('dry-run');
        $isDigest = (bool) $this->option('digest');

        $this->info(sprintf(
            '%s reminders for waves: %s',
            $isDigest ? 'Digest' : 'Individual',
            implode(', ', array_map(fn ($d) => "{$d}d", $days))
        ));

        $totalSent = 0;

        foreach ($days as $daysBefore) {
            $milestones = Milestone::query()
                ->dueInDays($daysBefore)
                ->with(['project.students.user', 'project.students.activeSupervisions.supervisorProfile.user'])
                ->get();

            if ($milestones->isEmpty()) {
                $this->line("  {$daysBefore}d: nothing due");
                continue;
            }

            $sent = 0;

            foreach ($milestones as $milestone) {
                $recipients = $milestone->project->students
                    ->pluck('user')
                    ->filter();

                if ($isDigest) {
                    $recipients = $recipients->filter(fn (User $u) => $u->digest_only);
                }

                if ($recipients->isEmpty()) {
                    continue;
                }

                $daysText = $daysBefore === 1 ? 'tomorrow' : "in {$daysBefore} days";

                $payload = [
                    'title'      => "Deadline {$daysText}: {$milestone->title}",
                    'body'       => sprintf(
                        "'%s' for %s is due %s. Current status: %s.",
                        $milestone->title,
                        $milestone->project->code,
                        $milestone->due_at?->format('d M Y'),
                        $milestone->status->label(),
                    ),
                    'action_url' => "/projects/{$milestone->project_id}/milestones/{$milestone->id}",
                    'urgent'     => $daysBefore <= 1,
                    'meta'       => [
                        'milestone_id' => $milestone->id,
                        'project_code' => $milestone->project->code,
                        'due_at'       => $milestone->due_at?->toDateString(),
                        'days_before'  => $daysBefore,
                    ],
                ];

                foreach ($recipients as $student) {
                    if ($dryRun) {
                        $this->line("  [dry-run] would notify {$student->email} ({$daysBefore}d, {$milestone->code})");
                        $sent++;
                        continue;
                    }

                    if ($notifications->sendReminder(
                        $student,
                        $milestone,
                        $daysBefore,
                        NotificationType::DeadlineReminder,
                        $payload,
                    )) {
                        $sent++;
                    }
                }

                // Keep supervisors aware without spamming them: only the
                // final wave, and only as information.
                if ($daysBefore === 1 && ! $isDigest && ! $dryRun) {
                    $supervisors = $milestone->project->students
                        ->flatMap(fn ($s) => $s->activeSupervisions)
                        ->map(fn ($a) => $a->supervisorProfile?->user)
                        ->filter()
                        ->unique('id');

                    foreach ($supervisors as $supervisor) {
                        $notifications->sendReminder(
                            $supervisor,
                            $milestone,
                            $daysBefore,
                            NotificationType::DeadlineReminder,
                            [
                                'title'      => "Deadline tomorrow: {$milestone->project->code}",
                                'body'       => "{$milestone->title} is due tomorrow and has not been submitted.",
                                'action_url' => "/projects/{$milestone->project_id}/milestones/{$milestone->id}",
                            ],
                        );
                    }
                }
            }

            $this->line("  {$daysBefore}d: {$sent} reminder(s) sent");
            $totalSent += $sent;
        }

        $this->info("Total: {$totalSent} reminder(s)".($dryRun ? ' (dry run)' : ''));

        return self::SUCCESS;
    }
}
