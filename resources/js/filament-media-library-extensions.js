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
    const UPLOAD_STALL_TIMEOUT_MS = 90000

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
            if (anchorTile.querySelector(ROW_MARKER_SELECTOR)) {
                // List rows stack in a block container — append next to the
                // row's direct cell (`.fi-grid-col` wrapper, if any).
                const rowCell = anchorTile.parentElement?.matches(GRID_COLUMN_SELECTOR)
                    ? anchorTile.parentElement
                    : anchorTile

                if (rowCell.parentElement) {
                    return {
                        parent: rowCell.parentElement,
                        mode: 'row',
                        cellClassName: rowCell === anchorTile ? null : rowCell.className,
                    }
                }
            }

            // Grid tiles sit (possibly wrapped) inside the nearest
            // `display: grid` ancestor — the ghost must become a sibling GRID
            // CHILD of the cell containing the anchor tile, cloning that
            // cell's classes, so it occupies its own cell in every browser.
            let gridContainer = anchorTile.parentElement

            while (
                gridContainer &&
                gridContainer !== scope &&
                getComputedStyle(gridContainer).display !== 'grid'
            ) {
                gridContainer = gridContainer.parentElement
            }

            if (gridContainer && gridContainer !== scope && getComputedStyle(gridContainer).display === 'grid') {
                let gridCell = anchorTile

                while (gridCell.parentElement !== gridContainer) {
                    gridCell = gridCell.parentElement
                }

                return {
                    parent: gridContainer,
                    mode: 'grid',
                    cellClassName: gridCell === anchorTile ? null : gridCell.className,
                }
            }
        }

        const fallbackContainer = scope.querySelector(FALLBACK_CONTAINER_SELECTOR)

        if (fallbackContainer) {
            return { parent: fallbackContainer, mode: 'card' }
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

        return { parent: dynamicContainer, mode: 'card' }
    }

    const GHOST_FILE_ICON_SVG =
        '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">'
        + '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>'
        + '</svg>'

    const ghostCaption = (entry, isOverlay) => {
        const caption = document.createElement('div')
        caption.className = isOverlay
            ? 'mle-ghost-caption mle-ghost-caption--overlay text-white absolute w-full left-0 bottom-0 z-10 bg-gradient-to-t from-black to-transparent p-3 pt-5 rounded-b-lg'
            : 'mle-ghost-caption w-full'

        const nameElement = document.createElement('span')
        nameElement.className = 'mle-inline-upload-name'
        nameElement.textContent = entry.name

        const progressElement = document.createElement('div')
        progressElement.className = isOverlay
            ? 'mle-inline-upload-progress mle-inline-upload-progress--overlay'
            : 'mle-inline-upload-progress'
        progressElement.appendChild(document.createElement('div'))

        caption.append(nameElement, progressElement)

        return caption
    }

    // Mimics the real tile markup (same utility classes as the vendor views):
    // image uploads show a local preview with the vendor's gradient caption,
    // other files get the icon-style card; rows mirror the list layout.
    const renderGhostContent = (entry, container) => {
        const { mode, cellClassName } = container

        entry.element.className = [cellClassName ?? '', 'mle-ghost-tile', `mle-ghost-tile--${mode}`]
            .filter(Boolean)
            .join(' ')
        entry.element.replaceChildren()

        if (mode === 'row') {
            const row = document.createElement('div')
            row.className = 'flex flex-row items-center w-full h-12 border-gray-200 dark:border-white/5'
            entry.element.appendChild(row)

            const selectionSpacer = document.createElement('div')
            selectionSpacer.className = 'mle-ghost-row-spacer'

            const thumbnailCell = document.createElement('div')
            thumbnailCell.className = 'px-1 py-4 flex flex-row justify-center items-center w-10 shrink-0 grow-0'

            if (entry.objectUrl) {
                const thumbnail = document.createElement('img')
                thumbnail.src = entry.objectUrl
                thumbnail.className = 'mle-ghost-row-thumbnail object-cover rounded-full ring ring-gray-100 dark:ring-gray-800'
                thumbnailCell.appendChild(thumbnail)
            } else {
                thumbnailCell.innerHTML = GHOST_FILE_ICON_SVG
                thumbnailCell.firstElementChild.classList.add('mle-ghost-row-icon', 'text-primary-600', 'dark:text-primary-400')
            }

            const nameCell = document.createElement('div')
            nameCell.className = 'px-3 py-4 grow truncate'

            const nameElement = document.createElement('span')
            nameElement.className = 'mle-inline-upload-name'
            nameElement.textContent = entry.name
            nameCell.appendChild(nameElement)

            const progressCell = document.createElement('div')
            progressCell.className = 'pl-3 pr-4 shrink-0 grow-0'

            const progressElement = document.createElement('div')
            progressElement.className = 'mle-inline-upload-progress mle-ghost-row-progress'
            progressElement.appendChild(document.createElement('div'))
            progressCell.appendChild(progressElement)

            row.append(selectionSpacer, thumbnailCell, nameCell, progressCell)

            return
        }

        const card = document.createElement('div')
        card.className = 'size-full bg-white dark:bg-gray-900 shadow-sm rounded-lg'

        if (!entry.objectUrl) {
            card.classList.add('ring-1', 'ring-gray-500/20')
        }

        const cardContent = document.createElement('div')
        cardContent.className = 'relative size-full p-3 flex flex-col justify-between'

        if (entry.objectUrl) {
            const preview = document.createElement('img')
            preview.src = entry.objectUrl
            preview.className = 'absolute top-0 left-0 size-full object-cover rounded-lg'
            cardContent.appendChild(preview)
            cardContent.appendChild(document.createElement('div'))
            cardContent.appendChild(ghostCaption(entry, true))
        } else {
            const iconRow = document.createElement('div')
            iconRow.className = 'flex flex-row justify-between'
            iconRow.innerHTML = GHOST_FILE_ICON_SVG
            iconRow.firstElementChild.classList.add('size-8', 'text-primary-600', 'dark:text-primary-400')

            cardContent.appendChild(iconRow)
            cardContent.appendChild(ghostCaption(entry, false))
        }

        card.appendChild(cardContent)

        // Field tiles ARE the square cell; modal cells and fallback hosts
        // need the tile's inner square wrapper.
        if (entry.element.classList.contains('aspect-square')) {
            entry.element.appendChild(card)
        } else {
            const square = document.createElement('div')
            square.className = 'relative aspect-square'
            square.appendChild(card)
            entry.element.appendChild(square)
        }
    }

    const attachGhost = (entry) => {
        const container = resolveGhostContainer(entry.scope)
        const containerSignature = `${container.mode}|${container.cellClassName ?? ''}`

        if (entry.containerSignature !== containerSignature) {
            entry.containerSignature = containerSignature
            renderGhostContent(entry, container)
        }

        // Always show uploads last, regardless of the list's sort order.
        container.parent.appendChild(entry.element)
    }

    const addGhost = (scope, uuid, file) => {
        const element = document.createElement('div')
        element.setAttribute(GHOST_ATTRIBUTE, uuid)
        element.setAttribute('wire:ignore', '')

        const entry = {
            element,
            scope,
            name: file.name,
            objectUrl: file.type?.startsWith('image/') ? URL.createObjectURL(file) : null,
            containerSignature: null,
        }

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

    const discardGhostEntry = (uuid, entry) => {
        entry.element.remove()

        if (entry.objectUrl) {
            URL.revokeObjectURL(entry.objectUrl)
        }

        ghostRegistry.delete(uuid)
    }

    const removeGhost = (uuid) => {
        const entry = ghostRegistry.get(uuid)

        if (entry) {
            discardGhostEntry(uuid, entry)
        }

        cleanupGhostContainers()
    }

    const clearFinishedGhosts = () => {
        ghostRegistry.forEach((entry, uuid) => {
            if (!entry.element.classList.contains(ERRORED_CLASS)) {
                discardGhostEntry(uuid, entry)
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

            addGhost(scope, uuid, file)

            // Livewire does not always fire the error callback (e.g. when the
            // finish roundtrip dies) — a stall watchdog keeps ghosts and the
            // batch from hanging forever.
            let isSettled = false
            let stallTimer = null

            const settleOnce = (onSettle) => {
                if (isSettled) {
                    return
                }

                isSettled = true
                clearTimeout(stallTimer)
                onSettle()
                settle()
            }

            const restartStallTimer = () => {
                if (isSettled) {
                    return
                }

                clearTimeout(stallTimer)
                stallTimer = setTimeout(() => settleOnce(() => ghostErrored(uuid)), UPLOAD_STALL_TIMEOUT_MS)
            }

            restartStallTimer()

            wire.upload(
                `${config.uploadPath}.${uuid}`,
                file,
                () => settleOnce(() => ghostProgress(uuid, 100)),
                () => settleOnce(() => ghostErrored(uuid)),
                (event) => {
                    restartStallTimer()
                    ghostProgress(uuid, event.detail.progress)
                },
                () => settleOnce(() => removeGhost(uuid)),
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
