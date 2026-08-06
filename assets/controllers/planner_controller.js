import { Controller } from '@hotwired/stimulus';
import { alertModal } from '../js/modal.js';
import { backgroundFetch } from '../js/session.js';

/*
 * Standard Planner interaction.
 *
 * Dependency-free pointer handling over a time×day grid:
 *   - drag on empty grid to paint a new draft shift (snaps to the raster);
 *   - drag a block to move it, drag its bottom edge to resize;
 *   - click to select (shift/ctrl or tap adds to a multi-selection);
 *   - Delete/Backspace removes the selection.
 *
 * Every mutation posts JSON to the server (which owns validation and draft
 * persistence) and then reloads just the grid, so no flow needs a full refresh.
 */
export default class extends Controller {
    static targets = ['grid', 'dayBody', 'block', 'resizeHandle'];
    static values = {
        department: String,
        timezone: String,
        raster: { type: Number, default: 30 },
        paintUrl: String,
        paintToken: String,
        editToken: String,
        audience: { type: String, default: 'public_volunteer' },
        task: { type: String, default: '' },
        location: { type: String, default: '' },
        mode: { type: String, default: 'select' },
    };

    /*
     * Stimulus fires the *TargetConnected callbacks BEFORE connect(), so any state they read has to
     * exist by the end of initialize(). Setting these up in connect() instead threw on the first
     * block of a populated grid, and the exception aborted the controller's connection - taking
     * painting, dragging and the grid refresh down with it on every department that had shifts.
     */
    initialize() {
        this.selected = new Set();
        this.invalid = new Set();
    }

    connect() {
        this.selected = new Set();
        this.onPointerDown = this.handlePointerDown.bind(this);
        this.onPointerMove = this.handlePointerMove.bind(this);
        this.onPointerUp = this.handlePointerUp.bind(this);
        this.onKeyDown = this.handleKeyDown.bind(this);
        this.onChanged = () => this.reloadGrid();
        this.onRemoteChanged = () => this.queueRemoteReload();
        this.onEditMaybeFinished = () => this.applyRemoteReloadIfIdle();
        this.onSetPaint = (event) => this.applyPaintDefaults(event.detail);
        this.onInvalid = (event) => this.applyInvalid(event.detail?.uuids ?? []);

        this.element.addEventListener('pointerdown', this.onPointerDown);
        window.addEventListener('pointermove', this.onPointerMove);
        window.addEventListener('pointerup', this.onPointerUp);
        this.element.addEventListener('keydown', this.onKeyDown);
        // Toolbar/modal/panel forms ask us to reload the grid or adjust paint defaults.
        window.addEventListener('planner:changed', this.onChanged);
        window.addEventListener('planner:paint-defaults', this.onSetPaint);
        window.addEventListener('planner:invalid', this.onInvalid);
        window.addEventListener('planner:remote-changed', this.onRemoteChanged);
        document.addEventListener('hidden.bs.modal', this.onEditMaybeFinished);
    }

    disconnect() {
        this.element.removeEventListener('pointerdown', this.onPointerDown);
        window.removeEventListener('pointermove', this.onPointerMove);
        window.removeEventListener('pointerup', this.onPointerUp);
        this.element.removeEventListener('keydown', this.onKeyDown);
        window.removeEventListener('planner:changed', this.onChanged);
        window.removeEventListener('planner:paint-defaults', this.onSetPaint);
        window.removeEventListener('planner:invalid', this.onInvalid);
        window.removeEventListener('planner:remote-changed', this.onRemoteChanged);
        document.removeEventListener('hidden.bs.modal', this.onEditMaybeFinished);
        this.clearGesture();
    }

    /**
     * Someone else changed this department.
     *
     * Their change is not applied on arrival. reloadGrid() replaces the grid wholesale and clears
     * the selection, so doing that mid-edit would pull the blocks out from under a drag, empty the
     * side panel a manager was acting through, or refresh the page behind an open modal. The change
     * is remembered and applied at the next moment this manager is not in the middle of something.
     *
     * A manager's own edits are unaffected: those come through planner:changed and still reload
     * immediately, because they are the one who asked.
     */
    queueRemoteReload() {
        this.remotePending = true;
        this.applyRemoteReloadIfIdle();
    }

    /** True while applying a remote change would disrupt what this manager is doing. */
    midEdit() {
        return Boolean(this.gesture)
            || this.selected.size > 0
            || document.querySelector('.modal.show') !== null;
    }

    applyRemoteReloadIfIdle() {
        if (!this.remotePending || this.midEdit()) {
            return;
        }
        this.remotePending = false;
        this.reloadGrid();
    }

    applyPaintDefaults(detail) {
        if (detail.audience !== undefined) {
            this.audienceValue = detail.audience;
        }
        if (detail.task !== undefined) {
            this.taskValue = String(detail.task);
        }
        if (detail.location !== undefined) {
            this.locationValue = String(detail.location);
        }
        if (detail.mode !== undefined) {
            this.modeValue = detail.mode === 'paint' ? 'paint' : 'select';
        }
    }

