import { Controller } from '@hotwired/stimulus';

/*
 * THE CYCLE EDITOR — the ring, the per-day counts and the pool, held as a
 * DRAFT until Save.
 *
 * NOTHING IS WRITTEN UNTIL SAVE. That is the editor's promise and the whole
 * reason this controller exists: a ring is changed many times in one sitting,
 * and a surface that posted each click would generate a month per keystroke
 * and leave a half-made rotation behind if somebody closed the tab. Every
 * control here mutates local state and re-renders; the form's one submit
 * sends the whole draft.
 *
 * WITHOUT JAVASCRIPT THE PAGE STILL READS. The server renders the rotation as
 * it stands and this controller only takes over the editing — so an
 * installation whose assets did not compile shows a rotation somebody can
 * read rather than a row of dead buttons.
 *
 * THE DRAFT IS SERIALISED INTO ONE FIELD, deliberately: the server validates
 * it as one object (RotationDraft), so a malformed ring is refused whole with
 * a sentence rather than half-applied field by field.
 */
export default class extends Controller {
    static targets = ['ring', 'counts', 'pool', 'draft', 'summary'];

    static values = {
        /** The ring, the counts and the pool as the server last stored them. */
        draft: Object,
        /** The area's shift vocabulary: [{key, label}], in the area's own order. */
        shifts: Array,
        /** Everybody the pool may draw from: [{uuid, name}]. */
        candidates: Array,
    };

    connect() {
        // A COPY, never the value object itself: Stimulus hands back the same
        // reference each read, and mutating it would make "discard" impossible.
        this.draft = structuredClone(this.draftValue);
        this.render();
    }

    /** A ring day steps to the next shift, and past the last one to "off". */
    stepDay(event) {
        const at = Number(event.params.at);
        const order = [...this.shiftsValue.map((s) => s.key), 'off'];
        const current = order.indexOf(this.draft.cycle[at]);
        this.draft.cycle[at] = order[(current + 1) % order.length];
        this.render();
    }

    addDay() {
        this.draft.cycle.push('off');
        this.render();
    }

    /** The last day goes, unless it is the only one: a ring of no days repeats nothing. */
    removeDay() {
        if (this.draft.cycle.length > 1) {
            this.draft.cycle.pop();
            this.render();
        }
    }

    countUp(event) {
        const key = event.params.shift;
        this.draft.slots[key] = (this.draft.slots[key] ?? 0) + 1;
        this.render();
    }

    countDown(event) {
        const key = event.params.shift;
        this.draft.slots[key] = Math.max(0, (this.draft.slots[key] ?? 0) - 1);
        this.render();
    }

    /** In or out of the pool. Order is the plan, so somebody added joins at the back. */
    togglePerson(event) {
        const uuid = event.params.uuid;
        const at = this.draft.pool.indexOf(uuid);
        if (at === -1) {
            this.draft.pool.push(uuid);
        } else {
            this.draft.pool.splice(at, 1);
        }
        this.render();
    }

    /** ORDER IS THE PLAN: moving somebody changes which day of the ring they are on. */
    movePerson(event) {
        const { uuid, by } = event.params;
        const at = this.draft.pool.indexOf(uuid);
        const to = at + Number(by);
        if (at === -1 || to < 0 || to >= this.draft.pool.length) {
            return;
        }
        [this.draft.pool[at], this.draft.pool[to]] = [this.draft.pool[to], this.draft.pool[at]];
        this.render();
    }

    field(event) {
        const { name } = event.params;
        const el = event.currentTarget;
        this.draft[name] = name === 'horizonDays' ? Number(el.value) : el.value;
        this.render();
    }

    toggleWeekday(event) {
        const day = Number(event.params.weekday);
        const at = this.draft.standDown.indexOf(day);
        if (at === -1) {
            this.draft.standDown.push(day);
        } else {
            this.draft.standDown.splice(at, 1);
        }
        this.render();
    }

    render() {
        this.draftTarget.value = JSON.stringify(this.draft);
        this.#renderRing();
        this.#renderCounts();
        this.#renderPool();
        this.#renderSummary();
    }

