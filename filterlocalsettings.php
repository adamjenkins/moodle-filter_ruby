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
 * Per-context settings form for filter_ruby.
 *
 * This file is required directly by filter/manage.php, which then instantiates the
 * class by the name '<filter>_filter_local_settings_form'. The class name below is
 * therefore fixed and must stay global (not namespaced).
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Lets a teacher or manager override the site defaults of filter_ruby in one context.
 *
 * Every control has an explicit "inherit" state, because the effective configuration
 * (filter_ruby\local\config::for_context()) implements inheritance by absence: a
 * setting is inherited when no filter_config row exists for it in this context. So a
 * field left on "Site default" must delete its row rather than store the value it
 * happens to be showing, otherwise the choice would be frozen at today's parent value
 * and later changes higher up the context tree would stop reaching this context.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ruby_filter_local_settings_form extends \filter_local_settings_form {
    /** @var string The form value that means "store nothing here, inherit from above". */
    const INHERIT = '';

    /** @var string The form value that switches a boolean setting on in this context. */
    const ON = '1';

    /** @var string The form value that switches a boolean setting off in this context. */
    const OFF = '0';

    /** @var array The tier dictionary names, in school order. */
    const TIERS = ['elementary', 'jhs', 'shs', 'university'];

    /** @var array The display mode values, as documented in the design spec. */
    const DISPLAYMODES = ['all', 'first', 'hover', 'toggle'];

    /** @var array Word list check problems that must block the save. Everything else is a warning. */
    const BLOCKING_PROBLEMS = ['noseparator', 'emptyword'];

    /**
     * Add the form controls: the same seven settings as the site defaults page.
     *
     * @param \MoodleQuickForm $mform the form being built.
     */
    protected function definition_inner($mform) {
        // Values already stored in this context only. Anything absent here is inherited.
        $current = filter_get_local_config($this->filter, $this->context->id);

        $mform->addElement(
            'textarea',
            'wordlist',
            get_string('wordlist', 'filter_ruby'),
            ['rows' => 10, 'cols' => 60]
        );
        $mform->setType('wordlist', PARAM_RAW);
        $mform->addHelpButton('wordlist', 'wordlist', 'filter_ruby');
        $mform->setDefault('wordlist', $current['wordlist'] ?? '');

        foreach (self::TIERS as $tier) {
            $name = 'tier_' . $tier;
            $mform->addElement('select', $name, get_string($name, 'filter_ruby'), $this->onoff_choices());
            $mform->setType($name, PARAM_RAW);
            $mform->addHelpButton($name, $name, 'filter_ruby');
            $mform->setDefault($name, $current[$name] ?? self::INHERIT);
        }

        $mform->addElement(
            'select',
            'displaymode',
            get_string('displaymode', 'filter_ruby'),
            $this->displaymode_choices()
        );
        $mform->setType('displaymode', PARAM_RAW);
        $mform->addHelpButton('displaymode', 'displaymode', 'filter_ruby');
        $mform->setDefault('displaymode', $current['displaymode'] ?? self::INHERIT);

        $mform->addElement('select', 'lonekanji', get_string('lonekanji', 'filter_ruby'), $this->onoff_choices());
        $mform->setType('lonekanji', PARAM_RAW);
        $mform->addHelpButton('lonekanji', 'lonekanji', 'filter_ruby');
        $mform->setDefault('lonekanji', $current['lonekanji'] ?? self::INHERIT);
    }

    /**
     * The three states of a boolean setting in a context: inherit, on, off.
     *
     * @return array value => label, suitable for a select element.
     */
    protected function onoff_choices() {
        return [
            self::INHERIT => get_string('sitedefault'),
            self::ON => get_string('on', 'filters'),
            self::OFF => get_string('off', 'filters'),
        ];
    }

    /**
     * The display modes, plus the inherit option.
     *
     * @return array value => label, suitable for a select element.
     */
    protected function displaymode_choices() {
        $choices = [self::INHERIT => get_string('sitedefault')];
        foreach (self::DISPLAYMODES as $mode) {
            $choices[$mode] = get_string('displaymode_' . $mode, 'filter_ruby');
        }
        return $choices;
    }

    /**
     * Reject a word list that has lines the parser could not make sense of.
     *
     * Only 'noseparator' and 'emptyword' block the save. 'notkana' is a warning: a
     * reading in kanji or romaji is odd but still displayable, so it saves.
     *
     * @param array $data the submitted data.
     * @param array $files the submitted files.
     * @return array field name => error message.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $messages = [];
        foreach (\filter_ruby\local\wordlist::check($data['wordlist'] ?? '') as $problem) {
            if (!in_array($problem['problem'], self::BLOCKING_PROBLEMS, true)) {
                continue;
            }
            // The line text is whatever the user typed, and form errors are printed as
            // HTML, so it is escaped here at the sink.
            $a = (object) ['line' => $problem['line'], 'text' => s($problem['text'])];
            $messages[] = get_string('problem_' . $problem['problem'], 'filter_ruby', $a);
        }

        if ($messages) {
            $errors['wordlist'] = implode(html_writer::empty_tag('br'), $messages);
        }

        return $errors;
    }

    /**
     * Store the settings for this context, deleting rather than storing an inherited value.
     *
     * @param object $data the form data that was submitted.
     */
    public function save_changes($data) {
        $data = (array) $data;

        $values = ['wordlist' => trim($data['wordlist'] ?? '')];
        foreach (self::TIERS as $tier) {
            $values['tier_' . $tier] = $this->clean_choice($data['tier_' . $tier] ?? '', [self::ON, self::OFF]);
        }
        $values['displaymode'] = $this->clean_choice($data['displaymode'] ?? '', self::DISPLAYMODES);
        $values['lonekanji'] = $this->clean_choice($data['lonekanji'] ?? '', [self::ON, self::OFF]);

        foreach ($values as $name => $value) {
            if ($value === self::INHERIT) {
                // No row at all: config::for_context() then inherits this setting.
                filter_unset_local_config($this->filter, $this->context->id, $name);
            } else {
                filter_set_local_config($this->filter, $this->context->id, $name, $value);
            }
        }
    }

    /**
     * Reduce a submitted select value to a known choice, or to the inherit state.
     *
     * @param mixed $value the submitted value.
     * @param array $allowed the values that may be stored.
     * @return string one of $allowed, or self::INHERIT.
     */
    protected function clean_choice($value, array $allowed) {
        $value = (string) $value;
        return in_array($value, $allowed, true) ? $value : self::INHERIT;
    }
}
