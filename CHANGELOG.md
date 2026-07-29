# Release Notes for Scribe

## 1.0.0-beta.6 - 2026-07-29

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
