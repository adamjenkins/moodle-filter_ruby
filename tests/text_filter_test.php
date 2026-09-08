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
 * Unit tests for the furigana text filter.
 *
 * @package    filter_ruby
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby;

/**
 * Unit tests for {@see \filter_ruby\text_filter}.
 *
 * Nothing here is mocked. Every test builds a real course with the data
 * generator, writes real rows into core's `filter_config` table with
 * `filter_set_local_config()` and real site defaults with `set_config()`, then
 * runs a real filter object over real text. The point of this class is the
 * wiring between the filter and the three `filter_ruby\local` classes, and a
 * mock of any of them would test the mock instead.
 *
 * The individual behaviours of parsing, resolution, compilation and annotation
 * have their own test classes; this one only asserts that the filter reaches
 * them, and that it does nothing at all when it does not have to.
 *
 * Related assertions are grouped into few test methods on purpose: PHPMD's
 * TooManyPublicMethods rule, part of the moodle-plugin-ci gate, caps a class at
 * ten public methods and a data provider counts towards that total.
 *
 * @package    filter_ruby
 * @category   test
 * @covers     \filter_ruby\text_filter
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class text_filter_test extends \advanced_testcase {
    /** @var \core\context\course The context every test filters in. */
    private \core\context\course $coursecontext;

    /**
     * Build the course whose context the tests filter in.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->coursecontext = \core\context\course::instance($course->id);
    }

    /**
     * A word from the course list is annotated, in place, inside the surrounding markup.
     *
     * @return void
     */
    public function test_a_configured_word_is_annotated_in_place(): void {
        filter_set_local_config('ruby', $this->coursecontext->id, 'wordlist', '漢字=かんじ');

        $filtered = $this->make_filter()->filter('<p>これは漢字です。</p>');

        $this->assertSame('<p>これは' . $this->ruby('漢字', 'かんじ') . 'です。</p>', $filtered);
    }

    /**
     * The course word list overrides the site word list, word by word.
     *
     * The site sets two words and the course redefines one of them. The
     * redefined word must come out with the course reading and the untouched
     * word must still come out with the site reading, because the lists merge
     * rather than replace one another.
     *
     * @return void
     */
    public function test_the_course_word_list_beats_the_site_word_list(): void {
        set_config('wordlist', "金=きん\n銀=ぎん", 'filter_ruby');
        filter_set_local_config('ruby', $this->coursecontext->id, 'wordlist', '金=かね');

        $filtered = $this->make_filter()->filter('金と銀');

        $this->assertSame($this->ruby('金', 'かね') . 'と' . $this->ruby('銀', 'ぎん'), $filtered);
    }

    /**
     * With nothing configured anywhere the filter returns kanji text byte for byte.
     *
     * This is the state of a freshly enabled filter on a site that has not set
     * a word list yet, and it is the one case that must cost nothing: no ruby,
     * no rewriting, not even a changed byte.
     *
     * @return void
     */
    public function test_nothing_configured_is_a_no_op_even_on_kanji_text(): void {
        $text = '<p>これは漢字です。<a title="漢字">金</a></p>';

        $this->assertSame($text, $this->make_filter()->filter($text));
    }

    /**
     * Text with no kanji in it is returned byte for byte, configured or not.
     *
     * @return void
     */
    public function test_text_without_kanji_passes_through_unchanged(): void {
        filter_set_local_config('ruby', $this->coursecontext->id, 'wordlist', '漢字=かんじ');
        $filter = $this->make_filter();

        $text = '<p>Plain English, ひらがな and カタカナ &amp; an entity.</p>';
        $this->assertSame($text, $filter->filter($text));
        $this->assertSame('', $filter->filter(''));
    }

    /**
     * Inline markup is honoured even though no word list is configured.
     *
     * The empty-dictionary shortcut in the filter must not swallow this: a
     * teacher can annotate a one-off word inline without setting up any list.
     *
     * @return void
     */
    public function test_inline_markup_is_annotated_with_an_empty_dictionary(): void {
        $filter = $this->make_filter();

        $this->assertSame($this->ruby('漢字', 'かんじ'), $filter->filter('{漢字|かんじ}'));
        $this->assertSame($this->ruby('東京', 'とうきょう'), $filter->filter('｜東京《とうきょう》'));
    }

    /**
     * The 'first' display mode annotates once per page, not once per fragment.
     *
     * `filter_manager` keeps one filter object per context for the whole
     * request, so the second call here is what a second paragraph on the same
     * page looks like. It must come out plain.
     *
     * @return void
     */
    public function test_first_mode_annotates_only_the_first_occurrence_on_the_page(): void {
        filter_set_local_config('ruby', $this->coursecontext->id, 'wordlist', '漢字=かんじ');
        filter_set_local_config('ruby', $this->coursecontext->id, 'displaymode', 'first');
        $filter = $this->make_filter();

        $this->assertSame('<p>' . $this->ruby('漢字', 'かんじ') . '</p>', $filter->filter('<p>漢字</p>'));
        $this->assertSame('<p>漢字</p>', $filter->filter('<p>漢字</p>'));
    }

    /**
     * The filter runs at the post_clean stage and at no earlier one.
     *
     * The design depends on this: the ruby markup is generated after
     * HTMLPurifier has run, so it is never purified and never has to satisfy
     * the purifier's requirement for an `<rb>` element. Core gives a plain
     * `filter()` implementation exactly that placement, so this test asserts the
     * inherited behaviour rather than any override of ours — if a later change
     * overrode a stage method, this is what would catch it.
     *
     * @return void
     */
    public function test_the_filter_runs_at_the_post_clean_stage_only(): void {
        filter_set_local_config('ruby', $this->coursecontext->id, 'wordlist', '漢字=かんじ');
        $filter = $this->make_filter();
        $text = '<p>漢字</p>';

        $this->assertSame('<p>' . $this->ruby('漢字', 'かんじ') . '</p>', $filter->filter_stage_post_clean($text, []));
        $this->assertSame($text, $filter->filter_stage_pre_format($text, []));
        $this->assertSame($text, $filter->filter_stage_pre_clean($text, []));
    }

    /**
     * Only the JavaScript display modes put a module on the page.
     *
     * @param string $displaymode The configured display mode.
     * @param bool $expectsjs Whether that mode should have claimed the module's one-time item.
     * @return void
     * @dataProvider displaymode_provider
     */
    public function test_javascript_is_loaded_only_for_the_modes_that_need_it(
        string $displaymode,
        bool $expectsjs,
    ): void {
        filter_set_local_config('ruby', $this->coursecontext->id, 'displaymode', $displaymode);

        $page = new \moodle_page();
        $page->set_context($this->coursecontext);
        $this->make_filter()->setup_page_for_filters($page, $this->coursecontext);

        $this->assertSame($expectsjs, $page->requires->has_one_time_item_been_created('filter_ruby_toggle'));
    }

    /**
     * Display modes and whether each one needs JavaScript.
     *
     * @return array[] Data sets of [displaymode, expectsjs].
     */
    public static function displaymode_provider(): array {
        return [
            'all' => ['all', false],
            'first' => ['first', false],
            'hover' => ['hover', true],
            'toggle' => ['toggle', true],
        ];
    }

    /**
     * A filter object for the test course context.
     *
     * Built fresh by each test, after that test has written its settings,
     * because the filter resolves and compiles its configuration once and then
     * keeps it for the life of the object.
     *
     * @return text_filter The filter under test.
     */
    private function make_filter(): text_filter {
        return new text_filter($this->coursecontext, []);
    }

    /**
     * The exact markup the annotator emits for one annotated word in the default mode.
     *
     * @param string $base The word as it appears in the text.
     * @param string $reading The furigana.
     * @return string The expected ruby element.
     */
    private function ruby(string $base, string $reading): string {
        return '<ruby class="filter_ruby">' . $base . '<rp>(</rp><rt>' . $reading . '</rt><rp>)</rp></ruby>';
    }
}
