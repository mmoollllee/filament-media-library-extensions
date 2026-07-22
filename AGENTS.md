# Agent guidelines — `mmoollllee/filament-media-library-extensions`

If you are an AI coding agent working in this package (or in an app that
installs it), prefer these rules over guesses based on similarly-named
conventions. Several mechanisms here look replaceable but are load-bearing —
each one below cost a debugging session.

## What this package does

Extends **Filament Media Library Pro** (`ralphjsmit/laravel-filament-media-library`,
built on `ralphjsmit/laravel-filament-explore`) with upload UX around the
`MediaPicker`. Nothing in the vendor packages is patched: everything hooks in
through `configureUsing()`, a driver trait, a copied field view and one JS asset.

| Feature | Config flag | Entry point |
|---|---|---|
| Inline uploads (no FilePond) | `inline_upload` | `ProcessInlineUploadsAction` + `resources/js/…` |
| Drag-and-drop zones | `dropzone` | `data-mle-dropzone` attributes + JS |
| Upload button on the field | `upload_button` | package view `media-picker.blade.php` |
| Auto-select fresh uploads | `auto_select_uploads` | `CreatedFilesCollector` + `UploadAction::after()` |
| Extended preview | `media_picker_preview` | `MediaPickerPreviewAction` via driver trait |
| Slim tile actions | `slim_tile_actions` | `HasMediaLibraryExtensions::getFileActions()` |

## Non-negotiable invariants

1. **`ProcessInlineUploadsAction` is the ONLY gate on the inline path.** It is
   client-mountable with client-controlled arguments, and no `FileUpload`
   validation rules run there. Keep all of: `FileAbility::Create` authorization
   against the *resolved target folder*, the disabled-picker check, the
   effective accepted types (field-level, falling back to the **driver's**), the
   driver max file size, and `PickerUploads::resolveDropTargetFolder()` for
   every folder key (scoped `findFile()` inside try/catch — the vendor driver
   *throws* on malformed keys — plus scoped-folder containment).
2. **Consume only the mounting batch's files** (`uploadKeys` argument).
   Concurrent upload batches share one pending bucket and may target different
   folders.
3. **The pending bucket is anchored at the root schema state path**
   (`PickerUploads::pendingUploadsStatePath()`), never as a sibling of the
   field: numeric segments (`mountedActions.0.…`) must survive, or a picker
   inside an action modal writes a string key into Livewire's numeric
   `mountedActions` array and the modal force-closes with all input lost.
4. **Config attributes are base64-encoded JSON.** Filament renders
   `extraAttributes` / `extraModalWindowAttributes` **unescaped** — raw JSON
   tears the attribute apart at the first quote.
5. **Ghost tiles must never sit in the DOM while Livewire morphs.** Livewire's
   block morph skips regions containing foreign nodes, so a leftover ghost makes
   the response with the fresh tile silently not apply. Hence detach on `morph`,
   re-attach on `morphed`, remove finished ghosts *before* the process
   roundtrip, and keep errored ghosts in a `wire:ignore` host outside the grid.
6. **Never resolve a lost ghost scope to another zone.** `document.querySelector`
   for a dropzone lands on a *different picker* on multi-picker pages — use the
   floating body host.
7. **Upload-trigger clicks are intercepted in the capture phase**, not via
   `alpineClickHandler`. The takeover variant disables `wire:click`, which
   leaves every upload button dead when the asset is missing (unpublished
   assets, JS error) — the FilePond modal must stay the natural fallback.
8. **The `MutationObserver.prototype.observe` guard is narrowed on purpose** to
   attribute-only observers inside `.fi-dropdown`. Widening it would disconnect
   foreign content observers; removing it revives a page freeze on list-heavy
   pages (filament/support v5.7.x leaks aria-sync observers across morphs).
   Delete it once the dropdown component disconnects its own observers.

## Maintenance duties

- `resources/views/filament/forms/components/media-picker.blade.php` is a copy of
  `filament-explore::filament.forms.components.file-picker` (v1.1.2) plus the
  inline config attribute, the upload button and the ghost fallback host.
  **Re-diff it against the vendor view when updating explore.**
- Run `php artisan filament:assets` after touching `resources/js|css` — asset
  URLs are content-hashed against the *published* copies.
- Only use utility classes that already appear in the vendor blades: the app's
  Tailwind theme does not scan this package's JS.
- Selection merges live in `PickerUploads` (`mergeCreatedFilesIntoModalSelection`
  / `mergeCreatedFilesIntoState`) and the parent modal is found via
  `findParentSelectFileAction()` (stack walk, not `count - 2`). Both upload paths
  must keep sharing them.
- Do not use the inline path inside Repeater/Builder items without re-testing —
  the pending bucket is root-anchored, but item state dehydrates wholesale.

## Testing

The package ships no test suite; it is covered from the consuming app
(`MediaLibraryExtensionsTest` for the actions/wiring, a Pest v4 browser suite for
the JS pipeline). When changing behaviour here, run those suites — the JS
pipeline (ghost placement, morph interplay, capture-phase clicks) only fails in a
real browser.
