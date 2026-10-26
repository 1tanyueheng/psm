<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A proposed title has to be a title.
 *
 * `string|max:255` accepts "1", "asdf" and "test test test" — and because
 * Lampiran A asks for three titles that must *differ*, three placeholders
 * satisfy every rule on the list. That is how agreement #5 in the demo data
 * ended up with the titles "1", "2" and "3", awaiting a supervisor who had
 * nothing to acknowledge.
 *
 * The checks here are deliberately few and mechanical. A title's quality is the
 * panel's judgement at the proposal milestone — this rule only rejects input
 * that is not an attempt at a title at all, so that the panel is never handed
 * something it cannot assess and the student is told immediately rather than
 * weeks later.
 */
class IsTitle implements ValidationRule
{
    /** Shortest string that could plausibly be a title, in characters. */
    public const MIN_LENGTH = 10;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('A proposed title must be text.');

            return;
        }

        $title = trim($value);

        if (mb_strlen($title) < self::MIN_LENGTH) {
            $fail('A proposed title must be at least '.self::MIN_LENGTH.' characters — write the title out.');

            return;
        }

        /**
         * A single word is not a title.
         *
         * "Blockchain" technically clears the length floor while saying nothing
         * about a project, and every real PSM title names both a subject and a
         * method or application.
         */
        if (count(preg_split('/\s+/', $title)) < 3) {
            $fail('A proposed title must be more than one or two words — say what the system does and how.');

            return;
        }

        // Letters, not only digits, punctuation or symbols.
        if (preg_match('/[A-Za-z]{3,}/', $title) !== 1) {
            $fail('A proposed title must contain words, not just numbers or symbols.');
        }
    }
}
