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
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('personal_access_tokens');
    }
};
