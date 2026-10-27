<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lampiran B becomes a record you can have several of.
 *
 * Until now Lampiran B existed only as `projects.agreement_id` plus
 * `projects.title`, so a refused title had nowhere to live: the panel's
 * objection pointed at a document that no longer existed once the title
 * changed. Every re-presentation overwrote the last one.
 *
 * With this table each filing is its own row — the title it proposed, the
 * supervisor's signature on it, and the verdict it received — so a student on
 * their third attempt has three forms and the panel can see what changed.
 *
 * `projects.title` keeps meaning "the title last accepted by a panel" (§3 of
 * thesis/PSM-LIFECYCLE-SPEC.md); a proposed title never overwrites it.
 *
 * The fields mirror the published form (`form required/lampiran A-B/lmapiranB.html`):
 * Part B asks for supervisor, title, project type and field of study, then the
 * requirements block; Part C the student's origin declaration; Part D the
 * supervisor's signature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lampiran_b_forms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            /**
             * Which filing this is, counted per project — 1, 2, 3…
             *
             * Not per term and not per student: the project is the thing being
             * retitled, and the sequence is what tells a reader that the second
             * form is a response to the first.
             */
            $table->unsignedSmallInteger('revision_no')->default(1);

            // ---- Part B: project details ----------------------------------
            $table->string('title');
            $table->string('project_type', 32);            // system | research
            $table->string('field_study')->nullable();

            $table->string('req_software')->nullable();
            $table->string('req_hardware')->nullable();
            $table->string('req_technology')->nullable();

            // ---- Part C: the student's declaration ------------------------
            $table->string('origin')->nullable();          // own idea | supervisor's

            // ---- Part D: the supervisor's signature -----------------------
            /**
             * A signature on *this* title. A supervisor who signed title X has
             * not signed title Y, so every re-filing carries its own.
             */
            $table->string('supervisor_signature_path')->nullable();
            $table->string('supervisor_signature_disk', 32)->nullable();
            $table->timestamp('supervisor_signed_at')->nullable();
            $table->foreignId('supervisor_profile_id')->nullable()
                ->constrained('supervisor_profiles')->nullOnDelete();

            /**
             * `signature_type` distinguishes a real signature from a seeded one.
             *
             * The demo data carries a plausible-looking scrawl so the printed
             * form is not empty. Nothing in the UI should present it as a real
             * approval, and this column is how a later reader can tell — the
             * image itself also carries a small DEMO mark.
             */
            $table->string('signature_type', 16)->nullable();   // drawn | uploaded | seeded

            // ---- State ----------------------------------------------------
            $table->string('status', 16)->default('draft');
            // draft | submitted | endorsed | accepted | rejected

            $table->string('rejected_reason')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();

            /**
             * The filing this one replaces, and the one that replaces it.
             *
             * `superseded_by_id` is null on the current form. Kept explicitly
             * rather than derived from `revision_no` so a withdrawn filing can
             * be skipped without making the chain lie about the order.
             */
            $table->foreignId('supersedes_id')->nullable()
                ->constrained('lampiran_b_forms')->nullOnDelete();
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('lampiran_b_forms')->nullOnDelete();

            /**
             * The milestone that carries this form in the chain.
             *
             * Null for the first filing: it was made before the project existed,
             * and the project's own `lampiran_b` milestone is its record. Every
             * later filing gets its own milestone inserted after the refusal.
             */
            $table->foreignId('milestone_id')->nullable()
                ->constrained('milestones')->nullOnDelete();

            $table->timestamps();

            $table->unique(['project_id', 'revision_no'], 'lampiran_b_project_revision_unique');
            $table->index(['project_id', 'status']);
        });

        $this->backfill();
    }

    /**
     * Build a form row for every project that came through registration.
     *
     * Only those with an `agreement_id` — the four registered projects in the
     * demo cohort. The other twenty-three were written straight into the
     * database, and inventing a signed form for them would put a supervisor's
     * name against a signature that never happened, which is the one thing a
     * faculty record must not do.
     */
    protected function backfill(): void
    {
        $projects = DB::table('projects')
            ->whereNotNull('agreement_id')
            ->whereNull('deleted_at')
            ->get(['id', 'title', 'category', 'metadata', 'created_at', 'approved_at', 'agreement_id']);

        foreach ($projects as $project) {
            $meta = json_decode($project->metadata ?? '{}', true) ?: [];
            $signed = $project->approved_at ?? $project->created_at;

            DB::table('lampiran_b_forms')->insert([
                'project_id'   => $project->id,
                'revision_no'  => 1,
                'title'        => $project->title,
                'project_type' => $project->category === 'research' ? 'research' : 'system',
                'field_study'  => $meta['field_study'] ?? null,
                'req_software' => $meta['requirements']['software'] ?? null,
                'req_hardware' => $meta['requirements']['hardware'] ?? null,
                'req_technology' => $meta['requirements']['technology'] ?? null,
                'origin'       => $meta['origin'] ?? null,
                'supervisor_signature_path' => null,
                'supervisor_signature_disk' => null,
                'supervisor_signed_at' => $signed,
                'supervisor_profile_id' => DB::table('supervisor_agreements')
                    ->where('id', $project->agreement_id)
                    ->value('supervisor_profile_id'),
                // Backfilled rows predate the demo signatures and had none.
                'signature_type' => null,
                'status'       => 'accepted',
                'milestone_id' => DB::table('milestones')
                    ->where('project_id', $project->id)
                    ->where('code', 'lampiran_b')
                    ->value('id'),
                'created_at'   => $project->created_at,
                'updated_at'   => $project->created_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lampiran_b_forms');
    }
};
