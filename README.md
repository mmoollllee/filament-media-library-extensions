# Filament Media Library Extensions

Extensions for [Filament Media Library Pro](https://ralphjsmit.com/filament-media-library) (`ralphjsmit/laravel-filament-media-library` v4, Filament v5):

- **Upload button on the `MediaPicker` field** — an "Upload files" action next to "Choose files" that reuses the original upload modal (Filament `FileUpload` / FilePond) with the field's driver, folder and accepted file types.
- **Drag-and-drop upload zones** — the `MediaPicker` field, the file selection modal and the upload modal accept dropped files. Dropped files are handed to the original FilePond field, so validation, progress and error handling stay untouched.
- **Auto-selection of fresh uploads** — files uploaded through the selection modal are added to the modal's selection; files uploaded through the field's upload button are merged into the field state (respecting `maxFiles`, single pickers replace their value).
- **Extended preview action** — PDF iframe preview, previous/next navigation (buttons + arrow keys) between the picker's files, and file URLs resolved through the Spatie URL generator (private disks with a policy-guarded serve route keep working). Used on `MediaPicker` file tiles and — for drivers opting in — on modal file tiles and in the file info sidebar.

## Installation

```bash
composer require mmoollllee/filament-media-library-extensions
```

The service provider is auto-discovered. Field, modal and upload wiring is applied automatically via `configureUsing()`. Publish the frontend assets:

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

Each feature (`upload_button`, `dropzone`, `auto_select_uploads`, `media_picker_preview`) can be disabled individually.

## Note on the field view

The upload button placement requires a copy of the explore package's `file-picker` view (`resources/views/filament/forms/components/media-picker.blade.php`). Re-diff it against `filament-explore::filament.forms.components.file-picker` when updating `ralphjsmit/laravel-filament-explore` (currently based on v1.1.2).
