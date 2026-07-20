<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions;

use Illuminate\Support\Arr;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\CreatedFilesCollector;
use RalphJSmit\Filament\Explore\Data\FileData;
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

                $createdFiles = app(CreatedFilesCollector::class)->consume();

                if ($createdFiles->isEmpty()) {
                    return;
                }

                if (! $component->isMultiple()) {
                    $component->state($createdFiles->first()->getKey());
                    $component->callAfterStateUpdated();

                    return;
                }

                $state = collect(Arr::wrap($component->getState()))
                    ->map(fn (mixed $key): string => (string) $key)
                    ->concat($createdFiles->map(fn (FileData $file): string => $file->getKey()))
                    ->unique()
                    ->values();

                if ($maxFiles = $component->getMaxFiles()) {
                    $state = $state->take($maxFiles);
                }

                $component->state($state->all());
                $component->callAfterStateUpdated();
            });
    }
}
