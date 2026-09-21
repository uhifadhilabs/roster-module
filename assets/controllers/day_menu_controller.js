import { Controller } from '@hotwired/stimulus';

/*
 * THE BY-HAND MENU ON ONE DAY.
 *
 * IT IS LIFTED OUT OF THE SHEET, and that is not a nicety. The sheet is a
 * scroller with a sticky column head and, at four weeks, a sticky ranger
 * column; its `<tbody>` carries `isolation: isolate` so a day cell — which is
 * positioned, because an edited day wears a corner mark — can never paint
 * over the row saying which day it is. Two separate things were wrong with a
 * menu drawn inside all that: `.psheetwrap` is `overflow:auto`, so the panel
 * was CLIPPED, which no z-index can argue with; and inside the tbody it lost
 * the hit test to the day squares. So on open the element moves to a layer on
 * `<body>` and on close it goes back where the markup drew it — the template
 * stays the one place the menu exists, and nothing is cloned.
 *
 * IT STAYS ATTACHED TO ITS CELL, and that is CSS's job, not a scroll handler's.
 * The open cell is given an `anchor-name` and the panel is positioned against
 * it with `position-anchor` / `anchor()` and `position-try` fallbacks, so the
 * browser moves the two together on the same frame it paints the scroll. The
 * JS fallback below runs only where anchor positioning is missing, and even
 * then it writes inside `requestAnimationFrame` rather than from the scroll
 * event — repositioning from the handler is exactly the jitter the owner saw,
 * the panel landing a frame behind the cell it belongs to.
 *
 * AND IT CLOSES WHEN ITS CELL LEAVES THE BAND. A menu anchored to a row that
 * has slid under the pinned day head, or past the bottom of the scroller, is
 * a menu pointing at nothing — so that, an outside click and Escape are the
 * three ways it goes away. A scroll is not one of them: it follows.
 */
export default class extends Controller {
    /* The one anchor name in play: only one menu is ever open. */
    static ANCHOR = '--roster-day-cell';

    /* How far the panel stays clear of the viewport's edges, in the
       no-anchor fallback. */
    static GUTTER = 8;

    connect() {
        this.menu = null;
        this.home = null;
        this.cell = null;
        this.frame = 0;

        this.anchored = CSS.supports?.(`position-anchor: ${this.constructor.ANCHOR}`) ?? false;

        this.dismiss = (event) => {
            if (event.target.closest('.pmenu') || event.target.closest('[data-action*="day-menu#open"]')) {
                return;
            }

            this.close();
        };
        this.escape = (event) => {
            if (event.key === 'Escape') {
                this.close();
            }
        };
        this.follow = () => this.schedule();

        document.addEventListener('click', this.dismiss);
        document.addEventListener('keydown', this.escape);
        // Capture, so the sheet's own scroller is heard as well as the page.
        window.addEventListener('scroll', this.follow, true);
        window.addEventListener('resize', this.follow);
    }

    disconnect() {
        // Put it back before the controller goes, or a navigation would
        // leave the menu stranded on the layer with nothing owning it.
        this.close();

        document.removeEventListener('click', this.dismiss);
        document.removeEventListener('keydown', this.escape);
        window.removeEventListener('scroll', this.follow, true);
        window.removeEventListener('resize', this.follow);
    }

    open(event) {
        const wrap = event.target.closest('.pmenuwrap');
        if (!wrap) {
            return;
        }

        const wasOpen = this.home === wrap;
        this.close();

        if (wasOpen) {
            return;
        }

        const menu = wrap.querySelector('.pmenu');
        const cell = wrap.querySelector('.cl');
        if (!menu || !cell) {
            return;
        }

        this.home = wrap;
        this.menu = menu;
        this.cell = cell;

        wrap.classList.add('on');
        cell.classList.add('cl-on');
        cell.style.anchorName = this.constructor.ANCHOR;

        menu.classList.add('pmout');
        this.layer().appendChild(menu);

        if (!this.anchored) {
            this.place();
        }
    }

