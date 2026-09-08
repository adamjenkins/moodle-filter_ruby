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
 * The compiled word to reading map used by the annotator.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby\local;

/**
 * Two maps compiled from the tier data files and the word lists: the main word
 * to reading map, and the last-resort one-kanji fallback map.
 *
 * The MAIN map is layered lowest priority first:
 *
 * 1. the `words` section of each enabled tier data file, in the fixed order
 *    elementary, jhs, shs, university
 * 2. the word list carried by the resolved configuration, which the config
 *    resolver has already merged site-first so that the nearest context wins
 *
 * Each layer overwrites the previous one for a word they share, which produces
 * the precedence of the design spec section 3 (course beats site beats tier).
 * Inline markup, the highest source, never reaches this class: the annotator
 * resolves it before consulting the dictionary at all.
 *
 * Everything in the main map is authoritative — a human or a curated list said
 * it — so it is matched at every length, a single character included. Nothing
 * about the main map is gated on the lone-kanji setting.
 *
 * The FALLBACK map is the only thing that setting gates. See below.
 *
 * Tier files live in `<dirroot>/filter/ruby/data/tier_<name>.php` and each
 * returns an array of exactly two keys:
 *
 *     return [
 *         'words' => ['今日' => 'きょう', '忘' => 'わす'],
 *         'kanji' => ['金' => 'きん'],
 *     ];
 *
 * The two are not interchangeable, and the difference between them is the whole
 * reason the lone-kanji setting exists:
 *
 * - `words` holds word-level and kanji-run readings. They go into the main map,
 *   which is matched at EVERY length, one character included: a single
 *   character entry there is a deliberate kanji-run reading (忘 to わす, so
 *   that 忘れる and 忘れました both match), not a guess.
 * - `kanji` holds one guessed reading per kanji and goes into a SEPARATE
 *   fallback map, served by {@see self::fallback_lookup()}. Those readings are
 *   frequently wrong inside compounds, so they are consulted only where the
 *   main map matched nothing at all, and only when the teacher has enabled the
 *   lone-kanji setting. Otherwise the fallback map is not even built.
 *
 * The empty string is a value in the main map and is NOT one in the fallback
 * map. In the main map it is the "suppress" instruction — something has to say
 * "this word is handled, emit it with no ruby", because without it the scan
 * would fall through to a shorter match. In the fallback map there is nothing
 * to suppress: emitting the kanji with no ruby is exactly what already happens
 * when the fallback has no entry for it, so an empty guess would be a second
 * spelling of "absent". Rather than leave two spellings of one answer, an empty
 * reading in a `kanji` section is dropped at compile time — see
 * {@see self::guesses()} — and {@see self::fallback_lookup()} answers null for
 * everything it has no usable guess for.
 *
 * The files are generated, not hand written, and none of them may exist yet: a
 * tier whose file is missing is skipped silently, so the plugin works correctly
 * with no data files installed at all. A file that does not have this shape
 * contributes nothing rather than being guessed at, which is the safe direction
 * to fail in: wrong furigana teaches a wrong reading.
 *
 * The compilation is cached in the MUC application cache
 * `filter_ruby/dictionary` under `sha1(serialize($config))`. Because the key is
 * derived from the effective settings, saving new settings produces a new key
 * and there is deliberately no invalidation code anywhere in this class.
 *
 * The key is taken over the WHOLE resolved configuration object, `lonekanji`
 * included. That matters here and not only for tidiness: the flag decides
 * whether the fallback map is built at all, so two configurations differing in
 * nothing else must not share a cached compilation, and do not.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dictionary {
    /**
     * Every tier the plugin knows, in the order the tiers are layered.
     *
     * This list is also the whitelist that keeps a configuration value from
     * ever reaching the filesystem: a tier name that is not in here is ignored
     * rather than turned into a path.
     *
     * @var string[]
     */
    private const TIERS = ['elementary', 'jhs', 'shs', 'university'];

    /** @var string Directory holding the generated tier data files, relative to dirroot. */
    private const DATA_DIRECTORY = '/filter/ruby/data';

    /** @var string Key of the tier file section that feeds the main map: word and kanji-run readings. */
    private const SECTION_WORDS = 'words';

    /** @var string Key of the tier file section that feeds the fallback map: one guess per kanji. */
    private const SECTION_KANJI = 'kanji';

    /** @var string Name of the MUC application cache defined in db/caches.php. */
    private const CACHE_AREA = 'dictionary';

    /** @var string|null Data directory override, set by tests only. Null means use dirroot. */
    private static ?string $datadirectory = null;

    /** @var array Map of word (string) to reading (string, possibly empty). */
    private array $map;

    /** @var int Length in characters of the longest word in the map, or 0 when it is empty. */
    private int $maxlen;

    /**
     * Map of one kanji (string) to its guessed commonest reading (string).
     *
     * Separate from {@see self::$map} on purpose: this is the last-resort layer
     * and nothing but {@see self::fallback_lookup()} may reach it. It is empty
     * whenever the lone-kanji setting is off, because it is not compiled then.
     *
     * Unlike {@see self::$map}, no value in here is ever the empty string: an
     * empty guess means nothing distinct from having no guess, so it is dropped
     * while compiling rather than stored.
     *
     * @var array
     */
    private array $fallback;

    /**
     * Wrap an already compiled map.
     *
     * Private on purpose: every dictionary comes from {@see self::for_context()},
     * which is the only place that knows how to compile and cache one.
     *
     * @param array $map Map of word to reading.
     * @param int $maxlen Length in characters of the longest word in the map.
     * @param array $fallback Map of one kanji to its guessed reading, empty when the fallback is off.
     */
    private function __construct(array $map, int $maxlen, array $fallback) {
        $this->map = $map;
        $this->maxlen = $maxlen;
        $this->fallback = $fallback;
    }

    /**
     * Compile, or fetch from the cache, the dictionary for a resolved configuration.
     *
     * @param \core\context $context The context whose text is being filtered.
     * @param \stdClass $config Resolved settings from {@see \filter_ruby\local\config::for_context()},
     *                          carrying at least 'wordlist' (array), 'tiers' (string[]) and
     *                          'lonekanji' (bool); a missing property is treated as unset.
     * @return self The compiled dictionary.
     */
    public static function for_context(\core\context $context, \stdClass $config): self {
        // The context is part of the published signature so callers can hand over
        // what they have, but everything this class needs is already resolved into
        // $config by config::for_context() — including the site to course word list
        // merge. Discarding it here keeps the cache shared between every context
        // that resolves to the same settings, which is the common case on a site.
        unset($context);

        $cache = \cache::make('filter_ruby', self::CACHE_AREA);
        $key = sha1(serialize($config));

        $compiled = $cache->get($key);
        if (!self::is_compiled($compiled)) {
            $compiled = self::compile($config);
            $cache->set($key, $compiled);
        }

        return new self($compiled['map'], $compiled['maxlen'], $compiled['fallback']);
    }

    /**
     * The reading for a word, or null when the word is not in the dictionary.
     *
     * An empty string is a legal reading and is NOT the same as an absent word:
     * it is the documented "suppress" value, written `漢字=` in a word list or
     * `{漢字|}` inline, and it means "this word is handled, emit it with no ruby".
     * So a caller must distinguish the two — `null` means "keep looking, try a
     * shorter match or the lone-kanji fallback", `''` means "stop, emit plain".
     * Test with `!== null`, never with `empty()` or a truthiness check.
     *
     * @param string $word The exact word to look up. No normalisation is applied.
     * @return string|null The reading, possibly the empty string, or null when the word is absent.
     */
    public function lookup(string $word): ?string {
        if (!array_key_exists($word, $this->map)) {
            return null;
        }

        return $this->map[$word];
    }

    /**
     * The last-resort guessed reading for one kanji, or null when there is none.
     *
     * This is a DIFFERENT question from {@see self::lookup()} and the two must
     * not be conflated:
     *
     * - `lookup()` answers "did a human, or a curated word list, say what this
     *   run of characters reads as?". Its answer is authoritative, is honoured
     *   at every length including one character, and its empty string means
     *   "handled, emit it plain".
     * - `fallback_lookup()` answers "failing all that, what does this single
     *   kanji most commonly read as on its own?". The answer is a guess, taken
     *   from the `kanji` section of the tier data files, and it is frequently
     *   wrong inside a compound.
     *
     * A caller must therefore consult it ONLY at a position where `lookup()`
     * returned null at every length — never after a suppression entry, which is
     * an explicit instruction to stay silent — and only when the teacher has
     * enabled the lone-kanji setting. The second condition is enforced here as
     * well: with the setting off the fallback map is never compiled, so this
     * method returns null for everything.
     *
     * THE RETURN IS EITHER A NON-EMPTY READING OR NULL. It is never the empty
     * string, and that is the one place this method's contract deliberately
     * differs from `lookup()`'s, where the empty string is load bearing:
     *
     * - In the main map, `''` has to exist as a distinct answer, because it is
     *   how a human says "this word is handled, emit it with no ruby" and stops
     *   the scan falling through to a shorter match or to this fallback.
     * - Here, "emit the kanji with no ruby" is already what a null produces,
     *   because a guess is all this layer ever adds. A "suppress" concept has
     *   nothing to suppress, so `''` would only be a second spelling of null,
     *   and a caller reading it as a reading would emit an empty `<rt>`.
     *
     * So there is exactly one answer for "no usable guess", and it is null. It
     * is enforced twice, at both ends of the cache: {@see self::guesses()} drops
     * an empty reading while compiling, so nothing empty is ever stored, and the
     * test below covers a compiled map that came from the cache rather than from
     * this release — the cache key is derived from the settings, not from the
     * plugin version, so an entry written by an older release outlives an upgrade.
     * A caller may test the result with `!== null` alone.
     *
     * @param string $kanji The single character to guess a reading for. No normalisation is applied.
     * @return string|null The guessed reading, never the empty string, or null when there is no guess.
     */
    public function fallback_lookup(string $kanji): ?string {
        $guess = $this->fallback[$kanji] ?? null;
        if (!is_string($guess) || $guess === '') {
            return null;
        }

        return $guess;
    }

    /**
     * Length of the longest word in the dictionary, in characters.
     *
     * Characters, not bytes: the annotator uses this as the starting length for
     * its longest-match scan over multibyte text, so a byte count would make it
     * try substrings three times longer than any entry can be.
     *
     * @return int The character length of the longest word, or 0 when the dictionary is empty.
     */
    public function maxlen(): int {
        return $this->maxlen;
    }

    /**
     * Whether the dictionary holds no entries at all, in either map.
     *
     * The fallback map counts. A caller uses this to decide whether scanning the
     * text can possibly change it, and a dictionary carrying nothing but tier
     * `kanji` entries — an enabled tier plus the lone-kanji setting, with no word
     * list anywhere — still has something to say.
     *
     * Only real guesses count, because only they are stored: a `kanji` section of
     * nothing but empty readings supplies none, and leaves the dictionary empty.
     *
     * @return bool True when there is nothing to look up and nothing to guess.
     */
    public function is_empty(): bool {
        return $this->map === [] && $this->fallback === [];
    }

    /**
     * Point tier loading at a different directory, for tests only.
     *
     * Tier data files normally live under dirroot, which a test may not write
     * to. This lets a test build a directory of its own and hand it over, and
     * pass null afterwards to restore the default.
     *
     * @param string|null $directory Directory holding tier_<name>.php files, or null for the default.
     * @return void
     * @throws \coding_exception When called outside a PHPUnit run.
     */
    public static function set_data_directory_for_testing(?string $directory): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('set_data_directory_for_testing() is only available in unit tests');
        }

        self::$datadirectory = $directory;
    }

    /**
     * Build both maps and the maximum word length from a resolved configuration.
     *
     * The fallback map is built ONLY when the lone-kanji setting is on. That is
     * a real gate and not an optimisation: it is what keeps the guessed readings
     * out of a site that did not ask for them, and it is why the setting has to
     * take part in the cache key.
     *
     * `maxlen` measures the main map alone, because it is the length the
     * annotator's longest-match scan starts at and the fallback is consulted one
     * character at a time.
     *
     * @param \stdClass $config Resolved settings.
     * @return array Three keys: 'map' (word to reading, where an empty reading is the
     *               suppress value), 'maxlen' (int) and 'fallback' (kanji to guessed
     *               reading, empty when the setting is off and never holding an empty reading).
     */
    private static function compile(\stdClass $config): array {
        $tierdata = [];
        foreach (self::enabled_tiers($config) as $tier) {
            // Read each file once: both sections come out of the same include.
            $tierdata[] = self::load_tier($tier);
        }

        $map = [];
        foreach ($tierdata as $data) {
            $map = self::layer($map, self::section($data, self::SECTION_WORDS));
        }

        // The word list goes on last so a teacher's entry beats a tier entry.
        $map = self::layer($map, self::configured_wordlist($config));

        $fallback = [];
        if (!empty($config->lonekanji)) {
            foreach ($tierdata as $data) {
                // The guesses() filter comes first: an empty reading is a value
                // in the main map and is not one here, so it never gets layered.
                $fallback = self::layer($fallback, self::guesses(self::section($data, self::SECTION_KANJI)));
            }
        }

        return ['map' => $map, 'maxlen' => self::longest_word($map), 'fallback' => $fallback];
    }

    /**
     * One section of a tier data file, treating anything unexpected as empty.
     *
     * @param array $data The array a tier data file returned.
     * @param string $key Either SECTION_WORDS or SECTION_KANJI.
     * @return array Map of word to reading, empty when the section is absent or not an array.
     */
    private static function section(array $data, string $key): array {
        $section = $data[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * The usable guesses in one tier file's `kanji` section.
     *
     * An entry whose reading is the empty string is dropped here rather than
     * layered, which is what makes {@see self::fallback_lookup()} total: after
     * this, a kanji either has a real guess or has no entry at all, and there is
     * no third state for a caller to interpret. See that method for why the
     * empty string cannot mean here what it means in the main map.
     *
     * The dropping is per file and happens BEFORE layering, so an empty entry in
     * a later tier cannot delete a good guess an earlier tier supplied. That is
     * the conservative reading of a malformed generated file: it contributes
     * nothing, rather than silently taking something away.
     *
     * Entries that are unusable for any other reason — an empty key, a value
     * that is not a string — are left for {@see self::layer()}, which drops them
     * for both maps alike.
     *
     * @param array $entries The `kanji` section of one tier data file.
     * @return array The same entries with every empty reading removed.
     */
    private static function guesses(array $entries): array {
        $guesses = [];
        foreach ($entries as $kanji => $reading) {
            if ($reading === '') {
                continue;
            }

            $guesses[$kanji] = $reading;
        }

        return $guesses;
    }

    /**
     * The enabled tiers, in the canonical layering order.
     *
     * The result is driven by {@see self::TIERS}, not by the order of the
     * configured list, so the compiled map does not depend on the order in
     * which the tier checkboxes happen to have been saved. Unknown names are
     * dropped rather than turned into a file path.
     *
     * @param \stdClass $config Resolved settings.
     * @return string[] The enabled tier names, lowest priority first.
     */
    private static function enabled_tiers(\stdClass $config): array {
        $enabled = $config->tiers ?? [];
        if (!is_array($enabled)) {
            return [];
        }

        $tiers = [];
        foreach (self::TIERS as $tier) {
            if (in_array($tier, $enabled, true)) {
                $tiers[] = $tier;
            }
        }

        return $tiers;
    }

    /**
     * The word list carried by a resolved configuration.
     *
     * @param \stdClass $config Resolved settings.
     * @return array Map of word to reading, empty when the configuration carries none.
     */
    private static function configured_wordlist(\stdClass $config): array {
        $wordlist = $config->wordlist ?? [];

        return is_array($wordlist) ? $wordlist : [];
    }

    /**
     * Read one tier data file, treating a missing or unusable one as empty.
     *
     * A tier file that does not exist is not an error: the data files are
     * generated separately and a site may legitimately have none of them.
     *
     * The array is returned as it came out of the file, sections and all;
     * {@see self::section()} is what picks the two keys apart, so a file is
     * included once however many sections the compiler wants from it.
     *
     * @param string $tier A tier name that has already passed the {@see self::TIERS} whitelist.
     * @return array The array the file returned, empty when the file is absent or returns something else.
     */
    private static function load_tier(string $tier): array {
        $path = self::data_directory() . '/tier_' . $tier . '.php';
        if (!is_readable($path)) {
            return [];
        }

        $data = include($path);

        return is_array($data) ? $data : [];
    }

    /**
     * The directory tier data files are read from.
     *
     * @return string Absolute path, without a trailing slash.
     */
    private static function data_directory(): string {
        global $CFG;

        if (self::$datadirectory !== null) {
            return self::$datadirectory;
        }

        return $CFG->dirroot . self::DATA_DIRECTORY;
    }

    /**
     * Overlay one layer of entries onto the map being built.
     *
     * Entries in the new layer win. Unusable entries are dropped quietly, which
     * matters because a tier file is generated and a word list comes from a
     * textarea: neither is guaranteed well formed, and a bad entry must never
     * take a page down.
     *
     * An empty reading survives, because in the main map that is the "suppress"
     * value. It is not this method's job to know that the fallback map has no
     * use for one: {@see self::guesses()} has already removed those before the
     * fallback layers get here, so both callers get what they need out of one
     * rule rather than this method growing a flag.
     *
     * @param array $map The map built so far.
     * @param array $entries The layer to overlay.
     * @return array The map with the layer applied.
     */
    private static function layer(array $map, array $entries): array {
        foreach ($entries as $word => $reading) {
            // PHP silently turns a numeric-string array key into an int, so cast
            // back before using it as a word.
            $word = (string)$word;
            if ($word === '' || !is_string($reading)) {
                continue;
            }

            $map[$word] = $reading;
        }

        return $map;
    }

    /**
     * Length of the longest key in a map, counted in characters.
     *
     * @param array $map Map of word to reading.
     * @return int The character length of the longest word, or 0 for an empty map.
     */
    private static function longest_word(array $map): int {
        $maxlen = 0;
        foreach (array_keys($map) as $word) {
            $length = \core_text::strlen((string)$word);
            if ($length > $maxlen) {
                $maxlen = $length;
            }
        }

        return $maxlen;
    }

    /**
     * Whether a value fetched from the cache is a usable compiled map.
     *
     * The cache can hand back false on a miss, and could hold a value written by
     * an older release of the plugin, so the shape is checked before it is trusted.
     *
     * @param mixed $value The value returned by the cache.
     * @return bool True when the value can be used as a compiled map.
     */
    private static function is_compiled($value): bool {
        return is_array($value)
            && array_key_exists('map', $value) && is_array($value['map'])
            && array_key_exists('maxlen', $value) && is_int($value['maxlen'])
            && array_key_exists('fallback', $value) && is_array($value['fallback']);
    }
}
