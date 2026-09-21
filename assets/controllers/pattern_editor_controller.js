import { Controller } from '@hotwired/stimulus';

/*
 * THE SENTENCE EDITOR — a cycle said as parts, and the name it derives.
 *
 * RULED 21 sep, owner: "the name should not be written by user but rather
 * generated from the config the user chooses." There is no name field: the
 * sentence is the input and the name is output, recomputed on every
 * keystroke — and the SERVER derives it again from the posted cycle, so the
 * register, this editor and the sheet's fill row cannot print three
 * different names for one object.
 *
 * THE DERIVATION HERE AND THE DERIVATION IN PHP ARE ONE RULE IN TWO PLACES,
 * and that is the seam a text-level test pins: `<n> days of <shift>`,
 * singular `<1> day of <shift>`, and an off part `<n> off`. THE PRODUCT
 * NEVER PLURALISES A SHIFT NAME — the words are the area's own, it does not
 * know one from another, and it will not be taught English. Longer, and
 * never wrong.
 *
 * THE STRIP IS DRAWN FROM THE SAME PARTS THE SENTENCE NAMES, never written
 * out beside them, so the two cannot disagree and neither needs a round
 * trip. A day wears its shift's own stored palette slot, which the shell
 * turns into a colour; this file names no hue.
 *
 * WITHOUT JAVASCRIPT THE PAGE STILL READS. The server renders the cycle, its
 * name and its length as they stand; this controller only takes over the
 * editing, so an installation whose assets did not compile shows a pattern
 * somebody can read rather than a row of dead controls.
 */
export default class extends Controller {
    /** THE LONGEST STRIP AN EDITOR DRAWS before it says "+N", so a
     *  twenty-eight day cycle cannot make the card twice as tall. */
    static STRIP_DAYS = 28;

    connect() {
        this.render();
    }

    /** A part, added after the last one — the drawn "+ then…". */
    add(event) {
        event.preventDefault();

        const parts = this.parts;
        const last = parts[parts.length - 1];
        if (!last) {
            return;
        }

        const copy = last.cloneNode(true);
        copy.querySelector('[data-roster-days]').value = '1';
        // EVERY PART BUT THE FIRST CARRIES ITS OWN REMOVE, and the first
        // never does: a cycle of no parts is not a cycle, so the control
        // that would empty it does not exist.
        if (!copy.querySelector('.x')) {
            copy.insertAdjacentHTML('beforeend', '<button type="button" class="x" title="Remove this part" data-action="roster--pattern-editor#drop">×</button>');
        }
        last.after(copy);
        this.render();
    }

    /** And one removed. */
    drop(event) {
        event.preventDefault();
        event.currentTarget.closest('[data-roster-part]')?.remove();
        this.render();
    }

    /** Any keystroke or choice inside the sentence re-derives everything. */
    render() {
        const parts = this.parts.map((row) => ({
            row,
            days: Math.max(0, parseInt(row.querySelector('[data-roster-days]').value, 10) || 0),
            shift: row.querySelector('[data-roster-shift]'),
        }));

        this.renderLeads(parts);
        this.renderName(parts);
        this.renderStrip(parts);
        this.renderCycle(parts);
    }

    /** THE LEADS AND THE WORD BEFORE THE SHIFT, kept in step. */
    renderLeads(parts) {
        parts.forEach((part, index) => {
            const lead = part.row.querySelector('.lead');
            if (lead) {
                lead.textContent = 0 === index ? '' : 'then';
            }

            const unit = part.row.querySelector('[data-roster-unit]');
            if (unit) {
                unit.textContent = 1 === part.days ? 'day of' : 'days of';
            }
        });
    }

    /** The name, derived — and the length beside it. */
    renderName(parts) {
        const name = parts
            .filter((part) => part.days > 0)
            .map((part) => {
                const key = part.shift.value;
                if ('off' === key) {
                    return `${part.days} off`;
                }

                const label = part.shift.options[part.shift.selectedIndex]?.text ?? key;

                return `${part.days} ${1 === part.days ? 'day' : 'days'} of ${label}`;
            })
            .join(', ');

        const days = parts.reduce((total, part) => total + part.days, 0);

        const title = this.element.querySelector('[data-roster-name]');
        if (title) {
            title.textContent = name || 'Build the cycle and the name writes itself';
            title.classList.toggle('empty', '' === name);
        }

        const length = this.element.querySelector('[data-roster-length]');
        if (length) {
            length.textContent = '';
            if (0 === days) {
                length.textContent = 'nothing in the cycle yet';
            } else {
                const bold = document.createElement('b');
                bold.textContent = `${days} day${1 === days ? '' : 's'} long`;
                length.append(bold);
            }
        }
    }

    /** The cycle, as its days. */
    renderStrip(parts) {
        const strip = this.element.querySelector('[data-roster-strip]');
        if (!strip) {
            return;
        }

        strip.textContent = '';

        let drawn = 0;
        let total = 0;
        parts.forEach((part) => {
            const off = 'off' === part.shift.value;
            const slot = part.shift.options[part.shift.selectedIndex]?.dataset.cat;

            for (let day = 0; day < part.days; day++) {
                total++;
                if (drawn >= this.constructor.STRIP_DAYS) {
                    continue;
                }

                const box = document.createElement('i');
                box.className = off ? 'pd o' : 'pd';
                if (!off && slot) {
                    box.dataset.cat = slot;
                }
                strip.append(box);
                drawn++;
            }
        });

        if (total > drawn) {
            const more = document.createElement('em');
            more.className = 'pmore';
            more.textContent = `+${total - drawn}`;
            strip.append(more);
        }
    }

    /**
     * AND THE ONE FIELD THE FORM POSTS. The cycle goes as one object because
     * the server validates it as one: a ring applied half-way is a ring that
     * plans a month nobody asked for.
     */
    renderCycle(parts) {
        const field = this.element.querySelector('[data-roster-cycle]');
        if (field) {
            field.value = JSON.stringify(
                parts.filter((part) => part.days > 0).map((part) => ({ days: part.days, shift: part.shift.value })),
            );
        }
    }

    get parts() {
        return [...this.element.querySelectorAll('[data-roster-part]')];
    }
}
