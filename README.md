Ruby filter
===========

A Moodle text filter that adds furigana (ruby) readings above difficult kanji, with
word lists that each course can customise for itself.

```
漢字      →     漢字
                かんじ
```

Readings come from three places, and the nearest one wins:

1. **Inline markup** typed by the author into the content itself.
2. **Word lists** — a plain `word=reading` list, edited per course (or per
   activity, or per category), merged over a site-wide list.
3. **Tier dictionaries** shipped with the plugin, grouped by school level and
   toggled on or off independently.

Where two entries could match at the same position, the **longest** one wins, so
`今日` is read *きょう* rather than 今 + 日.

Requirements
============

- Moodle 4.5 or later (supported: 4.5 – 5.3)

Installation
============

Install from *Site administration → Plugins → Install plugins*, or extract the ZIP
into `filter/ruby/` (Moodle 4.5 – 5.0) or `public/filter/ruby/` (Moodle 5.1 and
later), then log in as an administrator and go to *Site administration →
Notifications* to complete the install.

Then go to *Site administration → Plugins → Filters → Manage filters* and set
**Ruby (furigana)** to *On*.

Editing a course's word list
============================

A teacher with the `moodle/filter:manage` capability can give a single course its
own readings, without touching anything site-wide:

1. Open the course.
2. *More → Filters*.
3. On the **Ruby (furigana)** row, click **Settings**.

That page has the same settings as the site defaults, and anything left unset is
inherited from the level above (activity → course → category → site). Word lists
are *merged* rather than replaced, so a course only has to list the words it wants
to add or override.

The list is one entry per line:

```
漢字=かんじ
勉強=べんきょう
# lines starting with a hash are comments, and blank lines are ignored
金=
```

- A later line beats an earlier one for the same word.
- A **one-character entry** (`金=かね`) is honoured exactly like any other. It is
  unrelated to the *Single-kanji fallback* setting below, which only governs
  *guessed* readings for kanji no list covers.
- An **empty reading** (`金=` above) *suppresses* annotation of that word — useful
  when a shipped tier dictionary is annotating something your readers do not need.
- A word may be at most 32 characters long; no real word comes near that, and a
  longer entry would slow down every page it is matched against.
- In the course form, malformed lines are reported when you save. A missing `=`,
  an empty word or an over-long word is an error and blocks the save; a reading
  that is not written in kana is only a warning, shown after the save, so you can
  still save it deliberately.
- The site-wide list on the admin settings page is not checked when it is saved.
  Malformed lines there are simply ignored when the list is read.

Inline markup
=============

Two syntaxes are recognised anywhere in filtered content. Both take precedence over
every word list, so an author can always override a reading in place.

**Brace form**

```
{漢字|かんじ}          →  漢字 with かんじ above it
{金|}                  →  no reading here, even if a list would add one
```

**Aozora Bunko form**

```
｜漢字《かんじ》        →  漢字 with かんじ above it
｜金《》                →  suppress
```

The leading full-width `｜` is **required** in the Aozora form. Without it, ordinary
Japanese text using 《》 as quotation marks would be silently rewritten.

Tier dictionaries
=================

Four independent checkboxes, each turning on a shipped word list:

| Tier | Covers |
|---|---|
| Elementary school words | words whose hardest kanji is taught in grades 1–6 |
| Junior high school words | words whose hardest kanji is taught in junior high |
| Senior high school words | beyond the junior high list, including name kanji |
| University and rare words | the rarest kanji, outside the everyday-use list |

Between them the four files hold roughly 9,700 word and kanji-run readings and
3,200 single-kanji fallback readings, derived from JMdict and KANJIDIC2 (see *Attribution* below). They are
plain PHP arrays, so they cost a `require` and no parsing at run time.

Ticking a tier means **"annotate words at this level"** — *not* "readers already
know this level". The checkboxes are independent on purpose: a course reading
academic Japanese can annotate university-level vocabulary while leaving elementary
words plain.

Display modes
=============

| Mode | Behaviour |
|---|---|
| Always show, on every occurrence | *(default)* every match is annotated, every time |
| Show on the first occurrence of each word only | annotate a word once per page, then leave it plain |
| Show on hover | readings are hidden until the reader points at the word with a mouse; keyboard and touch-screen readers cannot reveal them, so use the reader toggle for them |
| Reader toggle (button) | readings are hidden until the reader presses the **Furigana** button; the choice is remembered per user |

Limitations
===========

These are real and are not worked around. Read them before deciding the plugin
fits your course.

**No inflection handling.** Matching is literal. A list entry `忘れる` matches
忘れる but not 忘れました or 忘れない. There is no morphological analyser
available to a Moodle plugin, so the filter cannot know that those are the same
word. The tier dictionaries mitigate this by also keying on the **kanji run**
(忘 → わす), so the inflected forms still get a reading on the kanji itself,
while longest-match keeps compounds correct (読書 → どくしょ is found before the
lone 読). A run key is only shipped where the kanji's okurigana word family has
one dominant reading, so genuinely ambiguous kanji get none — 行 (行く いく vs
行う おこなう), 出, 生 and 難 (難しい むずかしい vs 難い かたい) are all excluded
deliberately. Where a kanji run is ambiguous and no compound entry covers it, the filter stays silent unless you opt in to the single-kanji fallback — which
is off by default, because wrong furigana teaches a wrong reading. That setting
gates only the *guess*: a single kanji you have listed yourself, or one a tier
file lists as a kanji-run reading, is annotated whatever the setting says.

