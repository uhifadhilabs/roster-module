import { Controller } from '@hotwired/stimulus';

/*
 * WHERE "NOW" IS ON THE DAY BOARD — placed from the VIEWER'S clock, and
 * placed again every minute.
 *
 * THE SERVER CANNOT ANSWER THIS, and that is the whole reason the controller
 * exists rather than a `style="left:…"` rendered in Twig. Three things were
 * wrong with the server-rendered line, and only the third is obvious:
 *
 *   1. IT WAS THE SERVER'S INSTANT, in the SERVER'S timezone. The ruling is
 *      that the viewer's clock is the authority for every instant in the
 *      product; a box in UTC drawing a line for somebody in UTC+3 is three
 *      hours wrong and looks deliberate.
 *   2. IT WAS THE SERVER'S IDEA OF WHICH DAY IT IS. Whether to draw the line
 *      at all was decided by comparing dates in the server's zone, so for
 *      the hours around midnight the line appeared on the wrong day.
 *   3. IT WAS FROZEN AT RENDER. The position never moved again. This board
 *      is explicitly designed for "a screen on an office wall", which is a
 *      page nobody reloads — so by the afternoon the line was wherever the
 *      morning had left it. This is the defect somebody actually reported.
 *
 * SO: the element is rendered with the DAY it belongs to and nothing else,
 * and the browser — which is the only thing that knows what time it is where
 * the reader is — decides both the position and whether there is a line to
 * draw at all.
 *
 * IT TICKS ON THE MINUTE, not every sixty seconds from whenever it loaded, so
 * the line moves when the clock does and two boards side by side agree.
 */
export default class extends Controller {
    static values = {
        /** The day this board is drawing, as YYYY-MM-DD in the AREA's own calendar. */
        day: String,
    };

    connect() {
        this.place();
        this.tick();
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    /**
     * THE LINE, OR NO LINE. A day that is not the viewer's today has no "now"
     * on it: drawing one would put this minute on a day nobody is standing.
     */
    place() {
        const now = new Date();

        if (this.dayValue !== NowLine.localDate(now)) {
            this.element.hidden = true;

            return;
        }

        this.element.hidden = false;
        this.element.style.left = `${NowLine.percentOfDay(now)}%`;
    }

    /** Re-place on the next minute boundary, then every minute after it. */
    tick() {
        const msToNextMinute = 60000 - (Date.now() % 60000);

        this.timer = setTimeout(() => {
            this.place();
            this.tick();
        }, msToNextMinute);
    }
}

/*
 * THE MATHS, KEPT OUT OF THE CONTROLLER so it can be read — and compared with
 * the server's own percent-of-a-day, which places the blocks the line runs
 * over. The two have to agree or the line lands between the right hours.
 */
export const NowLine = {
    /** How far through the LOCAL day an instant is, as a percentage. */
    percentOfDay(at) {
        return ((at.getHours() * 60 + at.getMinutes()) / (24 * 60)) * 100;
    },

    /** The viewer's own calendar date, which is not necessarily the server's. */
    localDate(at) {
        return [
            at.getFullYear(),
            String(at.getMonth() + 1).padStart(2, '0'),
            String(at.getDate()).padStart(2, '0'),
        ].join('-');
    },
};
