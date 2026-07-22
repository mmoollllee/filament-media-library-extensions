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
use RalphJSmit\Filament\Explore\Filament\Forms\Components\FilePicker;
use Throwable;

/**
 * Modal-less picker action consuming the pending inline uploads.
 *
 * The picker's JS uploads dropped/picked files via Livewire's upload API into
 * the picker's pending-uploads state path and then mounts this action — from
 * the field itself or nested inside the picker's selection modal. It
 * authorizes against the driver, validates each file against the picker's
 * effective accepted types (field-level, falling back to the driver's — the
 * FileUpload rules of the FilePond path do not run here) and the driver's max
 * file size, stores valid files via `Driver::createFile()` and selects them:
 * in the field state, or in the selection modal's selection when one is
 * mounted. The `uploadKeys` argument limits consumption to the mounting
 * batch's own files; an optional `folderKey` argument targets a drop-target
 * subfolder — resolved scope-safely through the driver ({@see
 * PickerUploads::resolveDropTargetFolder()}); otherwise files land in the
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

            $uploadKeys = $arguments['uploadKeys'] ?? null;
            $uploadKeys = is_array($uploadKeys)
                ? array_values(array_filter($uploadKeys, fn (mixed $uploadKey): bool => is_string($uploadKey)))
                : null;

            $pendingUploads = PickerUploads::consumePendingUploads(
                $livewire,
                PickerUploads::pendingUploadsStatePath($component),
                $uploadKeys,
            );

            if ($pendingUploads->isEmpty()) {
                return;
            }

            $driver = $component->getDriver();

            $parentSelectAction = PickerUploads::findParentSelectFileAction($livewire);
            [$parentActionNestingIndex, $parentAction] = $parentSelectAction ?? [null, null];

            $scopedFolder = $component->getScopedFolder();
            $targetFolder = $scopedFolder ?? $component->getDefaultFolder();

            if ($parentAction) {
                $targetFolder = PickerUploads::resolveDropTargetFolder(
                    $driver,
                    data_get($livewire, "mountedActions.{$parentActionNestingIndex}.data.files.folder_key"),
                    $scopedFolder,
                ) ?? $targetFolder;
            }

            $targetFolder = PickerUploads::resolveDropTargetFolder($driver, $arguments['folderKey'] ?? null, $scopedFolder)
                ?? $targetFolder;

            // The blade view withholds the upload UI for disabled pickers,
            // but the action itself stays client-mountable — enforce the
            // disabled state server-side like the legacy FilePond action.
            if ($component->isDisabled() || ! $driver->authorize(FileAbility::Create, FileType::File, $targetFolder)->allowed()) {
                $pendingUploads->each(fn (TemporaryUploadedFile $file): ?bool => $file->delete());

                Notification::make()
                    ->title(__('filament-media-library-extensions::actions.inline_upload.unauthorized'))
                    ->danger()
                    ->send();

                return;
            }

            $acceptedFileTypes = PickerUploads::effectiveAcceptedFileTypes($component);
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

                // One failing file (disk error, conversion failure) must not
                // abort the batch — the vendor FilePond path stores each file
                // independently too.
                try {
                    $createdFiles->push($driver->createFile(
                        folder: $targetFolder,
                        temporaryFileUploadData: TemporaryFileUploadData::fromUploadedFile($file),
                    ));
                } catch (Throwable $exception) {
                    report($exception);

                    $rejectedMessages[] = __('filament-media-library-extensions::actions.inline_upload.failed', [
                        'name' => $file->getClientOriginalName(),
                    ]);

                    rescue(fn (): ?bool => $file->delete(), report: false);
                }
            }

            if ($parentAction) {
                PickerUploads::mergeCreatedFilesIntoModalSelection($livewire, $parentActionNestingIndex, $parentAction, $createdFiles);
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
