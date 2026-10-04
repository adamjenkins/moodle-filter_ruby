# Changes

## v1.0.2 (2026100400)

- Installing with Composer no longer caps the Moodle version: `composer.json`
  now requires `moodle/moodle` `^4.5 || ^5.0` (was `>=4.5 <5.4`).
- Continuous integration now tests against the released Moodle 5.3
  (`MOODLE_503_STABLE`) instead of Moodle's development branch.
- Pushing a release tag now also publishes the release to the camp registry,
  with the plugin's listing text in `.camp/listing.yml`.
