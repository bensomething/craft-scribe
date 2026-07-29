# Release Notes for Scribe

## 1.0.0-beta.1 - 2026-07-29

### Added

- Initial beta release.
- Scribe field: pick one of your GitHub repositories, then optionally a **Start From** and **End Before** heading to show a section of its README.
- Searchable repo picker and heading menus. End Before only offers headings after Start From, and sub-headings are indented.
- Optional per-field **Show Preview** pane that renders the selected section while editing.
- Template access via `render()`, `headings`, and raw `url` / `startFrom` / `endBefore`.
- Token authentication that lists your public and private repositories and restricts fetching to repositories you own.
- Optional code-block template setting.
- **GitHub READMEs (Scribe)** cache-clear option in Utilities and via `clear-caches`.
