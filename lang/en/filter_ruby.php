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
 * Strings for component 'filter_ruby', language 'en'.
 *
 * @package    filter_ruby
 * @copyright  2026 Adam Jenkins <adam@wisecat.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['displaymode'] = 'Display mode';
$string['displaymode_all'] = 'Always show, on every occurrence';
$string['displaymode_first'] = 'Show on the first occurrence of each word only';
$string['displaymode_help'] = 'How furigana readings are presented to the reader.

* **Always show, on every occurrence** — every matched word is annotated, every time it appears.
* **Show on the first occurrence of each word only** — a word is annotated the first time it appears on the page, then left plain.
* **Show on hover** — readings are hidden until the reader hovers over (or focuses) the word.
* **Reader toggle** — readings are hidden until the reader turns them on with the furigana button. The choice is remembered.';
$string['displaymode_hover'] = 'Show on hover';
$string['displaymode_toggle'] = 'Reader toggle (button)';
$string['edrdgacknowledgement'] = 'The shipped tier dictionaries are derived from the JMdict/EDICT and KANJIDIC2 files. These files are the property of the Electronic Dictionary Research and Development Group, and are used in conformance with the Group\'s licence, which is Creative Commons Attribution-ShareAlike 4.0 International (CC BY-SA 4.0). See <a href="https://www.edrdg.org/edrdg/licence.html">https://www.edrdg.org/edrdg/licence.html</a>.';
$string['filtername'] = 'Ruby (furigana)';
$string['lonekanji'] = 'Single-kanji fallback';
$string['lonekanji_help'] = 'When no word in any list matches, fall back to annotating a single kanji with its most common reading.

This is off by default and should stay off for most courses: a single kanji read out of context is often given the wrong reading (for example 今日 is *kyō*, not *ima* + *hi*). With the fallback off, the filter simply stays silent where it is unsure.';
$string['pluginname'] = 'Ruby filter';
$string['privacy:preference:show'] = 'Whether the reader has chosen to show or hide furigana readings when the display mode is set to the reader toggle.';
$string['problem_emptyword'] = 'Line {$a->line}: the text before the "=" is empty ({$a->text}). Each line must read word=reading.';
$string['problem_noseparator'] = 'Line {$a->line}: no "=" found ({$a->text}). Each line must read word=reading.';
$string['problem_notkana'] = 'Line {$a->line}: the reading is not written in kana ({$a->text}). The line has still been saved.';
$string['tier_elementary'] = 'Elementary school words';
$string['tier_elementary_help'] = 'Annotate words whose hardest kanji is taught in elementary school (grades 1-6).';
$string['tier_jhs'] = 'Junior high school words';
$string['tier_jhs_help'] = 'Annotate words whose hardest kanji is taught in junior high school.';
$string['tier_shs'] = 'Senior high school words';
$string['tier_shs_help'] = 'Annotate words whose hardest kanji is beyond the junior high school list, including name kanji.';
$string['tier_university'] = 'University and rare words';
$string['tier_university_help'] = 'Annotate words built from the rarest kanji, outside the everyday-use list.';
$string['tiers'] = 'Tier dictionaries';
$string['tiers_help'] = 'Ticking a tier means "annotate words at this level", not "readers already know this level". The tiers are independent, so you can annotate university-level words while leaving elementary words plain.';
$string['togglebutton'] = 'Furigana';
$string['wordlist'] = 'Word list';
$string['wordlist_help'] = 'One entry per line, written as word=reading, for example 漢字=かんじ

* Blank lines and lines beginning with # are ignored.
* A later line overrides an earlier one for the same word.
* An empty reading (for example 金= ) suppresses annotation of that word.
* This list overrides the site list, which overrides the tier dictionaries.';
