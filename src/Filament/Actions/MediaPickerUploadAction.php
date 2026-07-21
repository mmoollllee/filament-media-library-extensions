<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions;

use Mmoollllee\FilamentMediaLibraryExtensions\Support\CreatedFilesCollector;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\PickerUploads;
use RalphJSmit\Filament\Explore\Filament\Actions\UploadAction;
use RalphJSmit\Filament\Explore\Filament\Forms\Components\FilePicker;

/**
 * Upload action registered directly on a `MediaPicker`/`FilePicker` field.
 *
 * Reuses the original upload modal (Filament FileUpload / FilePond) and, when
 * `auto_select_uploads` is enabled, merges the freshly uploaded files into the
 * field state — appending for multiple pickers (up to `maxFiles`, existing
 * selection wins) and replacing the value for single pickers.
 */
class MediaPickerUploadAction extends UploadAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('filament-media-library-extensions::actions.media_picker_upload.label'))
            ->color('gray')
            ->after(function (FilePicker $component): void {
                if (! config('filament-media-library-extensions.auto_select_uploads')) {
                    return;
                }

                PickerUploads::mergeCreatedFilesIntoState(
                    $component,
                    app(CreatedFilesCollector::class)->consume(),
                );
            });
    }
}
