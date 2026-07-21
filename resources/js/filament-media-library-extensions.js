// Drag-and-drop and inline uploads for the Filament Media Library.
//
// Elements marked with `data-mle-dropzone` (MediaPicker fields, the selection
// modal, the upload modal) accept dropped files:
//
// - MediaPicker fields render their own inline uploader (`mleInlineUploader`
//   Alpine component) with per-file progress tiles.
// - Selection modals carrying a `data-mle-inline-modal` config upload inline
//   too: files go through Livewire's upload API into the picker's pending
//   path, a fixed overlay panel shows per-file progress, and the picker's
//   modal-less `process_inline_uploads` action stores and selects them.
//   Drops onto a folder tile target that subfolder. The topbar upload
//   button opens the native file dialog for the same flow.
// - Zones without an inline config (e.g. the media library page) fall back
//   to the original FilePond modal: the upload action is opened and the
//   files are injected into its FilePond browse input.
//
// Drags that start inside the page (file tiles, reordering) never activate
// the drop zones — only external file drags do.
(() => {
    if (window.mleMediaLibraryDropzonesInitialized) {
        return
    }

    window.mleMediaLibraryDropzonesInitialized = true

    const ZONE_SELECTOR = '[data-mle-dropzone]'
    const TRIGGER_SELECTOR = '[data-mle-upload-trigger]'
    const INLINE_UPLOADER_SELECTOR = '[data-mle-inline-upload]'
    const POND_BROWSER_SELECTOR = 'input[type="file"].filepond--browser'
    const FOLDER_TILE_SELECTOR = '[data-file-type="folder"][data-file-key]'
    const MODAL_CONFIG_ATTRIBUTE = 'data-mle-inline-modal'
    const MOUNT_CONTEXT_ATTRIBUTE = 'data-mle-mount-context'
    const ACTIVE_CLASS = 'mle-dropzone-active'
    const FOLDER_ACTIVE_CLASS = 'mle-folder-dropzone-active'
    const POND_POLL_INTERVAL_MS = 150
    const POND_POLL_TIMEOUT_MS = 10000
    const ERRORED_UPLOAD_HIDE_AFTER_MS = 8000

    const randomUuid = () => crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`

    // Alpine component for the inline upload on MediaPicker fields: dropped or
    // picked files upload straight through Livewire's JS upload API (one call
    // per file, so each tile gets its own progress), and once no upload is
    // pending anymore, the picker's modal-less `process_inline_uploads`
    // action validates, stores and selects them server-side.
    window.mleInlineUploader = ({ statePath, processContext }) => ({
        uploads: [],
        pendingCount: 0,

        queueFiles(files) {
            Array.from(files ?? []).forEach((file) => this.uploadFile(file))
        },

        uploadFile(file) {
            const uuid = randomUuid()

            this.uploads.push({ uuid, name: file.name, progress: 0, error: false })
            this.pendingCount++

            this.$wire.upload(
                `${statePath}.${uuid}`,
                file,
                () => this.finishUpload(uuid, false),
                () => this.finishUpload(uuid, true),
                (event) => {
                    const upload = this.uploads.find((candidate) => candidate.uuid === uuid)

                    if (upload) {
                        upload.progress = event.detail.progress
                    }
                },
                () => this.discardUpload(uuid),
            )
        },

        finishUpload(uuid, errored) {
            const upload = this.uploads.find((candidate) => candidate.uuid === uuid)

            if (upload) {
                upload.progress = 100
                upload.error = errored
            }

            if (errored) {
                setTimeout(() => this.discardUpload(uuid, false), ERRORED_UPLOAD_HIDE_AFTER_MS)
            }

            this.settle()
        },

        discardUpload(uuid, isPending = true) {
            this.uploads = this.uploads.filter((candidate) => candidate.uuid !== uuid)

            if (isPending) {
                this.settle()
            }
        },

        settle() {
            this.pendingCount--

            if (this.pendingCount <= 0) {
                this.pendingCount = 0
                this.processUploads()
            }
        },

        async processUploads() {
            await this.$wire.mountAction('process_inline_uploads', {}, processContext)

            // Keep errored tiles visible (they auto-hide), drop finished ones —
            // the processed files re-render as regular picker tiles.
            this.uploads = this.uploads.filter((candidate) => candidate.error)
        },
    })

    // ---------------------------------------------------------------- helpers

    let pondPollTimer = null
    let isInternalDrag = false
    let activeFolderTile = null

    // Drags that originate inside the page (dragging a file tile, sortable
    // reordering) must never activate the drop zones — external OS file
    // drags never fire `dragstart` within the document.
    document.addEventListener('dragstart', () => {
        isInternalDrag = true
    }, true)

    document.addEventListener('dragend', () => {
        isInternalDrag = false
    }, true)

    document.addEventListener('drop', () => {
        setTimeout(() => {
            isInternalDrag = false
        })
    }, true)

    const isFileDrag = (event) =>
        Array.from(event.dataTransfer?.types ?? []).includes('Files')

    const wireFromElement = (element) => {
        const livewireElement = element.closest('[wire\\:id]')

        if (!livewireElement) {
            return null
        }

        // `Livewire.find()` returns the component's `$wire` proxy directly.
        return window.Livewire?.find?.(livewireElement.getAttribute('wire:id')) ?? null
    }

    // Config attributes are base64-encoded JSON, because Filament renders
    // extra (modal window) attributes unescaped.
    const decodeJsonAttribute = (rawValue) => {
        if (!rawValue) {
            return null
        }

        try {
            return JSON.parse(atob(rawValue))
        } catch {
            return null
        }
    }

    const inlineModalConfigFromZone = (zone) => decodeJsonAttribute(zone.getAttribute(MODAL_CONFIG_ATTRIBUTE))

    const zoneFromEvent = (event) => {
        const target = event.target instanceof Element ? event.target : null
        const zone = target?.closest(ZONE_SELECTOR)

        if (!zone) {
            return null
        }

        // A zone is only actionable when it can receive uploads right now: an
        // inline upload config or uploader, a visible FilePond field, or an
        // upload action to open.
        if (
            !inlineModalConfigFromZone(zone) &&
            !zone.querySelector(INLINE_UPLOADER_SELECTOR) &&
            !zone.querySelector(POND_BROWSER_SELECTOR) &&
            !zone.querySelector(TRIGGER_SELECTOR)
        ) {
            return null
        }

        return zone
    }

    const folderTileFromEvent = (event, zone) => {
        if (!inlineModalConfigFromZone(zone)) {
            return null
        }

        const tile = event.target instanceof Element ? event.target.closest(FOLDER_TILE_SELECTOR) : null

        return tile && zone.contains(tile) ? tile : null
    }

    const setActiveFolderTile = (tile) => {
        if (tile === activeFolderTile) {
            return
        }

        activeFolderTile?.classList.remove(FOLDER_ACTIVE_CLASS)
        activeFolderTile = tile
        activeFolderTile?.classList.add(FOLDER_ACTIVE_CLASS)
    }

    const clearHighlights = () => {
        document
            .querySelectorAll(`${ZONE_SELECTOR}.${ACTIVE_CLASS}`)
            .forEach((zone) => zone.classList.remove(ACTIVE_CLASS))

        setActiveFolderTile(null)
    }

    // ------------------------------------- inline upload panel (modal / page)

    const uploadPanel = {
        element: null,

        ensure() {
            if (this.element?.isConnected) {
                return this.element
            }

            this.element = document.createElement('div')
            this.element.className = 'mle-upload-panel'
            document.body.appendChild(this.element)

            return this.element
        },

        add(uuid, name) {
            const tile = document.createElement('div')
            tile.className = 'mle-inline-upload'
            tile.dataset.mleUploadUuid = uuid

            const nameElement = document.createElement('span')
            nameElement.className = 'mle-inline-upload-name'
            nameElement.textContent = name

            const progressElement = document.createElement('div')
            progressElement.className = 'mle-inline-upload-progress'
            progressElement.appendChild(document.createElement('div'))

            tile.append(nameElement, progressElement)
            this.ensure().appendChild(tile)
        },

        tile(uuid) {
            return this.element?.querySelector(`[data-mle-upload-uuid="${uuid}"]`) ?? null
        },

        setProgress(uuid, progress) {
            const bar = this.tile(uuid)?.querySelector('.mle-inline-upload-progress > div')

            if (bar) {
                bar.style.width = `${progress}%`
            }
        },

        setErrored(uuid) {
            this.tile(uuid)?.classList.add('mle-inline-upload-errored')

            setTimeout(() => this.remove(uuid), ERRORED_UPLOAD_HIDE_AFTER_MS)
        },

        remove(uuid) {
            this.tile(uuid)?.remove()
            this.cleanup()
        },

        clearFinished() {
            this.element
                ?.querySelectorAll('[data-mle-upload-uuid]:not(.mle-inline-upload-errored)')
                .forEach((tile) => tile.remove())

            this.cleanup()
        },

        cleanup() {
            if (this.element && !this.element.childElementCount) {
                this.element.remove()
                this.element = null
            }
        },
    }

    const startInlineUploads = (wire, config, files, folderKey = null) => {
        if (!wire || !config?.uploadPath || !config?.processName || !files.length) {
            return
        }

        let pendingCount = files.length

        const settle = () => {
            pendingCount--

            if (pendingCount > 0) {
                return
            }

            Promise.resolve(wire.mountAction(
                config.processName,
                folderKey ? { folderKey } : {},
                config.processContext ?? {},
            )).then(() => uploadPanel.clearFinished())
        }

        files.forEach((file) => {
            const uuid = randomUuid()

            uploadPanel.add(uuid, file.name)

            wire.upload(
                `${config.uploadPath}.${uuid}`,
                file,
                () => {
                    uploadPanel.setProgress(uuid, 100)
                    settle()
                },
                () => {
                    uploadPanel.setErrored(uuid)
                    settle()
                },
                (event) => uploadPanel.setProgress(uuid, event.detail.progress),
                () => {
                    uploadPanel.remove(uuid)
                    settle()
                },
            )
        })
    }

    // -------------------------------------------------- native dialog trigger

    let dialogUploadContext = null

    const dialogInput = () => {
        let input = document.querySelector('input[data-mle-upload-dialog]')

        if (!input) {
            input = document.createElement('input')
            input.type = 'file'
            input.multiple = true
            input.hidden = true
            input.setAttribute('data-mle-upload-dialog', 'true')
            document.body.appendChild(input)

            input.addEventListener('change', () => {
                const context = dialogUploadContext
                dialogUploadContext = null

                const files = Array.from(input.files ?? [])
                input.value = ''

                if (context && files.length) {
                    startInlineUploads(context.wire, context.config, files)
                }
            })
        }

        return input
    }

    // Click handler for upload action buttons when inline uploads are
    // enabled: inside a zone with an inline config the native file dialog
    // opens; elsewhere (e.g. the media library page) the original FilePond
    // upload modal is mounted as fallback.
    window.mleUploadTriggerClicked = (event) => {
        const trigger = event.currentTarget instanceof Element ? event.currentTarget : null

        if (!trigger) {
            return
        }

        const zone = trigger.closest(ZONE_SELECTOR)
        const config = zone ? inlineModalConfigFromZone(zone) : null

        if (config) {
            const wire = wireFromElement(trigger)

            if (!wire) {
                return
            }

            dialogUploadContext = { wire, config }

            const input = dialogInput()
            input.accept = config.accept ?? ''
            input.click()

            return
        }

        const mountContext = decodeJsonAttribute(trigger.getAttribute(MOUNT_CONTEXT_ATTRIBUTE)) ?? {}

        wireFromElement(trigger)?.mountAction('upload', {}, mountContext)
    }

    // ------------------------------------- FilePond handoff (fallback flows)

    const injectFilesIntoPondInput = (input, files) => {
        const dataTransfer = new DataTransfer()

        files.forEach((file) => dataTransfer.items.add(file))

        input.files = dataTransfer.files
        input.dispatchEvent(new Event('change', { bubbles: true }))
    }

    const waitForNewPondInput = (knownPondInputs, files) => {
        clearInterval(pondPollTimer)

        const startedAt = Date.now()

        pondPollTimer = setInterval(() => {
            if (Date.now() - startedAt > POND_POLL_TIMEOUT_MS) {
                clearInterval(pondPollTimer)

                return
            }

            const input = Array.from(document.querySelectorAll(POND_BROWSER_SELECTOR)).find(
                (candidate) => !knownPondInputs.has(candidate),
            )

            if (!input) {
                return
            }

            clearInterval(pondPollTimer)
            injectFilesIntoPondInput(input, files)
        }, POND_POLL_INTERVAL_MS)
    }

    // ------------------------------------------------------ document listeners

    document.addEventListener('dragover', (event) => {
        if (isInternalDrag || !isFileDrag(event)) {
            return
        }

        const zone = zoneFromEvent(event)

        if (!zone) {
            clearHighlights()

            return
        }

        event.preventDefault()
        event.dataTransfer.dropEffect = 'copy'

        if (!zone.classList.contains(ACTIVE_CLASS)) {
            clearHighlights()
            zone.classList.add(ACTIVE_CLASS)
        }

        setActiveFolderTile(folderTileFromEvent(event, zone))
    })

    document.addEventListener('dragleave', (event) => {
        if (isInternalDrag) {
            return
        }

        const target = event.target instanceof Element ? event.target : null
        const zone = target?.closest(ZONE_SELECTOR)

        if (zone && event.relatedTarget instanceof Element && zone.contains(event.relatedTarget)) {
            return
        }

        clearHighlights()
    })

    document.addEventListener('dragend', clearHighlights)

    document.addEventListener('drop', (event) => {
        if (isInternalDrag) {
            clearHighlights()

            return
        }

        const zone = zoneFromEvent(event)
        const folderTile = zone ? folderTileFromEvent(event, zone) : null

        clearHighlights()

        if (!zone || !isFileDrag(event)) {
            return
        }

        event.preventDefault()

        const files = Array.from(event.dataTransfer?.files ?? [])

        if (!files.length) {
            return
        }

        // Selection modals with an inline config upload without any FilePond
        // modal — drops onto a folder tile target that subfolder ...
        const inlineModalConfig = inlineModalConfigFromZone(zone)

        if (inlineModalConfig) {
            startInlineUploads(
                wireFromElement(zone),
                inlineModalConfig,
                files,
                folderTile?.getAttribute('data-file-key') ?? null,
            )

            return
        }

        // ... fields with the inline uploader take the files without any modal ...
        const inlineUploader = zone.querySelector(INLINE_UPLOADER_SELECTOR)

        if (inlineUploader) {
            inlineUploader.dispatchEvent(new CustomEvent('mle-upload-files', { detail: { files } }))

            return
        }

        // ... the upload modal itself (or an already open FilePond) takes the
        // files directly ...
        const pondInput = zone.querySelector(POND_BROWSER_SELECTOR)

        if (pondInput) {
            injectFilesIntoPondInput(pondInput, files)

            return
        }

        // ... otherwise open the zone's upload action and hand the files to
        // the FilePond instance it mounts.
        const trigger = zone.querySelector(TRIGGER_SELECTOR)

        if (!trigger) {
            return
        }

        const knownPondInputs = new Set(document.querySelectorAll(POND_BROWSER_SELECTOR))

        trigger.click()
        waitForNewPondInput(knownPondInputs, files)
    })
})()
