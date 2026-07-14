import { Controller } from '@hotwired/stimulus';

/*
 * Global Planning Availability grid: a paint interaction over a day×time grid.
 * Pick a value (Preferred/Available/Avoid/Unavailable) or Erase,
 * then drag to paint. Painting reconciles overlaps so each cell holds one value;
 * confirmed-assignment overlays are shown and cannot be painted over. On submit
 * the whole schedule is serialized to a hidden field — one global submission.
 */
export default class extends Controller {
    static targets = ['dayBody', 'payload', 'grid'];
    static values = {
        ranges: Array,
        overlays: Array,
        raster: { type: Number, default: 60 },
    };

    connect() {
        this.value = 'available';
        const checked = this.element.querySelector('input[name="paint-value"]:checked');
        if (checked) {
            this.value = checked.value;
        }
        this.blocks = this.rangesValue.map((r) => ({ ...r }));
        this.overlaysByDay = {};
        this.overlaysValue.forEach((o) => {
            (this.overlaysByDay[o.day] = this.overlaysByDay[o.day] || []).push(o);
        });

        this.onDown = this.handleDown.bind(this);
        this.onMove = this.handleMove.bind(this);
        this.onUp = this.handleUp.bind(this);
        this.element.addEventListener('pointerdown', this.onDown);
        window.addEventListener('pointermove', this.onMove);
        window.addEventListener('pointerup', this.onUp);
        this.element.addEventListener('submit', () => this.serialize());

        this.render();
    }

    disconnect() {
        this.element.removeEventListener('pointerdown', this.onDown);
        window.removeEventListener('pointermove', this.onMove);
        window.removeEventListener('pointerup', this.onUp);
    }

    setValue(event) {
        this.value = event.target.value; // '' = erase
    }

    // ---- geometry ---------------------------------------------------------

    minutesAt(body, clientY) {
        const rect = body.getBoundingClientRect();
        const raw = (clientY - rect.top) / rect.height * 1440;
        return Math.max(0, Math.min(1440, Math.round(raw / this.rasterValue) * this.rasterValue));
    }

    isoAt(day, minutes) {
        const [y, m, d] = day.split('-').map(Number);
        const base = new Date(Date.UTC(y, m - 1, d));
        base.setUTCMinutes(base.getUTCMinutes() + minutes);
        const pad = (n) => String(n).padStart(2, '0');
        return `${base.getUTCFullYear()}-${pad(base.getUTCMonth() + 1)}-${pad(base.getUTCDate())}`
            + `T${pad(base.getUTCHours())}:${pad(base.getUTCMinutes())}:00`;
    }

    // ---- painting ---------------------------------------------------------

    handleDown(event) {
        const body = event.target.closest('.avail-day-body');
        if (!body || event.button !== 0) {
            return;
        }
        event.preventDefault();
        const start = this.minutesAt(body, event.clientY);
        this.gesture = { body, day: body.dataset.day, start, end: start };
        this.preview = document.createElement('div');
        this.preview.className = 'planner-paint-preview';
        body.appendChild(this.preview);
        this.drawPreview();
    }

    handleMove(event) {
        if (!this.gesture) {
            return;
        }
        this.gesture.end = this.minutesAt(this.gesture.body, event.clientY);
        this.drawPreview();
    }

    drawPreview() {
        const g = this.gesture;
        const top = Math.min(g.start, g.end);
        const height = Math.max(this.rasterValue, Math.abs(g.end - g.start));
        this.preview.style.top = `${top / 1440 * 100}%`;
        this.preview.style.height = `${height / 1440 * 100}%`;
    }

    handleUp() {
        const g = this.gesture;
        if (!g) {
            return;
        }
        this.gesture = null;
        this.preview?.remove();
        this.preview = null;

        const start = Math.min(g.start, g.end);
        const end = Math.max(g.start, g.end);
        if (end - start < this.rasterValue) {
            return;
        }
        this.paint(g.day, start, end);
        this.render();
    }

    paint(day, start, end) {
        // Remove/trim existing blocks in this day that overlap the painted span.
        const kept = [];
        for (const block of this.blocks) {
            if (block.day !== day || block.endMin <= start || block.startMin >= end) {
                kept.push(block);
                continue;
            }
            if (block.startMin < start) {
                kept.push({ ...block, endMin: start });
            }
            if (block.endMin > end) {
                kept.push({ ...block, startMin: end });
            }
        }
        this.blocks = kept;
        if (this.value !== '') {
            this.blocks.push({ day, startMin: start, endMin: end, value: this.value });
        }
    }

    // ---- render & serialize ----------------------------------------------

    render() {
        this.dayBodyTargets.forEach((body) => {
            body.querySelectorAll('.avail-block, .avail-overlay').forEach((el) => el.remove());
            const day = body.dataset.day;

            (this.overlaysByDay[day] || []).forEach((o) => {
                body.appendChild(this.makeBlock('avail-overlay', o.startMin, o.endMin, o.title));
            });
            this.blocks.filter((b) => b.day === day).forEach((b) => {
                body.appendChild(this.makeBlock(`avail-block avail-swatch-${b.value}`, b.startMin, b.endMin));
            });
        });
    }

    makeBlock(className, startMin, endMin, label) {
        const el = document.createElement('div');
        el.className = className;
        el.style.top = `${startMin / 1440 * 100}%`;
        el.style.height = `${(endMin - startMin) / 1440 * 100}%`;
        if (label) {
            el.textContent = label;
        }
        return el;
    }

    serialize() {
        const payload = this.blocks.map((b) => ({
            start: this.isoAt(b.day, b.startMin),
            end: this.isoAt(b.day, b.endMin),
            value: b.value,
        }));
        this.payloadTarget.value = JSON.stringify(payload);
    }
}
