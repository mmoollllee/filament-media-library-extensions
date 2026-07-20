<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Drivers\Concerns;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions\MediaPickerPreviewAction;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\CreatedFilesCollector;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Data\TemporaryFileUploadData;
use RalphJSmit\Filament\Explore\Filament\Actions\PreviewAction;
use RalphJSmit\Filament\Explore\Support\ActionsCollection;

/**
 * Opt-in extension hooks for explore/media library drivers.
 *
 * Records every created file in the {@see CreatedFilesCollector} (the package
 * fires no events), so upload actions can auto-select fresh uploads. When the
 * `media_picker_preview` feature is enabled, the extended
 * {@see MediaPickerPreviewAction} is prepended to the file tile actions and
 * replaces the default preview in the file info sidebar — covering the
 * selection modal, the mobile view modal, and the media library page.
 */
trait HasMediaLibraryExtensions
{
    public function createFile(?FileData $folder, TemporaryFileUploadData $temporaryFileUploadData): FileData
    {
        $fileData = parent::createFile($folder, $temporaryFileUploadData);

        app(CreatedFilesCollector::class)->record($fileData);

        return $fileData;
    }

    public function getFileActions(): ActionsCollection
    {
        $actions = parent::getFileActions();

        if (! config('filament-media-library-extensions.media_picker_preview')) {
            return $actions;
        }

        return new ActionsCollection([
            MediaPickerPreviewAction::make()->driver($this),
            ...$actions->all(),
        ]);
    }

    public function getFileInfoActions(): ActionsCollection
    {
        $actions = parent::getFileInfoActions();

        if (! config('filament-media-library-extensions.media_picker_preview')) {
            return $actions;
        }

        return new ActionsCollection($actions
            ->map(function (Action|ActionGroup $action): Action|ActionGroup {
                if ($action instanceof PreviewAction && ! $action instanceof MediaPickerPreviewAction) {
                    return MediaPickerPreviewAction::make()->driver($this);
                }

                return $action;
            })
            ->all());
    }
}
