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
 * Unit tests for the ruby annotator.
 *
 * @package    filter_ruby
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby;

use filter_ruby\local\annotator;
use filter_ruby\local\dictionary;

/**
 * Unit tests for {@see \filter_ruby\local\annotator}.
 *
 * The annotator is the engine of the plugin, so these cases are deliberately
 * exhaustive: each group below pins down one of the properties that a naive
 * implementation gets wrong, and the group's comment says what the wrong
 * implementation would have produced.
 *
 * @package    filter_ruby
 * @category   test
 * @covers     \filter_ruby\local\annotator
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class annotator_test extends \advanced_testcase {
    /** @var array The word list most cases use: one two-character entry. */
    private const KANJI_LIST = ['漢字' => 'かんじ'];

    /** @var string The empty directory tier data files are read from for the duration of one case. */
    private string $datadirectory;

    /**
     * The dictionary compiles through the MUC, so every case needs the reset.
     *
     * The tier data directory is pointed at an empty temporary directory as
     * well, so that a site which happens to have the generated phase 2 data
     * files installed cannot change what these cases see.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->datadirectory = make_request_directory();
        dictionary::set_data_directory_for_testing($this->datadirectory);
    }

    /**
     * Drop the tier data directory override, which is static and would leak.
     *
     * @return void
     */
    #[\Override]
    protected function tearDown(): void {
        dictionary::set_data_directory_for_testing(null);
        parent::tearDown();
    }

    /**
     * Build an annotator over a word list and, when a case wants one, one tier data file.
     *
     * Tier data is written as the elementary tier, in the shape the generator
     * emits: `['words' => …, 'kanji' => …]`. The two sections behave completely
     * differently and several cases below exist only to prove it, so a case says
     * which it means by writing the section it wants.
     *
     * @param array $wordlist Map of word to reading, as the resolved configuration carries it.
     * @param string $displaymode One of 'all', 'first', 'hover', 'toggle'.
     * @param bool $lonekanji Whether a kanji the dictionary does not cover may be guessed at.
     * @param array $tierdata The array a tier data file returns, or [] for no tier data at all.
     * @return annotator The annotator under test.
     */
    private function make_annotator(
        array $wordlist,
        string $displaymode,
        bool $lonekanji,
        array $tierdata = []
    ): annotator {
        $dictionary = $this->make_dictionary($wordlist, $lonekanji, $tierdata, $displaymode);

        return new annotator($dictionary, $displaymode, $lonekanji);
    }

    /**
     * Compile a dictionary on its own, so a case can pair it with any annotator it likes.
     *
     * Separate from {@see self::make_annotator()} for exactly one case: the two
     * objects are normally built from one resolved configuration and therefore
     * always agree about the lone-kanji setting, and proving that the annotator
     * enforces it for itself means building a pair that disagrees.
     *
     * @param array $wordlist Map of word to reading, as the resolved configuration carries it.
     * @param bool $lonekanji Whether the dictionary should compile a fallback map at all.
     * @param array $tierdata The array a tier data file returns, or [] for no tier data at all.
     * @param string $displaymode The display mode to put in the configuration, which only
     *                            takes part here because it is part of the cache key.
     * @return dictionary The compiled dictionary.
     */
    private function make_dictionary(
        array $wordlist,
        bool $lonekanji,
        array $tierdata = [],
        string $displaymode = 'all'
    ): dictionary {
        $tiers = [];
        if ($tierdata !== []) {
            file_put_contents(
                $this->datadirectory . '/tier_elementary.php',
                "<?php\nreturn " . var_export($tierdata, true) . ";\n"
            );
            $tiers = ['elementary'];
        }

        $config = (object) [
            'wordlist' => $wordlist,
            'tiers' => $tiers,
            'displaymode' => $displaymode,
            'lonekanji' => $lonekanji,
        ];

        return dictionary::for_context(\core\context\system::instance(), $config);
    }

    /**
     * The contents of a tier data file, in the shape the generator emits.
     *
     * @param array $words The `words` section: word and kanji-run readings, matched at every length.
     * @param array $kanji The `kanji` section: one guess per kanji, used only as a last resort.
     * @return array The array a tier data file returns.
     */
    private static function tier(array $words, array $kanji = []): array {
        return ['words' => $words, 'kanji' => $kanji];
    }

    /**
     * The exact markup the annotator is contracted to emit.
     *
     * Spelled out here rather than built by the code under test, so that a
     * change to the output shape has to be made deliberately in two places.
     *
     * @param string $base The base text.
     * @param string $reading The reading, as it should appear inside the rt element.
     * @param string $modifier A mode modifier such as 'hover', or the empty string for none.
     * @return string The expected ruby element.
     */
    private static function ruby(string $base, string $reading, string $modifier = ''): string {
        $class = 'filter_ruby';
        if ($modifier !== '') {
            $class .= ' filter_ruby--' . $modifier;
        }

        return '<ruby class="' . $class . '">' . $base
            . '<rp>(</rp><rt>' . $reading . '</rt><rp>)</rp></ruby>';
    }

    /**
     * The ruby element for 漢字, which most cases expect.
     *
     * @return string The expected ruby element.
     */
    private static function kanji_ruby(): string {
        return self::ruby('漢字', 'かんじ');
    }

    // Point 1: the fast bail-out.

    /**
     * Text with no kanji and no inline markup comes back as the very same string.
     *
     * This is the hot path on a site with no Japanese content at all, so it must
     * not be rebuilt piece by piece: assertSame on the input proves the return
     * value is identical, byte for byte, entities and whitespace included.
     *
     * @param string $input Text that should not be touched.
     * @return void
     * @dataProvider untouched_provider
     */
    public function test_text_with_nothing_to_do_is_returned_unchanged(string $input): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, 'all', false);

        $this->assertSame($input, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_text_with_nothing_to_do_is_returned_unchanged()}.
     *
     * @return array Cases of [input].
     */
    public static function untouched_provider(): array {
        return [
            'empty string' => [''],
            'plain english' => ['Hello world'],
            'html with no japanese' => ['<p>Nothing to see here.</p>'],
            'entities are left alone' => ['Fish &amp; chips &lt;still&gt; fine'],
            'hiragana only' => ['ひらがなだけです'],
            'katakana only' => ['カタカナダケデス'],
            'a brace with no pipe' => ['a {b} c'],
            'a pipe with no brace' => ['a | b'],
            'whitespace is preserved exactly' => ["  \n\t line one \r\n line two  "],
        ];
    }

    /**
     * A dictionary with nothing in it annotates nothing, even where kanji are present.
     *
     * @return void
     */
    public function test_an_empty_dictionary_annotates_nothing(): void {
        $annotator = $this->make_annotator([], 'all', false);

        $this->assertSame('漢字のテストです。', $annotator->annotate('漢字のテストです。'));
    }

    // Point 2: tag and text splitting.

    /**
     * Nothing inside a tag is ever rewritten.
     *
     * The regression this pins down is an implementation that runs the scan over
     * the whole string: `<a title="漢字">` would come back with a ruby element
     * inside the attribute value, which is broken HTML.
     *
     * @param string $input The document text.
     * @param string $expected The expected result.
     * @return void
     * @dataProvider tag_provider
     */
    public function test_tags_are_never_rewritten(string $input, string $expected): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, 'all', false);

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_tags_are_never_rewritten()}.
     *
     * @return array Cases of [input, expected].
     */
    public static function tag_provider(): array {
        $ruby = self::kanji_ruby();

        return [
            'attribute holding a dictionary word' => [
                '<a title="漢字">漢字</a>',
                '<a title="漢字">' . $ruby . '</a>',
            ],
            'attribute holding inline markup' => [
                '<a title="{漢字|よみ}">漢字</a>',
                '<a title="{漢字|よみ}">' . $ruby . '</a>',
            ],
            'alt text is an attribute too' => [
                '<img src="x.png" alt="漢字">漢字',
                '<img src="x.png" alt="漢字">' . $ruby,
            ],
            'a word split across a tag boundary does not match' => [
                '漢<b>字</b>',
                '漢<b>字</b>',
            ],
            'an html comment is passed through' => [
                '<!-- 漢字 -->漢字',
                '<!-- 漢字 -->' . $ruby,
            ],
            'text on both sides of a tag is annotated' => [
                '漢字<br>漢字',
                $ruby . '<br>' . $ruby,
            ],
        ];
    }

    // Point 3: skip zones, which nest.

    /**
     * Skip zones nest, survive unbalanced markup and never leak.
     *
     * A boolean "am I skipping" flag passes the simple `<code>漢字</code>` case
     * and then fails on `<code><span>漢字</span></code>`, because the inner
     * `</span>` clears the flag; every case here would be wrong under that
     * implementation or under a naive integer depth.
     *
     * @param string $input The document text.
     * @param string $expected The expected result.
     * @return void
     * @dataProvider skipzone_provider
     */
    public function test_skip_zones(string $input, string $expected): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, 'all', false);

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_skip_zones()}.
     *
     * @return array Cases of [input, expected].
     */
    public static function skipzone_provider(): array {
        $ruby = self::kanji_ruby();

        return [
            'code' => ['<code>漢字</code>', '<code>漢字</code>'],
            'pre' => ['<pre>漢字</pre>', '<pre>漢字</pre>'],
            'script' => ['<script>var x = "漢字";</script>', '<script>var x = "漢字";</script>'],
            'style' => ['<style>/* 漢字 */</style>', '<style>/* 漢字 */</style>'],
            'textarea' => ['<textarea>漢字</textarea>', '<textarea>漢字</textarea>'],
            'the moodle nolink element' => ['<nolink>漢字</nolink>', '<nolink>漢字</nolink>'],
            'ruby the author wrote by hand' => [
                '<ruby>漢字<rp>(</rp><rt>かんじ</rt><rp>)</rp></ruby>',
                '<ruby>漢字<rp>(</rp><rt>かんじ</rt><rp>)</rp></ruby>',
            ],
            'a nested element does not end the zone' => [
                '<code><span>漢字</span></code>',
                '<code><span>漢字</span></code>',
            ],
            'two levels of nesting' => [
                '<pre><code><em>漢字</em></code></pre>',
                '<pre><code><em>漢字</em></code></pre>',
            ],
            'the zone really does end' => [
                '<code>漢字</code>漢字',
                '<code>漢字</code>' . $ruby,
            ],
            'text before the zone is annotated' => [
                '漢字<code>漢字</code>漢字',
                $ruby . '<code>漢字</code>' . $ruby,
            ],
            'a skip element nested in itself' => [
                '<code>a<code>漢字</code>漢字</code>漢字',
                '<code>a<code>漢字</code>漢字</code>' . $ruby,
            ],
            'a self closing tag inside a zone does not close it' => [
                '<code>漢字<br/>漢字</code>漢字',
                '<code>漢字<br/>漢字</code>' . $ruby,
            ],
            'an unclosed zone skips to the end without throwing' => [
                '<code>漢字 and 漢字',
                '<code>漢字 and 漢字',
            ],
            'a stray closing tag does not take the depth negative' => [
                '</code>漢字',
                '</code>' . $ruby,
            ],
            'many stray closing tags still leave text annotated' => [
                '</code></pre></ruby>漢字',
                '</code></pre></ruby>' . $ruby,
            ],
            'a closing tag for an element never opened is ignored' => [
                '漢字</span>漢字',
                $ruby . '</span>' . $ruby,
            ],
            'uppercase tag names are recognised' => [
                '<CODE>漢字</CODE>漢字',
                '<CODE>漢字</CODE>' . $ruby,
            ],
        ];
    }

    /**
     * A skip zone opened by a class survives a nested element of the same name.
     *
     * Given as its own case because it is the reason the implementation cannot
     * simply count tag names: the inner `</span>` must not close the outer
     * `<span class="nolink">`.
     *
     * @return void
     */
    public function test_a_nolink_zone_survives_a_nested_element_of_the_same_name(): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, 'all', false);

        $input = '<span class="nolink">漢字<span>漢字</span>漢字</span>漢字';
        $expected = '<span class="nolink">漢字<span>漢字</span>漢字</span>' . self::kanji_ruby();

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * A void element carrying class="nolink" does not open a zone that never closes.
     *
     * @return void
     */
    public function test_a_void_element_with_the_nolink_class_opens_no_zone(): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, 'all', false);

        $input = '<img class="nolink" alt="x">漢字';
        $expected = '<img class="nolink" alt="x">' . self::kanji_ruby();

        $this->assertSame($expected, $annotator->annotate($input));
    }

    // Point 4: the nolink class is matched as a whole class token.

    /**
     * class="nolink" is matched among other class names, and only as a whole token.
     *
     * The regression is `str_contains($tag, 'nolink')`, which silently disables
     * the filter inside every element whose class happens to contain those six
     * letters — `nolinkage`, `prenolink`, or a `data-class` attribute.
     *
     * @param string $tag The opening tag to wrap the text in.
     * @param bool $skipped Whether the element is expected to be a skip zone.
     * @return void
     * @dataProvider nolink_class_provider
     */
    public function test_the_nolink_class_is_matched_as_a_token(string $tag, bool $skipped): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, 'all', false);

        $inner = $skipped ? '漢字' : self::kanji_ruby();

        $this->assertSame($tag . $inner . '</span>', $annotator->annotate($tag . '漢字</span>'));
    }

    /**
     * Data provider for {@see self::test_the_nolink_class_is_matched_as_a_token()}.
     *
     * @return array Cases of [opening tag, whether it should skip].
     */
    public static function nolink_class_provider(): array {
        return [
            'the class on its own' => ['<span class="nolink">', true],
            'the class among others' => ['<span class="foo nolink bar">', true],
            'the class first' => ['<span class="nolink bar">', true],
            'the class last' => ['<span class="foo nolink">', true],
            'extra whitespace between class names' => ['<span class="foo   nolink   bar">', true],
            'single quoted' => ["<span class='nolink'>", true],
            'unquoted' => ['<span class=nolink>', true],
            'space around the equals sign' => ['<span class = "nolink">', true],
            'another attribute first' => ['<span id="x" class="nolink">', true],
            'another attribute after' => ['<span class="nolink" id="x">', true],
            'nolinkage is a different class' => ['<span class="nolinkage">', false],
            'prenolink is a different class' => ['<span class="prenolink">', false],
            'nolink-ish is a different class' => ['<span class="nolinkish">', false],
            'a data attribute is not the class attribute' => ['<span data-class="nolink">', false],
            'an attribute ending in class is not the class attribute' => ['<span myclass="nolink">', false],
            'no class attribute at all' => ['<span>', false],
            'an empty class attribute' => ['<span class="">', false],
            'an unrelated class' => ['<span class="foo bar">', false],
        ];
    }

    // Point 5: inline markup resolves first, and its output is never rescanned.

    /**
     * Both inline markup forms, their suppression forms and the bare-《》 non-match.
     *
     * The word list behind these cases maps 漢字 to まちがい ("wrong") on purpose:
     * anywhere that reading appears in a result, the dictionary has been allowed
     * to rescan text that inline markup produced.
     *
     * @param string $input The document text.
     * @param string $expected The expected result.
     * @return void
     * @dataProvider inline_provider
     */
    public function test_inline_markup(string $input, string $expected): void {
        $annotator = $this->make_annotator(['漢字' => 'まちがい', '今日' => 'きょう'], 'all', false);

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_inline_markup()}.
     *
     * @return array Cases of [input, expected].
     */
    public static function inline_provider(): array {
        return [
            'brace form' => ['{漢字|ただしい}', self::ruby('漢字', 'ただしい')],
            'brace form beats the dictionary' => [
                'a{漢字|ただしい}b',
                'a' . self::ruby('漢字', 'ただしい') . 'b',
            ],
            'brace form with an empty reading suppresses' => ['{漢字|}', '漢字'],
            'brace form on text with no kanji' => ['x{abc|よみ}y', 'x' . self::ruby('abc', 'よみ') . 'y'],
            'aozora form' => ['｜漢字《ただしい》', self::ruby('漢字', 'ただしい')],
            'aozora form with an empty reading suppresses' => ['｜漢字《》', '漢字'],
            'a bare kakko pair is ordinary quotation, not markup' => [
                'いわゆる《かんじ》について',
                'いわゆる《かんじ》について',
            ],
            'a bare kakko pair around a dictionary word is still scanned normally' => [
                '《漢字》',
                '《' . self::ruby('漢字', 'まちがい') . '》',
            ],
            'the aozora form needs the full width bar, not an ascii one' => [
                '|漢字《ただしい》',
                '|' . self::ruby('漢字', 'まちがい') . '《ただしい》',
            ],
            'inline output is not rescanned' => [
                '{漢字|ただしい}',
                self::ruby('漢字', 'ただしい'),
            ],
            'a reading containing a dictionary word is not rescanned' => [
                '{あ|漢字}',
                self::ruby('あ', '漢字'),
            ],
            'a suppressed base is not rescanned either' => ['{漢字|}漢字', '漢字' . self::ruby('漢字', 'まちがい')],
            'text between two inline matches is scanned' => [
                '{あ|い}漢字{う|え}',
                self::ruby('あ', 'い') . self::ruby('漢字', 'まちがい') . self::ruby('う', 'え'),
            ],
            'the two forms mix in one run of text' => [
                '{あ|い}｜今日《きょう》',
                self::ruby('あ', 'い') . self::ruby('今日', 'きょう'),
            ],
            'an unterminated brace is left alone' => ['{漢字|かんじ', '{' . self::ruby('漢字', 'まちがい') . '|かんじ'],
            'a brace form may not span a pipe' => ['{a|b|c}', '{a|b|c}'],
        ];
    }

    /**
     * A reading that came from the document is not escaped again.
     *
     * Inline markup is part of the already-cleaned document text, so an entity
     * in it must survive as it was written.
     *
     * @return void
     */
    public function test_an_inline_reading_is_not_escaped_again(): void {
        $annotator = $this->make_annotator([], 'all', false);

        $this->assertSame(self::ruby('あ', '&amp;'), $annotator->annotate('{あ|&amp;}'));
    }

    /**
     * Inline markup inside a skip zone is left as the author typed it.
     *
     * @return void
     */
    public function test_inline_markup_inside_a_skip_zone_is_left_alone(): void {
        $annotator = $this->make_annotator([], 'all', false);

        $this->assertSame('<code>{漢字|かんじ}</code>', $annotator->annotate('<code>{漢字|かんじ}</code>'));
    }

    // Point 6: longest match wins, and non-kanji positions cost nothing.

    /**
     * At a kanji the longest entry wins; elsewhere the scan simply moves on.
     *
     * The regression is a scan that tries length 1 first, which turns 今日 into
     * 今 + 日 and teaches the reader two wrong readings.
     *
     * @param array $wordlist The word list.
     * @param bool $lonekanji Whether single kanji may be annotated.
     * @param string $input The document text.
     * @param string $expected The expected result.
     * @return void
     * @dataProvider longest_match_provider
     */
    public function test_longest_match_wins(
        array $wordlist,
        bool $lonekanji,
        string $input,
        string $expected
    ): void {
        $annotator = $this->make_annotator($wordlist, 'all', $lonekanji);

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_longest_match_wins()}.
     *
     * @return array Cases of [word list, lone kanji flag, input, expected].
     */
    public static function longest_match_provider(): array {
        $kyou = ['今日' => 'きょう', '今' => 'いま', '日' => 'ひ'];

        return [
            'the compound beats its parts' => [$kyou, true, '今日', self::ruby('今日', 'きょう')],
            'the parts still match on their own' => [$kyou, true, '今', self::ruby('今', 'いま')],
            'the compound is found inside a sentence' => [
                $kyou,
                true,
                'それは今日のことです',
                'それは' . self::ruby('今日', 'きょう') . 'のことです',
            ],
            'the scan resumes after the whole match' => [
                $kyou,
                true,
                '今日日',
                self::ruby('今日', 'きょう') . self::ruby('日', 'ひ'),
            ],
            'a compound beats a lone kanji entry' => [
                ['困難' => 'こんなん', '難' => 'むずか'],
                true,
                '困難な',
                self::ruby('困難', 'こんなん') . 'な',
            ],
            'the lone kanji entry is used where no compound matches' => [
                ['困難' => 'こんなん', '難' => 'むずか'],
                true,
                '難しい',
                self::ruby('難', 'むずか') . 'しい',
            ],
            'a compound beats a single character entry with the fallback off' => [
                ['困難' => 'こんなん', '難' => 'むずか'],
                false,
                '困難な難しい',
                self::ruby('困難', 'こんなん') . 'な' . self::ruby('難', 'むずか') . 'しい',
            ],
            'the compound beats its parts with the fallback off as well' => [
                $kyou,
                false,
                '今日',
                self::ruby('今日', 'きょう'),
            ],
            'a four character entry beats a two character one' => [
                ['一生懸命' => 'いっしょうけんめい', '一生' => 'いっしょう'],
                false,
                '一生懸命',
                self::ruby('一生懸命', 'いっしょうけんめい'),
            ],
            'a kanji with no entry is left alone' => [
                self::KANJI_LIST,
                false,
                '山川漢字',
                '山川' . self::kanji_ruby(),
            ],
            'a match at the very end of the text' => [
                self::KANJI_LIST,
                false,
                'これは漢字',
                'これは' . self::kanji_ruby(),
            ],
            'an entry longer than the remaining text is skipped safely' => [
                ['漢字学習' => 'かんじがくしゅう', '漢字' => 'かんじ'],
                false,
                '漢字',
                self::kanji_ruby(),
            ],
            'a word list entry that starts with a non kanji never matches' => [
                ['abc' => 'よみ', '漢字' => 'かんじ'],
                false,
                'abc漢字',
                'abc' . self::kanji_ruby(),
            ],
            'every occurrence is annotated in all mode' => [
                self::KANJI_LIST,
                false,
                '漢字と漢字',
                self::kanji_ruby() . 'と' . self::kanji_ruby(),
            ],
        ];
    }

    // Point 7: an empty reading suppresses, and does not fall through.

    /**
     * An empty reading emits the base plain and stops the scan for that span.
     *
     * Falling through to a shorter match is the specific bug this guards: with
     * 今日= the reader must see 今日, not 今 with a reading on it, which is
     * exactly what the teacher suppressed the word to prevent.
     *
     * @param array $wordlist The word list.
     * @param bool $lonekanji Whether single kanji may be annotated.
     * @param string $input The document text.
     * @param string $expected The expected result.
     * @return void
     * @dataProvider suppression_provider
     */
    public function test_an_empty_reading_suppresses(
        array $wordlist,
        bool $lonekanji,
        string $input,
        string $expected
    ): void {
        $annotator = $this->make_annotator($wordlist, 'all', $lonekanji);

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_an_empty_reading_suppresses()}.
     *
     * @return array Cases of [word list, lone kanji flag, input, expected].
     */
    public static function suppression_provider(): array {
        return [
            'no ruby is emitted at all' => [['漢字' => ''], false, '漢字', '漢字'],
            'suppression does not fall through to a shorter match' => [
                ['今日' => '', '今' => 'いま', '日' => 'ひ'],
                true,
                '今日',
                '今日',
            ],
            'suppression does not fall through to the lone kanji fallback' => [
                ['漢字' => '', '漢' => 'かん', '字' => 'じ'],
                true,
                '漢字',
                '漢字',
            ],
            'the scan resumes past the whole suppressed span' => [
                ['今日' => '', '日' => 'ひ'],
                true,
                '今日日',
                '今日' . self::ruby('日', 'ひ'),
            ],
            'a suppressed word does not stop the words around it' => [
                ['今日' => '', '漢字' => 'かんじ'],
                false,
                '今日と漢字',
                '今日と' . self::kanji_ruby(),
            ],
            'a suppressed lone kanji is silent with the fallback on' => [
                ['金' => ''],
                true,
                'お金',
                'お金',
            ],
        ];
    }

    // Point 8: an explicit entry is never gated, and the guess always is.

    /**
     * A one character entry a human asked for is annotated whatever the setting says.
     *
     * This is the regression that shipped, and it is the reason this group
     * exists. The setting was implemented as a minimum match length of two
     * characters, which does stop the guesses — and also silently threw away
     * every single-character entry a teacher had typed into a course word list,
     * and every kanji-run entry in a tier file. `金と銀` in a course whose list
     * says `金=かね` and `銀=ぎん` came back with no ruby at all.
     *
     * The rule these cases pin: the main dictionary is matched at ALL lengths,
     * one included, and nothing about that depends on the lone-kanji setting.
     *
     * @param array $wordlist The word list.
     * @param array $tierdata The tier data file contents, or [] for none.
     * @param bool $lonekanji Whether the last-resort guess is enabled.
     * @param string $input The document text.
     * @param string $expected The expected result.
     * @return void
     * @dataProvider explicit_entry_provider
     */
    public function test_an_explicit_single_character_entry_is_never_gated(
        array $wordlist,
        array $tierdata,
        bool $lonekanji,
        string $input,
        string $expected
    ): void {
        $annotator = $this->make_annotator($wordlist, 'all', $lonekanji, $tierdata);

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_an_explicit_single_character_entry_is_never_gated()}.
     *
     * @return array Cases of [word list, tier data, lone kanji flag, input, expected].
     */
    public static function explicit_entry_provider(): array {
        $kane = self::ruby('金', 'かね');
        $gin = self::ruby('銀', 'ぎん');
        $muzuka = self::ruby('難', 'むずか');

        return [
            'the shipped regression: two word list entries, the fallback off' => [
                ['金' => 'かね', '銀' => 'ぎん'], [], false, '金と銀', $kane . 'と' . $gin,
            ],
            'the same entries with the fallback on' => [
                ['金' => 'かね', '銀' => 'ぎん'], [], true, '金と銀', $kane . 'と' . $gin,
            ],
            'one entry in a sentence, the fallback off' => [
                ['金' => 'かね'], [], false, 'お金がない', 'お' . $kane . 'がない',
            ],
            'a dictionary of nothing but single kanji still annotates' => [
                ['金' => 'かね', '山' => 'やま'], [], false, 'お金と山',
                'お' . $kane . 'と' . self::ruby('山', 'やま'),
            ],
            // The words section of a tier file is explicit too: a single character
            // entry there is a kanji-run reading, chosen so inflected forms match.
            'a tier kanji-run entry, the fallback off' => [
                [], self::tier(['難' => 'むずか']), false, '難しい', $muzuka . 'しい',
            ],
            'the same run entry matches the inflected form, the fallback off' => [
                [], self::tier(['難' => 'むずか']), false, '難しかった', $muzuka . 'しかった',
            ],
            // The word list still beats the tier, at one character as at any other.
            'a word list entry beats a tier words entry at one character' => [
                ['難' => 'かた'], self::tier(['難' => 'むずか']), false, '難しい',
                self::ruby('難', 'かた') . 'しい',
            ],
            // And the longest match still wins: the gate is gone, not the ordering.
            'a compound still beats a one character entry with the fallback off' => [
                [], self::tier(['困難' => 'こんなん', '難' => 'むずか']), false, '困難な難しい',
                self::ruby('困難', 'こんなん') . 'な' . $muzuka . 'しい',
            ],
            'a suppression entry of one character is honoured with the fallback off' => [
                ['金' => ''], [], false, 'お金', 'お金',
            ],
        ];
    }

    /**
     * The tier `kanji` guess fires only with the setting on, and only where nothing else matched.
     *
     * The guesses are what the setting was always meant to gate: one reading per
     * kanji, frequently wrong inside a compound, so they are consulted only at a
     * position where the main dictionary said nothing at any length. A
     * suppression entry is something said, not nothing.
     *
     * @param array $wordlist The word list.
     * @param array $tierdata The tier data file contents, or [] for none.
     * @param bool $lonekanji Whether the last-resort guess is enabled.
     * @param string $input The document text.
     * @param string $expected The expected result.
     * @return void
     * @dataProvider fallback_provider
     */
    public function test_the_lone_kanji_fallback_is_a_gate(
        array $wordlist,
        array $tierdata,
        bool $lonekanji,
        string $input,
        string $expected
    ): void {
        $annotator = $this->make_annotator($wordlist, 'all', $lonekanji, $tierdata);

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_the_lone_kanji_fallback_is_a_gate()}.
     *
     * @return array Cases of [word list, tier data, lone kanji flag, input, expected].
     */
    public static function fallback_provider(): array {
        // One compound in the words section, and guesses for three kanji.
        $tier = self::tier(['困難' => 'こんなん'], ['金' => 'きん', '困' => 'こま', '難' => 'なん']);
        $konnan = self::ruby('困難', 'こんなん');

        return [
            'off, the guess is not made' => [[], $tier, false, 'お金', 'お金'],
            'on, the guess is made' => [[], $tier, true, 'お金', 'お' . self::ruby('金', 'きん')],
            'on, a compound beats the guesses for the kanji inside it' => [
                [], $tier, true, '困難', $konnan,
            ],
            'on, the guess fires only where the main dictionary missed' => [
                [], $tier, true, '困難な難', $konnan . 'な' . self::ruby('難', 'なん'),
            ],
            'on, a kanji no section covers is still left plain' => [[], $tier, true, '山', '山'],
            'on, a word list entry beats the guess' => [
                ['金' => 'かね'], $tier, true, 'お金', 'お' . self::ruby('金', 'かね'),
            ],
            // Suppression is an instruction to stay silent, not an absence, so the
            // scan must stop at it rather than reaching past it for a guess.
            'on, a suppression entry does not fall through to the guess' => [
                ['金' => ''], $tier, true, 'お金', 'お金',
            ],
            'on, a suppressed compound does not fall through to the guesses for its parts' => [
                ['困難' => ''], $tier, true, '困難', '困難',
            ],
            // Phase 2 has not generated any tier file yet, so this is the state
            // of every site that ticks the box today: nothing to guess with.
            'on, but no tier data exists at all, so there is nothing to guess with' => [
                [], [], true, 'お金', 'お金',
            ],
            'on, with a word list but still no tier data, only the list matches' => [
                ['漢字' => 'かんじ'], [], true, 'お金と漢字', 'お金と' . self::kanji_ruby(),
            ],
            // A tier file is configuration, so its readings are escaped at the sink
            // exactly like a word list reading.
            'on, a guessed reading is escaped like any configuration reading' => [
                [], self::tier([], ['金' => 'か<ん']), true, '金', self::ruby('金', 'か&lt;ん'),
            ],
        ];
    }

    /**
     * The annotator keeps the guess shut for itself, not only because the map is empty.
     *
     * There are two gates on the guess: the dictionary does not compile a
     * fallback map when the setting is off, and the annotator does not ask it
     * when the setting is off. Every other case here builds the pair from one
     * configuration, so the two always agree and either gate alone would make
     * them pass. This case builds a dictionary that HAS a fallback map and hands
     * it to an annotator told the setting is off, which is the only arrangement
     * that can tell the annotator's gate apart from the dictionary's.
     *
     * @return void
     */
    public function test_the_annotator_gates_the_guess_itself(): void {
        $dictionary = $this->make_dictionary([], true, self::tier([], ['金' => 'きん']));

        // The map really is there, so the assertions below are about the gate.
        $this->assertSame('きん', $dictionary->fallback_lookup('金'));

        $off = new annotator($dictionary, 'all', false);
        $on = new annotator($dictionary, 'all', true);

        $this->assertSame('お金', $off->annotate('お金'));
        $this->assertSame('お' . self::ruby('金', 'きん'), $on->annotate('お金'));
    }

    // Point 9: the 'first' display mode and its seen set.

    /**
     * In 'first' mode a word is annotated once, then emitted plain.
     *
     * @return void
     */
    public function test_first_mode_annotates_a_word_once(): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, 'first', false);

        $expected = self::kanji_ruby() . 'と漢字と漢字';

        $this->assertSame($expected, $annotator->annotate('漢字と漢字と漢字'));
    }

    /**
     * The seen set is per word, not one flag for the whole request.
     *
     * @return void
     */
    public function test_first_mode_tracks_each_word_separately(): void {
        $wordlist = ['漢字' => 'かんじ', '今日' => 'きょう'];
        $annotator = $this->make_annotator($wordlist, 'first', false);

        $expected = self::kanji_ruby() . self::ruby('今日', 'きょう') . '漢字今日';

        $this->assertSame($expected, $annotator->annotate('漢字今日漢字今日'));
    }

    /**
     * The seen set spans several annotate() calls, because it is per request.
     *
     * @return void
     */
    public function test_first_mode_remembers_across_calls(): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, 'first', false);

        $this->assertSame(self::kanji_ruby(), $annotator->annotate('漢字'));
        $this->assertSame('漢字', $annotator->annotate('漢字'));
        $this->assertSame('漢字', $annotator->annotate('漢字'));
    }

    /**
     * reset_seen() clears the set, which is what lets these cases run independently.
     *
     * @return void
     */
    public function test_reset_seen_starts_over(): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, 'first', false);

        $this->assertSame(self::kanji_ruby(), $annotator->annotate('漢字'));
        $this->assertSame('漢字', $annotator->annotate('漢字'));

        $annotator->reset_seen();

        $this->assertSame(self::kanji_ruby(), $annotator->annotate('漢字'));
    }

    /**
     * Inline markup obeys 'first' as well: it is a display mode, not a source rule.
     *
     * @return void
     */
    public function test_first_mode_covers_inline_markup(): void {
        $annotator = $this->make_annotator([], 'first', false);

        $expected = self::ruby('漢字', 'かんじ') . '漢字';

        $this->assertSame($expected, $annotator->annotate('{漢字|かんじ}{漢字|かんじ}'));
    }

    /**
     * Every other mode annotates every occurrence, and reset_seen() is harmless there.
     *
     * @param string $mode The display mode.
     * @return void
     * @dataProvider every_occurrence_mode_provider
     */
    public function test_modes_other_than_first_annotate_every_occurrence(string $mode): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, $mode, false);

        $modifier = ($mode === 'hover' || $mode === 'toggle') ? $mode : '';
        $ruby = self::ruby('漢字', 'かんじ', $modifier);

        $this->assertSame($ruby . $ruby, $annotator->annotate('漢字漢字'));

        $annotator->reset_seen();

        $this->assertSame($ruby . $ruby, $annotator->annotate('漢字漢字'));
    }

    /**
     * Data provider for {@see self::test_modes_other_than_first_annotate_every_occurrence()}.
     *
     * @return array Cases of [display mode].
     */
    public static function every_occurrence_mode_provider(): array {
        return [
            'all' => ['all'],
            'hover' => ['hover'],
            'toggle' => ['toggle'],
            'an unrecognised mode behaves as all' => ['nonsense'],
        ];
    }

    /**
     * Only 'hover' and 'toggle' add a modifier class; 'all' and 'first' do not.
     *
     * @param string $mode The display mode.
     * @param string $expectedclass The expected value of the class attribute.
     * @return void
     * @dataProvider mode_class_provider
     */
    public function test_the_mode_class(string $mode, string $expectedclass): void {
        $annotator = $this->make_annotator(self::KANJI_LIST, $mode, false);

        $expected = '<ruby class="' . $expectedclass . '">漢字'
            . '<rp>(</rp><rt>かんじ</rt><rp>)</rp></ruby>';

        $this->assertSame($expected, $annotator->annotate('漢字'));
    }

    /**
     * Data provider for {@see self::test_the_mode_class()}.
     *
     * @return array Cases of [display mode, expected class attribute].
     */
    public static function mode_class_provider(): array {
        return [
            'all' => ['all', 'filter_ruby'],
            'first' => ['first', 'filter_ruby'],
            'hover' => ['hover', 'filter_ruby filter_ruby--hover'],
            'toggle' => ['toggle', 'filter_ruby filter_ruby--toggle'],
            'an unrecognised mode gets the base class only' => ['nonsense', 'filter_ruby'],
        ];
    }

    // Point 10: escaping, in both directions.

    /**
     * A reading that came from configuration is escaped; document text is not.
     *
     * Both halves are real regressions. A word list is raw text a teacher typed
     * into a textarea and has never been through HTMLPurifier, so a `<` in a
     * reading must come out as `&lt;`. The base text, by contrast, is a
     * substring of already-cleaned HTML: escaping it again would turn a
     * perfectly good `&amp;` into `&amp;amp;` on the page.
     *
     * @param array $wordlist The word list.
     * @param string $input The document text.
     * @param string $expected The expected result.
     * @return void
     * @dataProvider escaping_provider
     */
    public function test_escaping(array $wordlist, string $input, string $expected): void {
        $annotator = $this->make_annotator($wordlist, 'all', false);

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_escaping()}.
     *
     * @return array Cases of [word list, input, expected].
     */
    public static function escaping_provider(): array {
        return [
            'a less than sign in a configured reading is escaped' => [
                ['漢字' => 'か<ん'],
                '漢字',
                self::ruby('漢字', 'か&lt;ん'),
            ],
            'a whole tag in a configured reading is escaped' => [
                ['漢字' => '<script>alert(1)</script>'],
                '漢字',
                self::ruby('漢字', '&lt;script&gt;alert(1)&lt;/script&gt;'),
            ],
            'an ampersand in a configured reading is escaped' => [
                ['漢字' => 'a&b'],
                '漢字',
                self::ruby('漢字', 'a&amp;b'),
            ],
            'a double quote in a configured reading is escaped' => [
                ['漢字' => 'a"b'],
                '漢字',
                self::ruby('漢字', 'a&quot;b'),
            ],
            'a single quote in a configured reading is escaped' => [
                ['漢字' => "a'b"],
                '漢字',
                self::ruby('漢字', 'a&#039;b'),
            ],
            'an entity in the document is not double encoded' => [
                self::KANJI_LIST,
                'Fish &amp; chips 漢字',
                'Fish &amp; chips ' . self::kanji_ruby(),
            ],
            'entities either side of the base text survive' => [
                self::KANJI_LIST,
                '&lt;漢字&gt;',
                '&lt;' . self::kanji_ruby() . '&gt;',
            ],
            'an entity inside a suppressed span survives' => [
                ['漢字' => ''],
                '&amp;漢字&amp;',
                '&amp;漢字&amp;',
            ],
        ];
    }

    /**
     * The escaped reading really is inert: the output holds no live script tag.
     *
     * Asserted on the substring rather than the whole string so the point is not
     * lost among the surrounding markup.
     *
     * @return void
     */
    public function test_a_configured_reading_cannot_inject_markup(): void {
        $annotator = $this->make_annotator(['漢字' => '</rt><script>alert(1)</script>'], 'all', false);

        $result = $annotator->annotate('漢字');

        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;script&gt;', $result);
        // Exactly one rt element opened and one closed, so the escaping held.
        $this->assertSame(1, substr_count($result, '<rt>'));
        $this->assertSame(1, substr_count($result, '</rt>'));
    }

    // Ordinary happy paths.

    /**
     * Realistic mixed content behaves the way a teacher would expect.
     *
     * @param string $input The document text.
     * @param string $expected The expected result.
     * @return void
     * @dataProvider happy_path_provider
     */
    public function test_happy_paths(string $input, string $expected): void {
        $wordlist = [
            '漢字' => 'かんじ',
            '今日' => 'きょう',
            '勉強' => 'べんきょう',
        ];
        $annotator = $this->make_annotator($wordlist, 'all', false);

        $this->assertSame($expected, $annotator->annotate($input));
    }

    /**
     * Data provider for {@see self::test_happy_paths()}.
     *
     * @return array Cases of [input, expected].
     */
    public static function happy_path_provider(): array {
        $kanji = self::ruby('漢字', 'かんじ');
        $kyou = self::ruby('今日', 'きょう');
        $benkyou = self::ruby('勉強', 'べんきょう');

        return [
            'one word on its own' => ['漢字', $kanji],
            'a sentence' => [
                '今日は漢字の勉強をします。',
                $kyou . 'は' . $kanji . 'の' . $benkyou . 'をします。',
            ],
            'inside a paragraph' => [
                '<p>今日は漢字です。</p>',
                '<p>' . $kyou . 'は' . $kanji . 'です。</p>',
            ],
            'across several elements' => [
                '<ul><li>今日</li><li>漢字</li></ul>',
                '<ul><li>' . $kyou . '</li><li>' . $kanji . '</li></ul>',
            ],
            'mixed with english' => [
                'Today (今日) means today.',
                'Today (' . $kyou . ') means today.',
            ],
            'adjacent words with nothing between them' => ['今日漢字', $kyou . $kanji],
            'a word in a heading and again in the body' => [
                '<h3>漢字</h3><p>漢字</p>',
                '<h3>' . $kanji . '</h3><p>' . $kanji . '</p>',
            ],
            'kanji with no entry are left plain among ones that have entries' => [
                '山と漢字と川',
                '山と' . $kanji . 'と川',
            ],
        ];
    }

    /**
     * Two annotators over the same dictionary do not share a seen set.
     *
     * @return void
     */
    public function test_annotators_do_not_share_state(): void {
        $one = $this->make_annotator(self::KANJI_LIST, 'first', false);
        $two = $this->make_annotator(self::KANJI_LIST, 'first', false);

        $this->assertSame(self::kanji_ruby(), $one->annotate('漢字'));
        $this->assertSame(self::kanji_ruby(), $two->annotate('漢字'));
        $this->assertSame('漢字', $one->annotate('漢字'));
    }
}
