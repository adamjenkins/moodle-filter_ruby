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
 * Resolution of the effective filter settings for a context.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace filter_ruby\local;

/**
 * Works out what the filter should actually do in a given context.
 *
 * Settings live in two places, neither of them owned by this plugin:
 *
 * - Site defaults in `config_plugins`, read with `get_config('filter_ruby', …)`.
 * - Per-context values in core's `filter_config` table, read with
 *   `filter_get_local_config('ruby', $contextid)`.
 *
 * Both use the same seven setting names: `wordlist`, `tier_elementary`,
 * `tier_jhs`, `tier_shs`, `tier_university`, `displaymode` and `lonekanji`.
 *
 * {@see self::for_context()} layers those sources from the outside in — site
 * defaults first, then every context on the path from the system context down
 * to the given context — so the NEAREST context is applied LAST and wins.
 *
 * Two different merge rules apply, per section 3 of the design spec:
 *
 * - The word list MERGES. Every layer contributes its entries and a nearer
 *   layer only overwrites the words it names, so a course can override
 *   `金=きん` without retyping the school-wide list.
 * - The scalars (`displaymode`, `lonekanji` and the four tier flags) do NOT
 *   merge. The nearest layer that set one wins outright; a layer that did not
 *   set it inherits from further out.
 *
 * Contexts that may not hold filter settings at all — block and user, see
 * `filter_context_may_have_filter_settings()` in `lib/filterlib.php` — are
 * skipped rather than read, so a stray `filter_config` row on one of them
 * cannot take effect.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config {
    /** @var string The Moodle plugin component, used for the site level defaults. */
    private const COMPONENT = 'filter_ruby';

    /** @var string The filter name as core's filter_config table records it — no 'filter_' prefix. */
    private const FILTERNAME = 'ruby';

    /** @var string[] The tier dictionary names, in the order they are reported. */
    public const TIERS = ['elementary', 'jhs', 'shs', 'university'];

    /** @var string[] The legal display modes. Anything else resolves to DEFAULT_DISPLAYMODE. */
    public const DISPLAY_MODES = ['all', 'first', 'hover', 'toggle'];

    /** @var string The display mode used when nothing set one, or set it to something unrecognised. */
    public const DEFAULT_DISPLAYMODE = 'all';

    /** @var string The setting holding the raw "word=reading" list. */
    private const SETTING_WORDLIST = 'wordlist';

    /** @var string The setting holding the display mode. */
    private const SETTING_DISPLAYMODE = 'displaymode';

    /** @var string The setting holding the single-kanji fallback flag. */
    private const SETTING_LONEKANJI = 'lonekanji';

    /** @var string Prefix of the four per-tier on/off settings, completed by a name from TIERS. */
    private const SETTING_TIER_PREFIX = 'tier_';

    /**
     * Resolve the effective settings for a context.
     *
     * @param \core\context $context The context the text is being filtered in.
     * @return \stdClass Object with properties wordlist (array of word to reading),
     *                   tiers (array of enabled tier names), displaymode (string,
     *                   one of DISPLAY_MODES) and lonekanji (bool).
     */
    public static function for_context(\core\context $context): \stdClass {
        $layers = [self::site_layer()];

        foreach (self::settable_context_ids($context) as $contextid) {
            $layers[] = filter_get_local_config(self::FILTERNAME, $contextid);
        }

        return self::resolve($layers);
    }

    /**
     * The site level defaults, shaped like a layer so the resolver can treat every source alike.
     *
     * @return array Map of setting name to raw value, holding only the settings that are actually set.
     */
    private static function site_layer(): array {
        $siteconfig = get_config(self::COMPONENT);
        $layer = [];

        foreach (self::setting_names() as $name) {
            // A setting that has never been saved is simply absent from the returned object.
            if (isset($siteconfig->$name)) {
                $layer[$name] = $siteconfig->$name;
            }
        }

        return $layer;
    }

    /**
     * The ids of the contexts on this context's path that may hold filter settings, system first.
     *
     * `get_parent_context_ids(true)` returns the path nearest-first, so it is reversed here: the
     * caller applies the layers in order and the nearest context must therefore come last.
     *
     * @param \core\context $context The context the text is being filtered in.
     * @return int[] Context ids ordered from the system context down to this one.
     */
    private static function settable_context_ids(\core\context $context): array {
        $contextids = array_reverse($context->get_parent_context_ids(true));
        $settable = [];

        foreach ($contextids as $contextid) {
            $candidate = \core\context::instance_by_id((int) $contextid, IGNORE_MISSING);
            if (!$candidate) {
                // A context that has been deleted from under us contributes nothing.
                continue;
            }
            if (!filter_context_may_have_filter_settings($candidate)) {
                // Block and user contexts, per lib/filterlib.php.
                continue;
            }
            $settable[] = (int) $contextid;
        }

        return $settable;
    }

    /**
     * Collapse an ordered list of setting layers into the resolved settings object.
     *
     * @param array $layers Layers ordered outermost first, each a map of setting name to raw value.
     * @return \stdClass The resolved settings, in the shape documented on for_context().
     */
    private static function resolve(array $layers): \stdClass {
        $words = [];
        $displaymode = null;
        $lonekanji = null;
        $tierflags = array_fill_keys(self::TIERS, null);

        foreach ($layers as $layer) {
            // The word list merges: this layer adds its entries and overwrites only the words it names.
            if (isset($layer[self::SETTING_WORDLIST])) {
                foreach (wordlist::parse((string) $layer[self::SETTING_WORDLIST]) as $word => $reading) {
                    $words[$word] = $reading;
                }
            }

            // The scalars do not merge: a layer that set one replaces it outright, and a layer
            // that did not set it leaves the value inherited from further out untouched.
            $value = self::raw_scalar($layer, self::SETTING_DISPLAYMODE);
            if ($value !== null) {
                $displaymode = $value;
            }

            $value = self::raw_scalar($layer, self::SETTING_LONEKANJI);
            if ($value !== null) {
                $lonekanji = self::to_bool($value);
            }

            foreach (self::TIERS as $tier) {
                $value = self::raw_scalar($layer, self::SETTING_TIER_PREFIX . $tier);
                if ($value !== null) {
                    $tierflags[$tier] = self::to_bool($value);
                }
            }
        }

        $resolved = new \stdClass();
        $resolved->wordlist = $words;
        $resolved->tiers = array_values(array_filter(self::TIERS, function (string $tier) use ($tierflags): bool {
            return $tierflags[$tier] === true;
        }));
        $resolved->displaymode = in_array((string) $displaymode, self::DISPLAY_MODES, true)
            ? (string) $displaymode
            : self::DEFAULT_DISPLAYMODE;
        $resolved->lonekanji = (bool) $lonekanji;

        return $resolved;
    }

    /**
     * Read one scalar setting out of a layer, distinguishing "not set here" from "set to something".
     *
     * A missing key, a null and an empty string all mean "this layer did not set it", so the value
     * inherited from further out survives. An empty string is treated that way deliberately: it is
     * not a legal display mode, and it is what a settings form's "inherit" choice stores.
     *
     * @param array $layer Map of setting name to raw value.
     * @param string $name The setting to read.
     * @return string|null The raw value as a string, or null when this layer did not set it.
     */
    private static function raw_scalar(array $layer, string $name): ?string {
        if (!isset($layer[$name])) {
            return null;
        }

        $value = trim((string) $layer[$name]);

        return $value === '' ? null : $value;
    }

    /**
     * Interpret a stored checkbox value.
     *
     * Moodle's admin settings and forms store these as the strings '1' and '0'; the other spellings
     * are accepted so a hand-edited or imported row cannot silently read as true.
     *
     * @param string $value The raw stored value, already known to be non-empty.
     * @return bool Whether the flag is on.
     */
    private static function to_bool(string $value): bool {
        return !in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Every setting name this filter understands.
     *
     * @return string[] The setting names.
     */
    private static function setting_names(): array {
        $names = [self::SETTING_WORDLIST, self::SETTING_DISPLAYMODE, self::SETTING_LONEKANJI];

        foreach (self::TIERS as $tier) {
            $names[] = self::SETTING_TIER_PREFIX . $tier;
        }

        return $names;
    }
}
