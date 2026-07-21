<?php

declare(strict_types=1);

namespace Mmoollllee\FilamentMediaLibraryExtensions\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use RalphJSmit\Filament\Explore\Data\FileData;
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
