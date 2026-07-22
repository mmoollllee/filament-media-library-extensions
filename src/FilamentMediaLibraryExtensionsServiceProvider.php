<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions;

use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions\MediaPickerPreviewAction;
use Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions\MediaPickerUploadAction;
use Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions\ProcessInlineUploadsAction;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\Assets\ContentVersionedCss;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\Assets\ContentVersionedJs;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\CreatedFilesCollector;
use Mmoollllee\FilamentMediaLibraryExtensions\Support\PickerUploads;
use RalphJSmit\Filament\Explore\Authorization\FileAbility;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Drivers\Driver;
use RalphJSmit\Filament\Explore\Enums\FileType;
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
            ContentVersionedJs::make('filament-media-library-extensions', __DIR__.'/../resources/js/filament-media-library-extensions.js'),
            ContentVersionedCss::make('filament-media-library-extensions', __DIR__.'/../resources/css/filament-media-library-extensions.css'),
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
                            ->driver(fn (): Driver => $component->getDriver()),
                    ]);
                } else {
                    $component->registerActions([
                        fn (MediaPicker $component): MediaPickerUploadAction => MediaPickerUploadAction::make()
                            ->driver(fn (): Driver => $component->getDriver())
                            ->folder(fn (): ?FileData => $component->getScopedFolder() ?? $component->getDefaultFolder())
                            ->acceptedFileTypes(fn (): Collection => $component->getAcceptedFileTypes())
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
     * gets the trigger marker: the package JS intercepts trigger clicks in
     * the capture phase and — inside a zone carrying an inline upload
     * config — opens the native file dialog instead. Without a config (media
     * library page) or without the JS asset (no-JS, unpublished assets) the
     * regular `wire:click` mounts the original FilePond modal, which stays
     * the server-side fallback. The upload modal becomes a drop target, and
     * uploads inside a selection modal are auto-added to its selection.
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

                $livewire = $action->getLivewire();

                // Absolute mounted-action paths work for both the topbar and
                // the empty-state upload button (relative Get/Set would
                // resolve wrongly inside the empty state's own state path).
                $parentSelectAction = PickerUploads::findParentSelectFileAction($livewire);

                if (! $parentSelectAction) {
                    return;
                }

                [$parentActionNestingIndex, $parentAction] = $parentSelectAction;

                PickerUploads::mergeCreatedFilesIntoModalSelection($livewire, $parentActionNestingIndex, $parentAction, $createdFiles);
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

                    // Mirror the field view's gate: users who may not create
                    // files get no drop/upload affordance — otherwise every
                    // dropped byte uploads before the server denies it.
                    if (
                        $picker->isDisabled()
                        || ! $picker
                            ->getDriver()
                            ->authorize(FileAbility::Create, FileType::File, $picker->getScopedFolder() ?? $picker->getDefaultFolder())
                            ->allowed()
                    ) {
                        return [];
                    }

                    // Base64: modal window attributes render unescaped — raw
                    // JSON quotes would tear the attribute apart.
                    return [
                        'data-mle-inline-modal' => base64_encode(json_encode([
                            'uploadPath' => PickerUploads::pendingUploadsStatePath($picker),
                            'processName' => ProcessInlineUploadsAction::getDefaultName(),
                            'processContext' => ['schemaComponent' => $picker->getKey()],
                            'accept' => PickerUploads::effectiveAcceptedFileTypes($picker)->implode(','),
                        ])),
                    ];
                }, merge: true);
            }
        });
    }
}
