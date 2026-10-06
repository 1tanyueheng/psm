<?php

namespace App\Enums;

/**
 * The five undergraduate programmes FSKTM offers, and the only ones PSM runs for.
 *
 * This exists because a supervisor may only supervise — and an examiner may only
 * examine — a student from **their own programme**. That rule needs the
 * programme to be a value both sides carry and that can be compared, so it is an
 * enum rather than free text: the six programme strings that were previously
 * stored ("Computer Science" and "Bachelor of Computer Science", two different
 * names sharing `IT240`) could not be compared reliably even in principle.
 *
 * The **code** is the key, because it is what the matching rule compares and it
 * is stable if the faculty renames a programme. `label()` carries the full
 * official name for printing.
 *
 * `Both` does not exist here: a person belongs to exactly one programme. That is
 * the point of the rule, and an "any programme" case would silently disable it.
 */
enum Programme: string
{
    case Bis = 'BIS';   // Bachelor of Computer Science (Information Security) With Honours
    case Bim = 'BIM';   // Bachelor of Computer Science (Multimedia Computing) With Honours
    case Bik = 'BIK';   // Bachelor of Computer Science (Software Engineering) With Honours
    case Biw = 'BIW';   // Bachelor of Computer Science (Web Technology) With Honours
    case Bit = 'BIT';   // Bachelor of Information Technology With Honours

    /** Full official name, for display. */
    public function label(): string
    {
        return match ($this) {
            self::Bis => 'Bachelor of Computer Science (Information Security) With Honours',
            self::Bim => 'Bachelor of Computer Science (Multimedia Computing) With Honours',
            self::Bik => 'Bachelor of Computer Science (Software Engineering) With Honours',
            self::Biw => 'Bachelor of Computer Science (Web Technology) With Honours',
            self::Bit => 'Bachelor of Information Technology With Honours',
        };
    }

    /** The shorter form, for a column that has no room for the full name. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Bis => 'CS (Information Security)',
            self::Bim => 'CS (Multimedia Computing)',
            self::Bik => 'CS (Software Engineering)',
            self::Biw => 'CS (Web Technology)',
            self::Bit => 'Information Technology',
        };
    }

    /**
     * Tolerate the loose values that already exist.
     *
     * Rows were written with codes (`CS230`, `IT240`, `SE250`) and with names
     * ("Computer Science"), and a value arriving from an old form or a query
     * string must not become a 500. Returns null for anything unrecognised so
     * the caller decides — matching on null would equate "unknown" with a real
     * programme and quietly permit an allocation the rule exists to refuse.
     *
     * The legacy mapping, applied by the migration that introduced this enum:
     *   CS230, CS240 -> BIS   (the CS intake is the Information Security stream)
     *   IT240        -> BIT
     *   SE240, SE250 -> BIK
     */
    public static function tryParse(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $needle = strtoupper(trim($value));

        if ($needle === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($needle === $case->value) {
                return $case;
            }
        }

        return match ($needle) {
            'CS230', 'CS240'     => self::Bis,
            'IT240', 'IT250'     => self::Bit,
            'SE240', 'SE250'     => self::Bik,
            // Older rows store a programme *name* rather than a code.
            'BACHELOR OF COMPUTER SCIENCE', 'COMPUTER SCIENCE' => self::Bis,
            'BACHELOR OF INFORMATION TECHNOLOGY', 'INFORMATION TECHNOLOGY' => self::Bit,
            'BACHELOR OF SOFTWARE ENGINEERING', 'SOFTWARE ENGINEERING' => self::Bik,
            default => null,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** `['BIS' => 'Bachelor of …', …]` — for a select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