    // The class lives on the controller element, not on the grid: reloadGrid() replaces the grid
    // wholesale after every edit, which would drop it.
    modeValueChanged() {
        this.element.classList.toggle('is-painting', this.modeValue === 'paint');
    }

    // ---- geometry helpers -------------------------------------------------

    minutesPerPixel(body) {
        return 1440 / body.getBoundingClientRect().height;
    }

    snap(minutes) {
        const r = this.rasterValue;
        return Math.max(0, Math.min(1440, Math.round(minutes / r) * r));
    }

    pointerMinutes(body, clientY) {
        const rect = body.getBoundingClientRect();
        return this.snap((clientY - rect.top) * this.minutesPerPixel(body));
    }

    isoAtMinutes(dayIso, minutes) {
        const [y, m, d] = dayIso.split('-').map(Number);
        const base = new Date(Date.UTC(y, m - 1, d));
        base.setUTCMinutes(base.getUTCMinutes() + minutes);
        const pad = (n) => String(n).padStart(2, '0');
        return `${base.getUTCFullYear()}-${pad(base.getUTCMonth() + 1)}-${pad(base.getUTCDate())}`
            + `T${pad(base.getUTCHours())}:${pad(base.getUTCMinutes())}:00`;
    }

    // ---- gesture start ----------------------------------------------------

    handlePointerDown(event) {
        if (event.button !== 0) {
            return;
        }
        // Creating a shift is exclusive to paint mode, including over an existing block: a drag in
        // select mode never creates anything, so an imprecise drag on empty grid cannot leave a
        // stray shift behind. The block under the pointer is ignored here, which is the only way to
        // start a shift running in parallel with one already there. Blocks live inside the day body,
        // so the body is still resolvable from a pointer that landed on a block.
        const body = event.target.closest('.planner-day-body');
        if (this.modeValue === 'paint') {
            if (body) {
                this.startPaint(event, body);
            }
            return;
        }

        const block = event.target.closest('.planner-block');
        if (block) {
            if (event.target.closest('.planner-block-resize')) {
                this.startResize(event, block);
            } else {
                this.startMove(event, block);
            }
        }
    }

    startPaint(event, body) {
        event.preventDefault();
        const start = this.pointerMinutes(body, event.clientY);
        this.gesture = { kind: 'paint', body, day: body.dataset.plannerDay, start, end: start };
        this.preview = document.createElement('div');
        this.preview.className = 'planner-paint-preview';
        body.appendChild(this.preview);
        this.renderPreview();
    }

    startMove(event, block) {
        event.preventDefault();
        const body = block.closest('.planner-day-body');
        const durationMin = this.blockDuration(block);
        const grabMin = this.pointerMinutes(body, event.clientY);
        const topMin = this.snap(parseFloat(block.style.top) / 100 * 1440);
        this.gesture = { kind: 'move', block, body, durationMin, offset: grabMin - topMin };
        block.classList.add('is-dragging');
    }

    startResize(event, block) {
        event.preventDefault();
        const body = block.closest('.planner-day-body');
        const topMin = this.snap(parseFloat(block.style.top) / 100 * 1440);
        this.gesture = { kind: 'resize', block, body, topMin };
        block.classList.add('is-dragging');
    }

    // ---- gesture move -----------------------------------------------------

    handlePointerMove(event) {
        if (!this.gesture) {
            return;
        }
        const g = this.gesture;
        if (g.kind === 'paint') {
            g.end = this.pointerMinutes(g.body, event.clientY);
            this.renderPreview();
        } else if (g.kind === 'move') {
            let top = this.snap(this.pointerMinutes(g.body, event.clientY) - g.offset);
            top = Math.min(top, 1440 - g.durationMin);
            g.block.style.top = `${top / 1440 * 100}%`;
            g.block.style.height = `${g.durationMin / 1440 * 100}%`;
            g.newTop = top;
        } else if (g.kind === 'resize') {
            const end = Math.max(g.topMin + this.rasterValue, this.pointerMinutes(g.body, event.clientY));
            g.block.style.height = `${(end - g.topMin) / 1440 * 100}%`;
            g.newEnd = end;
        }
    }

    renderPreview() {
        const g = this.gesture;
        const top = Math.min(g.start, g.end);
        const height = Math.max(this.rasterValue, Math.abs(g.end - g.start));
        this.preview.style.top = `${top / 1440 * 100}%`;
        this.preview.style.height = `${height / 1440 * 100}%`;
    }

    // ---- gesture end ------------------------------------------------------

    handlePointerUp() {
        const g = this.gesture;
        if (!g) {
            return;
        }
        this.gesture = null;

        if (g.kind === 'paint') {
            const start = Math.min(g.start, g.end);
            const end = Math.max(g.start, g.end);
            this.preview?.remove();
            this.preview = null;
            if (end - start >= this.rasterValue) {
                this.paint(g.day, start, end);
            }
        } else if (g.kind === 'move') {
            g.block.classList.remove('is-dragging');
            if (g.newTop !== undefined) {
                const day = g.body.dataset.plannerDay;
                this.moveShift(g.block, this.isoAtMinutes(day, g.newTop), this.isoAtMinutes(day, g.newTop + g.durationMin));
            }
        } else if (g.kind === 'resize') {
            g.block.classList.remove('is-dragging');
            if (g.newEnd !== undefined) {
                const day = g.body.dataset.plannerDay;
                this.moveShift(g.block, g.block.dataset.start, this.isoAtMinutes(day, g.newEnd));
            }
        }

        this.releaseDeferredReload();
    }

