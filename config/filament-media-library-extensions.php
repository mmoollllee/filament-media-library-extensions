<?php

declare(strict_types=1);

return [
    /*
     * Registers an "Upload files" action on every MediaPicker field, next to the
     * "Choose files" button, and swaps the field view for the package view that
     * renders it. Uploads reuse the original media library upload modal
     * (Filament FileUpload / FilePond) with the field's driver, folder
     * and accepted file types.
     */
    'upload_button' => true,

    /*
     * Uploads directly on the MediaPicker field bypass the FilePond modal
     * entirely: the "Upload files" button opens the native file dialog,
     * dropped/picked files upload via Livewire's JS upload API with inline
     * progress tiles, and a modal-less picker action validates, stores
     * (driver `createFile()`) and selects them. Freshly uploaded files are
     * always selected on this path (that is its purpose) — `auto_select_uploads`
     * only governs the FilePond modal paths. Requires `upload_button`;
     * set to `false` to fall back to the FilePond upload modal on the field.
     */
    'inline_upload' => true,

    /*
     * Turns the MediaPicker field, the file selection modal, and the upload modal
     * into drag-and-drop targets. Dropped files are handed to the original
     * upload modal's FilePond instance, so validation, progress and error
     * handling stay untouched. The field drop zone requires the
     * `upload_button` feature (it opens that action's modal).
     */
    'dropzone' => true,

    /*
     * Automatically selects freshly uploaded files: in the selection modal they
     * are added to the (bulk) selection, on the field's own upload action they
     * are merged into the field state (respecting `maxFiles`). Requires the
     * driver to record created files, see `HasMediaLibraryExtensions`.
     */
    'auto_select_uploads' => true,

    /*
     * Uses the package's MediaPickerPreviewAction (PDF iframe preview, arrow-key
     * navigation, policy-aware file URLs) for the preview on MediaPicker file
     * tiles. The same action is also used on modal file tiles and in the
     * file info sidebar for drivers using `HasMediaLibraryExtensions`.
     */
    'media_picker_preview' => true,
];
