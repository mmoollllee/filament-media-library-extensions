<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Drivers\Driver;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\Explore\Filament\Actions\SelectFileAction;
use RalphJSmit\Filament\Explore\Filament\Forms\Components\FilePicker;
use Throwable;

class PickerUploads
{
    /**
     * Livewire property path holding a picker's pending inline uploads,
     * keyed by a client-generated uuid per file. The bucket is anchored at
     * the picker's ROOT schema state path (`data.mle_pending_uploads.<field>`,
     * `mountedActions.0.data.mle_pending_uploads.<field>`, …), so it sits
     * outside every dehydrating subtree: `Schema::getState()` ignores it and
     * it never leaks into saved models — also for pickers nested in Repeater
     * items or Builder blocks (their relative path becomes the bucket name).
     * Numeric path segments (`mountedActions.0…`) are preserved verbatim.
     */
    public static function pendingUploadsStatePath(FilePicker $component): string
    {
        $statePath = $component->getStatePath();
        $rootStatePath = $component->getRootContainer()->getStatePath();

        $relativeStatePath = filled($rootStatePath) && str_starts_with($statePath, "{$rootStatePath}.")
            ? Str::after($statePath, "{$rootStatePath}.")
            : $statePath;

        return implode('.', array_filter(
            [$rootStatePath, 'mle_pending_uploads', str_replace('.', '_', $relativeStatePath)],
            fn (string $segment): bool => $segment !== '',
        ));
    }

    /**
     * Consume (read and clear) pending inline uploads at the given Livewire
     * property path. When `$onlyUploadKeys` is given, only those entries are
     * consumed — a settling upload batch must not sweep files of a
     * concurrently uploading batch (which may target another folder).
     *
     * @param  list<string>|null  $onlyUploadKeys
     * @return Collection<int, TemporaryUploadedFile>
     */
    public static function consumePendingUploads(Component $livewire, string $pendingUploadsStatePath, ?array $onlyUploadKeys = null): Collection
    {
        $allPendingUploads = collect(Arr::wrap(data_get($livewire, $pendingUploadsStatePath)));

        $consumedUploads = $allPendingUploads
            ->filter(fn (mixed $file, int|string $uploadKey): bool => $file instanceof TemporaryUploadedFile
                && ($onlyUploadKeys === null || in_array((string) $uploadKey, $onlyUploadKeys, true)));

        data_set(
            $livewire,
            $pendingUploadsStatePath,
            $onlyUploadKeys === null ? [] : $allPendingUploads->except($consumedUploads->keys())->all(),
        );

        return $consumedUploads->values();
    }

    /**
     * Nearest `SelectFileAction` below the currently running action on the
     * mounted-action stack — robust against additional modals (preview, move,
     * file info) mounted in between.
     *
     * @return array{0: int, 1: SelectFileAction}|null
     */
    public static function findParentSelectFileAction(Component $livewire): ?array
    {
        $mountedActions = $livewire->getMountedActions();

        for ($index = count($mountedActions) - 2; $index >= 0; $index--) {
            if ($mountedActions[$index] instanceof SelectFileAction) {
                return [$index, $mountedActions[$index]];
            }
        }

        return null;
    }

    /**
     * Resolve a client-supplied drop-target folder key scope-safely: through
     * the driver's scoped `findFile()` (visibility) and constrained to the
     * picker's scoped folder. Malformed keys (the vendor driver throws for
     * wrong-type or unparseable keys instead of returning null) resolve to
     * null — the caller falls back to its default target.
     */
    public static function resolveDropTargetFolder(Driver $driver, mixed $folderKey, ?FileData $scopedFolder): ?FileData
    {
        if (! is_string($folderKey) || blank($folderKey)) {
            return null;
        }

        try {
            $folder = $driver->findFile(FileType::Folder, $folderKey);
        } catch (Throwable) {
            return null;
        }

        if (! $folder || ! static::folderIsWithinScope($folder, $scopedFolder)) {
            return null;
        }

        return $folder;
    }

    /**
     * Accepted mimetype patterns effectively governing a picker's uploads:
     * the field-level types, or — matching the vendor `UploadAction` — the
     * driver's types when the field defines none.
     *
     * @return Collection<int, string>
     */
    public static function effectiveAcceptedFileTypes(FilePicker $component): Collection
    {
        $acceptedFileTypes = $component->getAcceptedFileTypes();

        return $acceptedFileTypes->isNotEmpty()
            ? $acceptedFileTypes
            : $component->getDriver()->getAcceptedFileTypes();
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
