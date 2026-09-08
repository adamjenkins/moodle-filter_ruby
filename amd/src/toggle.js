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
 * The reader's furigana toggle.
 *
 * Loaded by `\filter_ruby\text_filter::setup_page_for_filters()` for the two
 * display modes that need JavaScript, and called as `init()` with no arguments.
 * Both modes share the module, so which one is in play is read from the DOM:
 * the annotator marks its output `filter_ruby--toggle` or `filter_ruby--hover`
 * (classes/local/annotator.php:415-417). Hover mode needs no JavaScript at all
 * -- styles.css does the whole job -- so this module only acts when it finds
 * toggle-mode ruby on the page, and quietly does nothing otherwise.
 *
 * The visible state lives in one class on `<body>`, which styles.css keys off:
 * the CSS hides `.filter_ruby--toggle rt` unless `body.filter_ruby-show` is
 * present. Flipping one class is what makes the toggle instant for a page that
 * may hold hundreds of annotations.
 *
 * The reader's choice is remembered in a user preference, and that only works
 * because the plugin's lib.php declares it: `setUserPreference()` posts to
 * core's REST route, which refuses any preference no plugin has declared
 * through the `user_preferences` callback
 * (user/classes/route/api/preferences.php:225-232 asking
 * `\core\user::can_edit_preference()`). Reading is not gated the same way --
 * `get_preferences()` (preferences.php:70-89) just calls
 * `get_user_preferences()` -- so without lib.php the load path worked, the save
 * path was refused, and the toggle forgot every choice. Do not remove
 * `filter_ruby_user_preferences()`.
 *
 * @module     filter_ruby/toggle
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {getString} from 'core/str';
import {getUserPreference, setUserPreference} from 'core_user/repository';
import Notification from 'core/notification';
import Pending from 'core/pending';

/**
 * The user preference holding the reader's choice.
 *
 * Named identically in `\filter_ruby\text_filter::PREFERENCE` and
 * `\filter_ruby\privacy\provider::PREFERENCE`.
 *
 * @type {String}
 */
const PREFERENCE = 'filter_ruby_show';

/**
 * The body class that reveals the readings. Contract with styles.css.
 *
 * @type {String}
 */
const SHOWNCLASS = 'filter_ruby-show';

/**
 * Selector matching a ruby element that this module is responsible for.
 *
 * @type {String}
 */
const TOGGLERUBY = '.filter_ruby--toggle';

/**
 * Where to put the button, best first.
 *
 * The main content region is preferred so that the control sits with the text
 * it acts on; `body` is a floor rather than a real answer, and exists so that a
 * theme without either landmark still gets a usable toggle.
 *
 * @type {String[]}
 */
const HOSTSELECTORS = ['#region-main', '[role="main"]', 'main', 'body'];

/**
 * The button, once built.
 *
 * @type {HTMLButtonElement|null}
 */
let button = null;

/**
 * Whether init() has already run.
 *
 * Set synchronously on entry, before the first await, and deliberately separate
 * from `button`: core calls filter setup once per context, so two contexts on
 * one page can produce two init() calls, and testing a variable that is only
 * assigned after two awaits would let the second call race past the guard and
 * build a second button.
 *
 * @type {Boolean}
 */
let started = false;

/**
 * Whether the readings are currently visible.
 *
 * The body class is the single source of truth, so there is no second copy of
 * the state to fall out of step with it.
 *
 * @return {Boolean}
 */
const isShown = () => document.body.classList.contains(SHOWNCLASS);

/**
 * Show or hide every toggle-mode reading on the page.
 *
 * @param {Boolean} shown Whether readings should be visible.
 * @return {void}
 */
const applyState = (shown) => {
    document.body.classList.toggle(SHOWNCLASS, shown);
    if (button) {
        // The aria-pressed attribute is what tells a screen reader that this is
        // a toggle and which way it currently sits; the visible label never
        // changes, so that attribute is the whole of the state announcement.
        button.setAttribute('aria-pressed', shown ? 'true' : 'false');
    }
};

/**
 * Resolve once the document has been parsed.
 *
 * `js_call_amd()` output lands in the footer, so the body is normally complete
 * by the time init() runs -- but "normally" is not "always" (a theme may move
 * the requirements, and Behat can win the race), and this module decides what to
 * do by looking for elements in the page. Waiting costs nothing when the
 * document is already parsed.
 *
 * @return {Promise} Resolved when the DOM is ready.
 */
const domReady = () => new Promise((resolve) => {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => resolve(), {once: true});
    } else {
        resolve();
    }
});

/**
 * Build the toggle control and put it on the page.
 *
 * A real `<button type="button">` is used rather than a styled link or span, so
 * that it is in the tab order, fires on both Enter and Space, and is announced
 * as a button, all without a line of extra code. The label is set with
 * textContent, which cannot inject markup whatever a translator writes.
 *
 * @param {String} label The visible, translated button label.
 * @return {HTMLButtonElement} The button that was inserted.
 */
