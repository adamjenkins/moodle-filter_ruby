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
 * Unit tests for the user preference declaration.
 *
 * @package    filter_ruby
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby;

use core_cache\helper as cachehelper;
use core\user;
use filter_ruby\privacy\provider;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/filter/ruby/lib.php');

/**
 * Unit tests for {@see filter_ruby_user_preferences()}.
 *
 * The toggle button in amd/src/toggle.js remembers the reader's choice in the
 * `filter_ruby_show` user preference, and a preference that no plugin declares
 * cannot be written by anyone at all: `\core\user::can_edit_preference()` looks
 * the name up, catches the "Invalid preference requested" exception and answers
 * false (public/lib/classes/user.php:1274-1284), and core's REST route turns
 * that false into an access-denied response
 * (public/user/classes/route/api/preferences.php:230-232). Before lib.php
 * existed this plugin's toggle therefore forgot every choice a reader made, with
 * no visible error at all — the read path is not gated the same way, so it was
 * only the save that vanished.
 *
 * These tests fail if lib.php is deleted. Deleting it removes filter_ruby from
 * the list `get_plugins_with_function('user_preferences')` builds — that
 * function only ever looks for `<plugintype>_<plugin>_<callback>` inside a
 * plugin's `lib.php` (public/lib/moodlelib.php:7416-7450) — so the name is
 * unknown to `\core\user::fill_preferences_cache()`,
 * `get_preference_definition()` throws, and every assertion below that expects a
 * definition, or expects `can_edit_preference()` to be true, fails. The
 * require_once above would fail first and just as loudly.
 *
 * @package    filter_ruby
 * @category   test
 * @covers     ::filter_ruby_user_preferences
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preference_test extends \advanced_testcase {
    /**
     * Start every test from a freshly built list of plugin callbacks.
     *
     * `get_plugins_with_function()` caches its answer in the `core/plugin_functions`
     * MUC cache under a key built from the all-versions hash
     * (public/lib/moodlelib.php:7353-7361), and its staleness check only walks
     * the plugins already present in the cached array
     * (moodlelib.php:7367-7404). A plugin that gains a lib.php without changing
     * its version therefore stays missing from a warm cache, which is a real
     * trap on a live site — purging here means these tests measure the code on
     * disk rather than a cache written before it existed.
     *
     * @return void
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();

        cachehelper::purge_by_definition('core', 'plugin_functions');
        user::reset_caches();
    }

    /**
     * The preference is declared, with the shape core expects.
     *
     * @return void
     */
    public function test_the_preference_is_declared(): void {
        $definition = user::get_preference_definition(text_filter::PREFERENCE);

        $this->assertSame(PARAM_BOOL, $definition['type']);
        $this->assertSame(NULL_NOT_ALLOWED, $definition['null']);
        $this->assertSame(0, $definition['default']);
        $this->assertSame([0, 1], $definition['choices']);

        // Core calls this as $callback($user, $preferencename)
        // (public/lib/classes/user.php:1291-1295), so an uncallable value here
        // is a coding_exception on the reader's very first click.
        $this->assertTrue(is_callable($definition['permissioncallback']));
    }

    /**
     * A reader may set their own preference.
     *
     * This is the assertion the whole file exists for: it is false on a plugin
     * without lib.php, and true on one with it.
     *
     * @return void
     */
    public function test_a_user_may_edit_their_own_preference(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertTrue(user::can_edit_preference(text_filter::PREFERENCE, $user));
    }

    /**
     * A reader may not set somebody else's preference.
     *
     * @return void
     */
    public function test_a_user_may_not_edit_another_users_preference(): void {
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $this->assertFalse(user::can_edit_preference(text_filter::PREFERENCE, $other));
    }

    /**
     * Not even an administrator may set somebody else's preference.
     *
     * This is what proves the permission callback is the plugin's own. Without
     * one, core falls back to `default_preference_permission_check()`, which
     * lets anybody holding `moodle/user:editprofile` in the target's context —
     * an administrator, for one — write the preference for them
     * (public/lib/classes/user.php:1242-1265). Whether a reader's furigana are
     * showing is nobody else's decision, so the callback refuses it while still
     * allowing the administrator their own.
     *
     * @return void
     */
    public function test_an_admin_may_not_edit_another_users_preference(): void {
        global $USER;

        $other = $this->getDataGenerator()->create_user();
        $this->setAdminUser();

        $this->assertFalse(user::can_edit_preference(text_filter::PREFERENCE, $other));
        $this->assertTrue(user::can_edit_preference(text_filter::PREFERENCE, $USER));
    }

    /**
     * The values the toggle sends survive core's cleaning unchanged.
     *
     * Not a formality: the REST route cleans the submitted value and rejects the
     * save outright when the cleaned result differs from what was sent
     * (public/user/classes/route/api/preferences.php:240-242, comparing with
     * !==). PARAM_BOOL cleans to integer 1 or 0
     * (public/lib/classes/param.php:817-828) and the route casts a numeric
     * submission to int before cleaning (preferences.php:273-285), so both sides
     * are integers and the comparison holds. A `'choices' => ['1', '0']` typo in
     * lib.php would break that and is exactly what this test would catch.
     *
     * @param int $value The value as the route will have cast it.
     * @return void
     * @dataProvider clean_provider
     */
    public function test_the_toggle_values_survive_cleaning(int $value): void {
        $this->assertSame($value, user::clean_preference($value, text_filter::PREFERENCE));
    }

    /**
     * Values for {@see test_the_toggle_values_survive_cleaning()}.
     *
     * @return array[] Data sets of [value].
     */
    public static function clean_provider(): array {
        return [
            'shown' => [1],
            'hidden' => [0],
        ];
    }

    /**
     * Every copy of the preference name still says the same thing.
     *
     * The name is spelled once in PHP, in {@see \filter_ruby\text_filter::PREFERENCE},
     * which lib.php declares the preference under. The privacy provider and the
     * AMD module cannot share that constant — one predates it by design, the
     * other is JavaScript — so they repeat the literal, and a rename that
     * touched only some of them would leave the reader's choice being saved
     * under one name, read under another and exported under a third, with no
     * error anywhere. This is the test that would notice.
     *
     * @return void
     */
    public function test_the_preference_name_agrees_everywhere(): void {
        global $CFG;

        $this->assertSame('filter_ruby_show', text_filter::PREFERENCE);
        $this->assertSame(text_filter::PREFERENCE, provider::PREFERENCE);

        $module = file_get_contents($CFG->dirroot . '/filter/ruby/amd/src/toggle.js');
        $this->assertNotFalse($module, 'The toggle module must be readable.');
        $this->assertStringContainsString(
            "const PREFERENCE = '" . text_filter::PREFERENCE . "';",
            $module,
            'amd/src/toggle.js must write the same preference name that lib.php declares.'
        );
    }
}
