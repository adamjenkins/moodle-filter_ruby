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
 * Library callbacks for the furigana filter.
 *
 * This file exists for exactly one reason: a preference that no plugin declares
 * cannot be written by anybody. `\core\user::can_edit_preference()` looks the
 * name up with `get_preference_definition()`, which throws for an unknown name,
 * and the throw is caught and turned into `false`
 * (public/lib/classes/user.php:1274-1284). The REST route the toggle uses then
 * refuses the write outright
 * (public/user/classes/route/api/preferences.php:230-232). Without the callback
 * below, the reader's choice is discarded on every save.
 *
 * The file is included by `get_plugins_with_function()`, which requires the
 * function to be named `<plugintype>_<plugin>_<callback>` — here
 * `filter_ruby_user_preferences` — and to live in `lib.php`
 * (public/lib/moodlelib.php:7429-7448). Nothing here runs when the file is
 * included — it declares one function and stops — so it needs no
 * MOODLE_INTERNAL guard, and phpcs flags one as unnecessary here.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Declare the user preference the furigana toggle writes.
 *
 * The array shape is the one documented on `\core\user::fill_preferences_cache()`
 * (public/lib/classes/user.php:1040-1054) and used by every core plugin that
 * declares a preference — `block_myoverview_user_preferences()`
 * (public/blocks/myoverview/lib.php:82-156) is the closest model for this one.
 *
 * `permissioncallback` is called as `$callback($user, $preferencename)`
 * (user.php:1291-1295), so `\core\user::is_current_user()`, whose signature is
 * `is_current_user(stdClass $user): bool` (user.php:599), fits it directly and
 * ignores the second argument. That callable — rather than a closure — is what
 * core itself uses for preferences of this kind, both in `core_user`'s own
 * definitions (user.php:1110 and following, `[static::class, 'is_current_user']`)
 * and in `block_myoverview` (`[core_user::class, 'is_current_user']`). The class
 * is addressed here by its modern name `\core\user`, verified present on the
 * oldest supported branch as well: `lib/classes/user.php:17` declares
 * `namespace core;` and line 592 declares `is_current_user()` on
 * MOODLE_405_STABLE (`git show origin/MOODLE_405_STABLE:lib/classes/user.php`).
 *
 * Restricting the preference to its owner is deliberate and is stricter than the
 * default check core would otherwise apply: `default_preference_permission_check()`
 * lets anybody holding `moodle/user:editprofile` in another user's context set
 * that user's preferences (user.php:1242-1265). Nobody has any business deciding
 * for somebody else whether their furigana are showing, so the callback here
 * refuses it — and the REST route refuses cross-user requests a second time in
 * `check_user()` (preferences.php:253-260) regardless.
 *
 * @return array The preference definitions, keyed by preference name.
 */
function filter_ruby_user_preferences(): array {
    return [
        \filter_ruby\text_filter::PREFERENCE => [
            // PARAM_BOOL cleans to integer 1 or 0 (public/lib/classes/param.php:817-828),
            // which matters: the route compares the cleaned value with the submitted one
            // using !== and rejects the save on any difference (preferences.php:240-242).
            // The AMD module therefore sends '1'/'0', which the route casts to int before
            // cleaning (preferences.php:273-285), so both sides of that comparison are ints.
            'type' => PARAM_BOOL,
            'null' => NULL_NOT_ALLOWED,
            // Hidden until the reader asks: the 'toggle' display mode renders the page
            // with readings suppressed and amd/src/toggle.js falls back to the same
            // state when no preference has been stored.
            'default' => 0,
            'choices' => [0, 1],
            'permissioncallback' => [\core\user::class, 'is_current_user'],
        ],
    ];
}
