# Release Notes for Scribe

## Unreleased

### Added

- A refresh button in the field, which fetches the chosen readme again on its own.
- Scribe fields can be shown as a column in an element index, or on a card, where they read as `craft-dub (Requirements → Usage)`.

### Changed

- The field is drawn as one bordered card, with its three menus sharing a single row between them at any width.
- The repository list is fetched when the readme menu is first opened, rather than on every page the field renders on.
- The readme menu names a repository's readme file only when it isn't called `README.md`.
- **Start From** and **End Before** give way to a note when a readme has no headings, or couldn't be fetched. Any range already saved against it is left alone.
- The preview pane is remembered open or closed for each instance of a field on the page, so one in a Matrix is remembered a block at a time.

### Fixed

- A readme rewritten under a field took the saved range with it, without a word: the menus fell back to their placeholders and the next save cleared the range for good. Both are now held, and a warning under the field says which heading has gone.
- A repository list that failed to build was held for six hours, emptying the readme menu until it expired. It's now held for two minutes.
- The repository list arrived filtered down to a single entry, leaving the menu looking empty until you clicked away and back into it.
- A preview pane left closed flashed open on page load.

## 1.0.0-beta.6 - 2026-07-29

### Changed

- The preview pane remembers whether it was left open or closed, per field.
- Scrolling past the end of the preview pane carries on scrolling the page.

### Fixed

- The preview pane took its background from `--white` rather than `--pane-bg`, leaving it bright under a control panel themed dark. Unchanged on a stock control panel, where the two are the same colour.

## 1.0.0-beta.5 - 2026-07-29

### Changed

- The repository list is cached for six hours rather than the configured **Cache Duration**, which applies to READMEs. Clearing Scribe's caches still flushes it.

### Fixed

- Rendering a field no longer waits on the repository list being built, which could add several seconds to the first page load after a cache expiry. Ownership is now confirmed by a single lookup that also reports the branch.

## 1.0.0-beta.4 - 2026-07-29

### Added

- **Hide Images** field setting, leaving images out of the rendered README along with any link or paragraph left empty by their going.

### Changed

- Images in a rendered README carry `loading="lazy"` and `decoding="async"`, unless the README set them itself.

## 1.0.0-beta.3 - 2026-07-29

### Fixed

- The readme menu was missing wherever the field renders statically, such as a revision, since Craft discards the JavaScript that sets the menu up there.

## 1.0.0-beta.2 - 2026-07-29

### Added

- The readme picker now shows each repository's README filename beside its name.
- A spinner while a readme is being fetched.

### Changed

- The field's first menu is labelled **Readme File** and lists only repositories that have a README. The list comes from a single GraphQL request per 100 repositories, so the check costs no extra API calls.
- The three menus no longer carry visible labels: each one's blank option is now its placeholder.
- The readme menu is capped at roughly eight rows.
- The **Preview** pane's disclosure caret sits in a fixed square box so it can't shift the label, its header takes a full-width border when open, and its content has top padding.

### Fixed

- Focusing a readme picker that already had a value hid the **Start From** / **End Before** menus and the preview, then refetched them on blur.
- Fields rendered without a GitHub token threw a JavaScript error.

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
