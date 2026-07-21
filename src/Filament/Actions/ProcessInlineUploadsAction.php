<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions;

use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
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

/**
 * Modal-less picker action consuming the pending inline uploads.
 *
 * The field's JS uploads dropped/picked files via Livewire's upload API into
 * the picker's pending-uploads state path and then mounts this action. It
 * authorizes against the driver, validates each file against the field's
 * accepted types and the driver's max file size (the FileUpload rules of the
 * modal path do not run here), stores valid files via `Driver::createFile()`
 * and merges them into the picker selection. It is never rendered as a
 * button, so all safety checks live inside the action itself.
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

        $this->action(function (FilePicker $component, self $action): void {
            $livewire = $action->getLivewire();
            $pendingUploadsStatePath = PickerUploads::pendingUploadsStatePath($component);

            $pendingUploads = collect(Arr::wrap(data_get($livewire, $pendingUploadsStatePath)))
                ->filter(fn (mixed $file): bool => $file instanceof TemporaryUploadedFile);

            data_set($livewire, $pendingUploadsStatePath, []);

            if ($pendingUploads->isEmpty()) {
                return;
            }

            $driver = $component->getDriver();
            $folder = $component->getScopedFolder() ?? $component->getDefaultFolder();

            if (! $driver->authorize(FileAbility::Create, FileType::File, $folder)->allowed()) {
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
                    folder: $folder,
                    temporaryFileUploadData: TemporaryFileUploadData::fromUploadedFile($file),
                ));
            }

            PickerUploads::mergeCreatedFilesIntoState($component, $createdFiles);

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
