# Changes

## v1.0.3 (2026101200)

- Faster pages with many activities: each context's resolved filter settings are
  now cached for the request (MUC request cache `filter_ruby/config`), so the
  site, category and course settings are read once per page instead of once per
  activity. Each activity's own settings are taken from what Moodle has already
  loaded for the filter, so an activity costs no query of its own.
- Added the missing language string for the `dictionary` cache definition, and
  one for the new `config` cache.
- The plugin now has an icon (`pix/icon.svg`), shown on *Site administration →
  Plugins → Plugins overview*.
- Pushing a release tag now also publishes the release to the Moodle Plugins
  directory, as a ZIP whose root folder is `ruby`.
- The camp registry listing now has screenshots.
