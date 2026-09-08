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
 * Unit tests for the compiled dictionary.
 *
 * @package    filter_ruby
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby;

use filter_ruby\local\dictionary;

/**
 * Unit tests for {@see \filter_ruby\local\dictionary}.
 *
 * Tier data files normally live under dirroot, which these tests may not write
 * to, so every case points the dictionary at a request directory of its own via
 * dictionary::set_data_directory_for_testing() and writes the tier files it wants
 * there. A tier a case does not write is a tier whose file does not exist.
 *
 * A tier file returns two sections and they are not interchangeable, which is
 * what most of these cases are really about:
 *
 *     return ['words' => ['今日' => 'きょう'], 'kanji' => ['金' => 'きん']];
 *
 * `words` feeds the main map that {@see \filter_ruby\local\dictionary::lookup()}
 * serves, at every length; `kanji` feeds the separate fallback map that
 * {@see \filter_ruby\local\dictionary::fallback_lookup()} serves, which is
 * compiled at all only when the lone-kanji setting is on. {@see self::tier()}
 * builds the shape so a case can say which section it means in one line.
 *
 * @package    filter_ruby
 * @category   test
 * @covers     \filter_ruby\local\dictionary
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dictionary_test extends \advanced_testcase {
    /**
     * The dictionary writes to the MUC, so every case needs the reset.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Drop the tier data directory override, which is static and would otherwise leak into the next test.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void {
        dictionary::set_data_directory_for_testing(null);
        parent::tearDown();
    }

    /**
     * Build a resolved configuration object of the shape config::for_context() returns.
     *
     * @param array $overrides Values to set, by property name.
     * @return \stdClass The configuration.
     */
    private static function make_config(array $overrides = []): \stdClass {
        return (object) ($overrides + [
            'wordlist' => [],
            'tiers' => [],
            'displaymode' => 'all',
            'lonekanji' => false,
        ]);
    }

    /**
     * Point tier loading at a fresh empty directory and return its path.
     *
     * @return string Absolute path of the directory tier files may be written into.
     */
    private static function use_temporary_data_directory(): string {
        $directory = make_request_directory();
        dictionary::set_data_directory_for_testing($directory);

        return $directory;
    }

    /**
     * Write one tier data file.
     *
     * @param string $directory The directory to write into.
     * @param string $tier The tier name.
     * @param array|string $entries The array the file should return, written out the way the
     *                              phase 2 generator emits one, or raw file contents to write verbatim.
     * @return void
     */
    private static function write_tier(string $directory, string $tier, $entries): void {
        $contents = is_string($entries) ? $entries : "<?php\nreturn " . var_export($entries, true) . ";\n";
        file_put_contents($directory . '/tier_' . $tier . '.php', $contents);
    }

    /**
     * The contents of a tier data file, in the shape the generator emits.
     *
     * @param array $words The `words` section: word and kanji-run readings, matched at every length.
     * @param array $kanji The `kanji` section: one guessed reading per kanji, used only as a fallback.
     * @return array The array a tier data file returns.
     */
    private static function tier(array $words, array $kanji = []): array {
        return ['words' => $words, 'kanji' => $kanji];
    }

    /**
     * The system context, which every case uses because the context is not what selects the data.
     *
     * @return \core\context The system context.
     */
    private static function context(): \core\context {
        return \core\context\system::instance();
    }

    /**
     * Compiling a configuration produces the expected lookups, length and emptiness.
     *
     * @param array $tierfiles Tier data files to create, as tier name => entries map or raw contents.
     * @param array $overrides Configuration values to set, by property name.
     * @param array $lookups Expected lookups, as word => expected reading, with null meaning absent.
     * @param int $maxlen Expected maxlen(), in characters.
     * @param bool $isempty Expected is_empty().
     * @param array $fallbacks Expected fallback lookups, as kanji => expected guess, with null meaning none.
     * @return void
     * @dataProvider compile_provider
     */
    public function test_compile(
        array $tierfiles,
        array $overrides,
        array $lookups,
        int $maxlen,
        bool $isempty,
        array $fallbacks = []
    ): void {
        $directory = self::use_temporary_data_directory();
        foreach ($tierfiles as $tier => $entries) {
            self::write_tier($directory, (string) $tier, $entries);
        }

        $dictionary = dictionary::for_context(self::context(), self::make_config($overrides));

        foreach ($lookups as $word => $expected) {
            $actual = $dictionary->lookup((string) $word);
            if ($expected === null) {
                $this->assertNull($actual, "Expected '{$word}' to be absent from the dictionary");
            } else {
                $this->assertSame($expected, $actual, "Wrong reading for '{$word}'");
            }
        }

        foreach ($fallbacks as $kanji => $expected) {
            $actual = $dictionary->fallback_lookup((string) $kanji);
            if ($expected === null) {
                $this->assertNull($actual, "Expected no fallback reading for '{$kanji}'");
            } else {
                $this->assertSame($expected, $actual, "Wrong fallback reading for '{$kanji}'");
            }
        }

        $this->assertSame($maxlen, $dictionary->maxlen());
        $this->assertSame($isempty, $dictionary->is_empty());
        $this->assertDebuggingNotCalled();
    }

    /**
     * Data provider for {@see self::test_compile()}.
     *
     * @return array Cases of [tier files, config overrides, expected lookups, expected maxlen,
     *               expected is_empty, expected fallback lookups].
     */
    public static function compile_provider(): array {
        $alltiers = ['elementary', 'jhs', 'shs', 'university'];

        return [
            'nothing configured at all' => [[], [], ['漢字' => null], 0, true, ['金' => null]],
            // Phase 2 generates the data files; the plugin must work correctly before that.
            'every tier enabled and not one data file present' => [
                [], ['tiers' => $alltiers, 'lonekanji' => true], ['今日' => null], 0, true, ['金' => null],
            ],
            'a word list still works when the tier files are absent' => [
                [], ['tiers' => ['elementary', 'university'], 'wordlist' => ['今日' => 'きょう']],
                ['今日' => 'きょう'], 2, false,
            ],
            'a present tier loads while the absent ones beside it are skipped' => [
                ['jhs' => self::tier(['困難' => 'こんなん'])], ['tiers' => $alltiers], ['困難' => 'こんなん'], 2, false,
            ],
            'a present but disabled tier is not loaded' => [
                ['shs' => self::tier(['困難' => 'こんなん'])], ['tiers' => ['elementary']], ['困難' => null], 0, true,
            ],
            'the word list beats the tiers, and a tier only word survives' => [
                ['elementary' => self::tier(['金' => 'きん', '山' => 'やま'])],
                ['tiers' => ['elementary'], 'wordlist' => ['金' => 'かね']],
                ['金' => 'かね', '山' => 'やま'], 1, false,
            ],
            'a later tier beats an earlier one' => [
                ['elementary' => self::tier(['今日' => 'こんにち']), 'jhs' => self::tier(['今日' => 'きょう'])],
                ['tiers' => ['elementary', 'jhs']], ['今日' => 'きょう'], 2, false,
            ],
            // The tier named "evil" is a well formed name whose file exists here:
            // only the tier whitelist stops a configuration value becoming a path.
            'an unrecognised tier name never becomes a path' => [
                ['elementary' => self::tier(['山' => 'やま']), 'evil' => self::tier(['崖' => 'がけ'])],
                ['tiers' => ['evil', 'nosuchtier', '../../secret', 'elementary']],
                ['山' => 'やま', '崖' => null], 1, false,
            ],
            // An empty reading is the documented "suppress" value, and a caller
            // has to tell it apart from a word the dictionary has never heard of.
            'an empty reading is a value, not an absence' => [
                [], ['wordlist' => ['漢字' => '']], ['漢字' => '', '今日' => null], 2, false,
            ],
            'an empty reading in the word list suppresses a tier reading' => [
                ['elementary' => self::tier(['金' => 'きん'])],
                ['tiers' => ['elementary'], 'wordlist' => ['金' => '']], ['金' => ''], 1, false,
            ],
            'lookup matches whole entries only' => [
                [], ['wordlist' => ['今日' => 'きょう']],
                ['今日' => 'きょう', '今' => null, '今日は' => null, '' => null], 2, false,
            ],
            // Point of the whole class: a one character entry in the word list or in a
            // tier's `words` section is an ordinary entry. Nothing about it is gated on
            // the lone-kanji setting, which governs the `kanji` section and nothing else.
            'a single character word list entry is an ordinary entry with the fallback off' => [
                [], ['wordlist' => ['金' => 'かね'], 'lonekanji' => false],
                ['金' => 'かね'], 1, false, ['金' => null],
            ],
            'a single character kanji-run entry in a tier is an ordinary entry too' => [
                ['jhs' => self::tier(['難' => 'むずか', '困難' => 'こんなん'])],
                ['tiers' => ['jhs'], 'lonekanji' => false],
                ['難' => 'むずか', '困難' => 'こんなん'], 2, false, ['難' => null],
            ],
            'maxlen counts characters, not the twelve bytes of this word' => [
                [], ['wordlist' => ['一生懸命' => 'いっしょうけんめい']], ['一生懸命' => 'いっしょうけんめい'], 4, false,
            ],
            'maxlen is the longest of several words' => [
                [], ['wordlist' => ['金' => 'きん', '漢字' => 'かんじ', '一生懸命' => 'いっしょうけんめい']], [], 4, false,
            ],
            'maxlen measures an ascii word the same way' => [
                [], ['wordlist' => ['abcde' => 'x', '漢字' => 'かんじ']], [], 5, false,
            ],
            'a suppressed word still counts towards maxlen' => [
                [], ['wordlist' => ['一生懸命' => '']], ['一生懸命' => ''], 4, false,
            ],
            'maxlen covers tier entries too' => [
                ['university' => self::tier(['一生懸命' => 'いっしょうけんめい'])],
                ['tiers' => ['university'], 'wordlist' => ['金' => 'かね']], [], 4, false,
            ],
            // The fallback map, which is the only thing the lone-kanji setting gates.
            'a kanji section is not compiled at all with the setting off' => [
                ['elementary' => self::tier(['山' => 'やま'], ['金' => 'きん'])],
                ['tiers' => ['elementary'], 'lonekanji' => false],
                ['山' => 'やま', '金' => null], 1, false, ['金' => null, '山' => null],
            ],
            'a kanji section becomes the fallback with the setting on' => [
                ['elementary' => self::tier(['山' => 'やま'], ['金' => 'きん'])],
                ['tiers' => ['elementary'], 'lonekanji' => true],
                ['山' => 'やま', '金' => null], 1, false, ['金' => 'きん', '山' => null],
            ],
            // Nothing in the main map, something to guess with: not an empty dictionary.
            'a tier of nothing but kanji guesses is not an empty dictionary' => [
                ['elementary' => self::tier([], ['金' => 'きん'])],
                ['tiers' => ['elementary'], 'lonekanji' => true],
                ['金' => null], 0, false, ['金' => 'きん'],
            ],
            'the same tier of kanji guesses is an empty dictionary with the setting off' => [
                ['elementary' => self::tier([], ['金' => 'きん'])],
                ['tiers' => ['elementary'], 'lonekanji' => false],
                ['金' => null], 0, true, ['金' => null],
            ],
            'a kanji in both sections keeps a separate reading in each' => [
                ['elementary' => self::tier(['金' => 'かね'], ['金' => 'きん'])],
                ['tiers' => ['elementary'], 'lonekanji' => true],
                ['金' => 'かね'], 1, false, ['金' => 'きん'],
            ],
            'a word list never reaches the fallback map' => [
                [], ['wordlist' => ['金' => 'かね'], 'lonekanji' => true],
                ['金' => 'かね'], 1, false, ['金' => null],
            ],
            'the fallback layers across tiers like the main map does' => [
                [
                    'elementary' => self::tier([], ['金' => 'かね']),
                    'jhs' => self::tier([], ['金' => 'きん', '銀' => 'ぎん']),
                ],
                ['tiers' => ['elementary', 'jhs'], 'lonekanji' => true],
                [], 0, false, ['金' => 'きん', '銀' => 'ぎん'],
            ],
            'unusable entries in a kanji section are dropped' => [
                ['elementary' => self::tier([], ['金' => 'きん', '' => 'ignored', '銀' => 42])],
                ['tiers' => ['elementary'], 'lonekanji' => true],
                [], 0, false, ['金' => 'きん', '' => null, '銀' => null],
            ],
            // The empty string is a value in the main map — the suppress instruction —
            // and is NOT one in the fallback map, where "emit it plain" is what null
            // already produces. There is one answer for "no guess", and it is null.
            'an empty guess is not stored and reads back as no guess at all' => [
                ['elementary' => self::tier(['山' => 'やま'], ['金' => '', '銀' => 'ぎん'])],
                ['tiers' => ['elementary'], 'lonekanji' => true],
                ['山' => 'やま', '金' => null], 1, false, ['金' => null, '銀' => 'ぎん'],
            ],
            // Contrast, one line apart from the case above: the very same empty string
            // in the `words` section IS kept, because there it means suppress.
            'the same empty reading in the words section is kept as the suppress value' => [
                ['elementary' => self::tier(['金' => ''], ['金' => ''])],
                ['tiers' => ['elementary'], 'lonekanji' => true],
                ['金' => ''], 1, false, ['金' => null],
            ],
            // Dropped where it is found, before the layering, so a malformed later
            // tier contributes nothing instead of taking a good guess away.
            'an empty guess in a later tier does not delete an earlier tier guess' => [
                [
                    'elementary' => self::tier([], ['金' => 'きん']),
                    'jhs' => self::tier([], ['金' => '', '銀' => 'ぎん']),
                ],
                ['tiers' => ['elementary', 'jhs'], 'lonekanji' => true],
                [], 0, false, ['金' => 'きん', '銀' => 'ぎん'],
            ],
            'a kanji section of nothing but empty guesses leaves an empty dictionary' => [
                ['elementary' => self::tier([], ['金' => '', '銀' => ''])],
                ['tiers' => ['elementary'], 'lonekanji' => true],
                ['金' => null], 0, true, ['金' => null, '銀' => null],
            ],
            'a kanji section that is not an array is ignored' => [
                ['elementary' => ['words' => ['山' => 'やま'], 'kanji' => '金=きん']],
                ['tiers' => ['elementary'], 'lonekanji' => true],
                ['山' => 'やま'], 1, false, ['金' => null],
            ],
            // The shape is a contract with the generator, and a file that does not
            // honour it contributes nothing rather than being guessed at.
            'a tier file with no sections at all contributes nothing' => [
                ['elementary' => ['山' => 'やま', '金' => 'きん']],
                ['tiers' => ['elementary'], 'lonekanji' => true],
                ['山' => null, '金' => null], 0, true, ['金' => null],
            ],
            'a tier file that does not return an array is ignored' => [
                ['elementary' => "<?php\nreturn 'not an array';\n"], ['tiers' => ['elementary']],
                ['山' => null], 0, true,
            ],
            'unusable entries in a tier file are dropped' => [
                [
                    'elementary' => self::tier(
                        ['山' => 'やま', '' => 'ignored', '崖' => ['not', 'a', 'string'], '谷' => 42]
                    ),
                ],
                ['tiers' => ['elementary']],
                ['山' => 'やま', '' => null, '崖' => null, '谷' => null], 1, false,
            ],
            'a tiers value that is not an array is ignored' => [
                ['elementary' => self::tier(['山' => 'やま'])], ['tiers' => 'elementary'], ['山' => null], 0, true,
            ],
            'a word list value that is not an array is ignored' => [
                [], ['wordlist' => '漢字=かんじ'], ['漢字' => null], 0, true,
            ],
        ];
    }

    /**
     * A configuration object missing the properties altogether compiles to an empty dictionary.
     *
     * The resolver always sets them, but the filter runs on every page and a
     * missing property must not turn into a fatal there.
     *
     * @return void
     */
    public function test_a_configuration_missing_its_properties_compiles_empty(): void {
        self::use_temporary_data_directory();

        $dictionary = dictionary::for_context(self::context(), (object) ['displaymode' => 'all']);

        $this->assertTrue($dictionary->is_empty());
        $this->assertSame(0, $dictionary->maxlen());
        $this->assertNull($dictionary->lookup('漢字'));
        // An absent lonekanji property means no fallback map, and an empty
        // fallback map answers null to everything rather than blowing up.
        $this->assertNull($dictionary->fallback_lookup('漢'));
        $this->assertNull($dictionary->fallback_lookup(''));
    }

    /**
     * The byte length of the longest word is not what maxlen() reports.
     *
     * Stated on its own because a byte count would also be an int and would also
     * look like a plausible number in the provider driven cases above.
     *
     * @return void
     */
    public function test_maxlen_is_not_the_byte_length(): void {
        self::use_temporary_data_directory();

        $config = self::make_config(['wordlist' => ['一生懸命' => 'いっしょうけんめい']]);
        $dictionary = dictionary::for_context(self::context(), $config);

        $this->assertSame(12, strlen('一生懸命'));
        $this->assertSame(4, \core_text::strlen('一生懸命'));
        $this->assertSame(4, $dictionary->maxlen());
    }

    /**
     * Tier layering follows the canonical order, not the order the tiers were saved in.
     *
     * @return void
     */
    public function test_tier_layering_order_is_canonical(): void {
        $directory = self::use_temporary_data_directory();
        self::write_tier($directory, 'elementary', self::tier(['今日' => 'こんにち']));
        self::write_tier($directory, 'jhs', self::tier(['今日' => 'きょう']));

        $forwards = dictionary::for_context(
            self::context(),
            self::make_config(['tiers' => ['elementary', 'jhs']])
        );
        $backwards = dictionary::for_context(
            self::context(),
            self::make_config(['tiers' => ['jhs', 'elementary']])
        );

        $this->assertSame('きょう', $forwards->lookup('今日'));
        $this->assertSame('きょう', $backwards->lookup('今日'));
    }

    /**
     * Configurations that compile differently get different cache keys and do not collide.
     *
     * Three settings are checked because each one changes the compilation in a
     * different way: the word list changes a reading, the tier list changes
     * which files are read, and `lonekanji` decides whether the fallback map is
     * built at all. That last one is the subtle member of the three — it changes
     * nothing in the main map, so a key built from only "the parts that look
     * like dictionary data" would hand a lone-kanji site the cached, empty
     * fallback of a site that had the setting off, and the guesses would
     * silently never appear.
     *
     * @return void
     */
    public function test_configurations_that_compile_differently_do_not_share_a_cache_entry(): void {
        $directory = self::use_temporary_data_directory();
        self::write_tier($directory, 'elementary', self::tier(['山' => 'やま'], ['金' => 'きん']));

        $one = self::make_config(['wordlist' => ['金' => 'きん']]);
        $two = self::make_config(['wordlist' => ['金' => 'かね']]);
        $tiersoff = self::make_config();
        $tierson = self::make_config(['tiers' => ['elementary']]);
        $guessoff = self::make_config(['tiers' => ['elementary'], 'lonekanji' => false]);
        $guesson = self::make_config(['tiers' => ['elementary'], 'lonekanji' => true]);

        // Every pair differs in the key, which is what stops them sharing an entry.
        $this->assertNotSame(sha1(serialize($one)), sha1(serialize($two)));
        $this->assertNotSame(sha1(serialize($tiersoff)), sha1(serialize($tierson)));
        $this->assertNotSame(sha1(serialize($guessoff)), sha1(serialize($guesson)));

        // The word list.
        $this->assertSame('きん', dictionary::for_context(self::context(), $one)->lookup('金'));
        $this->assertSame('かね', dictionary::for_context(self::context(), $two)->lookup('金'));

        // The tier list.
        $this->assertNull(dictionary::for_context(self::context(), $tiersoff)->lookup('山'));
        $this->assertSame('やま', dictionary::for_context(self::context(), $tierson)->lookup('山'));

        // The lone-kanji flag, compiled in both orders in this one test so that a
        // shared entry would have to show up as one of the two being wrong.
        $this->assertNull(dictionary::for_context(self::context(), $guessoff)->fallback_lookup('金'));
        $this->assertSame('きん', dictionary::for_context(self::context(), $guesson)->fallback_lookup('金'));
        $this->assertNull(dictionary::for_context(self::context(), $guessoff)->fallback_lookup('金'));

        // Both compiled word list maps are in the cache, under the documented key.
        $cache = \cache::make('filter_ruby', 'dictionary');
        $compiledone = $cache->get(sha1(serialize($one)));
        $compiledtwo = $cache->get(sha1(serialize($two)));
        $this->assertIsArray($compiledone);
        $this->assertIsArray($compiledtwo);
        $this->assertSame(['金' => 'きん'], $compiledone['map']);
        $this->assertSame(['金' => 'かね'], $compiledtwo['map']);
        $this->assertSame([], $compiledone['fallback']);
    }

    /**
     * The compiled map really is served from the cache for an unchanged configuration.
     *
     * Proven by changing the data underneath it: the same configuration keeps the
     * map it compiled first, and only a configuration with a different key sees the
     * new data. That is also why no invalidation code exists — a settings save
     * produces a different key rather than needing the old entry purged.
     *
     * The same property is why the last case here matters: a cached compilation can
     * outlive the release that wrote it, so the empty-guess rule is checked against a
     * map that never went through this release's compiler.
     *
     * @return void
     */
    public function test_the_compiled_map_is_served_from_the_cache(): void {
        $directory = self::use_temporary_data_directory();
        $config = self::make_config(['tiers' => ['elementary']]);

        $this->assertTrue(dictionary::for_context(self::context(), $config)->is_empty());

        // The data file appears only after that first compile.
        self::write_tier($directory, 'elementary', self::tier(['山' => 'やま']));

        $this->assertTrue(dictionary::for_context(self::context(), $config)->is_empty());

        $other = self::make_config(['tiers' => ['elementary'], 'displaymode' => 'first']);
        $this->assertSame('やま', dictionary::for_context(self::context(), $other)->lookup('山'));

        // A map written by an older release, holding the empty guess this one never
        // stores, still reads back as "no guess": null is the only answer that means
        // that, whichever compiler produced the map.
        $stale = self::make_config(['tiers' => ['elementary'], 'displaymode' => 'hover', 'lonekanji' => true]);
        \cache::make('filter_ruby', 'dictionary')->set(
            sha1(serialize($stale)),
            ['map' => [], 'maxlen' => 0, 'fallback' => ['金' => '', '銀' => 'ぎん']]
        );

        $dictionary = dictionary::for_context(self::context(), $stale);
        $this->assertNull($dictionary->fallback_lookup('金'));
        $this->assertSame('ぎん', $dictionary->fallback_lookup('銀'));
    }

    /**
     * Passing null to the testing override restores the real data directory.
     *
     * @return void
     */
    public function test_the_data_directory_override_can_be_cleared(): void {
        // A sentinel no generated tier file could ever contain, so the assertion
        // holds whether or not the phase 2 data files are installed on this site.
        $sentinel = 'zzsentinelzz';

        $directory = self::use_temporary_data_directory();
        self::write_tier($directory, 'elementary', self::tier([$sentinel => 'せんちねる']));
        $config = self::make_config(['tiers' => ['elementary']]);

        $this->assertSame('せんちねる', dictionary::for_context(self::context(), $config)->lookup($sentinel));

        dictionary::set_data_directory_for_testing(null);

        // A different key, so this recompiles rather than reusing the cached map.
        $other = self::make_config(['tiers' => ['elementary'], 'displaymode' => 'first']);

        $this->assertNull(dictionary::for_context(self::context(), $other)->lookup($sentinel));
    }
}
