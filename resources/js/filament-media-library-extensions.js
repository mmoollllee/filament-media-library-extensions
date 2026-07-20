// Drag-and-drop upload zones for the Filament Media Library.
//
// Elements marked with `data-mle-dropzone` (MediaPicker fields, the selection
// modal, the upload modal) accept dropped files. Dropped files are handed to
// the original upload modal's FilePond instance: if the zone already shows a
// FilePond field, the files are injected directly; otherwise the zone's
// upload action (`data-mle-upload-trigger`) is clicked and the files are
// injected as soon as its FilePond browse input appears. Validation,
// progress and error handling stay entirely with FilePond.
(() => {
    if (window.mleMediaLibraryDropzonesInitialized) {
        return
    }

    window.mleMediaLibraryDropzonesInitialized = true

    const ZONE_SELECTOR = '[data-mle-dropzone]'
    const TRIGGER_SELECTOR = '[data-mle-upload-trigger]'
    const POND_BROWSER_SELECTOR = 'input[type="file"].filepond--browser'
    const ACTIVE_CLASS = 'mle-dropzone-active'
    const POND_POLL_INTERVAL_MS = 150
    const POND_POLL_TIMEOUT_MS = 10000

    let pondPollTimer = null

    const isFileDrag = (event) =>
        Array.from(event.dataTransfer?.types ?? []).includes('Files')

    const zoneFromEvent = (event) => {
        const target = event.target instanceof Element ? event.target : null
        const zone = target?.closest(ZONE_SELECTOR)

        if (!zone) {
            return null
        }

        // A zone is only actionable when it can receive uploads right now:
        // either a FilePond field is visible or an upload action exists.
        if (!zone.querySelector(POND_BROWSER_SELECTOR) && !zone.querySelector(TRIGGER_SELECTOR)) {
            return null
        }

        return zone
    }

    const clearHighlights = () => {
        document
            .querySelectorAll(`${ZONE_SELECTOR}.${ACTIVE_CLASS}`)
            .forEach((zone) => zone.classList.remove(ACTIVE_CLASS))
    }

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

    document.addEventListener('dragover', (event) => {
        if (!isFileDrag(event)) {
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
    })

    document.addEventListener('dragleave', (event) => {
        const target = event.target instanceof Element ? event.target : null
        const zone = target?.closest(ZONE_SELECTOR)

        if (zone && event.relatedTarget instanceof Element && zone.contains(event.relatedTarget)) {
            return
        }

        zone ? zone.classList.remove(ACTIVE_CLASS) : clearHighlights()
    })

    document.addEventListener('dragend', clearHighlights)

    document.addEventListener('drop', (event) => {
        const zone = zoneFromEvent(event)

        clearHighlights()

        if (!zone || !isFileDrag(event)) {
            return
        }

        event.preventDefault()

        const files = Array.from(event.dataTransfer?.files ?? [])

        if (!files.length) {
            return
        }

        // The upload modal itself (or an already open FilePond) takes the
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
