<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions;

use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\PickerUploads;
use RalphJSmit\Filament\Explore\Authorization\FileAbility;
use RalphJSmit\Filament\Explore\Data\TemporaryFileUploadData;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\Explore\Filament\Actions\Action;
use RalphJSmit\Filament\Explore\Filament\Actions\SelectFileAction;
use RalphJSmit\Filament\Explore\Filament\Forms\Components\FilePicker;

/**
 * Modal-less picker action consuming the pending inline uploads.
 *
 * The picker's JS uploads dropped/picked files via Livewire's upload API into
 * the picker's pending-uploads state path and then mounts this action — from
 * the field itself or nested inside the picker's selection modal. It
 * authorizes against the driver, validates each file against the field's
 * accepted types and the driver's max file size (the FileUpload rules of the
 * FilePond path do not run here), stores valid files via `Driver::createFile()`
 * and selects them: in the field state, or in the selection modal's selection
 * when one is mounted. An optional `folderKey` argument targets a drop-target
 * subfolder — resolved through the driver's scoped `findFile()` (visibility)
 * and constrained to the picker's scoped folder; otherwise files land in the
 * modal's current folder or the picker's scoped/default folder. It is never
 * rendered as a button, so all safety checks live inside the action itself.
 */
class ProcessInlineUploadsAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'process_inline_uploads';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->action(function (FilePicker $component, self $action, array $arguments): void {
            $livewire = $action->getLivewire();

            $pendingUploads = PickerUploads::consumePendingUploads(
                $livewire,
                PickerUploads::pendingUploadsStatePath($component),
            );

            if ($pendingUploads->isEmpty()) {
                return;
            }

            $driver = $component->getDriver();

            $mountedActions = $livewire->getMountedActions();
            $parentActionNestingIndex = count($mountedActions) - 2;
            $parentSelectAction = ($mountedActions[$parentActionNestingIndex] ?? null) instanceof SelectFileAction
                ? $mountedActions[$parentActionNestingIndex]
                : null;

            $scopedFolder = $component->getScopedFolder();
            $targetFolder = $scopedFolder ?? $component->getDefaultFolder();

            if ($parentSelectAction) {
                $currentFolderKey = data_get($livewire, "mountedActions.{$parentActionNestingIndex}.data.files.folder_key");
                $currentFolder = $currentFolderKey ? $driver->findFile(FileType::Folder, $currentFolderKey) : null;

                if ($currentFolder && PickerUploads::folderIsWithinScope($currentFolder, $scopedFolder)) {
                    $targetFolder = $currentFolder;
                }
            }

            $argumentFolderKey = $arguments['folderKey'] ?? null;

            if (filled($argumentFolderKey) && is_string($argumentFolderKey)) {
                $argumentFolder = $driver->findFile(FileType::Folder, $argumentFolderKey);

                if ($argumentFolder && PickerUploads::folderIsWithinScope($argumentFolder, $scopedFolder)) {
                    $targetFolder = $argumentFolder;
                }
            }

            if (! $driver->authorize(FileAbility::Create, FileType::File, $targetFolder)->allowed()) {
                $pendingUploads->each(fn (TemporaryUploadedFile $file) => $file->delete());

                Notification::make()
                    ->title(__('filament-media-library-extensions::actions.inline_upload.unauthorized'))
                    ->danger()
                    ->send();

                return;
            }

            $acceptedFileTypes = $component->getAcceptedFileTypes();
            $maxFileSizeKb = $driver->getMaxFileSizeKb();

            $createdFiles = collect();
            $rejectedMessages = [];

            foreach ($pendingUploads as $file) {
                if (! static::matchesAcceptedFileTypes($file, $acceptedFileTypes)) {
                    $rejectedMessages[] = __('filament-media-library-extensions::actions.inline_upload.rejected_type', [
                        'name' => $file->getClientOriginalName(),
                    ]);

                    $file->delete();

                    continue;
                }

                if ($maxFileSizeKb && ($file->getSize() > $maxFileSizeKb * 1024)) {
                    $rejectedMessages[] = __('filament-media-library-extensions::actions.inline_upload.rejected_size', [
                        'name' => $file->getClientOriginalName(),
                        'max' => Number::fileSize($maxFileSizeKb * 1024),
                    ]);

                    $file->delete();

                    continue;
                }

                $createdFiles->push($driver->createFile(
                    folder: $targetFolder,
                    temporaryFileUploadData: TemporaryFileUploadData::fromUploadedFile($file),
                ));
            }

            if ($parentSelectAction) {
                PickerUploads::mergeCreatedFilesIntoModalSelection($livewire, $parentActionNestingIndex, $parentSelectAction, $createdFiles);
            } else {
                PickerUploads::mergeCreatedFilesIntoState($component, $createdFiles);
            }

            if ($createdFiles->isNotEmpty()) {
                Notification::make()
                    ->title(trans_choice('filament-media-library-extensions::actions.inline_upload.uploaded', $createdFiles->count()))
                    ->success()
                    ->send();
            }

            foreach ($rejectedMessages as $rejectedMessage) {
                Notification::make()
                    ->title($rejectedMessage)
                    ->danger()
                    ->send();
            }
        });
    }

    /**
     * @param  Collection<int, string>  $acceptedFileTypes
     */
    protected static function matchesAcceptedFileTypes(TemporaryUploadedFile $file, Collection $acceptedFileTypes): bool
    {
        if ($acceptedFileTypes->isEmpty()) {
            return true;
        }

        $mimeType = $file->getMimeType();

        foreach ($acceptedFileTypes as $acceptedFileType) {
            if (Str::is($acceptedFileType, $mimeType)) {
                return true;
            }
        }

        return false;
    }
}
