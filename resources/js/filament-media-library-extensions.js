// Drag-and-drop and inline uploads for the Filament Media Library.
//
// Elements marked with `data-mle-dropzone` (MediaPicker fields, the selection
// modal, the upload modal) accept dropped files:
//
// - Zones carrying an inline upload config (`data-mle-inline-field` on the
//   picker, `data-mle-inline-modal` on the selection modal window) upload
//   through Livewire's upload API — one call per file. While uploading,
//   ghost cards/rows with a progress bar render directly inside the file
//   grid/list (protected from Livewire morphs), falling back to a dedicated
//   host container when no file list is visible. Afterwards the picker's
//   modal-less `process_inline_uploads` action validates, stores and selects
//   the files server-side. Drops onto a folder tile target that subfolder;
//   the upload buttons open the native file dialog for the same flow.
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
    const OPEN_DIALOG_SELECTOR = '[data-mle-inline-open]'
    const POND_BROWSER_SELECTOR = 'input[type="file"].filepond--browser'
    const FILE_TILE_SELECTOR = '[data-file-key]'
    const FOLDER_TILE_SELECTOR = '[data-file-type="folder"][data-file-key]'
    const GRID_COLUMN_SELECTOR = '.fi-grid-col'
    const ROW_MARKER_SELECTOR = '.fi-rjs-explore-file-row'
    const EMPTY_STATE_SELECTOR = '.fi-rjs-explore-empty-state'
    const FALLBACK_CONTAINER_SELECTOR = '[data-mle-ghost-fallback]'
    const MODAL_CONFIG_ATTRIBUTE = 'data-mle-inline-modal'
    const FIELD_CONFIG_ATTRIBUTE = 'data-mle-inline-field'
    const MOUNT_CONTEXT_ATTRIBUTE = 'data-mle-mount-context'
    const GHOST_ATTRIBUTE = 'data-mle-upload-ghost'
    const ACTIVE_CLASS = 'mle-dropzone-active'
    const FOLDER_ACTIVE_CLASS = 'mle-folder-dropzone-active'
    const ERRORED_CLASS = 'mle-inline-upload-errored'
    const POND_POLL_INTERVAL_MS = 150
    const POND_POLL_TIMEOUT_MS = 10000
    const ERRORED_UPLOAD_HIDE_AFTER_MS = 8000

    const randomUuid = () => crypto.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(36).slice(2)}`

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

    const inlineConfigFromZone = (zone) =>
        decodeJsonAttribute(zone.getAttribute(MODAL_CONFIG_ATTRIBUTE))
            ?? decodeJsonAttribute(zone.getAttribute(FIELD_CONFIG_ATTRIBUTE))

    const zoneFromEvent = (event) => {
        const target = event.target instanceof Element ? event.target : null
        const zone = target?.closest(ZONE_SELECTOR)

        if (!zone) {
            return null
        }

        // A zone is only actionable when it can receive uploads right now: an
        // inline upload config, a visible FilePond field, or an upload
        // action to open.
        if (
            !inlineConfigFromZone(zone) &&
            !zone.querySelector(POND_BROWSER_SELECTOR) &&
            !zone.querySelector(TRIGGER_SELECTOR)
        ) {
            return null
        }

        return zone
    }

    const folderTileFromEvent = (event, zone) => {
        if (!inlineConfigFromZone(zone)) {
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

    // ----------------------------------------------------------- ghost tiles
    //
    // Placeholder cards/rows rendered directly inside the file grid/list
    // while a file uploads. Livewire re-renders (morphs) the list on every
    // finished upload, so ghosts are protected via the `morph.removing`
    // hook and re-attached whenever they get disconnected (e.g. after
    // navigating to another folder mid-upload).

    const ghostRegistry = new Map()

    const onLivewireReady = (callback) => {
        window.Livewire ? callback() : document.addEventListener('livewire:init', callback)
    }

    onLivewireReady(() => {
        window.Livewire.hook?.('morph.removing', ({ el, skip }) => {
            if (el instanceof Element && el.hasAttribute(GHOST_ATTRIBUTE)) {
                skip()
            }
        })
    })

    const resolveGhostContainer = (scope) => {
        if (!scope.isConnected) {
            scope = document.querySelector(ZONE_SELECTOR) ?? document.body
        }

        const anchorTile = scope.querySelector(FILE_TILE_SELECTOR)

        if (anchorTile) {
            const gridCell = anchorTile.closest(GRID_COLUMN_SELECTOR) ?? anchorTile

            if (gridCell.parentElement) {
                return {
                    parent: gridCell.parentElement,
                    mode: anchorTile.querySelector(ROW_MARKER_SELECTOR) ? 'row' : 'grid',
                    prepend: true,
                }
            }
        }

        const fallbackContainer = scope.querySelector(FALLBACK_CONTAINER_SELECTOR)

        if (fallbackContainer) {
            return { parent: fallbackContainer, mode: 'card', prepend: false }
        }

        let dynamicContainer = scope.querySelector(`.mle-inline-uploads[${GHOST_ATTRIBUTE}]`)

        if (!dynamicContainer) {
            dynamicContainer = document.createElement('div')
            dynamicContainer.className = 'mle-inline-uploads'
            dynamicContainer.setAttribute(GHOST_ATTRIBUTE, 'container')

            const emptyState = scope.querySelector(EMPTY_STATE_SELECTOR)

            if (emptyState?.parentElement) {
                emptyState.parentElement.insertBefore(dynamicContainer, emptyState)
            } else {
                scope.appendChild(dynamicContainer)
            }
        }

        return { parent: dynamicContainer, mode: 'card', prepend: false }
    }

    const attachGhost = (entry) => {
        const { parent, mode, prepend } = resolveGhostContainer(entry.scope)

        entry.element.classList.remove('mle-ghost-tile--grid', 'mle-ghost-tile--row', 'mle-ghost-tile--card')
        entry.element.classList.add(`mle-ghost-tile--${mode}`)

        prepend && parent.firstElementChild
            ? parent.insertBefore(entry.element, parent.firstElementChild)
            : parent.appendChild(entry.element)
    }

    const addGhost = (scope, uuid, name) => {
        const element = document.createElement('div')
        element.className = 'mle-ghost-tile'
        element.setAttribute(GHOST_ATTRIBUTE, uuid)
        element.setAttribute('wire:ignore', '')

        const nameElement = document.createElement('span')
        nameElement.className = 'mle-inline-upload-name'
        nameElement.textContent = name

        const progressElement = document.createElement('div')
        progressElement.className = 'mle-inline-upload-progress'
        progressElement.appendChild(document.createElement('div'))

        element.append(nameElement, progressElement)

        const entry = { element, scope }
        ghostRegistry.set(uuid, entry)
        attachGhost(entry)
    }

    const ensureGhostAttached = (entry) => {
        if (!entry.element.isConnected) {
            attachGhost(entry)
        }
    }

    const ghostProgress = (uuid, progress) => {
        const entry = ghostRegistry.get(uuid)

        if (!entry) {
            return
        }

        ensureGhostAttached(entry)

        const bar = entry.element.querySelector('.mle-inline-upload-progress > div')

        if (bar) {
            bar.style.width = `${progress}%`
        }
    }

    const ghostErrored = (uuid) => {
        const entry = ghostRegistry.get(uuid)

        if (!entry) {
            return
        }

        ensureGhostAttached(entry)
        entry.element.classList.add(ERRORED_CLASS)

        setTimeout(() => removeGhost(uuid), ERRORED_UPLOAD_HIDE_AFTER_MS)
    }

    const removeGhost = (uuid) => {
        ghostRegistry.get(uuid)?.element.remove()
        ghostRegistry.delete(uuid)
        cleanupGhostContainers()
    }

    const clearFinishedGhosts = () => {
        ghostRegistry.forEach((entry, uuid) => {
            if (!entry.element.classList.contains(ERRORED_CLASS)) {
                entry.element.remove()
                ghostRegistry.delete(uuid)
            }
        })

        cleanupGhostContainers()
    }

    const cleanupGhostContainers = () => {
        document
            .querySelectorAll(`.mle-inline-uploads[${GHOST_ATTRIBUTE}]`)
            .forEach((container) => {
                if (!container.childElementCount) {
                    container.remove()
                }
            })
    }

    // ---------------------------------------------------------- upload engine

    const startInlineUploads = (wire, config, files, { folderKey = null, scope }) => {
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
            )).then(() => clearFinishedGhosts())
        }

        files.forEach((file) => {
            const uuid = randomUuid()

            addGhost(scope, uuid, file.name)

            wire.upload(
                `${config.uploadPath}.${uuid}`,
                file,
                () => {
                    ghostProgress(uuid, 100)
                    settle()
                },
                () => {
                    ghostErrored(uuid)
                    settle()
                },
                (event) => ghostProgress(uuid, event.detail.progress),
                () => {
                    removeGhost(uuid)
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
                    startInlineUploads(context.wire, context.config, files, { scope: context.scope })
                }
            })
        }

        return input
    }

    const openInlineUploadDialog = (wire, config, scope) => {
        if (!wire) {
            return
        }

        dialogUploadContext = { wire, config, scope }

        const input = dialogInput()
        input.accept = config.accept ?? ''
        input.click()
    }

    // The field's upload button opens the native dialog for its zone.
    document.addEventListener('click', (event) => {
        const opener = event.target instanceof Element ? event.target.closest(OPEN_DIALOG_SELECTOR) : null

        if (!opener) {
            return
        }

        const zone = opener.closest(ZONE_SELECTOR)
        const config = zone ? inlineConfigFromZone(zone) : null

        if (!config) {
            return
        }

        openInlineUploadDialog(wireFromElement(opener), config, zone)
    })

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
        const config = zone ? inlineConfigFromZone(zone) : null

        if (config) {
            openInlineUploadDialog(wireFromElement(trigger), config, zone)

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

        // Zones with an inline config upload without any FilePond modal —
        // drops onto a folder tile target that subfolder ...
        const inlineConfig = inlineConfigFromZone(zone)

        if (inlineConfig) {
            startInlineUploads(wireFromElement(zone), inlineConfig, files, {
                folderKey: folderTile?.getAttribute('data-file-key') ?? null,
                scope: zone,
            })

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
