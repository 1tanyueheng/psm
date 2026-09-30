<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 4 — Link a rubric component to a milestone.
 *
 * The supervisor's assessment form is now structured one component per
 * chapter (Proposal, Chapter 1-5, Final Report), so the mark entered on a
 * component is the mark for that chapter. Storing the milestone code on the
 * component makes that correspondence explicit rather than a naming
 * convention: the marking UI can show each chapter's submission status
 * alongside the mark being given for it.
 *
 * Deliberately nullable and not a foreign key. The code is a template-level
 * label (`chapter_1`), while milestones are per-project rows that come and go
 * with the project. Referencing them directly would make a rubric template
 * unusable as soon as any one project's chain changed. A plain, indexed
 * string survives that, and a component with no milestone_code is simply one
 * that is not chapter-scoped — which is how the examiner and coordinator
 * rubrics continue to work unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rubric_components', function (Blueprint $table) {
            $table->string('milestone_code', 32)->nullable()->after('code');
            $table->index('milestone_code');
        });
    }

    public function down(): void
    {
        Schema::table('rubric_components', function (Blueprint $table) {
            $table->dropIndex(['milestone_code']);
            $table->dropColumn('milestone_code');
        });
    }
};
