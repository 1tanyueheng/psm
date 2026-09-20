<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 6 — Reminder dispatch ledger.
 *
 * Moved here from `2026_01_01_000002_create_notifications_and_tokens_tables`,
 * where it could not run: it declares foreign keys to both `milestones` and
 * `users`, but `milestones` is only created by migration 000006. MySQL rejects
 * a foreign key to a table that does not exist yet:
 *
 *   SQLSTATE[HY000]: General error: 1824
 *   Failed to open the referenced table 'milestones'
 *
 * Nothing else references this table, so moving it later is safe. The
 * migration is numbered above every table it depends on.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Prevents duplicate reminders: one row per (milestone, wave, user).
        // The scheduled command is idempotent because of the unique key.
        Schema::create('reminder_dispatches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('days_before');          // 7, 3, 1
            $table->string('notification_type', 64);
            $table->string('channel', 32)->default('mail');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(
                ['milestone_id', 'user_id', 'days_before', 'notification_type'],
                'reminder_unique_wave'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_dispatches');
    }
};
