<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Populate rubric templates with the actual criteria from the official
 * Lampiran HTML forms. Uses the development variant as the unified set
 * (category was removed from rubric separation in the previous migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        // -----------------------------------------------------------------
        // Helper: wipe then insert components + criteria for one template
        // -----------------------------------------------------------------
        $fill = function (string $formCode, array $components) {
            $templateId = DB::table('rubric_templates')
                ->where('form_code', $formCode)
                ->value('id');

            if (! $templateId) {
                return;
            }

            // Delete existing criteria and components
            DB::table('rubric_criteria')
                ->whereIn('rubric_component_id', function ($q) use ($templateId) {
                    $q->select('id')->from('rubric_components')
                      ->where('rubric_template_id', $templateId);
                })->delete();

            DB::table('rubric_components')
                ->where('rubric_template_id', $templateId)
                ->delete();

            $seqComp = 0;
            foreach ($components as $comp) {
                $compId = DB::table('rubric_components')->insertGetId([
                    'rubric_template_id' => $templateId,
                    'code'               => $comp['code'],
                    'title'              => $comp['title'],
                    'description'        => $comp['description'] ?? null,
                    'weight_percent'     => $comp['weight_percent'],
                    'sequence'           => ++$seqComp,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);

                $seqCrit = 0;
                foreach ($comp['criteria'] as $c) {
                    DB::table('rubric_criteria')->insert([
                        'rubric_component_id' => $compId,
                        'code'                => $c['code'],
                        'title'               => $c['title'],
                        'description'         => $c['description'] ?? null,
                        'guidance'            => $c['guidance'] ?? null,
                        'weight_percent'      => $c['weight_percent'],
                        'max_marks'           => 5.00,
                        'sequence'            => ++$seqCrit,
                        'is_required'         => 1,
                        'created_at'          => now(),
                        'updated_at'          => now(),
                    ]);
                }
            }
        };

        // -----------------------------------------------------------------
        // Lampiran E — PSM 1 Supervisor (35%)
        // -----------------------------------------------------------------
        $fill('E', [
            [
                'code'           => 'A_logbook',
                'title'          => 'Komponen A: Buku Log (5%)',
                'description'    => 'Component A: Logbook',
                'weight_percent' => 5.00,
                'criteria'       => [
                    ['code' => 'A1', 'title' => 'Perancangan Pelaksanaan PSM (PSM Implementation Planning)', 'weight_percent' => 1.25],
                    ['code' => 'A2', 'title' => 'Interaksi dengan Penyelia dan Disiplin (Interaction with Supervisor)', 'weight_percent' => 1.25],
                    ['code' => 'A3', 'title' => 'Aktiviti Mingguan / Lampiran Kerja (Weekly Activities / Attachments)', 'weight_percent' => 1.25],
                    ['code' => 'A4', 'title' => 'Perbincangan Laporan Kemajuan Projek (Progress Report Discussion)', 'weight_percent' => 1.25],
                ],
            ],
            [
                'code'           => 'B_report',
                'title'          => 'Komponen B: Penulisan Laporan (20%)',
                'description'    => 'Component B: Report Writing',
                'weight_percent' => 20.00,
                'criteria'       => [
                    ['code' => 'B1', 'title' => 'Bab 1 (Chapter 1)', 'weight_percent' => 5.00],
                    ['code' => 'B2', 'title' => 'Bab 2 (Chapter 2)', 'weight_percent' => 5.00],
                    ['code' => 'B3', 'title' => 'Bab 3 (Chapter 3)', 'weight_percent' => 5.00],
                    ['code' => 'B4', 'title' => 'Bab 4 (Chapter 4)', 'weight_percent' => 5.00],
                ],
            ],
            [
                'code'           => 'C_prototype',
                'title'          => 'Komponen C: Penilaian Prototaip / Kerangka Kajian (10%)',
                'description'    => 'Component C: Prototype / Research Framework Assessment. For development projects, assess the prototype. For research projects, assess the research framework.',
                'weight_percent' => 10.00,
                'criteria'       => [
                    ['code' => 'C1', 'title' => 'Analisis & Spesifikasi (Analysis & Specifications)', 'weight_percent' => 3.30],
                    ['code' => 'C2', 'title' => 'Antaramuka / Papan Cerita (User Interface / Storyboard)', 'weight_percent' => 3.30],
                    ['code' => 'C3', 'title' => 'Prototaip (Prototype)', 'weight_percent' => 3.40],
                ],
            ],
        ]);

        // -----------------------------------------------------------------
        // Lampiran I — PSM 1 Examiner (30%)
        // -----------------------------------------------------------------
        $fill('I', [
            [
                'code'           => 'A_paper',
                'title'          => 'Komponen A: Kertas Prosiding (10%)',
                'description'    => 'Component A: Proceeding Paper',
                'weight_percent' => 10.00,
                'criteria'       => [
                    ['code' => 'A1', 'title' => 'Abstrak (Abstract)', 'weight_percent' => 1.45],
                    ['code' => 'A2', 'title' => 'Pengenalan (Introduction)', 'weight_percent' => 1.45],
                    ['code' => 'A3', 'title' => 'Kajian Literatur (Literature Review)', 'weight_percent' => 1.45],
                    ['code' => 'A4', 'title' => 'Metodologi (Methodology)', 'weight_percent' => 1.40],
                    ['code' => 'A5', 'title' => 'Analisis & Rekabentuk (Analysis & Design)', 'weight_percent' => 1.45],
                    ['code' => 'A6', 'title' => 'Kesimpulan & Rujukan (Conclusion & Reference)', 'weight_percent' => 1.40],
                    ['code' => 'A7', 'title' => 'Format Laporan (Report Formatting)', 'weight_percent' => 1.40],
                ],
            ],
            [
                'code'           => 'B_presentation',
                'title'          => 'Komponen B: Pembentangan Prototaip / Kerangka Kajian (15%)',
                'description'    => 'Component B: Presentation of Prototype / Research Framework',
                'weight_percent' => 15.00,
                'criteria'       => [
                    ['code' => 'B1', 'title' => 'Analisis & Spesifikasi (Analysis & Specifications)', 'weight_percent' => 4.95],
                    ['code' => 'B2', 'title' => 'Antaramuka / Papan Cerita (User Interface / Storyboard)', 'weight_percent' => 4.95],
                    ['code' => 'B3', 'title' => 'Prototaip (Prototype)', 'weight_percent' => 5.10],
                ],
            ],
            [
                'code'           => 'C_delivery',
                'title'          => 'Komponen C: Pembentangan (5%)',
                'description'    => 'Component C: Presentation Delivery',
                'weight_percent' => 5.00,
                'criteria'       => [
                    ['code' => 'C1', 'title' => 'Penampilan (Appearance)', 'weight_percent' => 1.65],
                    ['code' => 'C2', 'title' => 'Sesi Soal Jawab (Q&A Session)', 'weight_percent' => 1.65],
                    ['code' => 'C3', 'title' => 'Organisasi Pembentangan (Presentation Organization)', 'weight_percent' => 1.70],
                ],
            ],
        ]);

        // -----------------------------------------------------------------
        // Lampiran G — PSM 2 Supervisor (50%)
        // -----------------------------------------------------------------
        $fill('G', [
            [
                'code'           => 'A_logbook',
                'title'          => 'Komponen A: Buku Log (5%)',
                'description'    => 'Component A: Logbook',
                'weight_percent' => 5.00,
                'criteria'       => [
                    ['code' => 'A1', 'title' => 'Perancangan Pelaksanaan PSM (PSM Implementation Planning)', 'weight_percent' => 1.25],
                    ['code' => 'A2', 'title' => 'Interaksi dengan Penyelia dan Disiplin (Interaction with Supervisor)', 'weight_percent' => 1.25],
                    ['code' => 'A3', 'title' => 'Aktiviti Mingguan / Lampiran Kerja (Weekly Activities / Attachments)', 'weight_percent' => 1.25],
                    ['code' => 'A4', 'title' => 'Perbincangan Laporan Kemajuan Projek (Progress Report Discussion)', 'weight_percent' => 1.25],
                ],
            ],
            [
                'code'           => 'B_report',
                'title'          => 'Komponen B: Laporan PSM 2 (20%)',
                'description'    => 'Component B: PSM 2 Report',
                'weight_percent' => 20.00,
                'criteria'       => [
                    ['code' => 'B1', 'title' => 'Abstrak (Abstract)', 'weight_percent' => 1.15],
                    ['code' => 'B2', 'title' => 'Pengenalan (Introduction)', 'weight_percent' => 2.20],
                    ['code' => 'B3', 'title' => 'Kajian Literatur (Literature Review)', 'weight_percent' => 3.30],
                    ['code' => 'B4', 'title' => 'Metodologi (Methodology)', 'weight_percent' => 2.20],
                    ['code' => 'B5', 'title' => 'Analisis dan Rekabentuk (Analysis and Design)', 'weight_percent' => 3.30],
                    ['code' => 'B6', 'title' => 'Hasil Kajian / Dapatan / Output (Results / Findings)', 'weight_percent' => 4.50],
                    ['code' => 'B7', 'title' => 'Kesimpulan dan Cadangan (Conclusion and Suggestion)', 'weight_percent' => 1.15],
                    ['code' => 'B8', 'title' => 'Format Laporan (Report Formatting)', 'weight_percent' => 2.20],
                ],
            ],
            [
                'code'           => 'C_product',
                'title'          => 'Komponen C: Produk PSM 2 (25%)',
                'description'    => 'Component C: PSM 2 Product',
                'weight_percent' => 25.00,
                'criteria'       => [
                    ['code' => 'C1', 'title' => 'Analisa Sistem (System Analysis)', 'weight_percent' => 4.19],
                    ['code' => 'C2', 'title' => 'Rekabentuk Sistem (System Design)', 'weight_percent' => 4.19],
                    ['code' => 'C3', 'title' => 'Terjemahan Pembangunan (Translation of Development)', 'weight_percent' => 4.19],
                    ['code' => 'C4', 'title' => 'Implementasi (Implementation)', 'weight_percent' => 4.19],
                    ['code' => 'C5', 'title' => 'Pengujian, verifikasi dan validasi (Testing & Validation)', 'weight_percent' => 4.12],
                    ['code' => 'C6', 'title' => 'Kreatif, inovatif dan bercirikan komersial (Commercial Value)', 'weight_percent' => 4.12],
                ],
            ],
        ]);

        // -----------------------------------------------------------------
        // Lampiran H — PSM 2 Supervisor Progress Report (5%)
        // -----------------------------------------------------------------
        $fill('H', [
            [
                'code'           => 'progress',
                'title'          => 'Progress Report (5%)',
                'description'    => 'Progress Report Evaluation',
                'weight_percent' => 5.00,
                'criteria'       => [
                    ['code' => 'H1', 'title' => 'Pencapaian Penanda Aras (Milestone Achievement)', 'weight_percent' => 1.65],
                    ['code' => 'H2', 'title' => 'Kemajuan Projek seperti dirancang (Project Progressed as Planned)', 'weight_percent' => 1.65],
                    ['code' => 'H3', 'title' => 'Peningkatan di dalam Pengetahuan dan Kemahiran (Increased in Knowledge and Skills)', 'weight_percent' => 1.70],
                ],
            ],
        ]);

        // -----------------------------------------------------------------
        // Lampiran J — PSM 2 Examiner (40%)
        // -----------------------------------------------------------------
        $fill('J', [
            [
                'code'           => 'A_paper',
                'title'          => 'Komponen A: Kertas Prosiding (10%)',
                'description'    => 'Component A: Proceeding Paper',
                'weight_percent' => 10.00,
                'criteria'       => [
                    ['code' => 'A1', 'title' => 'Abstrak (Abstract)', 'weight_percent' => 1.45],
                    ['code' => 'A2', 'title' => 'Pengenalan dan Kajian Literatur (Intro & Literature Review)', 'weight_percent' => 1.45],
                    ['code' => 'A3', 'title' => 'Metodologi (Methodology)', 'weight_percent' => 1.45],
                    ['code' => 'A4', 'title' => 'Hasil Kajian / Perbincangan / Analisis (Findings / Discussion)', 'weight_percent' => 1.45],
                    ['code' => 'A5', 'title' => 'Kesimpulan & Cadangan (Conclusion & Suggestion)', 'weight_percent' => 1.40],
                    ['code' => 'A6', 'title' => 'Rujukan (Reference)', 'weight_percent' => 1.40],
                    ['code' => 'A7', 'title' => 'Format Laporan (Report Formatting)', 'weight_percent' => 1.40],
                ],
            ],
            [
                'code'           => 'B_product',
                'title'          => 'Komponen B: Produk Akhir PSM 2 (25%)',
                'description'    => 'Component B: Final Product',
                'weight_percent' => 25.00,
                'criteria'       => [
                    ['code' => 'B1', 'title' => 'Analisa Sistem (System Analysis)', 'weight_percent' => 4.19],
                    ['code' => 'B2', 'title' => 'Rekabentuk Sistem (System Design)', 'weight_percent' => 4.19],
                    ['code' => 'B3', 'title' => 'Terjemahan Pembangunan (Translation of Development)', 'weight_percent' => 4.19],
                    ['code' => 'B4', 'title' => 'Implementasi (Implementation)', 'weight_percent' => 4.19],
                    ['code' => 'B5', 'title' => 'Pengujian, verifikasi dan validasi (Testing & Validation)', 'weight_percent' => 4.12],
                    ['code' => 'B6', 'title' => 'Kreatif, inovatif dan bercirikan komersial (Commercial Value)', 'weight_percent' => 4.12],
                ],
            ],
            [
                'code'           => 'C_delivery',
                'title'          => 'Komponen C: Pembentangan (5%)',
                'description'    => 'Component C: Presentation',
                'weight_percent' => 5.00,
                'criteria'       => [
                    ['code' => 'C1', 'title' => 'Penampilan (Appearance)', 'weight_percent' => 1.25],
                    ['code' => 'C2', 'title' => 'Pengetahuan Bidang (Field Knowledge)', 'weight_percent' => 1.25],
                    ['code' => 'C3', 'title' => 'Organisasi Pembentangan (Presentation Organization)', 'weight_percent' => 1.25],
                    ['code' => 'C4', 'title' => 'Sesi Soal Jawab (Q&A Session)', 'weight_percent' => 1.25],
                ],
            ],
        ]);

        // Update template names to match the official forms
        DB::table('rubric_templates')->where('form_code', 'E')->update([
            'name' => 'Lampiran E — Borang Penilaian PSM 1 Bagi Penyelia (35%)',
        ]);
        DB::table('rubric_templates')->where('form_code', 'I')->update([
            'name' => 'Lampiran I — Borang Penilaian Seminar 1 Bagi Penilai (30%)',
        ]);
        DB::table('rubric_templates')->where('form_code', 'G')->update([
            'name' => 'Lampiran G — Borang Penilaian PSM 2 Bagi Penyelia (50%)',
        ]);
        DB::table('rubric_templates')->where('form_code', 'H')->update([
            'name' => 'Lampiran H — Borang Penilaian Laporan Kemajuan (5%)',
        ]);
        DB::table('rubric_templates')->where('form_code', 'J')->update([
            'name' => 'Lampiran J — Borang Penilaian Seminar Akhir Bagi Penilai (40%)',
        ]);
    }

    public function down(): void
    {
        // Not reversible to exact prior state; the previous seeder can be
        // re-run if needed.
    }
};
