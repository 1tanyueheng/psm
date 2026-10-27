<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project no longer carries a registration abstract.
 *
 * `projects.abstract` was required and collected at registration. It is on none
 * of the faculty's forms: Lampiran B asks for the supervisor, title, project
 * type and field of study, and then instructs the student to *attach a proposal*
 * containing the introduction, problem statement, objectives and scope. Those
 * sections are the proposal document, uploaded at the proposal milestone.
 *
 * Requiring an abstract at registration forced students to write one before
 * they had a settled title — which is how placeholder text ends up in the
 * record. Nothing downstream read it: the marks come from rubrics, the documents
 * render from the agreement, and the supervisor reads the uploaded proposal.
 *
 * The column stays, nullable, rather than being dropped. It is still written by
 * the seeder and by `ProjectController::update()`, and a nullable column that
 * some rows fill is honest about the history; dropping it would break the
 * project edit form and lose any abstract already recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('abstract')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restoring NOT NULL would fail on any project created since — those
        // rows legitimately have no abstract. A default empty string would be
        // worse than leaving it nullable, so the column is left as it is.
    }
};