    #labelFor(key) {
        return this.shiftsValue.find((s) => s.key === key)?.label ?? key;
    }

    #renderRing() {
        if (!this.hasRingTarget) return;

        const days = this.draft.cycle.map((key, at) => {
            const off = key === 'off';
            const night = !off && this.#labelFor(key).toLowerCase().includes('night');
            const cls = off ? 'o' : (night ? 'n' : 'd');

            return `<button type="button" class="${cls}" data-action="roster--rotation#stepDay"
                        data-roster--rotation-at-param="${at}"
                        title="Day ${at + 1} — click to step it to the next shift">
                        <b>${at + 1}</b><em>${off ? 'off' : this.#labelFor(key).toLowerCase()}</em>
                    </button>`;
        });

        this.ringTarget.innerHTML = days.join('')
            + `<button type="button" class="add" data-action="roster--rotation#addDay" title="Add a day to the ring"><b>+</b><em></em></button>`
            + `<button type="button" class="add" data-action="roster--rotation#removeDay" title="Take the last day off the ring"><b>&minus;</b><em></em></button>`;
    }

    #renderCounts() {
        if (!this.hasCountsTarget) return;

        // ONLY THE SHIFTS THE RING ACTUALLY STANDS. Asking how many people a
        // post wants on a watch it never runs is a question with no answer.
        const standing = [...new Set(this.draft.cycle.filter((k) => k !== 'off'))];

        this.countsTarget.innerHTML = standing.map((key) => `
            <span class="r-sh on"><i></i>${this.#labelFor(key).toLowerCase()}</span>
            <span class="r-cnt">
                <button type="button" data-action="roster--rotation#countDown" data-roster--rotation-shift-param="${key}" aria-label="One fewer on the ${this.#labelFor(key)} watch">&minus;</button>
                <b>${this.draft.slots[key] ?? 0}</b>
                <button type="button" data-action="roster--rotation#countUp" data-roster--rotation-shift-param="${key}" aria-label="One more on the ${this.#labelFor(key)} watch">+</button>
            </span>`).join('')
            || '<span class="more">the ring stands nothing — every day is off</span>';
    }

    #renderPool() {
        if (!this.hasPoolTarget) return;

        const chosen = this.draft.pool.map((uuid, at) => {
            const person = this.candidatesValue.find((c) => c.uuid === uuid);
            const name = person?.name ?? uuid;

            return `<span class="r-pill on">
                        <button type="button" data-action="roster--rotation#movePerson"
                                data-roster--rotation-uuid-param="${uuid}" data-roster--rotation-by-param="-1"
                                aria-label="${name} enters the ring one day earlier">&#9650;</button>
                        <span>${at + 1}. ${name}</span>
                        <button type="button" data-action="roster--rotation#movePerson"
                                data-roster--rotation-uuid-param="${uuid}" data-roster--rotation-by-param="1"
                                aria-label="${name} enters the ring one day later">&#9660;</button>
                        <button type="button" data-action="roster--rotation#togglePerson"
                                data-roster--rotation-uuid-param="${uuid}"
                                aria-label="Take ${name} out of the ring">&times;</button>
                    </span>`;
        });

        const rest = this.candidatesValue
            .filter((c) => !this.draft.pool.includes(c.uuid))
            .map((c) => `<button type="button" class="r-pill" data-action="roster--rotation#togglePerson"
                            data-roster--rotation-uuid-param="${c.uuid}">+ ${c.name}</button>`);

        this.poolTarget.innerHTML = chosen.join('') + rest.join('');
    }

    #renderSummary() {
        if (!this.hasSummaryTarget) return;

        const length = this.draft.cycle.length;
        const working = this.draft.cycle.filter((k) => k !== 'off').length;
        const asks = Object.entries(this.draft.slots)
            .filter(([, n]) => n > 0)
            .map(([k, n]) => `${this.#labelFor(k).toLowerCase()} ${n}`)
            .join(' · ');

        this.summaryTarget.textContent =
            `${length}-day ring · ${working} working, ${length - working} off · ${asks || 'nothing asked for'} · ${this.draft.pool.length} in the pool`;
    }
}
