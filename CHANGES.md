# Changes

## v1.0.0 (2026090800)

- Initial release. Adds furigana (ruby) readings to kanji in filtered text, from
  three sources in precedence order: inline markup in the content
  (`{漢字|かんじ}` or `｜漢字《かんじ》`), `word=reading` lists resolved per
  context and merged with the nearer context winning, and four independently
  toggleable tier dictionaries. Longest match wins within any one source, so
  今日 is read きょう and not 今 + 日.
- The tier dictionaries ship as generated data: `data/tier_elementary.php`,
  `tier_jhs.php`, `tier_shs.php` and `tier_university.php` hold roughly 9,700
  word and kanji-run readings and 3,200 single-kanji fallback readings, derived from JMdict/EDICT and
  KANJIDIC2 by `cli/build_tiers.php`. They are third party data under CC BY-SA
  4.0 (one-way compatible with GPLv3), declared in `thirdpartylibs.xml`, and
  the EDRDG acknowledgement is shown on the plugin's site settings page.
- Four display modes — always on for every occurrence (the default), first
  occurrence of each word only, show on hover, and a reader-operated toggle
  whose state is kept in the `filter_ruby_show` user preference — plus an
  optional single-kanji fallback that is off by default.
- No plugin database table: site defaults live in `config_plugins` and
  per-context settings in core's `filter_config`, so course backup and restore
  cover them.
- Course word lists are checked on save: a malformed line or a word longer than
  32 characters blocks the save, and a reading not in kana is a warning.
- Readings on the first line of a text are no longer clipped at the top in
  Firefox.
- Only English strings ship; translations go through AMOS.