    clearGesture() {
        this.preview?.remove();
        this.preview = null;
        this.gesture = null;
    }

    /**
     * The moments an edit can finish.
     *
     * Called after a gesture ends and after the selection changes, which together with the modal
     * listener in connect() cover every state {@see midEdit()} holds a remote change back for. A
     * deferred change that never found one of these would sit unapplied until the next local edit.
     */
    releaseDeferredReload() {
        this.applyRemoteReloadIfIdle();
    }

    blockDuration(block) {
        return this.snap(parseFloat(block.style.height) / 100 * 1440) || this.rasterValue;
    }

    // ---- selection & delete ----------------------------------------------

    handleKeyDown(event) {
        if ((event.key === 'Delete' || event.key === 'Backspace') && this.selected.size > 0) {
            event.preventDefault();
            this.deleteSelected();
        }
    }

    blockTargetConnected(block) {
        block.addEventListener('click', (event) => this.toggleSelect(event, block));
        // Runs for every block the reloaded grid brings in, which is what re-applies the outlines.
        block.classList.toggle('planner-block-invalid', this.invalid.has(block.dataset.shiftId));
    }

    applyInvalid(uuids) {
        this.invalid = new Set(uuids);
        this.blockTargets.forEach((block) => {
            block.classList.toggle('planner-block-invalid', this.invalid.has(block.dataset.shiftId));
        });
    }

    toggleSelect(event, block) {
        const additive = event.shiftKey || event.ctrlKey || event.metaKey;
        if (!additive) {
            this.selected.forEach((b) => b.classList.remove('is-selected'));
            this.selected.clear();
        }
        if (this.selected.has(block)) {
            this.selected.delete(block);
            block.classList.remove('is-selected');
        } else {
            this.selected.add(block);
            block.classList.add('is-selected');
        }
        this.dispatch('selection', {
            target: window,
            detail: { ids: Array.from(this.selected).map((b) => b.dataset.shiftId) },
        });

        this.releaseDeferredReload();
    }

    // ---- server calls -----------------------------------------------------

    async paint(day, startMin, endMin) {
        await this.post(this.paintUrlValue, {
            _token: this.paintTokenValue,
            department: this.departmentValue,
            audience: this.audienceValue,
            task: this.taskValue,
            location: this.locationValue,
            intervals: [{ start: this.isoAtMinutes(day, startMin), end: this.isoAtMinutes(day, endMin) }],
        });
    }

    async moveShift(block, start, end) {
        await this.post(block.dataset.moveUrl, { _token: this.editTokenValue, start, end });
    }

    async deleteSelected() {
        const blocks = Array.from(this.selected);
        this.selected.clear();
        for (const block of blocks) {
            await this.post(block.dataset.deleteUrl, { _token: this.editTokenValue });
        }
    }

    async post(url, body) {
        try {
            const response = await backgroundFetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body),
            });
            if (response === null) {
                return;
            }
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                await alertModal(data.error || 'The change could not be saved. Reload and try again.');
            }
        } catch (e) {
            console.error('Save request failed.', e);
            await alertModal('Network error while saving. Reload and try again.');
        }
        await this.reloadGrid();
    }

    async reloadGrid() {
        try {
            const response = await backgroundFetch(window.location.href);
            if (response === null) {
                return;
            }
            const html = await response.text();
            const parsed = new DOMParser().parseFromString(html, 'text/html');
            const fresh = parsed.querySelector('[data-planner-target="grid"]');
            const current = this.hasGridTarget ? this.gridTarget : this.element.querySelector('[data-planner-target="grid"]');
            if (fresh && current) {
                /*
                 * The grid element is the horizontal scroll container, so replacing it would land the
                 * manager back at day one after every paint. Carry the offsets over to the new element;
                 * the browser clamps them if the grid got narrower.
                 */
                const left = current.scrollLeft;
                const top = current.scrollTop;
                const pageX = window.scrollX;
                const pageY = window.scrollY;

                current.replaceWith(fresh);
                /*
                 * The blocks the panel was editing are gone with the old grid - after a batch delete
                 * they no longer exist at all - so the panel has to be told the selection is empty,
                 * or it keeps offering to act on shifts that are not there.
                 */
                this.selected = new Set();
                this.dispatch('selection', { target: window, detail: { ids: [] } });

                fresh.scrollLeft = left;
                fresh.scrollTop = top;
                window.scrollTo(pageX, pageY);
            }

            this.refreshPublishBar(parsed);
        } catch (e) {
            console.error('Refresh failed; falling back to a full page load.', e);
            window.location.reload();
        }
    }

    refreshPublishBar(parsed) {
        const fresh = parsed.querySelector('#planner-publish-bar');
        const current = document.querySelector('#planner-publish-bar');
        if (fresh && current) {
            current.replaceWith(fresh);
        }
    }
}
