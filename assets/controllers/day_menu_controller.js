import { Controller } from '@hotwired/stimulus';

/*
 * THE BY-HAND MENU ON ONE DAY.
 *
 * IT IS PORTALLED OUT OF THE SHEET, and that is not a nicety. The sheet is a
 * scroller with a sticky column head and, at four weeks, a sticky ranger
 * column; its `<tbody>` carries `isolation: isolate` so a day cell — which is
 * positioned, because an edited day wears a corner mark — can never paint
 * over the row saying which day it is. Every one of those is a stacking
 * context, and a menu opened INSIDE them is trapped underneath: it opened
 * behind the day cells, which is the design's own reported defect.
 *
 * No z-index can win an argument with an ancestor stacking context, so the
 * menu leaves. On open the element is moved to `document.body`, fixed at the
 * cell's own bounding rect, and given a z-index above every sticky layer the
 * sheet uses; on close it is put back where the markup drew it, so the
 * template stays the one place the menu exists and nothing is cloned.
 *
 * BEING FIXED, IT CANNOT FOLLOW THE CELL. So any scroll closes it, as does a
 * resize, a click outside it, and Escape — the ordinary behaviour of a menu
 * pinned to a thing that moves.
 */
export default class extends Controller {
    /* Above the sheet's sticky head (6), its sticky ranger column (5) and the
       house's grouped dropdown (50); below the shell's confirm modal (120),
       which must always win. Kept in step with `.pmenu.pmout` in roster.css. */
    static PORTAL_Z = 80;

    /* How far the menu stays clear of the viewport's edges. */
    static GUTTER = 8;

    connect() {
        this.open_ = null;
        this.home = null;

        this.dismiss = (event) => {
            if (event.target.closest('.pmenu') || event.target.closest('[data-action*="day-menu#open"]')) {
                return;
            }

            this.close();
        };
        this.away = () => this.close();
        this.escape = (event) => {
            if (event.key === 'Escape') {
                this.close();
            }
        };

        document.addEventListener('click', this.dismiss);
        document.addEventListener('keydown', this.escape);
        // Capture, so a scroll of the sheet's own scroller closes it too.
        window.addEventListener('scroll', this.away, true);
        window.addEventListener('resize', this.away);
    }

    disconnect() {
        // Put it back before the controller goes, or a navigation would
        // leave the menu stranded on the body with nothing owning it.
        this.close();

        document.removeEventListener('click', this.dismiss);
        document.removeEventListener('keydown', this.escape);
        window.removeEventListener('scroll', this.away, true);
        window.removeEventListener('resize', this.away);
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
        if (!menu) {
            return;
        }

        this.home = wrap;
        this.open_ = menu;
        wrap.classList.add('on');

        menu.classList.add('pmout');
        menu.style.zIndex = String(this.constructor.PORTAL_Z);
        document.body.appendChild(menu);

        this.place(wrap.getBoundingClientRect(), menu);
    }

    /* Under the cell where it fits, above it where it does not, and never
       off the side: a menu half off the screen is a menu nobody can finish. */
    place(cell, menu) {
        const gutter = this.constructor.GUTTER;
        const box = menu.getBoundingClientRect();

        let top = cell.bottom + 5;
        if (top + box.height > window.innerHeight - gutter) {
            top = Math.max(gutter, cell.top - box.height - 5);
        }

        let left = cell.left + cell.width / 2 - box.width / 2;
        left = Math.min(Math.max(gutter, left), Math.max(gutter, window.innerWidth - box.width - gutter));

        menu.style.top = `${Math.round(top)}px`;
        menu.style.left = `${Math.round(left)}px`;
    }

    close() {
        if (!this.open_ || !this.home) {
            return;
        }

        this.home.classList.remove('on');
        this.home.appendChild(this.open_);

        this.open_.classList.remove('pmout');
        this.open_.style.top = '';
        this.open_.style.left = '';
        this.open_.style.zIndex = '';

        this.open_ = null;
        this.home = null;
    }
}
