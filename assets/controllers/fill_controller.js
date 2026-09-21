import { Controller } from '@hotwired/stimulus';

/*
 * THE FILL ROW'S CYCLE STRIP.
 *
 * The strip is redrawn from the select's own data rather than fetched: every
 * cycle is already on the page, and a round trip to colour five squares
 * would be one round trip too many.
 *
 * A SHIFT'S COLOUR IS THE SHIFT'S — the strip reads the same stored slots
 * the sheet's cells do, through `data-cat`, so the row above the sheet and
 * the sheet itself can never disagree about which watch is which.
 */
export default class extends Controller {
    static targets = ['pattern', 'strip'];

    connect() {
        this.redraw();
    }

    redraw() {
        if (!this.hasPatternTarget || !this.hasStripTarget) {
            return;
        }

        const chosen = this.patternTarget.selectedOptions[0];
        const cycle = this.read(chosen && chosen.dataset.cycle);
        const colours = this.read(this.stripTarget.dataset.colours) || {};

        this.stripTarget.replaceChildren(
            ...(cycle || []).map((position) => {
                const day = document.createElement('i');
                const colour = colours[position];

                day.className = colour === undefined ? 'pd o' : 'pd';
                if (colour !== undefined) {
                    day.dataset.cat = String(colour);
                }
                day.title = position;

                return day;
            }),
        );
    }

    read(raw) {
        try {
            return raw ? JSON.parse(raw) : null;
        } catch (error) {
            return null;
        }
    }
}
