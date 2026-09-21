import { Controller } from '@hotwired/stimulus';

/*
 * THE WATCHES SECTION'S CONTROLS — which station runs which shift, what each
 * one does differently, and a shift's own colour.
 *
 * NOTHING IS WRITTEN UNTIL SAVE. Every control here changes the form and the
 * form's one submit sends it: twelve stations times four shifts is
 * forty-eight toggles, and a surface that posted each click would make
 * setting up a park forty-eight round trips and leave it half-configured if
 * somebody closed the tab.
 *
 * A TOGGLE DRIVES A DISABLED INPUT AND NOT THE DOM. Each station × shift
 * cell already carries the hidden input that would say it runs that shift;
 * the toggle only enables or disables it, and a disabled input is not
 * submitted. So the form's shape never changes, and nothing here has to
 * know how the server names a field.
 *
 * REMOVING AN EXCEPTION IS HOW A STATION GOES BACK TO FOLLOWING THE AREA.
 * There is no "same as the area" value to send: the row's silence is that
 * answer, which is why the only control beside an exception is a cross and
 * why the server replaces a station's exceptions with what its row sent.
 *
 * WITHOUT JAVASCRIPT THE PAGE STILL READS. The server renders every toggle
 * in the state it is in and every exception a station has; this controller
 * only takes over the changing of them.
 */
export default class extends Controller {
    /** How many slots the house's plate palette has. Stated once. */
    static SLOTS = 18;

    /** A station runs a shift, or stops running it. */
    toggle(event) {
        event.preventDefault();

        const button = event.currentTarget;
        const on = 'true' !== button.getAttribute('aria-pressed');

        button.setAttribute('aria-pressed', on ? 'true' : 'false');
        button.classList.toggle('on', on);

        const field = button.parentElement?.querySelector('[data-roster-expects]');
        if (field) {
            field.disabled = !on;
        }
    }

    /**
     * THE NEXT COLOUR IN THE PALETTE. A shift's colour is a SLOT and never a
     * hue: the house owns the eighteen and this cycles through them, so a
     * theme change reaches every shift in the product without a migration.
     */
    recolour(event) {
        event.preventDefault();

        const row = event.currentTarget.closest('[data-roster-shift]');
        const field = row?.querySelector('[data-roster-colour]');
        if (!row || !field) {
            return;
        }

        const next = (parseInt(field.value, 10) % this.constructor.SLOTS) + 1;
        field.value = String(next);
        row.dataset.cat = String(next);
    }

    /** Give this station its own answer to one rule. */
    except(event) {
        event.preventDefault();

        const box = event.currentTarget.closest('[data-roster-exceptions]');
        const shape = this.element.querySelector('[data-roster-exception-template]');
        if (!box || !shape) {
            return;
        }

        const line = shape.content.firstElementChild.cloneNode(true);
        const station = box.dataset.station;

        // THE FIELD NAMES ARE THE SERVER'S SHAPE and are written here rather
        // than in the template, because the template is one shape stamped
        // onto twelve rows and only the row knows which station it is.
        line.querySelector('[data-roster-field="kind"]').name = `exception_${station}_kind[]`;
        line.querySelector('[data-roster-field="value"]').name = `exception_${station}_value[]`;
        line.querySelector('[data-roster-field="unit"]').name = `exception_${station}_unit[]`;

        event.currentTarget.before(line);
        this.offerTheRightUnits(line);
        line.querySelector('[data-roster-field="kind"]').addEventListener('change', () => this.offerTheRightUnits(line));
        this.renameTheAddButton(box);
    }

    /** And take it back off, which is how it follows the area again. */
    forget(event) {
        event.preventDefault();

        const box = event.currentTarget.closest('[data-roster-exceptions]');
        event.currentTarget.closest('.ln')?.remove();

        if (box) {
            this.renameTheAddButton(box);
        }
    }

    /**
     * ONLY THE UNITS THAT CAN MEASURE THE CHOSEN RULE. A catchment in hours
     * is not a tight catchment, it is a sentence nobody can act on — and the
     * server refuses it, so offering it would be a control that fails.
     */
    offerTheRightUnits(line) {
        const kind = line.querySelector('[data-roster-field="kind"]').value;
        const unit = line.querySelector('[data-roster-field="unit"]');

        let first = null;
        [...unit.options].forEach((option) => {
            const fits = option.dataset.for === kind;
            option.hidden = !fits;
            option.disabled = !fits;
            if (fits && null === first) {
                first = option;
            }
        });

        if (first && unit.options[unit.selectedIndex]?.dataset.for !== kind) {
            unit.value = first.value;
        }
    }

    /**
     * THE ADD CONTROL SAYS WHICH IT IS. "+ give it its own rule" where a
     * station has none and "+ another" where it has — the drawn wording,
     * kept true as lines come and go.
     */
    renameTheAddButton(box) {
        const add = box.querySelector('.add');
        if (add) {
            add.textContent = box.querySelector('.ln') ? '+ another' : '+ give it its own rule';
        }
    }
}
