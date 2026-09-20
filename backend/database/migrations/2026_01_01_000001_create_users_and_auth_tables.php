<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 1 — Users, roles, sessions.
 *
 * Single-table user model with a role enum rather than separate tables per
 * role. Role-specific attributes live in profile tables (students,
 * supervisors) so a user can hold a profile without bloating this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Module 1 — RBAC
            $table->enum('role', ['student', 'supervisor', 'coordinator', 'examiner', 'admin'])
                  ->default('student')
                  ->index();

            // Account lifecycle: active | inactive | suspended | pending
            $table->enum('status', ['active', 'inactive', 'suspended', 'pending'])
                  ->default('active')
                  ->index();

            // Contact / display
            $table->string('phone', 32)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('staff_id', 64)->nullable()->unique();   // for staff roles
            $table->string('department')->nullable();

            // Session management & security
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();

            // Module 6 — notification preferences (per-type opt-out map)
            $table->json('notification_preferences')->nullable();
            $table->boolean('digest_only')->default(false);

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'status']);
        });

        // Token-based password reset (Module 1)
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
