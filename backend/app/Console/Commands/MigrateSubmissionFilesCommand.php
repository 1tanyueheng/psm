<?php

namespace App\Console\Commands;

use App\Models\SubmissionFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Move submission files from one storage disk to another.
 *
 * This is the switch-over step for putting uploads on object storage. The
 * `submission_files.disk` column is per row, so the migration is incremental
 * and needs no downtime: each file is copied, verified, then flipped to the new
 * disk. Rows already on the target are skipped, so an interrupted run is safe
 * to repeat — which is the property that matters, because this touches the only
 * copy of a student's thesis.
 *
 * **The source is never deleted.** Reclaiming the old disk is a separate,
 * deliberate act (`--prune`) and only ever runs after a copy has been read back
 * and checksum-matched. That ordering is what makes a bad credential or a
 * misconfigured bucket a no-op instead of data loss.
 */
class MigrateSubmissionFilesCommand extends Command
{
    protected $signature = 'psm:migrate-submission-files
                            {--from= : Source disk (default: the current disk on each row)}
                            {--to= : Destination disk (default: filesystems.default)}
                            {--prune : Delete the source object once the copy is verified}
                            {--dry-run : Report what would move, without moving it}
                            {--limit= : Stop after this many files (for a trial run)}';

    protected $description = 'Copy submission files to another storage disk, verifying each one';

    public function handle(): int
    {
        $to = (string) ($this->option('to') ?: config('filesystems.default', 'local'));
        $from = $this->option('from') ?: null;
        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if (! $this->diskIsConfigured($to)) {
            $this->error("The destination disk [{$to}] is not configured.");

            return self::FAILURE;
        }

        if ($from !== null && ! $this->diskIsConfigured($from)) {
            $this->error("The source disk [{$from}] is not configured.");

            return self::FAILURE;
        }

        if ($from !== null && $from === $to) {
            $this->error("Source and destination are both [{$to}] — there is nothing to migrate.");

            return self::FAILURE;
        }

        $query = SubmissionFile::query()
            ->when($from !== null, fn ($q) => $q->where('disk', $from))
            // Never try to copy a file onto the disk it is already on: that
            // would rewrite the object and could truncate it on a partial read.
            ->when($from === null, fn ($q) => $q->where('disk', '!=', $to))
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("Nothing to migrate — every file is already on [{$to}].");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d file(s) to [%s]%s',
            $dryRun ? '[dry-run] Would migrate' : 'Migrating',
            $limit !== null ? min($limit, $total) : $total,
            $to,
            $prune ? ' (pruning source after verification)' : ''
        ));

        $moved = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;

        $query->chunkById(100, function ($files) use (
            $to, $dryRun, $prune, $limit, &$moved, &$skipped, &$failed, &$processed
        ) {
            foreach ($files as $file) {
                // Counted across every outcome, not just successes: a `--limit`
                // that only advanced on success would run the whole table when
                // the first files happen to be already-migrated or missing.
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }

                $processed++;

                $outcome = $this->migrateOne($file, $to, $dryRun, $prune);

                match ($outcome) {
                    'moved'   => $moved++,
                    'skipped' => $skipped++,
                    default   => $failed++,
                };
            }

            return true;
        });

        $this->newLine();
        $this->info("Migrated: {$moved}   Skipped: {$skipped}   Failed: {$failed}");

        if ($failed > 0) {
            $this->warn(
                'Failed files were left exactly as they were — still on their original disk, '
                .'still referenced by their row. Nothing was lost; fix the cause and re-run.'
            );

            return self::FAILURE;
        }

        if (! $prune && ! $dryRun && $moved > 0) {
            $this->comment(
                'Source objects were kept. Once you have confirmed downloads work, '
                .'re-run with --prune to reclaim the old disk.'
            );
        }

        return self::SUCCESS;
    }

    /**
     * Copy one file, verify it, then flip its row.
     *
     * @return string 'moved' | 'skipped' | 'failed'
     */
    protected function migrateOne(SubmissionFile $file, string $to, bool $dryRun, bool $prune): string
    {
        $source = Storage::disk($file->disk);

        if (! $source->exists($file->path)) {
            $this->warn("  #{$file->id} missing on [{$file->disk}] — {$file->path}");

            return 'failed';
        }

        if ($dryRun) {
            $this->line("  #{$file->id} {$file->original_name} → [{$to}]");

            return 'moved';
        }

        $destination = Storage::disk($to);

        // Already there (an interrupted earlier run): only the row needs fixing.
        if ($destination->exists($file->path) && $this->checksumsMatch($source, $destination, $file->path)) {
            $file->update(['disk' => $to]);

            // The source is reclaimable here too, and on a resumed run this is
            // the *common* path — skipping it would leave the old disk holding
            // exactly the files an earlier `--prune` was meant to remove.
            $this->pruneSource($source, $file, $prune);

            $this->line("  #{$file->id} already present on [{$to}] — row updated");

            return 'skipped';
        }

        try {
            $stream = $source->readStream($file->path);

            if ($stream === null) {
                $this->warn("  #{$file->id} could not be read from [{$file->disk}]");

                return 'failed';
            }

            $destination->writeStream($file->path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        } catch (Throwable $e) {
            report($e);
            $this->warn("  #{$file->id} copy failed: {$e->getMessage()}");

            return 'failed';
        }

        /**
         * Read the copy back before trusting it.
         *
         * A `writeStream` that returns without throwing is not proof the bytes
         * landed — a truncated upload to object storage can succeed at the HTTP
         * level. The stored checksum is the only independent evidence, and it
         * already exists on every row, so verifying is nearly free and it is the
         * difference between a migration and a data-loss incident.
         */
        if (! $this->checksumsMatch($source, $destination, $file->path)) {
            $this->warn("  #{$file->id} checksum mismatch after copy — row left on [{$file->disk}]");

            return 'failed';
        }

        // The row flips only after the copy is proven readable.
        $file->update(['disk' => $to]);

        $this->pruneSource($source, $file, $prune);

        $this->line("  #{$file->id} {$file->original_name} → [{$to}]");

        return 'moved';
    }

    /**
     * Remove the source object once the copy is verified.
     *
     * Only ever reached after `checksumsMatch()` has passed and the row already
     * points at the destination, so the worst case for a failure here is an
     * orphaned object rather than a lost file.
     */
    protected function pruneSource($source, SubmissionFile $file, bool $prune): void
    {
        if (! $prune) {
            return;
        }

        try {
            $source->delete($file->path);
        } catch (Throwable $e) {
            report($e);
            $this->warn("  #{$file->id} copied, but the source could not be removed: {$e->getMessage()}");
        }
    }

    /**
     * Compare the two copies by content hash.
     *
     * Streams rather than loading either file into memory: these are thesis
     * PDFs and a 25 MB read per file would be an avoidable memory spike.
     */
    protected function checksumsMatch($source, $destination, string $path): bool
    {
        $sourceHash = $this->hashOf($source, $path);
        $destinationHash = $this->hashOf($destination, $path);

        return $sourceHash !== null && $sourceHash === $destinationHash;
    }

    protected function hashOf($disk, string $path): ?string
    {
        try {
            $stream = $disk->readStream($path);

            if ($stream === null) {
                return null;
            }

            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return hash_final($context);
        } catch (Throwable) {
            return null;
        }
    }

    protected function diskIsConfigured(string $disk): bool
    {
        return config("filesystems.disks.{$disk}") !== null;
    }
}
