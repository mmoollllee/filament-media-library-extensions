<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\Explore\Filament\Actions\SelectFileAction;
use RalphJSmit\Filament\Explore\Filament\Forms\Components\FilePicker;

class PickerUploads
{
    /**
     * Livewire property path holding a picker's pending inline uploads,
     * keyed by a client-generated uuid per file. Lives as a sibling of the
     * field inside the form data array (`data.mle_pending_uploads.<field>`),
     * so no schema component owns it: `Schema::getState()` ignores it and it
     * never leaks into saved models. (Inside a Repeater item the whole item
     * state is dehydrated — do not use the inline upload there.)
     */
    public static function pendingUploadsStatePath(FilePicker $component): string
    {
        $segments = explode('.', $component->getStatePath());
        $fieldName = array_pop($segments);

        return implode('.', array_filter([...$segments, 'mle_pending_uploads', $fieldName]));
    }

    /**
     * Consume (read and clear) the pending inline uploads at the given
     * Livewire property path.
     *
     * @return Collection<int, TemporaryUploadedFile>
     */
    public static function consumePendingUploads(Component $livewire, string $pendingUploadsStatePath): Collection
    {
        $pendingUploads = collect(Arr::wrap(data_get($livewire, $pendingUploadsStatePath)))
            ->filter(fn (mixed $file): bool => $file instanceof TemporaryUploadedFile)
            ->values();

        data_set($livewire, $pendingUploadsStatePath, []);

        return $pendingUploads;
    }

    /**
     * Whether a drop-target folder may be used: it must sit inside the
     * picker's scoped folder (or be the scoped folder itself), mirroring the
     * containment check of the original upload action. Visibility is already
     * enforced by resolving the folder through the driver's scoped `findFile()`.
     */
    public static function folderIsWithinScope(FileData $folder, ?FileData $scopedFolder): bool
    {
        if (! $scopedFolder) {
            return true;
        }

        if ($folder->getKey() === $scopedFolder->getKey()) {
            return true;
        }

        return $folder
            ->getAncestorFolders()
            ->contains(fn (FileData $ancestorFolder): bool => $ancestorFolder->getKey() === $scopedFolder->getKey());
    }

    /**
     * Merge freshly created files into the selection state of a mounted
     * selection modal (`mountedActions.{i}.data.files.*`): multi-select modals
     * append to the bulk selection up to `maxFiles`, single-select modals
     * replace the selected file.
     *
     * @param  Collection<int, FileData>  $createdFiles
     */
    public static function mergeCreatedFilesIntoModalSelection(Component $livewire, int $actionNestingIndex, SelectFileAction $selectFileAction, Collection $createdFiles): void
    {
        if ($createdFiles->isEmpty()) {
            return;
        }

        if (! $selectFileAction->allowsMultipleFileSelection()) {
            data_set(
                $livewire,
                "mountedActions.{$actionNestingIndex}.data.files.selected_file_keys",
                [$createdFiles->first()->getKeyHash() => FileType::File->value],
            );

            return;
        }

        $bulkSelectionStatePath = "mountedActions.{$actionNestingIndex}.data.files.bulk_selected_file_keys";
        $selectedFileKeys = (array) data_get($livewire, $bulkSelectionStatePath, []);
        $maxFiles = $selectFileAction->getMaxFiles();

        foreach ($createdFiles as $createdFile) {
            if ($maxFiles && count($selectedFileKeys) >= $maxFiles) {
                break;
            }

            $selectedFileKeys[$createdFile->getKeyHash()] = FileType::File->value;
        }

        data_set($livewire, $bulkSelectionStatePath, $selectedFileKeys);
    }

    /**
     * Merge freshly created files into the picker state: single pickers
     * replace their value, multiple pickers append up to `maxFiles`
     * (the existing selection wins over new uploads).
     *
     * @param  Collection<int, FileData>  $createdFiles
     */
    public static function mergeCreatedFilesIntoState(FilePicker $component, Collection $createdFiles): void
    {
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
    }
}
