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
use Mmoollllee\FilamentMediaLibraryExtensions\Support\CreatedFilesCollector;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Enums\FileType;
use RalphJSmit\Filament\Explore\Filament\Actions\SelectFileAction;
use RalphJSmit\Filament\Explore\Filament\Actions\UploadAction;
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
                $component
                    ->view('filament-media-library-extensions::filament.forms.components.media-picker')
                    ->registerActions([
                        fn (MediaPicker $component): MediaPickerUploadAction => MediaPickerUploadAction::make()
                            ->driver(fn () => $component->getDriver())
                            ->folder(fn (): ?FileData => $component->getScopedFolder() ?? $component->getDefaultFolder())
                            ->acceptedFileTypes(fn () => $component->getAcceptedFileTypes())
                            ->visible(fn (): bool => ! $component->isDisabled()),
                    ]);

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
     * auto-added to the modal's selection.
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
     * The selection modal window becomes a drop target: dropped files open the
     * topbar upload action and land in its FilePond field.
     */
    protected function configureSelectMediaAction(): void
    {
        SelectMediaAction::configureUsing(function (SelectMediaAction $action): void {
            if (! config('filament-media-library-extensions.dropzone')) {
                return;
            }

            $action->extraModalWindowAttributes(['data-mle-dropzone' => 'select-modal'], merge: true);
        });
    }
}