const createButton = (label) => {
    const wrapper = document.createElement('div');
    wrapper.className = 'filter_ruby-controls';

    const control = document.createElement('button');
    control.type = 'button';
    control.className = 'btn btn-secondary btn-sm filter_ruby-toggle';
    control.setAttribute('aria-pressed', 'false');
    control.textContent = label;

    wrapper.appendChild(control);

    const host = HOSTSELECTORS.reduce(
        (found, selector) => found || document.querySelector(selector),
        null
    );
    host.prepend(wrapper);

    return control;
};

/**
 * Report a failed save to the reader, without becoming a failure itself.
 *
 * `Notification.exception()` expects an Error: the first thing it does is
 * assign `ex.stack` (lib/amd/src/notification.js:337-343), and assigning a
 * property to a string primitive throws a TypeError in the strict mode every
 * ES module runs under. `core/fetch` rejects with `response.statusText` -- a
 * plain string -- for every non-2xx reply (lib/amd/src/fetch.js:96-101), which
 * is exactly the case here: a preference the server will not accept comes back
 * as an access-denied response, not as a network error. Passing that string
 * straight to `Notification.exception` would throw inside the very handler
 * meant to report the problem -- and because that function is `async`, the
 * throw would become one more unhandled rejection rather than a visible error,
 * so the reader would be told nothing at all.
 *
 * The reason is wrapped rather than replaced, so whatever the server said is
 * what is shown; this module invents no message of its own.
 *
 * @param {Error|String} reason Whatever the rejected promise carried.
 * @return {void}
 */
const reportSaveFailure = (reason) => {
    Notification.exception(reason instanceof Error ? reason : new Error(String(reason)));
};

/**
 * Serialises the saves, so the last press is the last write.
 *
 * Two quick presses start two requests, and nothing about HTTP guarantees they
 * finish in the order they were sent -- so an unchained pair can leave the
 * stored value disagreeing with the page. Chaining costs one variable and makes
 * the order the reader's, not the network's. The `catch` between the links is
 * what stops a failed save from cancelling the next one.
 *
 * @type {Promise}
 */
let saving = Promise.resolve();

/**
 * Remember the reader's choice.
 *
 * @param {Boolean} shown Whether readings should be visible.
 * @return {Promise} Resolved when this save has finished, successfully or not.
 */
const persist = (shown) => {
    saving = saving
        // Swallow the previous link's failure -- it has already been reported,
        // and it must not stop this press from being saved. `null` rather than
        // an empty body only because eslint's no-empty-function says so.
        .catch(() => null)
        .then(() => setUserPreference(PREFERENCE, shown ? '1' : '0'))
        .catch(reportSaveFailure);

    return saving;
};

/**
 * Handle a press: flip the page, then remember the choice.
 *
 * The page is updated first and the save is not awaited. The reader gets an
 * instant answer, and a save that fails leaves the page in the state they asked
 * for -- it simply will not be remembered on the next page, which is what
 * Notification.exception tells them.
 *
 * The button is left showing what the reader asked for even when the save
 * fails, because it is not lying: the readings really are in that state on this
 * page, and `aria-pressed` describes the page. What the failed save costs is
 * the memory of it on the next page, and that is what the notification says.
 * Reverting instead would take away the readings the reader just asked to see,
 * on account of a problem that has nothing to do with them.
 *
 * Note for future edits: `setUserPreference()` here comes from
 * `core_user/repository`, which returns a native Promise -- re-verified today
 * on MOODLE_405_STABLE (`git show origin/MOODLE_405_STABLE:user/amd/src/
 * repository.js`) and on this 5.2 site (user/amd/src/repository.js:109-116):
 * both are `Fetch.performPost(...).then().then()` chains, and `core/fetch`
 * itself resolves a `new Promise` (lib/amd/src/fetch.js:62-67). Were this ever
 * rewritten to call `core/ajax` directly, the returned object would be a jQuery
 * Deferred, which has no `.finally()`: chaining one throws while the chain is
 * being BUILT, so the handler works exactly once and then dies. Wrap in
 * `Promise.resolve()` before any `.finally()` if that day comes.
 *
 * @return {void}
 */
const handleClick = () => {
    const shown = !isShown();
    applyState(shown);
    persist(shown);
};

/**
 * Apply the reader's stored choice, if there is one.
 *
 * A failure here is deliberately not shown to the reader: the page has already
 * rendered with readings hidden, which is the documented default for this mode,
 * and the button still works for the current page. The write path does report
 * its failures, so a misconfigured site is not silent overall.
 *
 * @return {Promise} Resolved once the stored state has been applied, or not.
 */
const restoreState = async() => {
    try {
        const stored = await getUserPreference(PREFERENCE);
        applyState(stored === '1' || stored === 1 || stored === true);
    } catch (error) {
        applyState(false);
    }
};

/**
 * Set up the furigana toggle.
 *
 * @return {Promise} Resolved once the button is on the page.
 */
export const init = async() => {
    const pendingPromise = new Pending('filter_ruby/toggle');

    try {
        if (started) {
            return;
        }
        started = true;

        await domReady();

        // Hover mode loads this module too, and has nothing for it to do.
        if (!document.querySelector(TOGGLERUBY)) {
            return;
        }

        button = createButton(await getString('togglebutton', 'filter_ruby'));
        button.addEventListener('click', handleClick);

        await restoreState();
    } finally {
        pendingPromise.resolve();
    }
};
