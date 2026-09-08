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
 * Unit tests for the per-context settings resolver.
 *
 * @package    filter_ruby
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby;

use filter_ruby\local\config;

/**
 * Unit tests for {@see \filter_ruby\local\config}.
 *
 * These run against a real context chain — system, category, course, module — built with the
 * data generator, and against core's real `filter_config` storage through
 * `filter_set_local_config()`, because how those two interact is the whole point of the class
 * under test.
 *
 * Related assertions are grouped into few test methods on purpose: PHPMD's TooManyPublicMethods
 * rule, part of the moodle-plugin-ci gate, caps a class at ten public methods.
 *
 * @package    filter_ruby
 * @category   test
 * @covers     \filter_ruby\local\config
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class config_test extends \advanced_testcase {
    /** @var \core\context\system The system context, the outermost layer of every chain. */
    private \core\context\system $systemcontext;

    /** @var \core\context\coursecat The category holding the test course. */
    private \core\context\coursecat $categorycontext;

    /** @var \core\context\course The test course, inside the test category. */
    private \core\context\course $coursecontext;

    /** @var \core\context\module A page activity inside the test course. */
    private \core\context\module $modulecontext;

    /**
     * Build one system to category to course to module chain for every test to share.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $page = $generator->create_module('page', ['course' => $course->id]);

        $this->systemcontext = \core\context\system::instance();
        $this->categorycontext = \core\context\coursecat::instance($category->id);
        $this->coursecontext = \core\context\course::instance($course->id);
        $this->modulecontext = \core\context\module::instance($page->cmid);
    }

    /**
     * With nothing configured anywhere, the documented defaults come back.
     *
     * The chain assertion first is a guard, not decoration: if the generated contexts were not
     * really nested then every inheritance assertion in this file could pass while meaning nothing.
     *
     * @return void
     */
    public function test_defaults_when_nothing_is_configured(): void {
        $this->assertSame(
            [
                $this->systemcontext->id,
                $this->categorycontext->id,
                $this->coursecontext->id,
                $this->modulecontext->id,
            ],
            array_map('intval', array_reverse($this->modulecontext->get_parent_context_ids(true))),
            'the generated contexts must form a real system to module chain'
        );

        $resolved = config::for_context($this->modulecontext);

        $this->assertSame([], $resolved->wordlist);
        $this->assertSame([], $resolved->tiers);
        $this->assertSame('all', $resolved->displaymode);
        $this->assertFalse($resolved->lonekanji);
    }

    /**
     * A context with nothing of its own gets the site defaults, in the documented object shape.
     *
     * The shape assertion lives here rather than in a test of its own because section 4 of the
     * design spec makes it an API contract that other classes are being written against.
     *
     * @return void
     */
    public function test_site_defaults_reach_a_context_that_sets_nothing(): void {
        set_config('wordlist', "漢字=かんじ\n勉強=べんきょう", 'filter_ruby');
        set_config('displaymode', 'first', 'filter_ruby');
        set_config('lonekanji', '1', 'filter_ruby');
        set_config('tier_elementary', '1', 'filter_ruby');

        $resolved = config::for_context($this->modulecontext);

        $this->assertInstanceOf(\stdClass::class, $resolved);
        $this->assertSame(
            ['wordlist', 'tiers', 'displaymode', 'lonekanji'],
            array_keys(get_object_vars($resolved)),
            'the resolved object must carry exactly the four properties the spec names'
        );
        $this->assertIsArray($resolved->wordlist);
        $this->assertIsArray($resolved->tiers);
        $this->assertIsString($resolved->displaymode);
        $this->assertIsBool($resolved->lonekanji);

        $this->assertSame(['漢字' => 'かんじ', '勉強' => 'べんきょう'], $resolved->wordlist);
        $this->assertSame(['elementary'], $resolved->tiers);
        $this->assertSame('first', $resolved->displaymode);
        $this->assertTrue($resolved->lonekanji);
    }

    /**
     * A course setting beats the site setting, and a value the course did not set inherits it.
     *
     * @return void
     */
    public function test_course_setting_beats_site_setting_and_unset_values_inherit(): void {
        set_config('displaymode', 'all', 'filter_ruby');
        set_config('lonekanji', '1', 'filter_ruby');
        set_config('tier_shs', '1', 'filter_ruby');

        // The course overrides the display mode and nothing else.
        filter_set_local_config('ruby', $this->coursecontext->id, 'displaymode', 'toggle');

        $resolved = config::for_context($this->coursecontext);

        $this->assertSame('toggle', $resolved->displaymode, 'the course value must beat the site value');
        $this->assertTrue($resolved->lonekanji, 'lonekanji was not set on the course and must inherit');
        $this->assertSame(['shs'], $resolved->tiers, 'the tier flags were not set on the course and must inherit');

        // A sibling branch of the tree is untouched by the course row.
        $this->assertSame('all', config::for_context($this->categorycontext)->displaymode);

        // An override may also turn a site flag off, which is not the same as leaving it unset.
        filter_set_local_config('ruby', $this->coursecontext->id, 'lonekanji', '0');
        $this->assertFalse(config::for_context($this->coursecontext)->lonekanji);
        $this->assertTrue(config::for_context($this->categorycontext)->lonekanji);
    }

    /**
     * The nearest context that set a scalar wins, all the way down the chain.
     *
     * @return void
     */
    public function test_the_nearest_context_wins_for_scalars(): void {
        set_config('displaymode', 'all', 'filter_ruby');
        filter_set_local_config('ruby', $this->categorycontext->id, 'displaymode', 'first');

        $this->assertSame('first', config::for_context($this->categorycontext)->displaymode);
        $this->assertSame('first', config::for_context($this->coursecontext)->displaymode);
        $this->assertSame('first', config::for_context($this->modulecontext)->displaymode);

        filter_set_local_config('ruby', $this->coursecontext->id, 'displaymode', 'hover');

        $this->assertSame('first', config::for_context($this->categorycontext)->displaymode);
        $this->assertSame('hover', config::for_context($this->coursecontext)->displaymode);
        $this->assertSame('hover', config::for_context($this->modulecontext)->displaymode);

        filter_set_local_config('ruby', $this->modulecontext->id, 'displaymode', 'toggle');

        $this->assertSame('hover', config::for_context($this->coursecontext)->displaymode);
        $this->assertSame('toggle', config::for_context($this->modulecontext)->displaymode);

        // The system context sits above all of it and keeps the site value.
        $this->assertSame('all', config::for_context($this->systemcontext)->displaymode);
    }

    /**
     * Tier flags are scalars: a nearer context replaces each flag it names and inherits the rest.
     *
     * @return void
     */
    public function test_tier_flags_override_rather_than_merge(): void {
        set_config('tier_elementary', '1', 'filter_ruby');
        set_config('tier_jhs', '1', 'filter_ruby');

        $this->assertSame(['elementary', 'jhs'], config::for_context($this->coursecontext)->tiers);

        // The course turns elementary off and adds university; jhs is not named, so it inherits.
        filter_set_local_config('ruby', $this->coursecontext->id, 'tier_elementary', '0');
        filter_set_local_config('ruby', $this->coursecontext->id, 'tier_university', '1');

        $this->assertSame(['jhs', 'university'], config::for_context($this->coursecontext)->tiers);
        $this->assertSame(['elementary', 'jhs'], config::for_context($this->categorycontext)->tiers);
    }

    /**
     * Word lists merge down the chain: every layer contributes, and a nearer entry wins per word.
     *
     * @return void
     */
    public function test_word_lists_merge_across_the_chain(): void {
        set_config('wordlist', "金=きん\n漢字=かんじ", 'filter_ruby');
        filter_set_local_config('ruby', $this->categorycontext->id, 'wordlist', "銀=ぎん\n漢字=カンジ");
        filter_set_local_config('ruby', $this->coursecontext->id, 'wordlist', "金=かね\n銅=どう");
        filter_set_local_config('ruby', $this->modulecontext->id, 'wordlist', '鉄=てつ');

        $this->assertSame(
            ['金' => 'きん', '漢字' => 'かんじ'],
            config::for_context($this->systemcontext)->wordlist
        );
        $this->assertSame(
            ['金' => 'きん', '漢字' => 'カンジ', '銀' => 'ぎん'],
            config::for_context($this->categorycontext)->wordlist,
            'the category overrides one word and adds another, keeping the rest of the site list'
        );
        $this->assertSame(
            ['金' => 'かね', '漢字' => 'カンジ', '銀' => 'ぎん', '銅' => 'どう'],
            config::for_context($this->coursecontext)->wordlist,
            'the course overrides the site entry for 金 without losing the category entries'
        );
        $this->assertSame(
            ['金' => 'かね', '漢字' => 'カンジ', '銀' => 'ぎん', '銅' => 'どう', '鉄' => 'てつ'],
            config::for_context($this->modulecontext)->wordlist
        );
    }

    /**
     * Two edge cases of stored word lists: suppression survives the merge, and rubbish is not fatal.
     *
     * An empty reading is the documented way to suppress a word, so it must reach the caller as an
     * entry with an empty string, not vanish the way a malformed line does.
     *
     * @return void
     */
    public function test_stored_word_list_edge_cases(): void {
        set_config('wordlist', "金=きん\nno separator here\n=orphan\n漢字=かんじ", 'filter_ruby');
        filter_set_local_config('ruby', $this->coursecontext->id, 'wordlist', "# a comment\n\n金=\n勉強=べんきょう");

        $resolved = config::for_context($this->coursecontext);

        $this->assertSame(
            ['金' => '', '漢字' => 'かんじ', '勉強' => 'べんきょう'],
            $resolved->wordlist,
            'malformed lines drop out, and the course suppression of 金 survives as an empty reading'
        );
    }

    /**
     * An unrecognised display mode falls back to 'all' rather than reaching the annotator.
     *
     * The two blank cases are different in kind: a blank value means "this context set nothing",
     * so it inherits, and here nothing outside set a mode either, which lands on 'all' as well.
     *
     * @param string $stored The value found in storage.
     * @return void
     * @dataProvider bad_displaymode_provider
     */
    public function test_unrecognised_displaymode_falls_back_to_all(string $stored): void {
        filter_set_local_config('ruby', $this->coursecontext->id, 'displaymode', $stored);

        $this->assertSame('all', config::for_context($this->coursecontext)->displaymode);
    }

    /**
     * Data provider for {@see self::test_unrecognised_displaymode_falls_back_to_all()}.
     *
     * @return array Cases of [stored value].
     */
    public static function bad_displaymode_provider(): array {
        return [
            'nonsense' => ['wibble'],
            'wrong case' => ['ALL'],
            'legacy numeric' => ['1'],
            'whitespace only' => ['   '],
            'empty string' => [''],
        ];
    }

    /**
     * Block and user contexts may not hold filter settings, so rows on them are skipped.
     *
     * The course values below must survive unchanged: that proves the nearer row was skipped
     * rather than merely losing a comparison.
     *
     * @return void
     */
    public function test_block_and_user_context_settings_are_skipped(): void {
        $block = $this->getDataGenerator()->create_block(
            'online_users',
            ['parentcontextid' => $this->coursecontext->id]
        );
        $blockcontext = \core\context\block::instance($block->id);
        $usercontext = \core\context\user::instance($this->getDataGenerator()->create_user()->id);

        set_config('displaymode', 'hover', 'filter_ruby');
        filter_set_local_config('ruby', $this->coursecontext->id, 'displaymode', 'first');
        filter_set_local_config('ruby', $this->coursecontext->id, 'wordlist', '金=きん');
        filter_set_local_config('ruby', $blockcontext->id, 'displaymode', 'toggle');
        filter_set_local_config('ruby', $blockcontext->id, 'wordlist', '銀=ぎん');
        filter_set_local_config('ruby', $usercontext->id, 'displaymode', 'toggle');

        $resolved = config::for_context($blockcontext);

        $this->assertSame('first', $resolved->displaymode, 'the block row must not override the course');
        $this->assertSame(['金' => 'きん'], $resolved->wordlist, 'the block word list must not be merged in');
        $this->assertSame(
            'hover',
            config::for_context($usercontext)->displaymode,
            'the user row must not override the site default'
        );
    }
}
