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
 * The furigana text filter.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby;

use filter_ruby\local\annotator;
use filter_ruby\local\config;
use filter_ruby\local\dictionary;

/**
 * Adds furigana readings to kanji words.
 *
 * This class is deliberately thin. All of the work is done by three classes in
 * `filter_ruby\local`, and this one only wires them together once per request
 * and gets out of the way:
 *
 * - {@see \filter_ruby\local\config::for_context()} resolves the effective
 *   settings for the context being filtered, merging site defaults with the
 *   per-context values core stores in its own `filter_config` table.
 * - {@see \filter_ruby\local\dictionary::for_context()} compiles those settings
 *   into one word to reading map, cached in MUC.
 * - {@see \filter_ruby\local\annotator} walks the HTML and emits the ruby.
 *
 * ## Which filtering stage this runs at
 *
 * The design requires the filter to run at the `post_clean` stage, after
 * HTMLPurifier, so that the ruby markup this plugin generates is never
 * purified. That is what an unmodified `filter()` method already does, and this
 * class therefore overrides NO `filter_stage_*` method. Verified in core:
 * `\core_filters\text_filter::filter_stage_post_clean()` is the only stage
 * method whose default body calls `$this->filter()`
 * (public/filter/classes/text_filter.php:114-117), the other three return the
 * text untouched (lines 86-103); and `filter_manager::filter_text()` treats
 * "no stage given" as `post_clean`
 * (public/filter/classes/filter_manager.php:231-235).
 *
 * The one further caller of the plain `filter()` is
 * `filter_stage_string()` (text_filter.php:129-132), which serves
 * `format_string()`. That is intentional here — activity and section names get
 * furigana too — but note that `format_string()` post-processes whatever the
 * filters return: with `$CFG->formatstringstriptags` on, the default, the ruby
 * element is stripped back to `word(reading)`, which is exactly why the
 * annotator emits `<rp>` parentheses.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class text_filter extends \core_filters\text_filter {
    /**
     * Text that cannot possibly need this filter, recognised without touching the database.
     *
     * This mirrors step 1 of the annotator's own algorithm: a fragment is worth
     * looking at only if it holds a kanji or the opening of the brace inline
     * syntax. Doing the test here as well, before any configuration is read,
     * is what keeps the filter free on a site whose content is not Japanese —
     * otherwise every string on every page would resolve the context chain and
     * compile a dictionary in order to change nothing.
     *
     * @var string
     */
    private const TRIGGER_PATTERN = '/\p{Han}|\{[^}]*\|/u';

    /**
     * The display modes that need JavaScript on the page.
     *
     * 'all' and 'first' are pure markup and CSS, so no module is loaded for
     * them at all.
     *
     * @var string[]
     */
    private const JS_MODES = [annotator::MODE_HOVER, annotator::MODE_TOGGLE];

    /** @var string The AMD module driving the hover and toggle display modes. */
    private const JS_MODULE = 'filter_ruby/toggle';

    /**
     * The user preference the toggle button writes.
     *
     * Public because it is the canonical spelling of the name: `lib.php`
     * declares the preference under this constant, so the declaration and the
     * filter cannot drift apart. `amd/src/toggle.js` and
     * {@see \filter_ruby\privacy\provider::PREFERENCE} repeat the literal —
     * neither can reference a PHP constant here — and `tests/preference_test.php`
     * asserts that all three still agree.
     *
     * @var string
     */
    public const PREFERENCE = 'filter_ruby_show';

    /** @var dictionary|null The compiled word to reading map for this context, built once per request. */
    private ?dictionary $dictionary = null;

    /**
     * The annotator for this context.
     *
     * Held on the instance rather than rebuilt per call, because `filter_manager`
     * keeps one filter object per context for the whole request and the 'first'
     * display mode needs its seen-set to span every piece of text on the page.
     * A fresh annotator per call would annotate the first occurrence in every
     * paragraph instead of the first occurrence on the page.
     *
     * @var annotator|null
     */
    private ?annotator $annotator = null;

    /**
     * Annotate a fragment of already-cleaned HTML.
     *
     * @param string $text HTML content to process.
     * @param array $options Options passed to the filters. Not used by this filter.
     * @return string The content with ruby elements added, or the argument unchanged.
     */
    #[\Override]
    public function filter($text, array $options = []): string {
        // The options are part of the signature core mandates, but this filter
        // does not read any of them: what it does is decided entirely by the
        // settings of the context it was built for. Discarded explicitly so it
        // is clear that nothing was overlooked.
        unset($options);

        // The cheapest possible exit, taken before any database or cache access:
        // no kanji and no brace markup means there is nothing this filter could
        // ever do to the text. preg_match() returning false, which is what
        // malformed UTF-8 gives, lands here too and passing the text through is
        // the safe answer.
        if ($text === '' || $text === null || preg_match(self::TRIGGER_PATTERN, $text) !== 1) {
            return (string) $text;
        }

        $this->prepare();

        // Second exit: nothing is configured. An empty dictionary can still be
        // wanted, because inline markup works without any word list at all, but
        // both inline syntaxes need a pipe character, so text without one and
        // with nothing to look up cannot change. Testing for the two pipes is a
        // deliberately loose superset of the annotator's inline patterns: it may
        // say "maybe" where the annotator will find nothing, never the reverse.
        if ($this->dictionary->is_empty() && !str_contains($text, '|') && !str_contains($text, '｜')) {
            return $text;
        }

        return $this->annotator->annotate($text);
    }

    /**
     * Add the page requirements the configured display mode needs.
     *
     * This is the method core actually calls: `filter_manager::setup_page_for_filters()`
     * invokes `setup()` on each filter (public/filter/classes/filter_manager.php:266-271).
     * The design spec names the entry point `setup_page_for_filters()`, so the
     * work lives there and this override does nothing but forward to it.
     *
     * @param \moodle_page $page The page to add requirements to.
     * @param \core\context $context The context whose contents are going to be filtered.
     * @return void
     */
    #[\Override]
    public function setup($page, $context) {
        $this->setup_page_for_filters($page, $context);
    }

    /**
     * Load the JavaScript the 'hover' and 'toggle' display modes need.
     *
     * Nothing is loaded for the 'all' and 'first' modes: those are markup and
     * CSS only, and a page that does not need the module must not pay for it.
     * The stylesheet itself is never requested here — Moodle aggregates every
     * enabled filter's `styles.css` into the theme sheet on its own.
     *
     * Core invokes this once per piece of text filtered and makes the filter
     * responsible for its own cardinality (text_filter.php:55-58), so the first
     * thing it does is claim a one-time item for this context: the settings are
     * the same for every fragment in a context, so the decision only has to be
     * taken once, and the resolution that follows costs one context walk per
     * context per request rather than one per fragment.
     *
     * @param \moodle_page $page The page to add requirements to.
     * @param \core\context $context The context whose contents are going to be filtered.
     * @return void
     */
    public function setup_page_for_filters($page, $context): void {
        if (!$page->requires->should_create_one_time_item_now('filter_ruby_setup_' . $context->id)) {
            return;
        }

        $config = config::for_context($context);
        if (!in_array($config->displaymode, self::JS_MODES, true)) {
            return;
        }

        // A second, context-independent claim: two contexts on one page may both
        // ask for a JavaScript mode, and the module must still load only once.
        if (!$page->requires->should_create_one_time_item_now('filter_ruby_toggle')) {
            return;
        }

        $page->requires->js_call_amd(self::JS_MODULE, 'init', []);

        // Nothing else is needed here to let the module write the preference.
        //
        // What DOES make the write legal is `filter_ruby_user_preferences()` in
        // the plugin's lib.php: `core_user/repository` posts to core's REST
        // route, which asks `\core\user::can_edit_preference()`, which answers
        // from the definitions plugins declare through that callback
        // (public/user/classes/route/api/preferences.php:225-232). A missing
        // declaration is refused with 'access denied', so before lib.php existed
        // this filter's toggle silently forgot every choice a reader made.
        //
        // There used to be a guarded `user_preference_allow_ajax_update()` call
        // on this line. It was removed, and must not come back, because it does
        // nothing useful on any supported branch — checked one branch at a time
        // in reference-clones/moodle with
        // `git show origin/MOODLE_4xx_STABLE:lib/ajax/ajaxlib.php`:
        //
        // On 4.5 (ajaxlib.php:38) it works, but all it does is set
        // `$USER->ajax_updatable_user_prefs[$name]`, which is read only by the
        // legacy lib/ajax/setuserpref.php endpoint. 4.5's own
        // `core_user/repository` already posts to the REST route instead
        // (user/amd/src/repository.js on MOODLE_405_STABLE is a
        // `Fetch.performPost()` chain), so the legacy flag steers nothing —
        // while the function emits a DEBUG_DEVELOPER deprecation on every
        // render of a page carrying this filter.
        //
        // On 5.0 (ajaxlib.php:30) it is an argument-less
        // `#[deprecated(final: true)]` stub whose whole body is
        // `\core\deprecation::emit_deprecation(__FUNCTION__)`; it registers
        // nothing at all.
        //
        // On 5.1 and 5.2 the function is not declared at all (`grep -rn
        // 'function user_preference_allow_ajax_update' /srv/lms/moodle/public`
        // finds nothing; lib/UPGRADING.md:493 records the removal), so the call
        // was never reached here anyway.
        //
        // Nothing in this plugin read the legacy flag: `filter_ruby_show` is
        // written only by amd/src/toggle.js, through `core_user/repository`.
    }

    /**
     * Resolve the settings and build the dictionary and annotator, once per request.
     *
     * @return void
     */
    private function prepare(): void {
        if ($this->annotator !== null) {
            return;
        }

        $config = config::for_context($this->context);
        $this->dictionary = dictionary::for_context($this->context, $config);
        $this->annotator = new annotator($this->dictionary, $config->displaymode, (bool) $config->lonekanji);
    }
}
