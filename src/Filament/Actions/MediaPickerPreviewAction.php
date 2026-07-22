<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions;

use Filament\Actions\Action as BaseAction;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Components\Image;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use RalphJSmit\Filament\Explore\Data\FileData;
use RalphJSmit\Filament\Explore\Filament\Actions\PreviewAction;
use RalphJSmit\Filament\Explore\Filament\Forms\Components\FilePicker as ExploreFilePicker;
use RalphJSmit\Filament\MediaLibrary\Models\MediaLibraryItem;

/**
 * Preview action for media library files that renders PDFs in an iframe,
 * resolves file URLs through the Spatie URL generator (so private disks with a
 * policy-guarded serve route keep working), and offers previous/next
 * navigation (buttons + arrow keys) between the sibling files of the
 * owning `FilePicker` field.
 */
class MediaPickerPreviewAction extends PreviewAction
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->modalHeading(fn (FileData $file): string => $file->getDisplayName())
            ->extraModalWindowAttributes([
                'x-on:keydown.arrow-left.window.prevent.stop' => "\$el.querySelector('[data-media-picker-nav=previous]')?.click()",
                'x-on:keydown.arrow-right.window.prevent.stop' => "\$el.querySelector('[data-media-picker-nav=next]')?.click()",
            ], merge: true)
            ->schema(function (FileData $file): ?array {
                if ($this->isPdfFile($file)) {
                    $url = $this->resolveDirectFileUrl($file);

                    if (! $url) {
                        return null;
                    }

                    return [
                        View::make('filament-media-library-extensions::pdf-preview')
                            ->viewData([
                                'url' => $url,
                                'title' => $file->getDisplayName(),
                            ]),
                    ];
                }

                $imageGenerator = $file->getImageGenerator();

                if (! $imageGenerator) {
                    return null;
                }

                return [
                    Image::make(
                        url: $imageGenerator->getUrl($file, 848 * 2),
                        alt: $file->getDisplayName(),
                    )
                        ->imageHeight('auto')
                        ->imageWidth('100%'),
                ];
            })
            ->extraModalFooterActions(function (self $action, FileData $file): array {
                $actions = [];

                $directUrl = $this->resolveDirectFileUrl($file);

                if ($directUrl) {
                    $actions[] = BaseAction::make('open_in_new_tab')
                        ->label(__('filament-explore::filament/actions.preview_action.extra_modal_footer_actions.preview.label'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url($directUrl, shouldOpenInNewTab: true);
                }

                $actions[] = $action->makeNavigationAction(-1);
                $actions[] = $action->makeNavigationAction(1);

                return $actions;
            })
            ->url(fn (FileData $file): ?string => $this->shouldShowDirectFileLink($file) ? $this->resolveDirectFileUrl($file) : null)
            ->openUrlInNewTab(fn (FileData $file): bool => $this->shouldShowDirectFileLink($file));
    }

    protected function makeNavigationAction(int $offset): BaseAction
    {
        $isPrevious = $offset < 0;

        return BaseAction::make($isPrevious ? 'previous_file' : 'next_file')
            ->label(__($isPrevious
                ? 'filament-media-library-extensions::actions.media_picker_preview.previous_label'
                : 'filament-media-library-extensions::actions.media_picker_preview.next_label'))
            ->icon($isPrevious ? Heroicon::OutlinedChevronLeft : Heroicon::OutlinedChevronRight)
            ->iconPosition($isPrevious ? IconPosition::Before : IconPosition::After)
            ->color('gray')
            ->extraAttributes([
                'data-media-picker-nav' => $isPrevious ? 'previous' : 'next',
            ])
            ->disabled(function (BaseAction $action) use ($offset): bool {
                $parentAction = $action->getParentAction();

                if (! $parentAction instanceof self) {
                    return true;
                }

                return blank($parentAction->resolveSiblingFileKeyByOffset($offset));
            })
            ->action(function (BaseAction $action) use ($offset): void {
                $parentAction = $action->getParentAction();

                if (! $parentAction instanceof self) {
                    return;
                }

                $targetFileKey = $parentAction->resolveSiblingFileKeyByOffset($offset);

                if (blank($targetFileKey)) {
                    return;
                }

                $livewire = $action->getLivewire();

                // `replaceMountedAction()` resets the ENTIRE mounted stack.
                // When the preview sits on top of another modal (the picker's
                // selection modal, or a picker rendered inside an action's
                // form), that would close the parent and discard its input —
                // pop only the preview instead and mount the sibling on top.
                if (count($livewire->getMountedActions()) > 1) {
                    $livewire->unmountAction(cancelParentActions: false);
                    $livewire->mountAction(
                        $parentAction->getName(),
                        ['fileKey' => $targetFileKey],
                        $parentAction->getContext(),
                    );

                    return;
                }

                $livewire->replaceMountedAction(
                    $parentAction->getName(),
                    ['fileKey' => $targetFileKey],
                    $parentAction->getContext(),
                );
            });
    }

    protected function resolveSiblingFileKeyByOffset(int $offset): ?string
    {
        $file = $this->getFile();

        if (! $file) {
            return null;
        }

        [$previousFileKey, $nextFileKey] = $this->resolveSiblingFileKeys($file);

        return $offset < 0 ? $previousFileKey : $nextFileKey;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    protected function resolveSiblingFileKeys(FileData $file): array
    {
        $fileKeys = $this->resolveCurrentPickerFileKeys();
        $fileKeysCount = count($fileKeys);

        if ($fileKeysCount === 0) {
            return [null, null];
        }

        $currentIndex = array_search($file->getKey(), $fileKeys, true);

        if ($currentIndex === false) {
            return [null, null];
        }

        if ($fileKeysCount < 2) {
            return [null, null];
        }

        $previousIndex = ($currentIndex - 1 + $fileKeysCount) % $fileKeysCount;
        $nextIndex = ($currentIndex + 1) % $fileKeysCount;

        return [
            $fileKeys[$previousIndex] ?? null,
            $fileKeys[$nextIndex] ?? null,
        ];
    }

    /**
     * @return array<string>
     */
    protected function resolveCurrentPickerFileKeys(): array
    {
        $filePicker = $this->resolveOwningFilePicker();

        if (! $filePicker) {
            return [];
        }

        return $filePicker
            ->getFiles()
            ->map(fn (FileData $file): string => $file->getKey())
            ->values()
            ->all();
    }

    protected function resolveOwningFilePicker(): ?ExploreFilePicker
    {
        $component = $this->getSchemaComponent();

        while ($component instanceof SchemaComponent) {
            if ($component instanceof ExploreFilePicker) {
                return $component;
            }

            $component = $component->getContainer()->getParentComponent();
        }

        return null;
    }

    protected function isPdfFile(FileData $file): bool
    {
        return Str::lower($file->getExtension()) === 'pdf' || $file->getMimeType() === 'application/pdf';
    }

    protected function shouldShowDirectFileLink(FileData $file): bool
    {
        return ! $this->isPdfFile($file) && ! $file->getImageGenerator();
    }

    /**
     * URL via the Spatie URL generator: private disks may expose no direct disk
     * URLs — the generator then yields e.g. a policy-guarded serve route,
     * while public disks keep their regular URLs.
     */
    protected function resolveDirectFileUrl(FileData $file): ?string
    {
        $source = $file->getSource();

        if (! $source instanceof MediaLibraryItem) {
            return null;
        }

        return $source->getFirstMedia($source->getMediaLibraryCollectionName())?->getUrl();
    }
}
