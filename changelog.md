# Changelog

All notable changes to the Ruby filter (filter_ruby) are documented here.
Entries are ordered newest-first.

---

## [2026090800] — 2026-09-08 — Initial release

### Added

- `filter_ruby\text_filter`, running at the `post_clean` stage, which annotates
  kanji with furigana by wrapping them in HTML5 `<ruby>` markup. Because the
  filter runs after HTML cleaning, its output is never passed through
  HTMLPurifier and so does not need the HTML 4 `<rb>` wrapper.
- Three sources of readings, in precedence order: inline markup in the content,
  `word=reading` lists resolved per context (activity → course → category →
  site, merged with the nearer context winning), and the shipped tier
  dictionaries. Within any one source the longest match wins, so 今日 is read
  きょう rather than 今 + 日.
- Two inline syntaxes: the brace form `{漢字|かんじ}` and the Aozora Bunko form
  `｜漢字《かんじ》`. In both, an empty reading suppresses annotation of that
  word. The Aozora form requires its leading full-width `｜`, so that ordinary
  Japanese text using 《》 as quotation marks is not rewritten.
- Four independently toggleable tier dictionaries — elementary, junior high,
  senior high, university — grouped by the school level of a word's hardest
  kanji, as given by KANJIDIC2's `<grade>` field. They ship as generated PHP
  arrays in `data/`, holding roughly 9,700 word readings and 3,200 kanji-run
  readings between them; the kanji-run keys are what let an inflected form such
  as 難しかった still get a reading on 難.
- `cli/build_tiers.php`, the developer tool that generates those files from
  JMdict_e and KANJIDIC2 by streaming both with `XMLReader`, sorting words into
  tiers by grade and trimming each tier by JMdict frequency tags. It is not
  runtime code, does not bootstrap Moodle, and produces byte-identical output on
  repeated runs over the same sources. `data/` is declared in
  `thirdpartylibs.xml` as CC BY-SA 4.0 third party data and must never be
  hand-edited.
- Four display modes: always on for every occurrence (the default), first
  occurrence of each word only, show on hover, and a reader-operated toggle
  button whose state is remembered in the `filter_ruby_show` user preference.
- An optional single-kanji fallback, off by default, that guesses a reading for a
  kanji no list covers. It gates that guess and nothing else: the main
  dictionary is matched at every length down to one character unconditionally,
  so an explicit one-character entry — a teacher's 金=かね, or a tier file's
  kanji-run key — is honoured whether the setting is on or off.
- Site defaults in `settings.php` (stored in `config_plugins`) and per-context
  settings in `filterlocalsettings.php` (stored in core's `filter_config` table,
  so they are covered by course backup and restore). No plugin database table.
- Word-list validation: a line with no `=` separator or an empty word blocks the
  save; a reading not written in kana is reported as a warning only.
- MUC application cache `filter_ruby/dictionary` holding the compiled
  `word => reading` map, keyed by a hash of the effective configuration, so a
  settings change produces a new key and needs no explicit invalidation.
- `lib.php`'s `filter_ruby_user_preferences()` callback declaring
  `filter_ruby_show` (`PARAM_BOOL`, default 0, `permissioncallback` restricted to
  the preference's owner). Without the declaration
  `core_user::can_edit_preference()` refuses the save, so the reader toggle would
  silently discard the reader's choice; the deprecated
  `user_preference_allow_ajax_update()` is not a substitute, being a no-op stub
  on 5.0 and absent from 5.1 onwards.
- Privacy provider implementing `metadata\provider` and
  `user_preference_provider` for `filter_ruby_show`; no other personal data is
  stored, and no deletion code is needed because core deletes user preferences
  itself.
- PHPUnit tests and a Behat feature covering matching, precedence, skip zones,
  inline markup, malformed word lists, display modes, context inheritance and
  the privacy provider. PHPUnit metadata is kept in `@dataProvider` / `@covers`
  doc-comments rather than attributes, because Moodle 4.5 pins
  `phpunit/phpunit: ^9.6.34`, which predates attribute metadata; the
  deprecation notices the newer branches print for them are expected.

### Verified

- PHPUnit suite green: 262 tests, 474 assertions, 0 failures, exit 0.
- Every step in `tests/behat/filter_ruby.feature` resolves to a step definition
  in core, checked by extracting all 630 `@Given`/`@When`/`@Then` annotations
  from the `behat_*.php` context classes and matching each step line against
  them; the check was proven non-vacuous by feeding it an invented step, which
  it reported unmatched. The feature itself has not been executed here.
- Tier data sizes read back out of the generated files: 4272 / 4683 / 558 / 168
  word entries and 1026 / 1110 / 859 / 205 kanji-run entries for elementary,
  junior high, senior high and university respectively.

### Attribution

- The tier dictionaries are derived from JMdict/EDICT and KANJIDIC2, property of
  the Electronic Dictionary Research and Development Group, used under CC BY-SA
  4.0 (https://www.edrdg.org/edrdg/licence.html). The acknowledgement is shown
  on the plugin's site settings page as the licence requires.
