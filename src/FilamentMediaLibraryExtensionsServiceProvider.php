<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions;

use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;
use Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions\MediaPickerPreviewAction;
use Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions\MediaPickerUploadAction;
use Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions\ProcessInlineUploadsAction;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\CreatedFilesCollector;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\PickerUploads;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\Explore\Filament\Actions\SelectFileAction;
use RalphJSmit\Filament\Explore\Filament\Actions\UploadAction;
use RalphJSmit\Filament\Explore\Filament\Forms\Components\FilePicker;
use RalphJSmit\Filament\MediaLibrary\Filament\Actions\SelectMediaAction;
use RalphJSmit\Filament\MediaLibrary\Filament\Forms\Components\MediaPicker;

class FilamentMediaLibraryExtensionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/filament-media-library-extensions.php',
            'filament-media-library-extensions',
        );

        $this->app->scoped(CreatedFilesCollector::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-media-library-extensions');
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-media-library-extensions');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filament-media-library-extensions.php' => config_path('filament-media-library-extensions.php'),
            ], 'filament-media-library-extensions-config');
        }

        FilamentAsset::register([
            Js::make('filament-media-library-extensions', __DIR__.'/../resources/js/filament-media-library-extensions.js'),
            Css::make('filament-media-library-extensions', __DIR__.'/../resources/css/filament-media-library-extensions.css'),
        ], package: 'mmoollllee/filament-media-library-extensions');

        $this->configureMediaPicker();
        $this->configureUploadAction();
        $this->configureSelectMediaAction();
    }

    /**
     * Field-level extensions: the extended preview on file tiles, the "Upload
     * files" action next to "Choose files" (rendered by the package view),
     * and the drag-and-drop marker attribute for the drop zone script.
     */
    protected function configureMediaPicker(): void
    {
        MediaPicker::configureUsing(function (MediaPicker $component): void {
            if (config('filament-media-library-extensions.media_picker_preview')) {
                $component->modifyPreviewActionUsing(fn (): MediaPickerPreviewAction => MediaPickerPreviewAction::make());
            }

            if (config('filament-media-library-extensions.upload_button')) {
                $component->view('filament-media-library-extensions::filament.forms.components.media-picker');

                if (config('filament-media-library-extensions.inline_upload')) {
                    $component->registerActions([
                        fn (MediaPicker $component): ProcessInlineUploadsAction => ProcessInlineUploadsAction::make()
                            ->driver(fn () => $component->getDriver()),
                    ]);
                } else {
                    $component->registerActions([
                        fn (MediaPicker $component): MediaPickerUploadAction => MediaPickerUploadAction::make()
                            ->driver(fn () => $component->getDriver())
                            ->folder(fn (): ?FileData => $component->getScopedFolder() ?? $component->getDefaultFolder())
                            ->acceptedFileTypes(fn () => $component->getAcceptedFileTypes())
                            ->visible(fn (): bool => ! $component->isDisabled()),
                    ]);
                }

                if (config('filament-media-library-extensions.dropzone')) {
                    $component->extraAttributes(['data-mle-dropzone' => 'media-picker'], merge: true);
                }
            }
        });
    }

    /**
     * Every upload action (field, selection modal topbar, media library page)
     * gets the trigger marker the drop zone script clicks, its modal becomes
     * a drop target itself, and uploads inside a selection modal are
     * auto-added to the modal's selection. With `inline_upload` enabled the
     * button click is taken over client-side: inside a selection modal it
     * opens the native file dialog and uploads inline (FilePond stays
     * untouched as the no-JS/server fallback); elsewhere (e.g. the media
     * library page) the JS falls back to mounting the original modal.
     */
    protected function configureUploadAction(): void
    {
        UploadAction::configureUsing(function (UploadAction $action): void {
            $action->extraAttributes(['data-mle-upload-trigger' => 'true'], merge: true);

            if (config('filament-media-library-extensions.dropzone')) {
                $action->extraModalWindowAttributes(['data-mle-dropzone' => 'upload-modal'], merge: true);
            }

            if ($action instanceof MediaPickerUploadAction) {
                // The field action merges uploads into the field state itself.
                return;
            }

            if (
                config('filament-media-library-extensions.inline_upload')
                && config('filament-media-library-extensions.upload_button')
            ) {
                $action
                    ->alpineClickHandler('window.mleUploadTriggerClicked($event)')
                    ->extraAttributes(fn (UploadAction $action): array => [
                        'data-mle-mount-context' => json_encode($action->getContext()),
                    ], merge: true);
            }

            $action->after(function (UploadAction $action): void {
                if (! config('filament-media-library-extensions.auto_select_uploads')) {
                    return;
                }

                $createdFiles = app(CreatedFilesCollector::class)->consume();

                if ($createdFiles->isEmpty()) {
                    return;
                }

                $mountedActions = $action->getLivewire()->getMountedActions();
                $parentAction = count($mountedActions) >= 2 ? $mountedActions[count($mountedActions) - 2] : null;

                if (! $parentAction instanceof SelectFileAction) {
                    return;
                }

                $schemaComponent = $action->getSchemaComponent();

                if (! $schemaComponent) {
                    return;
                }

                $schemaComponent->evaluate(function (Get $get, Set $set) use ($createdFiles, $parentAction): void {
                    if (! $parentAction->allowsMultipleFileSelection()) {
                        $set(
                            'files.selected_file_keys',
                            [$createdFiles->first()->getKeyHash() => FileType::File->value],
                            shouldCallUpdatedHooks: true,
                        );

                        return;
                    }

                    $selectedFileKeys = $get('files.bulk_selected_file_keys') ?? [];
                    $maxFiles = $parentAction->getMaxFiles();

                    foreach ($createdFiles as $createdFile) {
                        if ($maxFiles && count($selectedFileKeys) >= $maxFiles) {
                            break;
                        }

                        $selectedFileKeys[$createdFile->getKeyHash()] = FileType::File->value;
                    }

                    $set('files.bulk_selected_file_keys', $selectedFileKeys, shouldCallUpdatedHooks: true);
                });
            });
        });
    }

    /**
     * The selection modal window becomes a drop target. With `inline_upload`
     * enabled it carries the inline upload config (pending path, process
     * action context, accepted types of the owning picker): drops and the
     * topbar button then upload inline with progress tiles instead of
     * opening the FilePond modal — including drops onto folder tiles,
     * which target that subfolder.
     */
    protected function configureSelectMediaAction(): void
    {
        SelectMediaAction::configureUsing(function (SelectMediaAction $action): void {
            if (config('filament-media-library-extensions.dropzone')) {
                $action->extraModalWindowAttributes(['data-mle-dropzone' => 'select-modal'], merge: true);
            }

            if (
                config('filament-media-library-extensions.inline_upload')
                && config('filament-media-library-extensions.upload_button')
            ) {
                $action->extraModalWindowAttributes(function (SelectMediaAction $action): array {
                    $picker = $action->getSchemaComponent();

                    if (! $picker instanceof FilePicker) {
                        return [];
                    }

                    return [
                        'data-mle-inline-modal' => json_encode([
                            'uploadPath' => PickerUploads::pendingUploadsStatePath($picker),
                            'processName' => ProcessInlineUploadsAction::getDefaultName(),
                            'processContext' => ['schemaComponent' => $picker->getKey()],
                            'accept' => $picker->getAcceptedFileTypes()->implode(','),
                        ]),
                    ];
                }, merge: true);
            }
        });
    }
}
