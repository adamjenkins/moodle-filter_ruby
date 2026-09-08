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
 * Site-wide default settings for filter_ruby.
 *
 * These are stored in config_plugins under the plugin name 'filter_ruby'. Each of
 * them can be overridden for an individual context by the per-context form in
 * filterlocalsettings.php.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Site word list. One "word=reading" pair per line; merged with, and overridden
    // by, the word list of any nearer context.
    $settings->add(new admin_setting_configtextarea(
        'filter_ruby/wordlist',
        get_string('wordlist', 'filter_ruby'),
        get_string('wordlist_help', 'filter_ruby'),
        '',
        PARAM_RAW,
        '60',
        '10'
    ));

    // Tier dictionaries. Independent switches, all off by default. The data ships: the
    // four generated files in data/ carry roughly 9,700 word readings and 3,200
    // kanji-run readings between them, so switching one on takes effect immediately.
    // Off by default all the same, because whether a course wants a level annotated is
    // a teaching decision, not something a site install should assume (decision D3).
    $settings->add(new admin_setting_heading(
        'filter_ruby/tiers',
        get_string('tiers', 'filter_ruby'),
        get_string('tiers_help', 'filter_ruby')
    ));

    $tiers = ['elementary', 'jhs', 'shs', 'university'];
    foreach ($tiers as $tier) {
        $settings->add(new admin_setting_configcheckbox(
            'filter_ruby/tier_' . $tier,
            get_string('tier_' . $tier, 'filter_ruby'),
            get_string('tier_' . $tier . '_help', 'filter_ruby'),
            0
        ));
    }

    // The EDRDG licence (CC BY-SA 4.0) requires this acknowledgement to be displayed
    // wherever the derived data is used, so it is rendered here as well as in the README.
    $settings->add(new admin_setting_heading(
        'filter_ruby/edrdgacknowledgement',
        get_string('license'),
        get_string('edrdgacknowledgement', 'filter_ruby')
    ));

    // How readings are presented to the reader.
    $settings->add(new admin_setting_configselect(
        'filter_ruby/displaymode',
        get_string('displaymode', 'filter_ruby'),
        get_string('displaymode_help', 'filter_ruby'),
        'all',
        [
            'all' => get_string('displaymode_all', 'filter_ruby'),
            'first' => get_string('displaymode_first', 'filter_ruby'),
            'hover' => get_string('displaymode_hover', 'filter_ruby'),
            'toggle' => get_string('displaymode_toggle', 'filter_ruby'),
        ]
    ));

    // Single-kanji fallback. This gates ONE thing: the guessed reading the annotator
    // may reach for where no list matched at that position at any length. It does NOT
    // gate one-character word list or kanji-run entries, which are always honoured
    // (classes/local/annotator.php, longest_match() runs down to length 1). Off by
    // default because a guessed reading is often the wrong one out of context, and
    // silence is safer than teaching a wrong reading.
    $settings->add(new admin_setting_configcheckbox(
        'filter_ruby/lonekanji',
        get_string('lonekanji', 'filter_ruby'),
        get_string('lonekanji_help', 'filter_ruby'),
        0
    ));
}
