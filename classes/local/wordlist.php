<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Parsing and validation of the teacher-authored word list.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby\local;

/**
 * Turns a raw "word=reading" textarea into a map, and reports what is wrong with it.
 *
 * The list format is one entry per line:
 *
 *     漢字=かんじ          a word and its reading
 *     漢字＝かんじ          the full-width separator is accepted too
 *     漢字=                an empty reading is legal and means "suppress"
 *     # a comment          lines whose first non-space character is #
 *
 * Blank lines and comment lines are skipped. Malformed lines are silently
 * ignored by {@see self::parse()} — never fatal — and reported by
 * {@see self::check()} so a settings form can show them.
 *
 * Two deliberate leniencies, both because Japanese IMEs produce them readily:
 *
 * - Line endings may be LF, CRLF or a lone CR; all three are normalised.
 * - The full-width equals sign U+FF1D (＝) is accepted as a separator
 *   alongside the ASCII '='. A Japanese IME in full-width mode emits ＝ for
 *   the same keystroke, and a list typed that way would otherwise parse as
 *   nothing but "noseparator" errors.
 *
 * Only the FIRST separator on a line splits it, so a word may not contain a
 * separator but a reading may. A reading containing one is not an error here;
 * it will simply fail the kana test in {@see self::check()} and be reported as
 * a 'notkana' warning.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wordlist {
    /** @var string The ASCII separator between a word and its reading. */
    private const SEPARATOR = '=';

    /** @var string The full-width separator U+FF1D, accepted as an equivalent of SEPARATOR. */
    private const SEPARATOR_FULLWIDTH = '＝';

    /** @var string The character starting a comment line, when it is the first non-space character. */
    private const COMMENT = '#';

    /** @var string A reading is expected to be kana, prolonged sound marks, middle dots and spaces only. */
    private const KANA_PATTERN = '/^[\p{Hiragana}\p{Katakana}ー・\s]+$/u';

    /**
     * @var int The longest word, in characters, that a list entry may have.
     *
     * The annotator tries every length from the longest entry down to one at each
     * kanji, so the cost per kanji grows with the square of the longest entry. One
     * very long line in a course list would otherwise slow every page in that course.
     * No real word comes near this; the shipped tier data stops at 8 characters.
     */
    public const MAX_WORD_LENGTH = 32;

    /**
     * Parse a raw textarea into a word to reading map.
     *
     * Blank lines and comment lines are skipped. A line with no separator, with
     * an empty word on the left of the separator, or with a word longer than
     * MAX_WORD_LENGTH characters, is malformed and is silently ignored. An empty reading is NOT malformed: it survives as an
     * empty string, because that is the documented way to suppress a word.
     * Where the same word appears more than once the last line wins.
     *
     * @param string $raw The raw textarea contents.
     * @return array Map of word (string) to reading (string, possibly empty).
     */
    public static function parse(string $raw): array {
        $entries = [];

        foreach (self::split_lines($raw) as $line) {
            $trimmed = trim($line);
            if (self::is_ignorable($trimmed)) {
                continue;
            }

            $pair = self::split_entry($trimmed);
            if ($pair === null) {
                // No separator on the line: malformed, ignored.
                continue;
            }

            [$word, $reading] = $pair;
            if ($word === '') {
                // Nothing to the left of the separator: malformed, ignored.
                continue;
            }
            if (mb_strlen($word) > self::MAX_WORD_LENGTH) {
                // Too long to be a word, and costly to match: ignored, and
                // reported by check(). Applies to lists stored before the limit too.
                continue;
            }

            // A later line overwrites an earlier one for the same word.
            $entries[$word] = $reading;
        }

        return $entries;
    }

    /**
     * Report the problems in a raw word list, for display on a settings form.
     *
     * Line numbers are 1-based and count every line of the input, including the
     * blank and comment lines that carry no entry, so they point at what the
     * user sees in the textarea. The reported text is the trimmed line.
     *
     * Problems reported:
     * - 'noseparator': the line has neither '=' nor '＝'. An error.
     * - 'emptyword': there is nothing to the left of the separator. An error.
     * - 'toolong': the word is longer than MAX_WORD_LENGTH characters. An error.
     * - 'notkana': the reading is not kana. A warning only — the form still saves.
     *
     * An empty reading is the legal "suppress" form and is not a problem at all.
     *
     * @param string $raw The raw textarea contents.
     * @return array List of diagnostics, each an array with keys 'line' (int),
     *               'text' (string) and 'problem' (string).
     */
    public static function check(string $raw): array {
        $problems = [];

        foreach (self::split_lines($raw) as $index => $line) {
            $trimmed = trim($line);
            if (self::is_ignorable($trimmed)) {
                continue;
            }

            $linenumber = $index + 1;

            $pair = self::split_entry($trimmed);
            if ($pair === null) {
                $problems[] = ['line' => $linenumber, 'text' => $trimmed, 'problem' => 'noseparator'];
                continue;
            }

            [$word, $reading] = $pair;
            if ($word === '') {
                $problems[] = ['line' => $linenumber, 'text' => $trimmed, 'problem' => 'emptyword'];
                continue;
            }
            if (mb_strlen($word) > self::MAX_WORD_LENGTH) {
                $problems[] = ['line' => $linenumber, 'text' => $trimmed, 'problem' => 'toolong'];
                continue;
            }

            // An empty reading means "suppress" and is deliberately not checked.
            if ($reading !== '' && !preg_match(self::KANA_PATTERN, $reading)) {
                $problems[] = ['line' => $linenumber, 'text' => $trimmed, 'problem' => 'notkana'];
            }
        }

        return $problems;
    }

    /**
     * Split raw text into lines, accepting LF, CRLF and lone CR line endings.
     *
     * @param string $raw The raw textarea contents.
     * @return array Zero-based list of lines, without their terminators.
     */
    private static function split_lines(string $raw): array {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $raw));
    }

    /**
     * Whether an already trimmed line carries no entry and should be skipped.
     *
     * @param string $trimmed A line with leading and trailing whitespace removed.
     * @return bool True for a blank line or a comment line.
     */
    private static function is_ignorable(string $trimmed): bool {
        return $trimmed === '' || str_starts_with($trimmed, self::COMMENT);
    }

    /**
     * Split one entry line on its first separator, whichever separator comes first.
     *
     * Byte offsets are safe here: '=' is ASCII and cannot occur inside a
     * multi-byte UTF-8 sequence, and '＝' is matched as a whole byte sequence.
     *
     * @param string $trimmed A line with leading and trailing whitespace removed.
     * @return array|null The word and the reading, both trimmed, or null when there is no separator.
     */
    private static function split_entry(string $trimmed): ?array {
        $lengths = [];
        foreach ([self::SEPARATOR, self::SEPARATOR_FULLWIDTH] as $separator) {
            $offset = strpos($trimmed, $separator);
            if ($offset !== false) {
                $lengths[$offset] = strlen($separator);
            }
        }

        if (!$lengths) {
            return null;
        }

        $offset = min(array_keys($lengths));

        return [
            trim(substr($trimmed, 0, $offset)),
            trim(substr($trimmed, $offset + $lengths[$offset])),
        ];
    }
}
