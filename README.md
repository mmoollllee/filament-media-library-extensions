# Filament Media Library Extensions

Extensions for [Filament Media Library Pro](https://ralphjsmit.com/filament-media-library) (`ralphjsmit/laravel-filament-media-library` v4, Filament v5):

- **Upload button on the `MediaPicker` field** — an "Upload files" action next to "Choose files". With `inline_upload` enabled (default) it opens the native file dialog; otherwise it reuses the original upload modal (Filament `FileUpload` / FilePond) with the field's driver, folder and accepted file types.
- **Inline uploads without FilePond** (`inline_upload`, default on) — dropped/picked files upload via Livewire's upload API with ghost progress tiles rendered directly inside the file grid/list. A modal-less picker action validates them (effective accepted types, driver max file size, authorization), stores them via `Driver::createFile()` and selects them. The FilePond modal remains the no-JS/server fallback and stays in place on the media library page.
- **Drag-and-drop upload zones** (`dropzone`) — the `MediaPicker` field, the file selection modal and the upload modal accept dropped files; folder tiles inside the selection modal are drop targets for subfolder uploads. With `inline_upload` disabled, dropped files are handed to the original FilePond field instead.
- **Auto-selection of fresh uploads** (`auto_select_uploads`) — files uploaded through the selection modal are added to the modal's selection; files uploaded through the field are merged into the field state (respecting `maxFiles`, single pickers replace their value).
- **Extended preview action** (`media_picker_preview`) — PDF iframe preview, previous/next navigation (buttons + arrow keys) between the picker's files, and file URLs resolved through the Spatie URL generator (private disks with a policy-guarded serve route keep working). Used on `MediaPicker` file tiles and — for drivers opting in — on modal file tiles and in the file info sidebar.
- **Slim tile actions** (`slim_tile_actions`, default off) — optional UI simplification replacing the vendor tile action set by plain preview/move/delete buttons; download and duplicate move to the file info sidebar.

The bundled JS also ships a narrow `MutationObserver` guard for a Filament dropdown observer leak (filament/support v5.7.x): dropdowns re-initialized across Livewire morphs leave stale aria-sync observers behind, which can escalate into a page-freezing loop on list-heavy pages. The guard keeps one active attribute-sync observer per dropdown element and can be dropped once fixed upstream.

## Installation

```bash
composer require mmoollllee/filament-media-library-extensions
```

The service provider is auto-discovered. Field, modal and upload wiring is applied automatically via `configureUsing()`. Publish the frontend assets (also after every package update — the asset URLs are content-hashed against the published copies):

```bash
php artisan filament:assets
```

## Driver opt-in

Auto-selection and the extended preview on modal tiles / file info sidebar hook into the driver. Add the trait to your (custom) driver:

```php
use Mmoollllee\FilamentMediaLibraryExtensions\Drivers\Concerns\HasMediaLibraryExtensions;
use RalphJSmit\Filament\MediaLibrary\Drivers\MediaLibraryItemDriver;

class MyDriver extends MediaLibraryItemDriver
{
    use HasMediaLibraryExtensions;
}
```

## Configuration

```bash
php artisan vendor:publish --tag=filament-media-library-extensions-config
```

Each feature (`upload_button`, `inline_upload`, `dropzone`, `auto_select_uploads`, `slim_tile_actions`, `media_picker_preview`) can be toggled individually — see the config file for the exact semantics and interdependencies.

## Note on the field view

The upload button placement requires a copy of the explore package's `file-picker` view (`resources/views/filament/forms/components/media-picker.blade.php`). Re-diff it against `filament-explore::filament.forms.components.file-picker` when updating `ralphjsmit/laravel-filament-explore` (currently based on v1.1.2).

## Limitations

- `MediaPicker` fields inside `Repeater`/`Builder` items work, but inline uploads there have not been battle-tested — the pending-upload scratch state is anchored at the root schema state path, outside the dehydrated item state.
- The inline upload path runs its own validation (accepted types via field or driver, driver max file size, `FileAbility::Create`); Laravel validation rules attached to the FilePond `FileUpload` do not run on this path.
