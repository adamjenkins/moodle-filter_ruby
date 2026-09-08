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
 * The engine that rewrites a fragment of HTML, adding ruby annotations.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby\local;

/**
 * Adds furigana ruby markup to a fragment of already-cleaned HTML.
 *
 * One annotator serves one request. It is cheap to build and holds the
 * "already annotated" set that the `first` display mode needs, so a caller that
 * wants that set cleared calls {@see self::reset_seen()}.
 *
 * The algorithm is the one in section 4 of the design spec, in this order:
 *
 * 1. Bail out immediately unless the text holds a kanji or inline markup. This
 *    is the hot path on a site with no Japanese content at all, and it returns
 *    the input string itself, byte for byte.
 * 2. Split into text and tags. Tags are copied through verbatim and are never
 *    examined for words, so an attribute value such as `<a title="漢字">` comes
 *    out exactly as it went in.
 * 3. Track which skip zones are open. Inside one, text is copied verbatim.
 * 4. In each unskipped text run, resolve inline markup FIRST. What inline
 *    markup produces is never handed back to the dictionary scan.
 * 5. Scan the remaining pieces with the dictionary, longest match first,
 *    anchored at kanji. Where the dictionary has nothing at all to say about a
 *    kanji, and only then, the lone-kanji fallback may guess a reading for it.
 * 6. Apply the display mode.
 *
 * Everything that measures or slices Japanese text does so in characters, via
 * `preg_split('//u', …)` and {@see \core_text}; byte offsets are used only where
 * the boundaries are known to fall between characters (tag splitting, and the
 * offsets `preg_match_all()` reports for an inline markup match).
 *
 * Escaping follows the spec exactly, and the two halves of the rule are
 * opposites, which is why they are done at different call sites:
 *
 * - The base text is a substring of HTML that has already been cleaned. It is
 *   emitted untouched. Escaping it again would turn a legitimate `&amp;` in the
 *   document into `&amp;amp;`.
 * - A reading that came from configuration (a word list or a tier file) is raw
 *   text that has never been cleaned, so it passes through {@see s()} at the
 *   sink, where it is written into the `<rt>` element.
 * - A reading that came from inline markup is a substring of the same cleaned
 *   document text as the base, so it is emitted untouched like the base.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class annotator {
    /** @var string Annotate every occurrence of every matched word. */
    public const MODE_ALL = 'all';

    /** @var string Annotate only the first occurrence of each word in the request. */
    public const MODE_FIRST = 'first';

    /** @var string Annotate everything, but let CSS reveal the reading on hover or focus. */
    public const MODE_HOVER = 'hover';

    /** @var string Annotate everything, but let the reader turn readings on and off. */
    public const MODE_TOGGLE = 'toggle';

    /**
     * Elements whose contents are never annotated.
     *
     * `ruby`, `rt` and `rp` are here so the filter does not annotate its own
     * output, or ruby an author wrote by hand. `nolink` is Moodle's own
     * "leave this alone" pseudo element.
     *
     * @var string[]
     */
    private const SKIP_ELEMENTS = [
        'ruby', 'rt', 'rp', 'code', 'pre', 'script', 'style', 'textarea', 'nolink',
    ];

    /**
     * HTML elements that never have an end tag, so they never open a zone.
     *
     * Without this an `<img class="nolink">` would push a zone that nothing
     * would ever close, and the rest of the page would go unannotated.
     *
     * @var string[]
     */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** @var string A class token on an opening tag that turns the element into a skip zone. */
    private const NOLINK_CLASS = 'nolink';

    /**
     * The cheap test that decides whether there is any work to do at all.
     *
     * Straight from the spec: a kanji, or the opening of the brace form of the
     * inline markup.
     *
     * @var string
     */
    private const TRIGGER_PATTERN = '/\p{Han}|\{[^}]*\|/u';

    /**
     * Splits HTML into alternating text and tags, keeping the tags.
     *
     * Deliberately not a UTF-8 pattern: `<` and `>` are ASCII and cannot occur
     * inside a multi-byte UTF-8 sequence, so a byte-wise split cannot cut a
     * character in half, and it does not have to validate the whole string.
     *
     * @var string
     */
    private const TAG_PATTERN = '/(<[^>]*>)/';

    /**
     * Both inline markup forms in one alternation.
     *
     * Groups 1 and 2 are the base and the reading of the brace form
     * `{漢字|かんじ}`; groups 3 and 4 are the base and the reading of the Aozora
     * form `｜漢字《かんじ》`.
     *
     * The full-width `｜` in the second branch is REQUIRED, and that is the
     * whole point of it: `《》` are ordinary Japanese quotation marks, so a bare
     * `漢字《かんじ》` in running text must be left completely alone.
     *
     * Either reading may be empty, which is the documented way to suppress a
     * word: `{漢字|}` and `｜漢字《》`.
     *
     * @var string
     */
    private const INLINE_PATTERN = '/\{([^{}|]+)\|([^{}|]*)\}|｜([^｜《》]+)《([^《》]*)》/u';

    /** @var string Matches a single character that is a kanji. */
    private const HAN_PATTERN = '/\p{Han}/u';

    /** @var string Pulls the leading slash and the element name off a tag. */
    private const TAG_NAME_PATTERN = '#^<(/?)\s*([a-zA-Z][a-zA-Z0-9:._-]*)#';

    /** @var string Pulls the value out of a class attribute, quoted with ", quoted with ', or bare. */
    private const CLASS_ATTRIBUTE_PATTERN = '/\sclass\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i';

    /** @var string Matches a tag that closes itself, so it opens no zone. */
    private const SELF_CLOSING_PATTERN = '#/\s*>$#';

    /** @var dictionary The compiled word to reading map. */
    private dictionary $dict;

    /** @var string One of the MODE_* constants. Anything unrecognised behaves as MODE_ALL. */
    private string $displaymode;

    /**
     * Whether the last-resort dictionary guess for a lone kanji is allowed.
     *
     * This gates ONE thing: {@see dictionary::fallback_lookup()}, the guessed
     * commonest reading of a single kanji that no word and no compound covers.
     * It gates nothing in the main dictionary, and that distinction is the point
     * of the setting rather than an implementation detail of it.
     *
     * The mistake it is easy to make here — and this code made it once — is to
     * turn the setting into a minimum match length of two characters. That also
     * vetoes the single-character entries a human explicitly asked for: a
     * teacher's own `金=かね` in a course word list, and the deliberate kanji-run
     * entries in a tier file's `words` section (忘 to わす, so that 忘れる and
     * 忘れました both match). Those are instructions, not guesses, and the
     * teacher who wrote one has already decided.
     *
     * @var bool
     */
    private bool $lonekanji;

    /** @var string The value of the class attribute on every <ruby> this annotator emits. */
    private string $rubyclass;

    /** @var array Set of base words already annotated this request, used by MODE_FIRST. */
    private array $seen = [];

    /**
     * Build an annotator for one request.
     *
     * @param dictionary $dict The compiled word to reading map.
     * @param string $displaymode One of 'all', 'first', 'hover', 'toggle'. Anything else behaves as 'all'.
     * @param bool $lonekanji Whether a kanji the dictionary does not cover may be given a guessed reading.
     */
    public function __construct(dictionary $dict, string $displaymode, bool $lonekanji) {
        $this->dict = $dict;
        $this->displaymode = $displaymode;
        $this->lonekanji = $lonekanji;
        $this->rubyclass = self::class_for_mode($displaymode);
    }

    /**
     * Annotate a fragment of already-cleaned HTML.
     *
     * @param string $html The text to annotate.
     * @return string The annotated text, or the argument itself when there is nothing to do.
     */
    public function annotate(string $html): string {
        // Step 1. The hot path on a site with no Japanese content: return the
        // input untouched, byte for byte. preg_match() returning false, which
        // happens on malformed UTF-8, lands here too and is the safe answer.
        if (preg_match(self::TRIGGER_PATTERN, $html) !== 1) {
            return $html;
        }

        // Step 2. Odd indices are tags and are copied through verbatim.
        $parts = preg_split(self::TAG_PATTERN, $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        // Step 3. The names of the skip zones currently open, innermost last.
        // The skip depth is the size of this stack; it is a stack rather than a
        // bare counter only so that the closing tag of a `class="nolink"`
        // element can be told from the closing tag of an unrelated element of
        // the same name.
        $open = [];
        $out = '';

        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                self::track($part, $open);
                $out .= $part;
                continue;
            }

            if ($part === '' || $open !== []) {
                $out .= $part;
                continue;
            }

            $out .= $this->annotate_text($part);
        }

        return $out;
    }

    /**
     * Forget which words have been annotated, so 'first' mode starts over.
     *
     * @return void
     */
    public function reset_seen(): void {
        $this->seen = [];
    }

    /**
     * Annotate one run of text that lies outside every tag and every skip zone.
     *
     * Step 4 of the algorithm: inline markup is resolved first and what it
     * produces is emitted directly, so the dictionary never sees it. Only the
     * pieces between the inline matches are scanned.
     *
     * @param string $text A run of text with no tags in it.
     * @return string The annotated run.
     */
    private function annotate_text(string $text): string {
        $matches = [];
        $found = preg_match_all(self::INLINE_PATTERN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (!$found) {
            return $this->scan($text);
        }

        $out = '';
        $cursor = 0;

        foreach ($matches as $match) {
            $start = $match[0][1];
            // Byte offsets, but every one of them falls on a character boundary
            // because the pattern is a UTF-8 one, so substr() is safe here.
            $out .= $this->scan(substr($text, $cursor, $start - $cursor));

            // An unmatched group reports offset -1, which is how the Aozora
            // branch is told from the brace branch. The value alone would not
            // do: an empty reading is legal and also gives an empty string.
            if (isset($match[3]) && $match[3][1] !== -1) {
                $base = $match[3][0];
                $reading = $match[4][0];
            } else {
                $base = $match[1][0];
                $reading = $match[2][0];
            }

            // The reading came out of the document, which is already clean, so
            // it is not escaped again.
            $out .= $this->emit($base, $reading, false);
            $cursor = $start + strlen($match[0][0]);
        }

        return $out . $this->scan(substr($text, $cursor));
    }

    /**
     * Scan a piece of plain text with the dictionary, longest match first.
     *
     * Step 5 of the algorithm. The scan is anchored at kanji: a character that
     * is not a kanji is copied and the scan moves on by exactly one character,
     * with no lookups at all, which is what keeps the cost down on text that is
     * mostly kana or mostly not Japanese.
     *
     * At a kanji there are two layers, in this order and never the other way
     * round:
     *
     * 1. The main dictionary, tried at every length from `maxlen` down to ONE.
     *    The first hit wins, so 今日 is found before 今. Length 1 is not special
     *    and is not gated on anything: an entry of one character is there
     *    because a teacher typed it or because a tier file states a kanji-run
     *    reading, and either way it was asked for.
     * 2. Only where layer 1 found nothing at any length, and only when the
     *    lone-kanji setting is on, {@see self::fallback()} may guess.
     *
     * A hit whose reading is empty is still a hit: it means suppress, so the
     * base text is emitted plain and the scan moves past the whole matched span.
     * It must not fall through to a shorter match nor to the fallback, or 今日=
     * would leave 今 to be annotated by itself — the very thing the suppression
     * was written to prevent.
     *
     * @param string $text A piece of text with no tags and no inline markup in it.
     * @return string The annotated piece.
     */
    private function scan(string $text): string {
        if ($text === '' || $this->dict->is_empty()) {
            return $text;
        }

        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            // Malformed UTF-8. Pass it through rather than mangling it.
            return $text;
        }

        $count = count($chars);
        $out = '';
        $index = 0;

        while ($index < $count) {
            $char = $chars[$index];

            if (preg_match(self::HAN_PATTERN, $char) !== 1) {
                $out .= $char;
                $index++;
                continue;
            }

            $match = $this->longest_match($chars, $index, $count);
            if ($match !== null) {
                [$length, $word, $reading] = $match;
                // The reading came from configuration, so it is escaped.
                $out .= $this->emit($word, $reading, true);
                // Always at least one, so the scan cannot stand still.
                $index += $length;
                continue;
            }

            $guess = $this->fallback($char);
            if ($guess !== null) {
                // A tier file is configuration too, so this is escaped as well.
                $out .= $this->emit($char, $guess, true);
                $index++;
                continue;
            }

            $out .= $char;
            $index++;
        }

        return $out;
    }

    /**
     * The longest main-dictionary entry starting at one position, or null for none.
     *
     * Lengths run from the dictionary's longest entry down to 1 inclusive. The
     * lower bound is deliberately not a variable: making it one is what broke
     * explicit single-character entries, so there is nothing here left to
     * configure.
     *
     * @param array $chars The text, one character per element.
     * @param int $index Index of the kanji the match must start at.
     * @param int $count Number of characters in $chars.
     * @return array|null A triple of match length in characters, matched word and reading, or null.
     */
    private function longest_match(array $chars, int $index, int $count): ?array {
        $limit = min($this->dict->maxlen(), $count - $index);

        for ($length = $limit; $length >= 1; $length--) {
            $word = implode('', array_slice($chars, $index, $length));
            $reading = $this->dict->lookup($word);
            // Not empty(), not a truthiness test: '' is the suppress value and
            // only null means "this word is not in the dictionary".
            if ($reading !== null) {
                return [$length, $word, $reading];
            }
        }

        return null;
    }

    /**
     * The guessed reading for a kanji the dictionary said nothing about.
     *
     * Two conditions have to hold, and the caller has already established the
     * first: the main dictionary produced no match at this position AT ANY
     * LENGTH — a suppression entry counts as a match and stops the scan here —
     * and the teacher has enabled the lone-kanji setting.
     *
     * The setting is tested here as well as in the dictionary, which only
     * compiles the fallback map when it is on. Two gates on one guess is
     * deliberate: this is the layer that can put a wrong reading in front of a
     * learner, and either gate alone would be enough to keep it shut.
     *
     * @param string $char The single kanji the scan is standing on.
     * @return string|null The guessed reading, or null when there is none or the setting is off.
     */
    private function fallback(string $char): ?string {
        if (!$this->lonekanji) {
            return null;
        }

        return $this->dict->fallback_lookup($char);
    }

    /**
     * Produce the output for one base word, ruby or plain.
     *
     * Step 6 of the algorithm lives here as well: under 'first' a word that has
     * already been annotated during this request comes out plain.
     *
     * @param string $base The word as it appears in the document. Already-cleaned HTML; never re-escaped.
     * @param string $reading The reading, or the empty string to suppress annotation of this word.
     * @param bool $escapereading Whether the reading is raw configuration data and must pass through s().
     * @return string Either a ruby element or the base text on its own.
     */
    private function emit(string $base, string $reading, bool $escapereading): string {
        if ($reading === '') {
            return $base;
        }

        if ($this->displaymode === self::MODE_FIRST) {
            if (isset($this->seen[$base])) {
                return $base;
            }
            $this->seen[$base] = true;
        }

        $rt = $escapereading ? s($reading) : $reading;

        return '<ruby class="' . $this->rubyclass . '">' . $base
            . '<rp>(</rp><rt>' . $rt . '</rt><rp>)</rp></ruby>';
    }

    /**
     * The class attribute for the ruby elements of one display mode.
     *
     * Every mode carries the base class. 'hover' and 'toggle' need CSS and
     * JavaScript to find their elements, so they carry a modifier class as well;
     * 'all' and 'first' differ only in what the filter emits, so they do not.
     *
     * @param string $displaymode One of the MODE_* constants.
     * @return string The class attribute value.
     */
    private static function class_for_mode(string $displaymode): string {
        if ($displaymode === self::MODE_HOVER || $displaymode === self::MODE_TOGGLE) {
            return 'filter_ruby filter_ruby--' . $displaymode;
        }

        return 'filter_ruby';
    }

    /**
     * Update the stack of open skip zones from one tag.
     *
     * Three things have to hold, and each has bitten a naive implementation:
     *
     * - Zones nest. `<code><span>漢字</span></code>` stays skipped all the way
     *   through, because `</span>` closes nothing that `<code>` opened.
     * - The stack never goes negative. A stray `</code>` with nothing open, or
     *   a close tag for an element that is not on the stack, is ignored.
     * - An unclosed tag does not throw. `<code>漢字` simply skips to the end of
     *   the fragment, which is the safe direction to fail in.
     *
     * An opening tag whose name is already on the stack is pushed even when it
     * is not itself a skip element, so that its own closing tag pops it instead
     * of prematurely closing the zone above it. That is what keeps
     * `<span class="nolink">漢<span>字</span></span>` skipped to the end.
     *
     * @param string $tag The whole tag, angle brackets included.
     * @param array $open The stack of open zone names, modified in place.
     * @return void
     */
    private static function track(string $tag, array &$open): void {
        $matches = [];
        if (!preg_match(self::TAG_NAME_PATTERN, $tag, $matches)) {
            // A comment, a doctype, a processing instruction or something that
            // only looks like a tag. It opens and closes nothing.
            return;
        }

        $name = \core_text::strtolower($matches[2]);

        if ($matches[1] === '/') {
            $positions = array_keys($open, $name, true);
            if ($positions !== []) {
                // Drop the innermost zone of this name and anything opened
                // inside it, which is how an unclosed inner tag is recovered from.
                array_splice($open, (int)end($positions));
            }

            return;
        }

        if (preg_match(self::SELF_CLOSING_PATTERN, $tag) || in_array($name, self::VOID_ELEMENTS, true)) {
            return;
        }

        if (
            in_array($name, self::SKIP_ELEMENTS, true)
            || in_array($name, $open, true)
            || self::has_nolink_class($tag)
        ) {
            $open[] = $name;
        }
    }

    /**
     * Whether an opening tag carries `nolink` as one of its class names.
     *
     * The test is on whole class tokens, split on whitespace, not on a
     * substring: `class="foo nolink bar"` is a skip zone and `class="nolinkage"`
     * is not.
     *
     * @param string $tag The whole tag, angle brackets included.
     * @return bool True when the element opens a skip zone because of its class.
     */
    private static function has_nolink_class(string $tag): bool {
        $matches = [];
        if (!preg_match(self::CLASS_ATTRIBUTE_PATTERN, $tag, $matches)) {
            return false;
        }

        // Exactly one of the three alternatives took part; the others are empty
        // or absent, and an empty class attribute has no tokens either way.
        $value = '';
        foreach ([1, 2, 3] as $group) {
            if (($matches[$group] ?? '') !== '') {
                $value = $matches[$group];
                break;
            }
        }

        $tokens = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($tokens) && in_array(self::NOLINK_CLASS, $tokens, true);
    }
}
