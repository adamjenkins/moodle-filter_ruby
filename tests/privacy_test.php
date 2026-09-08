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
 * Unit tests for the privacy provider.
 *
 * @package    filter_ruby
 * @category   test
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\user_preference;
use core_privacy\local\request\writer;
use filter_ruby\privacy\provider;

/**
 * Unit tests for {@see \filter_ruby\privacy\provider}.
 *
 * Extends `\core_privacy\tests\provider_testcase`, verified to exist on this
 * Moodle at `public/privacy/classes/tests/provider_testcase.php` (namespace
 * `core_privacy\tests`, abstract, extends `\advanced_testcase`, and resets the
 * privacy writer in tearDown()). The tests below use only that reset behaviour
 * and `advanced_testcase`, so the base class is doing real work here rather
 * than being decorative.
 *
 * @package    filter_ruby
 * @category   test
 * @covers     \filter_ruby\privacy\provider
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class privacy_test extends \core_privacy\tests\provider_testcase {
    /**
     * The metadata declares the one user preference and nothing else.
     *
     * @return void
     */
    public function test_get_metadata(): void {
        $collection = new collection('filter_ruby');
        $metadata = provider::get_metadata($collection);

        // The collection is returned for chaining, per the interface.
        $this->assertSame($collection, $metadata);

        $items = $metadata->get_collection();

        // Exactly one item, and it is a preference rather than a table: the
        // plugin has no database table, so a second item appearing here would
        // mean the provider had drifted from the design.
        $this->assertCount(1, $items);

        $item = reset($items);
        $this->assertInstanceOf(user_preference::class, $item);
        $this->assertSame('filter_ruby_show', $item->get_name());
        $this->assertSame('privacy:preference:show', $item->get_summary());

        // The summary must name a string that actually exists, otherwise the
        // privacy registry renders a "[[...]]" placeholder to the site admin.
        $this->assertTrue(
            get_string_manager()->string_exists($item->get_summary(), 'filter_ruby'),
            'The metadata summary must be a real lang string key in filter_ruby.'
        );
    }

    /**
     * A user who has never touched the toggle exports nothing.
     *
     * @return void
     */
    public function test_export_no_preference(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();

        provider::export_user_preferences($user->id);

        /** @var \core_privacy\tests\request\content_writer $writer */
        $writer = writer::with_context(\context_system::instance());
        $this->assertFalse($writer->has_any_data());
    }

    /**
     * The stored preference is exported, with its description.
     *
     * Both values are covered because '0' is a deliberate reader choice, not an
     * absence, and an empty() test in the provider would silently drop it.
     *
     * @param string $stored The value set for the test user.
     * @return void
     * @dataProvider export_provider
     */
    public function test_export_user_preferences(string $stored): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        set_user_preference('filter_ruby_show', $stored, $user);

        // A second user with the opposite value, so the assertions below prove
        // the export is keyed on the requested user and not just on "some user".
        $other = $this->getDataGenerator()->create_user();
        set_user_preference('filter_ruby_show', $stored === '1' ? '0' : '1', $other);

        // Run as somebody else again, mirroring the real export flow where an
        // admin exports on a user's behalf.
        $this->setAdminUser();

        provider::export_user_preferences($user->id);

        /** @var \core_privacy\tests\request\content_writer $writer */
        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());

        $prefs = $writer->get_user_preferences('filter_ruby');
        $this->assertObjectHasProperty('filter_ruby_show', $prefs);
        $this->assertSame($stored, $prefs->filter_ruby_show->value);
        $this->assertSame(
            get_string('privacy:preference:show', 'filter_ruby'),
            $prefs->filter_ruby_show->description
        );
    }

    /**
     * Values for {@see test_export_user_preferences()}.
     *
     * @return array
     */
    public static function export_provider(): array {
        return [
            'furigana shown' => ['1'],
            'furigana hidden' => ['0'],
        ];
    }
}
