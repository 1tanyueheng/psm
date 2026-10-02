<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Link a project back to the Lampiran A agreement it came from.
 *
 * The link already existed, but only as `projects.metadata.agreement_id` — a
 * JSON path, which means it cannot be indexed, has to be filtered in PHP on
 * every duplicate check, and is invisible to any `with()` on the model.
 *
 * Promoting it to a real nullable foreign key makes "has this agreement already
 * produced a project?" a single indexed lookup, and lets the registration list
 * link straight to the project. Existing rows are backfilled from the metadata
 * they already carry, so no project loses its origin.
 *
 * Nullable because a project can still be created directly through
 * ProjectController, which predates the Lampiran A/B flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('agreement_id')
                  ->nullable()
                  ->after('created_by')
                  ->constrained('supervisor_agreements')
                  ->nullOnDelete();
        });

        // Backfill from the JSON the Lampiran B flow already wrote. Portable
        // across drivers: read the candidate rows and match in PHP, because a
        // JSON path comparison is spelled differently on MySQL and Postgres.
        DB::table('projects')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(200, function ($projects) {
                foreach ($projects as $project) {
                    $metadata = json_decode($project->metadata, true);
                    $agreementId = is_array($metadata) ? ($metadata['agreement_id'] ?? null) : null;

                    if ($agreementId !== null) {
                        DB::table('projects')
                            ->where('id', $project->id)
                            ->update(['agreement_id' => (int) $agreementId]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agreement_id');
        });
    }
};
