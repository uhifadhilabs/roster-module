import { Controller } from '@hotwired/stimulus';

/*
 * THE SHEET IS BOUNDED TO WHAT IS LEFT OF THE SCREEN.
 *
 * CSS cannot say "the viewport, less everything above me", so the one number
 * it is missing is measured here and handed back as `--sheetmax`. The floor
 * keeps the card at 480px even when the sheet starts below the fold, and the
 * bound sits on the SCROLLER so a head that has wrapped to three lines at
 * 400px is never cut.
 *
 * AND THE WINDOW OPENS ON TODAY. The sheet starts on a monday whatever day
 * somebody opens it, so at four weeks today's column can be most of a month
 * to the right; a planner should not have to go looking for the day they are
 * standing in.
 */
export default class extends Controller {
    static targets = ['scroller', 'today'];

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

        const head = this.element.querySelector('.rb-hd');
        const chrome = (head ? head.offsetHeight : 0) + 24;
        const top = this.element.getBoundingClientRect().top + window.pageYOffset;
        const floor = Math.max(260, 480 - chrome);
        const free = window.innerHeight - Math.min(top, window.innerHeight) - chrome;

        this.scrollerTarget.style.setProperty('--sheetmax', `${Math.round(Math.max(floor, free))}px`);
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
