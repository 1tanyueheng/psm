<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lampiran C — the fields the official form asks for that the system does not
 * hold, plus the student's own signature.
 *
 * The form's Bahagian A wants No. KP, No. Passport, No. Tel and the date of the
 * title defence. None of those exist in `student_profiles`, and they are not
 * data the system has any other use for — so they are captured on the form
 * itself rather than added to the profile. That keeps the schema honest: these
 * are answers on one document, not facts about the student that other screens
 * would then be expected to maintain.
 *
 * `student_signature_*` mirrors the supervisor's columns added in the previous
 * migration. The paper form has both boxes — Bahagian C is the student's
 * declaration, Bahagian D the supervisor's acknowledgment — and a corrections
 * form signed only by the supervisor would be missing the student's own
 * attestation that the corrections were made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            // Bahagian A — typed by the student, not held on the profile.
            $table->string('lampiran_c_ic_number', 32)->nullable()->after('lampiran_c_filed_by');
            $table->string('lampiran_c_passport_number', 32)->nullable()->after('lampiran_c_ic_number');
            $table->string('lampiran_c_mobile_number', 32)->nullable()->after('lampiran_c_passport_number');
            $table->date('lampiran_c_defense_date')->nullable()->after('lampiran_c_mobile_number');

            // Bahagian C — the student's signature, alongside the supervisor's.
            $table->string('student_signature_path')->nullable()->after('lampiran_c_defense_date');
            $table->string('student_signature_disk', 32)->nullable()->after('student_signature_path');
            $table->string('student_signature_type', 16)->nullable()->after('student_signature_disk');
            $table->timestamp('student_signed_at')->nullable()->after('student_signature_type');
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn([
                'lampiran_c_ic_number',
                'lampiran_c_passport_number',
                'lampiran_c_mobile_number',
                'lampiran_c_defense_date',
                'student_signature_path',
                'student_signature_disk',
                'student_signature_type',
                'student_signed_at',
            ]);
        });
    }
};
