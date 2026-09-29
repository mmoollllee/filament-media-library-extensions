# Changelog

All notable changes to `mmoollllee/filament-media-library-extensions` will be documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Actions above the chosen files** — the field's "Choose files", "Upload
  files" and "Clear" now sit right under the label, with the chosen files (or
  the empty-state line) below them, so pickers side by side keep their buttons
  in one line whether or not they hold files. A narrow column wraps the row.
- **"Clear" is labelled** — the explore package leaves the action unlabelled,
  so it read "Clear" in every language; it now takes
  `actions.media_picker_clear.label` (de: "Auswahl entfernen"). A field's own
  `modifyClearActionUsing()` still wins.

## [0.2.0] — Thumbnail-sized previews

### Added

- **Thumbnail-sized previews** — every `MediaPicker` gets a container-breakpoint
  column ladder for its file grid, so the square tiles stay at roughly 6–9rem
  instead of stretching to the full field width (a single-file image field used
  to render its preview as tall as the field is wide). No config flag: apps
  override it with their own `MediaPicker::configureUsing()` (`gridColumns(null)`
  restores the vendor default) or per field via `->gridColumns(...)`.

## [0.1.0] — Initial release

### Added

- **Inline uploads without FilePond** (`inline_upload`, default on) — the field's
  "Upload files" button and the selection modal's topbar button open the native
  file dialog; dropped or picked files upload through Livewire's JS upload API
  with ghost progress tiles rendered directly inside the file grid/list. The
  modal-less `ProcessInlineUploadsAction` authorizes, validates, stores
  (`Driver::createFile()`) and selects them. FilePond stays as the no-JS
  fallback and on the media library page.
- **Drag-and-drop upload zones** (`dropzone`) — the `MediaPicker` field, the
  selection modal and the upload modal accept dropped files; folder tiles inside
  the selection modal are drop targets for subfolder uploads. Drags that start
  inside the page (tile drags, reordering) never activate a zone.
- **Auto-selection of fresh uploads** (`auto_select_uploads`) — files uploaded
  through the selection modal join the modal's (bulk) selection, files uploaded
  through the field are merged into the field state, respecting `maxFiles`.
- **Extended preview action** (`media_picker_preview`) — PDF iframe preview,
  previous/next navigation (buttons + arrow keys) across the picker's files, and
  file URLs resolved through the Spatie URL generator so private disks with a
  policy-guarded serve route keep working. Used on field tiles and — for drivers
  using `HasMediaLibraryExtensions` — on modal tiles and in the file info sidebar.
- **Slim tile actions** (`slim_tile_actions`, default off) — optional UI
  simplification replacing the vendor tile action set with plain preview/move/
  delete buttons; download and duplicate move into the file info sidebar.
- **Content-hashed asset versions** — `ContentVersionedJs`/`ContentVersionedCss`
  version asset URLs by the hash of the published file instead of the composer
  package version, which never changes for path/dev installs.
- **Dropdown observer guard** — a narrow `MutationObserver.prototype.observe`
  patch keeping one active attribute-sync observer per `.fi-dropdown` element.
  Works around a leak in filament/support v5.7.x where dropdowns re-initialized
  across Livewire morphs leave stale aria-sync observers behind, which escalate
  into a page-freezing loop on list-heavy pages. Remove once fixed upstream.