    /** ONE VERB'S SECOND STEP, under the row it belongs to. */
    step(event) {
        const wanted = event.params.step;
        const row = event.currentTarget;
        /* SCOPED TO THE PANEL THIS ROW IS IN. The controller sits on the
           card, so every cell's steps are its targets; `shift` means this
           cell's shift chips, not the first pair of them on the sheet. */
        const panel_ = row.closest('.pmenu');
        if (!panel_) {
            return;
        }

        const open = row.getAttribute('aria-expanded') !== 'true';

        panel_.querySelectorAll('.dmstep').forEach((other) => {
            other.hidden = true;
        });
        panel_.querySelectorAll('.dmr[aria-expanded]').forEach((other) => {
            other.setAttribute('aria-expanded', 'false');
            other.classList.remove('open');
        });

        if (!open) {
            return;
        }

        const panel = panel_.querySelector(`.dmstep[data-step="${wanted}"]`);
        if (!panel) {
            return;
        }

        panel.hidden = false;
        row.setAttribute('aria-expanded', 'true');
        row.classList.add('open');
    }

    /* THE LAYER, made once and left there: a fixed, inert full-screen box
       above every sticky layer the sheet uses, which is the only stacking
       context the panel ever has to win. */
    layer() {
        let layer = document.querySelector('.pmenulayer');
        if (!layer) {
            layer = document.createElement('div');
            layer.className = 'pmenulayer';
            document.body.appendChild(layer);
        }

        return layer;
    }

    /* One write per frame, never one per scroll event. */
    schedule() {
        if (!this.menu || this.frame) {
            return;
        }

        this.frame = requestAnimationFrame(() => {
            this.frame = 0;

            if (!this.menu) {
                return;
            }

            if (this.gone()) {
                this.close();

                return;
            }

            if (!this.anchored) {
                this.place();
            }
        });
    }

    /* HAS THE CELL LEFT THE BAND? Under the pinned day head, or past the
       bottom of the scroller — either way the menu is pointing at a row
       nobody can see. */
    gone() {
        const scroller = this.cell?.closest('.psheetwrap');
        if (!scroller || !this.cell) {
            return false;
        }

        const cell = this.cell.getBoundingClientRect();
        const port = scroller.getBoundingClientRect();
        const head = scroller.querySelector('thead');
        const top = head ? port.top + head.offsetHeight : port.top;

        return cell.bottom <= top || cell.top >= port.bottom;
    }

    /* THE FALLBACK PLACEMENT, where the browser has no anchor positioning:
       under the cell where it fits, above it where it does not, and never
       off the side — a menu half off the screen is one nobody can finish. */
    place() {
        if (!this.menu || !this.cell) {
            return;
        }

        const gutter = this.constructor.GUTTER;
        const cell = this.cell.getBoundingClientRect();
        const box = this.menu.getBoundingClientRect();

        let top = cell.bottom + 5;
        if (top + box.height > window.innerHeight - gutter) {
            top = Math.max(gutter, cell.top - box.height - 5);
        }

        let left = cell.left;
        left = Math.min(Math.max(gutter, left), Math.max(gutter, window.innerWidth - box.width - gutter));

        this.menu.style.top = `${Math.round(top)}px`;
        this.menu.style.left = `${Math.round(left)}px`;
    }

    close() {
        if (!this.menu || !this.home) {
            return;
        }

        this.menu.querySelectorAll('.dmstep').forEach((panel) => {
            panel.hidden = true;
        });
        this.menu.querySelectorAll('.dmr[aria-expanded]').forEach((row) => {
            row.setAttribute('aria-expanded', 'false');
            row.classList.remove('open');
        });

        this.home.classList.remove('on');
        this.home.appendChild(this.menu);

        this.menu.classList.remove('pmout');
        this.menu.style.top = '';
        this.menu.style.left = '';

        if (this.cell) {
            this.cell.classList.remove('cl-on');
            this.cell.style.anchorName = '';
        }

        this.menu = null;
        this.home = null;
        this.cell = null;
    }
}
