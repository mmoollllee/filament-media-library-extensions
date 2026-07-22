{{--
    Copy of `filament-explore::filament.forms.components.file-picker`
    (ralphjsmit/laravel-filament-explore v1.1.2), extended with the `upload`
    action rendered next to the select action. Re-diff against the original
    view when updating the explore package.
--}}
@php
    use Illuminate\View\ComponentAttributeBag;
    use Mmoollllee\FilamentMediaLibraryExtensions\Filament\Actions\ProcessInlineUploadsAction;
    use Mmoollllee\FilamentMediaLibraryExtensions\Support\PickerUploads;
    use RalphJSmit\Filament\Explore\Authorization\FileAbility;
    use RalphJSmit\Filament\Explore\Enums\FileType;
    use RalphJSmit\Filament\Explore\Filament\Schemas\Components\File\Version;
@endphp
<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $statePath = $getStatePath();
        $state = $getState();
        $version = $getVersion();
        $isBulkSelectable = $isBulkSelectable();
        $isReorderable = $isReorderable();
        $isMultiple = $isMultiple();
        $isDisabled = $isDisabled();

        $mleInlineFieldConfig = null;

        if (
            config('filament-media-library-extensions.inline_upload')
            && config('filament-media-library-extensions.upload_button')
            && ! $isDisabled
            && $field
                ->getDriver()
                ->authorize(FileAbility::Create, FileType::File, $field->getScopedFolder() ?? $field->getDefaultFolder())
                ->allowed()
        ) {
            // Base64: extra attributes render unescaped — raw JSON quotes
            // would tear the attribute apart.
            $mleInlineFieldConfig = base64_encode(json_encode([
                'uploadPath' => PickerUploads::pendingUploadsStatePath($field),
                'processName' => ProcessInlineUploadsAction::getDefaultName(),
                'processContext' => ['schemaComponent' => $field->getKey()],
                'accept' => PickerUploads::effectiveAcceptedFileTypes($field)->implode(','),
            ]));
        }
    @endphp
    <div
        wire:ignore.self
        wire:key="{{ $key = $getId() }}-alpine-component"
        x-load
        x-load-src="{{ \Filament\Support\Facades\FilamentAsset::getAlpineComponentSrc('file-picker-js', 'filament-explore') }}"
        x-data="filePicker({
            state: $wire.{{ $applyStateBindingModifiers("\$entangle('{$statePath}')") }},
            key: {{ \Illuminate\Support\Js::from($key) }},
            isBulkSelectable: {{ \Illuminate\Support\Js::from($isBulkSelectable) }},
        })"
        {{ $getExtraAttributeBag()->merge(filled($mleInlineFieldConfig) ? ['data-mle-inline-field' => $mleInlineFieldConfig] : []) }}
    >
        @if ($isBulkSelectable && $state)
            <div class="flex flex-row flex-wrap items-center justify-between gap-y-1">
                <div class="flex flex-row items-center gap-2">
                    <p
                        class="text-xs font-medium text-gray-500 uppercase"
                        x-text="Object.keys(bulkSelectedFileKeys || {}).length + ' {{ __('filament-explore::filament/forms/components/file-picker.bulk_selection_indicator.selected_count_suffix') }}'"
                    >
                        <!-- -->
                    </p>
                </div>
                <div class="flex flex-row gap-x-2">
                    <x-filament::link
                        color="gray"
                        tag="button"
                        x-on:click="bulkSelectAllFiles()"
                        x-show="Object.keys(bulkSelectedFileKeys || {}).length === 0"
                    >
                        {{ __('filament-explore::filament/forms/components/file-picker.bulk_selection_indicator.actions.select_all.label') }}
                    </x-filament::link>

                    <x-filament::link
                        color="primary"
                        tag="button"
                        x-on:click="bulkSelectAllFiles()"
                        x-show="Object.keys(bulkSelectedFileKeys || {}).length > 0"
                        x-cloak
                    >
                        {{ __('filament-explore::filament/forms/components/file-picker.bulk_selection_indicator.actions.select_all.label') }}
                    </x-filament::link>

                    <x-filament::link
                        color="danger"
                        tag="button"
                        x-show="Object.keys(bulkSelectedFileKeys || {}).length > 0"
                        x-on:click="bulkDeselectAllFiles()"
                        x-cloak
                    >
                        {{ __('filament-explore::filament/forms/components/file-picker.bulk_selection_indicator.actions.deselect_all.label') }}
                    </x-filament::link>
                </div>
            </div>
        @endif

        <div
            x-show="state != null"
            x-ref="files"
            class="mt-2"
        >
            {{ $getChildSchema($field::FILES_SCHEMA_KEY) }}
        </div>
        <p
            class="text-gray-500"
            x-show="state == null || (Array.isArray(state) && state.length === 0)"
            x-cloak
        >
            {{ $getEmptyStateHeading() }}
        </p>

        @if ($isBulkSelectable && $state)
            <div
                x-show="bulkSelectedFileKeys && Object.keys(bulkSelectedFileKeys).length > 0"
                x-cloak
                class="mt-4 px-3 py-2 border border-dashed border-gray-300 dark:border-gray-700 rounded-lg"
                x-data="{
                    bulkSelectedFileKeysFile: {},

                    init: function () {
                        this.updateBulkSelectedFileKeysFile()

                        $watch('bulkSelectedFileKeys', (value) => {
                            if (! value) {
                                return;
                            }

                            if (Object.keys(value).length === 0) {
                                setTimeout(() => {
                                    this.bulkSelectedFileKeysFile = {};
                                }, 75);

                                return;
                            }

                            this.updateBulkSelectedFileKeysFile();
                        });
                    },

                    updateBulkSelectedFileKeysFile: function () {
                        if (! this.bulkSelectedFileKeys) {
                            return;
                        }

                        this.bulkSelectedFileKeysFile = Object.fromEntries(
                            Object.entries(this.bulkSelectedFileKeys).filter(([key, type]) => type === {{ \Illuminate\Support\Js::from(FileType::File->value) }})
                        );
                    },

                    get bulkSelectedFileKeysCount() {
                        return Object.keys(this.bulkSelectedFileKeysFile).length;
                    },

                    get getActionArguments() {
                        return {
                            bulkSelectedFileKeys: this.bulkSelectedFileKeys,
                        }
                    }
                }"
            >
                <div class="flex flex-row items-center justify-between gap-x-4">
                    <p class="text-gray-500">
                        <span x-text="bulkSelectedFileKeysCount"></span>
                        <span x-show="bulkSelectedFileKeysCount === 1">
                            {{ trans_choice('filament-explore::filament/forms/components/file-picker.bulk_selection.file_selected', 1) }}
                        </span>
                        <span x-show="bulkSelectedFileKeysCount > 1">
                            {{ trans_choice('filament-explore::filament/forms/components/file-picker.bulk_selection.file_selected', 2) }}
                        </span>
                    </p>
                    <div class="flex flex-row items-center gap-x-2">
                        {{ $getChildSchema($field::BULK_ACTIONS_SCHEMA_KEY) }}
                    </div>
                </div>
            </div>
        @endif

        @php
            $selectFileAction = $getAction('select_file');
            $uploadAction = $getAction('upload');
            $clearAction = $getAction('clear');
        @endphp

        @if (filled($mleInlineFieldConfig))
            {{-- Fallback host for upload ghost tiles when the picker shows no files yet. --}}
            <div
                class="mle-inline-uploads mt-4"
                data-mle-ghost-fallback
                wire:ignore
            ></div>
        @endif

        <div class="mt-4 flex flex-row gap-4">
            @if ($selectFileAction->isVisible())
                {{ $selectFileAction }}
            @endif

            @if (filled($mleInlineFieldConfig))
                <x-filament::button
                    color="gray"
                    type="button"
                    data-mle-inline-open
                >
                    {{ __('filament-media-library-extensions::actions.media_picker_upload.label') }}
                </x-filament::button>
            @elseif ($uploadAction?->isVisible())
                {{ $uploadAction }}
            @endif

            @if ($clearAction->isVisible())
                {{ $clearAction }}
            @endif
        </div>
    </div>
</x-dynamic-component>
