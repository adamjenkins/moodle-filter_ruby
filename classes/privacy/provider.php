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
 * Privacy provider for the furigana filter.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;

/**
 * Privacy Subsystem implementation for filter_ruby.
 *
 * The filter stores no data of its own: the word lists live in core's
 * `config_plugins` and `filter_config` tables, which are site and course
 * configuration rather than personal data, and the plugin declares no database
 * table at all (design decision D5, and `db/` here holds only `caches.php`).
 *
 * The single piece of personal data is the reader's own choice of whether
 * furigana are currently shown, kept in the `filter_ruby_show` user preference
 * and written by `amd/src/toggle.js` in the 'toggle' display mode. So this
 * class implements the metadata provider and the user preference provider, and
 * deliberately NOT `\core_privacy\local\request\plugin\provider`: there are no
 * contexts to collect and no rows to export or delete. Core deletes user
 * preferences itself when a user is deleted, so nothing further is required
 * here.
 *
 * Shape taken from the two preference-only providers in this Moodle:
 * `public/lib/editor/classes/privacy/provider.php` (core_editor, the canonical
 * example) and `public/question/bank/managecategories/classes/privacy/provider.php`.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    // The filter has no database tables; the only stored datum is a preference.
    \core_privacy\local\metadata\provider,

    // The filter remembers whether the reader wants furigana shown.
    \core_privacy\local\request\user_preference_provider {
    /**
     * The user preference the reader toggle writes.
     *
     * Must stay in step with `\filter_ruby\text_filter` and with
     * `amd/src/toggle.js`, both of which name the same preference.
     *
     * @var string
     */
    public const PREFERENCE = 'filter_ruby_show';

    /**
     * Returns meta data about this plugin.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_user_preference(self::PREFERENCE, 'privacy:preference:show');

        return $collection;
    }

    /**
     * Export all user preferences for the plugin.
     *
     * The value is exported exactly as stored rather than translated to
     * "shown"/"hidden": the export is a record of what is held about the user,
     * and the accompanying description already explains what the value means.
     *
     * A never-touched toggle has no preference row at all, and `null` is the
     * documented "not set" return of `get_user_preferences()`, so that case
     * exports nothing. Note that `'0'` is a real, deliberate choice by the
     * reader and must still be exported, which is why this tests against null
     * rather than using empty().
     *
     * @param int $userid The id of the user whose data is to be exported.
     * @return void
     */
    public static function export_user_preferences(int $userid) {
        $value = get_user_preferences(self::PREFERENCE, null, $userid);
        if ($value === null) {
            return;
        }

        writer::export_user_preference(
            'filter_ruby',
            self::PREFERENCE,
            $value,
            get_string('privacy:preference:show', 'filter_ruby')
        );
    }
}
