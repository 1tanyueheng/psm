<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distinguish the official marking forms (Lampiran E, G, H, I, J) on a rubric
 * template.
 *
 * Lampiran G and Lampiran H are both (PSM2, supervisor), so without a form
 * discriminator they collide on the template unique key. `form_code` carries
 * the Lampiran letter and joins that key.
 *
 * The three discriminator columns were created as unbounded varchar(255), so on
 * utf8mb4 the old four-column key already sat at 3060 bytes — just inside
 * MySQL's 3072-byte index limit. Adding form_code pushed it over and MySQL
 * rejected the key outright (SQLSTATE[42000] 1071). Narrowing the columns to
 * their real domain (a category slug, a PSM part, an assessor role) brings the
 * key to 392 bytes. Raw ALTER is used so the standalone indexes on `category`
 * and `assessor_type` survive untouched; a schema-builder `change()` would
 * require every attribute to be restated and risks dropping them.
 */
return new class extends Migration
{
    /** @var array<string, int> */
    private const WIDTHS = [
        'category' => 50,
        'psm_part' => 20,
        'assessor_type' => 20,
    ];

    /** @var array<string, string> */
    private const DEFAULTS = [
        'psm_part' => 'BOTH',
        'assessor_type' => 'supervisor',
    ];

    public function up(): void
    {
        Schema::table('rubric_templates', function ($table) {
            $table->dropUnique('rubric_version_unique');
        });

        $this->setWidths(self::WIDTHS);

        Schema::table('rubric_templates', function ($table) {
            $table->string('form_code', 8)->nullable()->index();
        });

        Schema::table('rubric_templates', function ($table) {
            $table->unique(
                ['category', 'psm_part', 'assessor_type', 'form_code', 'version'],
                'rubric_form_version_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('rubric_templates', function ($table) {
            $table->dropUnique('rubric_form_version_unique');
            $table->dropColumn('form_code');
        });

        $this->setWidths(['category' => 255, 'psm_part' => 255, 'assessor_type' => 255]);

        Schema::table('rubric_templates', function ($table) {
            $table->unique(
                ['category', 'psm_part', 'assessor_type', 'version'],
                'rubric_version_unique'
            );
        });
    }

    /**
     * @param  array<string, int>  $widths
     */
    private function setWidths(array $widths): void
    {
        $driver = DB::getDriverName();

        foreach ($widths as $column => $width) {
            if ($driver === 'mysql') {
                $default = isset(self::DEFAULTS[$column])
                    ? " DEFAULT '".self::DEFAULTS[$column]."'"
                    : '';

                DB::statement(sprintf(
                    'ALTER TABLE `rubric_templates` MODIFY `%s` VARCHAR(%d) NOT NULL%s',
                    $column,
                    $width,
                    $default
                ));

                continue;
            }

            if ($driver !== 'pgsql') {
                throw new RuntimeException(sprintf(
                    'Narrowing rubric_templates.%s is only implemented for mysql and pgsql, not %s.',
                    $column,
                    $driver
                ));
            }

            // Postgres keeps NOT NULL and the column default when the type is
            // narrowed, so only the type needs restating.
            DB::statement(sprintf(
                'ALTER TABLE rubric_templates ALTER COLUMN %s TYPE VARCHAR(%d)',
                $column,
                $width
            ));
        }
    }
};
