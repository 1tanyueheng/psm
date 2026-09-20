<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 1 — Sanctum personal access tokens + Module 6 database notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------------------------------------------------------------
        // Module 1 — Sanctum personal access tokens
        // ---------------------------------------------------------------
        // Guarded rather than unconditional. Sanctum has shipped its own
        // migration for this table in some versions, and when the package
        // copy is loaded it runs first (its timestamp is 2019), leaving this
        // one to die with "Base table or view already exists: 1050".
        //
        // Depending on the installed Sanctum version that copy may or may not
        // be registered, so rather than assume, this creates the table only
        // when it is absent. On a fresh database the else-branch never runs.
        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        // ---------------------------------------------------------------
        // Module 6 — In-app notification queue
        // ---------------------------------------------------------------
        // Laravel's standard notifications table, extended with the columns
        // the UI needs to group and de-duplicate alerts.
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');

            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notif_recipient_read_idx');
        });

        // ---------------------------------------------------------------
        // Module 6 — Reminder dispatch ledger
        // ---------------------------------------------------------------
        // MOVED. This table has foreign keys to both `milestones` and `users`,
        // but `milestones` is not created until migration 000006. Creating it
        // here failed with:
        //
        //   SQLSTATE[HY000]: General error: 1824
        //   Failed to open the referenced table 'milestones'
        //
        // It now lives in 2026_01_01_000012_create_reminder_dispatches_table,
        // which runs after the tables it depends on.
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('personal_access_tokens');
    }
};
