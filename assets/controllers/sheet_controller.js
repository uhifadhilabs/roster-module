import { Controller } from '@hotwired/stimulus';

/*
 * THE SHEET IS BOUNDED TO WHAT IS LEFT OF THE SCREEN.
 *
 * CSS cannot say "the viewport, less everything above me", so the one number
 * it is missing is measured here and handed back as `--sheetmax`. The bound
 * sits on the SCROLLER so a head that has wrapped to three lines at 400px is
 * never cut.
 *
 * THE CHROME IS MEASURED, NOT ASSUMED. `card.offsetHeight - wrap.offsetHeight`
 * is the head, the body's padding and the key row, whatever they came to on
 * this screen; the constant it replaced was 80px against a real 115, which is
 * how a 480px floor rendered a 515px card.
 *
 * AND THE SPACE IS READ FROM THE CARD'S PLACE IN THE DOCUMENT, not from where
 * the screen happens to be pointing. Reading the viewport top means a page
 * opened already scrolled down measures a card that is nearly at the top of
 * the screen and hands it a taller sheet than the same page scrolled to 0 —
 * the height would be a function of the scroll position. It is a function of
 * the layout and the viewport, so it is recomputed on RESIZE and never on
 * scroll.
 *
 * AND THE WINDOW OPENS ON TODAY. The sheet starts on a monday whatever day
 * somebody opens it, so at four weeks today's column can be most of a month
 * to the right; a planner should not have to go looking for the day they are
 * standing in.
 */
export default class extends Controller {
    static targets = ['scroller', 'today'];

    /* RULED 21 sep, owner: "increase the height of The sheet by 30%." The
       sheet the owner was looking at measured 515px, so the card's floor is
       515 x 1.3, and what the screen leaves is taken at the same 1.3 and
       capped at one viewport less the card's own chrome — a tall screen gets
       a taller sheet, a short one is still bounded. */
    static CARD_FLOOR = 670;

    /* Below this the scroller stops being a sheet and starts being a slot.
       It must stay under the no-script `min-height` in roster.css, or a CSS
       minimum beats the measured maximum and the card overshoots. */
    static SCROLLER_FLOOR = 300;

    static GROW = 1.3;

    connect() {
        this.resize = () => this.size();
        window.addEventListener('resize', this.resize);
        this.size();
        this.showToday();
    }

    disconnect() {
        window.removeEventListener('resize', this.resize);
    }

    size() {
        if (!this.hasScrollerTarget) {
            return;
        }

        const wrap = this.scrollerTarget;
        // Everything of the card that is not the scroller, measured.
        const chrome = this.element.offsetHeight - wrap.offsetHeight;
        // The card's top in the DOCUMENT, so the answer does not move with
        // the scroll position.
        const top = this.element.getBoundingClientRect().top + window.scrollY;
        const floor = Math.max(this.constructor.SCROLLER_FLOOR, this.constructor.CARD_FLOOR - chrome);
        const free = window.innerHeight - Math.min(Math.max(top, 0), window.innerHeight) - chrome;
        const cap = window.innerHeight - chrome;

        const max = Math.round(Math.max(floor, Math.min(free * this.constructor.GROW, cap)));

        wrap.style.setProperty('--sheetmax', `${max}px`);
    }

    /* Sideways only: the sheet opens at its first station, never scrolled
       past one. */
    showToday() {
        if (!this.hasScrollerTarget || !this.hasTodayTarget) {
            return;
        }

        const column = this.todayTarget.getBoundingClientRect();
        const port = this.scrollerTarget.getBoundingClientRect();

        if (column.left >= port.left && column.right <= port.right) {
            return;
        }

        this.scrollerTarget.scrollLeft += column.left - port.left - (port.width - column.width) / 2;
    }
}
