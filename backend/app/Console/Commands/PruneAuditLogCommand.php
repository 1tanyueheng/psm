<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Module 7 — Audit retention.
 *
 * The audit trail is append-only, so it grows without bound. This command is
 * the *only* sanctioned deletion path, and it enforces the configured
 * retention window rather than allowing arbitrary pruning.
 */
class PruneAuditLogCommand extends Command
{
    protected $signature = 'psm:prune-audit-log
                            {--years= : Override the configured retention period}
                            {--keep-security : Always retain security-relevant entries regardless of age}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune audit log entries outside the retention window (Module 7)';

    public function handle(): int
    {
        $years = (int) ($this->option('years')
            ?? config('psm.audit.retain_years', 7));

        $cutoff = now()->subYears($years);
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Retention: {$years} year(s) — entries before {$cutoff->toDateString()} are eligible.");

        $query = AuditLog::query()->where('created_at', '<', $cutoff);

        // Security and grade-integrity events are retained indefinitely by
        // default: they are the entries an investigation would need.
        if ($this->option('keep-security')) {
            $query->whereNotIn('severity', ['warning', 'critical'])
                  ->where('category', '!=', 'Authentication');
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('Nothing to prune.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("[dry-run] {$count} entr(ies) would be deleted.");

            return self::SUCCESS;
        }

        // Delete in chunks to avoid a long-running lock on a large table
        $deleted = 0;

        do {
            $batch = $query->limit(1000)->delete();
            $deleted += $batch;

            if ($batch > 0) {
                $this->line("  deleted {$deleted}/{$count}");
            }
        } while ($batch > 0);

        $this->info("Pruned {$deleted} audit entr(ies).");

        return self::SUCCESS;
    }
}