**Authors typing raw `<ruby>` HTML need `<rb>`.** Moodle's HTML cleaner permits
`<ruby>`, but the content model it enforces is
`((rb, (rt | (rp, rt, rp))) | (rbc, rtc, rtc?))` — see
`lib/htmlpurifier/HTMLPurifier/HTMLModule/Ruby.php:20-33`. That is the HTML 4
model, in which the base text must be wrapped in an `<rb>` element. Modern HTML5
ruby, where the base text is bare, does not satisfy it, and the cleaner discards
the **whole** `<ruby>` element rather than just the base text:

```html
<ruby>漢字<rt>かんじ</rt></ruby>                     <!-- removed entirely -->
<ruby>漢字<rp>(</rp><rt>かんじ</rt><rp>)</rp></ruby>  <!-- removed entirely -->
<ruby><rb>漢字</rb><rt>かんじ</rt></ruby>            <!-- survives -->
```

This is exactly why the inline shorthand above exists: `{漢字|かんじ}` is plain
text, so nothing can strip it, and the filter emits the ruby markup itself after
cleaning has already run.

**No persistent cache of filtered text.** Moodle does not cache filtered output, so
the filter runs on every page render. The compiled word map is cached (MUC
application cache `filter_ruby/dictionary`), but the text scan itself is not.

Developer notes
===============

**The test suite keeps its PHPUnit metadata in doc-comments on purpose.** The
tests use `@dataProvider` and `@covers` doc-comment annotations rather than the
`#[DataProvider]` / `#[CoversClass]` attributes, so running the suite prints one
*"Metadata found in doc-comment ... is deprecated, use attributes instead"*
notice per annotated test — a couple of dozen of them, and the number moves
whenever a test is added. Those notices are expected and the annotations must
stay: Moodle 4.5 pins `"phpunit/phpunit": "^9.6.34"` in its `composer.json`, and
PHPUnit 9 predates attribute metadata entirely, so converting the tests would
break them on Moodle 4.5. (5.0 and 5.1 pin `^11`, and 5.2 takes PHPUnit through
`moodle/moodle-testing`, so 4.5 is the only branch affected.) Silence the notices
by upgrading Moodle, not by editing the tests.

**Regenerating the tier dictionaries.** The four files in `data/` are generated,
never hand-edited — an edit there is lost the next time anyone rebuilds. Fetch
the two source dictionaries:

- JMdict (English edition) — <http://ftp.edrdg.org/pub/Nihongo/JMdict_e.gz>
- KANJIDIC2 — <http://www.edrdg.org/kanjidic/kanjidic2.xml.gz>

then run the developer tool from the plugin root:

```
php cli/build_tiers.php --jmdict=JMdict_e.gz --kanjidic=kanjidic2.xml.gz
```

It streams both files with `XMLReader`, sorts each word into a tier by the school
grade of its hardest kanji (KANJIDIC2's `<grade>`), trims each tier by JMdict's
frequency tags, and rewrites `data/tier_elementary.php`, `data/tier_jhs.php`,
`data/tier_shs.php` and `data/tier_university.php`. It is a developer tool, not
runtime code: the filter never loads it, and it does not bootstrap Moodle. Run
`php cli/build_tiers.php --help` for the trimming options. Running it twice on
the same sources produces byte-identical files, so an unexpected diff means the
sources changed. When they do, update the `<version>` of each library in
`thirdpartylibs.xml` to match.

Languages
=========

Only the English strings ship with the plugin, as the Moodle Plugins directory
asks. Other languages, including Japanese, are translated in AMOS at
<https://lang.moodle.org/> and reach a site through its installed language packs.

Two option labels in the course settings form — *On* and *Off* — come from core's
own `filters` strings, not from this plugin, so they follow core's translation.

Privacy
=======

The plugin stores one user preference, `filter_ruby_show`, which records whether a
reader has turned furigana on in the *Reader toggle* display mode. Nothing else
personal is stored: the word lists live in core's `config_plugins` and
`filter_config` tables, which are site and course configuration, and the plugin
declares no database table of its own.

`classes/privacy/provider.php` implements `metadata\provider` and
`user_preference_provider`, so the preference is declared in the site's data
registry and is included in a user's data export. Deletion is not implemented
here and does not need to be: Moodle deletes a user's preferences itself, and the
provider has no rows of its own to remove.

Attribution
===========

The shipped tier dictionaries are derived from the **JMdict/EDICT** and
**KANJIDIC2** files. These files are the property of the
[Electronic Dictionary Research and Development Group](https://www.edrdg.org/), and
are used in conformance with the Group's licence, which is Creative Commons
Attribution-ShareAlike 4.0 International (CC BY-SA 4.0). See
<https://www.edrdg.org/edrdg/licence.html>.

This acknowledgement is also displayed on the plugin's site settings page.

The data in `data/` stays under CC BY-SA 4.0 and is declared in
`thirdpartylibs.xml`. Creative Commons lists CC BY-SA 4.0 as one-way compatible
with GPLv3 (<https://creativecommons.org/share-your-work/licensing-considerations/compatible-licenses/>),
so it can be distributed with this GPL plugin.

Support
=======

Bug reports and feature requests:
<https://github.com/adamjenkins/moodle-filter_ruby/issues>

License
=======

GNU GPL v3 or later — see http://www.gnu.org/copyleft/gpl.html
